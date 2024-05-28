<?php
namespace verbb\hyper\controllers;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\helpers\Fields;
use verbb\hyper\links\Embed;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\web\Controller;
use craft\web\Response as CraftResponse;

use yii\web\NotFoundHttpException;
use yii\web\Response;

class FieldsController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionLayoutDesigner(): Response
    {
        $view = Craft::$app->getView();

        $fieldLayoutUid = $this->request->getParam('layoutUid');
        $fieldIds = $this->request->getParam('fieldIds');
        $type = $this->request->getParam('type');
        $layoutConfig = $this->request->getParam('layout', []);

        $fieldLayout = $type::getDefaultFieldLayout();

        if ($fieldLayoutUid) {
            if ($existingFieldLayout = Craft::$app->getFields()->getLayoutByUid($fieldLayoutUid)) {
                $fieldLayout = $existingFieldLayout;
            }
        }

        // Prep the field layout from post - we could be editing an unsaved field layout
        if ($layoutConfig) {
            $layoutConfig = Json::decode($layoutConfig);
            $layoutConfig['type'] = $type;

            if ($newLayout = FieldLayout::createFromConfig($layoutConfig)) {
                $fieldLayout = $newLayout;
            }
        }

        // Fetch the available custom fields for the layout - we want to add some exceptions
        $availableCustomFields = $fieldLayout->getAvailableCustomFields();

        // Remove _this_ field - things could get hairy
        if ($fieldIds) {
            foreach ($availableCustomFields as $i => $groupFields) {
                foreach ($groupFields as $j => $fields) {
                    if (in_array($fields->getField()->id, $fieldIds)) {
                        unset($availableCustomFields[$i][$j]);
                    }
                }
            }
        }

        // Render the HTML for the FLD to send back to Vue
        $html = Fields::fieldLayoutDesignerHtml($fieldLayout, [
            // Ensure to namespace the FLD so it's unique. Important when used in Matrix blocks
            // as under normal Hyper field circumstances, you edit one FLD at a time.
            // 'id' => str_replace('type-', '', $blockHandle) . 'fld' . mt_rand(),
            'id' => 'fld' . mt_rand(),
            'availableCustomFields' => $availableCustomFields,
        ]);

        $headHtml = $view->getHeadHtml();
        $footHtml = $view->getBodyHtml();

        return $this->asJson([
            'html' => $html,
            'headHtml' => $headHtml,
            'footHtml' => $footHtml,
        ]);
    }

    public function actionCreateMatrixEntry()
    {
        // Override `MatrixController::actionCreateEntry` to handle non-saved-element owners.
        $fieldId = $this->request->getRequiredBodyParam('fieldId');
        $entryTypeId = $this->request->getRequiredBodyParam('entryTypeId');
        $siteId = $this->request->getRequiredBodyParam('siteId');
        $namespace = $this->request->getRequiredBodyParam('namespace');

        $field = Craft::$app->getFields()->getFieldById($fieldId);
        $entryType = Craft::$app->getEntries()->getEntryTypeById($entryTypeId);
        $site = Craft::$app->getSites()->getSiteById($siteId, true);

        $entry = Craft::createObject([
            'class' => Entry::class,
            'siteId' => $siteId,
            'uid' => StringHelper::UUID(),
            'typeId' => $entryType->id,
            'fieldId' => $fieldId,
            'slug' => ElementHelper::tempSlug(),
        ]);

        $entry->setScenario(Element::SCENARIO_ESSENTIALS);

        $view = $this->getView();
        $entries = [];

        $html = $view->namespaceInputs(fn() => $view->renderTemplate('_components/fieldtypes/Matrix/block.twig', [
            'name' => $field->handle,
            'entryTypes' => $field->getEntryTypesForField($entries, null),
            'entry' => $entry,
            'isFresh' => true,
        ]), $namespace);

        return $this->asJson([
            'blockHtml' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    public function actionInputSettings(): Response
    {
        $this->requireCpRequest();

        $fieldId = $this->request->getRequiredParam('fieldId');
        $data = $this->request->getRequiredParam('data');
        $field = Craft::$app->getFields()->getFieldById($fieldId);

        if (is_string($data) && Json::isJsonObject($data)) {
            $data = Json::decode($data);
        }

        if (!($field instanceof HyperField)) {
            throw new NotFoundHttpException('Field not found.');
        }

        $linkType = $field->getLinkTypeByHandle($data['handle']);

        if (!$linkType) {
            throw new NotFoundHttpException('Link type not found.');
        }

        $fieldLayout = $linkType->getFieldLayout();

        if (!$fieldLayout) {
            throw new NotFoundHttpException('Field Layout not found.');
        }

        // Update the content on the link, passed from Vue
        $linkType->setAttributes($data, false);

        // Remove the first tab (already shown in main Vue component)
        $layoutTabs = $fieldLayout->getTabs();
        array_shift($layoutTabs);
        $fieldLayout->setTabs($layoutTabs);

        // Use `prepareScreen` so that the rendered tabs/form have proper namespacing setup
        // which is important for field's JS
        return $this->asCpScreen()
            ->action('hyper/fields/input-settings-save')
            ->prepareScreen(function(CraftResponse $response) use ($fieldLayout, $linkType) {
                $form = $fieldLayout->createForm($linkType);

                $response
                    ->tabs($form->getTabMenu())
                    ->contentHtml($form->render());
            });
    }

    public function actionInputSettingsSave(): Response
    {
        $variables = $this->request->post();
        unset($variables['action']);

        return $this->asSuccess(null, $variables);
    }

    public function actionPreviewEmbed(): Response
    {
        $url = $this->request->getParam('value');
        $data = Embed::fetchEmbedData($url);

        if (isset($data['error'])) {
            return $this->asFailure($data['error']);
        }

        $html = $data['code'] ?? '';
        $preview = Embed::getPreviewHtml($html);

        // Check for validation
        $settings = Hyper::$plugin->getSettings();

        if ($settings->embedAllowedDomains && !$settings->doesUrlMatchDomain($url)) {
            return $this->asFailure(Craft::t('hyper', 'URL domain not allowed.'));
        }

        return $this->asSuccess(null, ['data' => $data, 'preview' => $preview]);
    }
}
