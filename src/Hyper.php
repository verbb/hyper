<?php
namespace verbb\hyper;

use verbb\hyper\base\PluginTrait;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\fieldlayoutelements\AriaLabelField;
use verbb\hyper\fieldlayoutelements\ClassesField;
use verbb\hyper\fieldlayoutelements\CustomAttributesField;
use verbb\hyper\fieldlayoutelements\EmbedPreview;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fieldlayoutelements\LinkTextField;
use verbb\hyper\fieldlayoutelements\LinkTitleField;
use verbb\hyper\fieldlayoutelements\NewWindowField;
use verbb\hyper\fieldlayoutelements\UrlSuffixField;
use verbb\hyper\gql\interfaces\LinkInterface as GqlLinkInterface;
use verbb\hyper\integrations\feedme\fields\Hyper as FeedMeHyperField;
use verbb\hyper\links\Embed;
use verbb\hyper\models\Settings;
use verbb\hyper\services\LinkTypeConfigs;
use verbb\hyper\variables\HyperVariable;

use Craft;
use craft\base\Plugin;
use craft\elements\ContentBlock;
use craft\elements\db\ElementQuery;
use craft\events\ConfigEvent;
use craft\events\DefineFieldLayoutElementsEvent;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\DeleteSiteEvent;
use craft\events\ModelEvent;
use craft\events\PopulateElementEvent;
use craft\events\PopulateElementsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Fields;
use craft\services\Gql;
use craft\services\ProjectConfig;
use craft\services\Sites;
use craft\web\Controller;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;

use yii\base\ActionEvent;
use yii\base\Event;

use craft\feedme\events\RegisterFeedMeFieldsEvent;
use craft\feedme\services\Fields as FeedMeFields;

class Hyper extends Plugin
{
    // Properties
    // =========================================================================

    public bool $hasCpSettings = true;
    public string $schemaVersion = '1.4.0';


    // Traits
    // =========================================================================

    use PluginTrait;


    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        $this->_registerVariables();
        $this->_registerFieldTypes();
        $this->_registerFieldLayoutElements();
        $this->_registerProjectConfigEventHandlers();
        $this->_registerEventHandlers();
        $this->_registerGraphQl();

        // A fresh install always starts with one editable stock config.
        $this->getLinkTypeConfigs()->ensureConfigsExist();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpRoutes();
        }

        $this->_registerCachePreload();
        $this->_registerLinkedElementWithParsing();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('hyper/settings'));
    }


    // Protected Methods
    // =========================================================================

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }


    // Private Methods
    // =========================================================================

    private function _registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['hyper'] = 'hyper/plugin/settings';
            $event->rules['hyper/settings'] = 'hyper/plugin/settings';
            $event->rules['hyper/settings/link-type-configs'] = 'hyper/plugin/link-type-configs';
            $event->rules['hyper/settings/link-type-configs/new'] = 'hyper/plugin/edit-link-type-config';
            $event->rules['hyper/settings/link-type-configs/<uid:[^\/]+>'] = 'hyper/plugin/edit-link-type-config';
            $event->rules['hyper/settings/link-type-configs/save'] = 'hyper/plugin/save-link-type-config';
            $event->rules['hyper/settings/link-type-configs/delete'] = 'hyper/plugin/delete-link-type-config';
            // Legacy redirects
            $event->rules['hyper/settings/link-type-defaults'] = 'hyper/plugin/link-type-configs';
            $event->rules['hyper/settings/migrate/<sourceId:[\w\-]+>'] = 'hyper/migrate/index';
            $event->rules['hyper/migrate/<sourceId:[\w\-]+>'] = 'hyper/migrate/run';
            $event->rules['hyper/migrate/<sourceId:[\w\-]+>/<stepId:[\w\-]+>'] = 'hyper/migrate/run';
        });
    }

    private function _registerVariables(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('hyper', HyperVariable::class);
        });
    }

    private function _registerFieldTypes(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = HyperField::class;
        });
    }

    private function _registerFieldLayoutElements(): void
    {
        Event::on(FieldLayout::class, FieldLayout::EVENT_DEFINE_NATIVE_FIELDS, function(DefineFieldLayoutFieldsEvent $event) {
            if (is_subclass_of($event->sender->type, LinkInterface::class)) {
                $event->fields[] = AriaLabelField::class;
                $event->fields[] = ClassesField::class;
                $event->fields[] = CustomAttributesField::class;
                $event->fields[] = LinkField::class;
                $event->fields[] = LinkTextField::class;
                $event->fields[] = LinkTitleField::class;
                $event->fields[] = NewWindowField::class;
                $event->fields[] = UrlSuffixField::class;
            }
        });

        Event::on(FieldLayout::class, FieldLayout::EVENT_DEFINE_UI_ELEMENTS, function(DefineFieldLayoutElementsEvent $event) {
            if ($event->sender->type === Embed::class) {
                $event->elements[] = EmbedPreview::class;
            }
        });
    }

    private function _registerProjectConfigEventHandlers(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $projectConfig
            ->onAdd(ProjectConfig::PATH_FIELDS . '.{uid}', [$this->getService(), 'handleChangedField'])
            ->onUpdate(ProjectConfig::PATH_FIELDS . '.{uid}', [$this->getService(), 'handleChangedField'])
            ->onRemove(ProjectConfig::PATH_FIELDS . '.{uid}', [$this->getService(), 'handleDeletedField']);

        $projectConfig
            ->onAdd(LinkTypeConfigs::PROJECT_CONFIG_PATH . '.{uid}', [$this->getLinkTypeConfigs(), 'handleChangedConfig'])
            ->onUpdate(LinkTypeConfigs::PROJECT_CONFIG_PATH . '.{uid}', [$this->getLinkTypeConfigs(), 'handleChangedConfig'])
            ->onRemove(LinkTypeConfigs::PROJECT_CONFIG_PATH . '.{uid}', [$this->getLinkTypeConfigs(), 'handleDeletedConfig']);

        $projectConfig->onRemove(ProjectConfig::PATH_SITES . '.{uid}', function(ConfigEvent $event) {
            Hyper::$plugin->getContent()->pruneDeletedSiteUid($event->tokenMatches[0]);
        });
    }

    private function _registerEventHandlers(): void
    {
        // Hijack requests to `actions/matrix/create-entry` to handle non-saved-element owners.
        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, function(ActionEvent $event) {
            // For "As inline-editable blocks"
            if ($event->action->id == 'create-entry' && $event->sender->id == 'matrix') {
                $ownerElementType = $event->sender->request->getParam('ownerElementType');

                // Only override things if this is coming from a Hyper field
                if (is_subclass_of($ownerElementType, LinkInterface::class)) {
                    Craft::$app->runAction('hyper/fields/create-matrix-entry')->send();
                }
            }
        });

        // Content Blocks within Vizy Blocks will try and save immediately, so we need to prevent that.
        Event::on(ContentBlock::class, ContentBlock::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            $contentBlock = $event->sender;

            if ($contentBlock->getOwner() instanceof LinkInterface) {
                $event->isValid = false;
            }
        });

        if (class_exists(FeedMeFields::class)) {
            Event::on(FeedMeFields::class, FeedMeFields::EVENT_REGISTER_FEED_ME_FIELDS, function(RegisterFeedMeFieldsEvent $event) {
                $event->fields[] = FeedMeHyperField::class;
            });
        }

        Event::on(Sites::class, Sites::EVENT_BEFORE_DELETE_SITE, function(DeleteSiteEvent $event) {
            Hyper::$plugin->getContent()->pruneDeletedSite($event);
        });
    }

    private function _registerLinkedElementWithParsing(): void
    {
        Event::on(ElementQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, function(\craft\events\CancelableEvent $event) {
            /** @var ElementQuery $query */
            $query = $event->sender;
            Hyper::$plugin->getLinkedElementEagerLoader()->parseWithPaths($query);
        });
    }

    private function _registerCachePreload(): void
    {
        if (!$this->_shouldPrimeLinkedElements()) {
            return;
        }

        // Register directly — nesting on Application::EVENT_INIT misses console/test bootstrap.
        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, function(PopulateElementEvent $event) {
            if (!$this->_isResponseOk() || !$event->element->id) {
                return;
            }

            Hyper::$plugin->getLinkRelations()->registerElementForPriming($event->element);
        });

        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENTS, function(PopulateElementsEvent $event) {
            if (!$this->_isResponseOk()) {
                return;
            }

            Hyper::$plugin->getLinkRelations()->primePendingOwners();
        });
    }

    private function _isResponseOk(): bool
    {
        $response = Craft::$app->getResponse();

        if ($response instanceof \yii\web\Response) {
            return $response->getIsOk();
        }

        // Console and integration tests have no HTTP status gate.
        return true;
    }

    private function _shouldPrimeLinkedElements(): bool
    {
        if (Craft::$app->getUpdates()->getAreMigrationsPending()) {
            return false;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsSiteRequest()) {
            return true;
        }

        return (getenv('ENVIRONMENT') ?: '') === 'testing';
    }

    private function _registerGraphQl(): void
    {
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_TYPES, function(RegisterGqlTypesEvent $event) {
            $event->types[] = GqlLinkInterface::class;
        });
    }

}
