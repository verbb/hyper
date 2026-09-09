<?php
namespace verbb\hyper\controllers;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\helpers\Fields;
use verbb\hyper\links\Embed;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\web\Controller;
use craft\web\Response as CraftResponse;

use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FieldsController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionLayoutDesigner(): Response
    {
        // Field layout designer is an admin / field-settings surface.
        $this->requireAdmin();
        $this->requireCpRequest();

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
        $this->requireCpRequest();
        $this->_requireOwnerElementAccess();

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

    public function actionCreateLinks(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->_requireOwnerElementAccess();

        $fieldId = $this->request->getRequiredBodyParam('fieldId');
        $handle = (string)$this->request->getRequiredBodyParam('handle');
        $mode = (string)$this->request->getBodyParam('mode', 'elements');
        $siteId = (int)($this->request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);

        $field = Craft::$app->getFields()->getFieldById((int)$fieldId);

        if (!($field instanceof HyperField)) {
            throw new NotFoundHttpException('Field not found.');
        }

        // Relation/element inputs resolve their target site from the current site.
        if ($site = Craft::$app->getSites()->getSiteById($siteId)) {
            Craft::$app->getSites()->setCurrentSite($site);
        }

        // Map the posted selection into hydration seeds for HyperField::getHydratedLinkBlocks().
        $seeds = [];
        $requireBulkSupport = true;

        if ($mode === 'seed') {
            // Full serialized payloads (clipboard paste) — any enabled type.
            $requireBulkSupport = false;

            foreach ((array)$this->request->getBodyParam('seeds', []) as $seed) {
                if (is_array($seed)) {
                    $seeds[] = $seed;
                }
            }
        } elseif ($mode === 'elements') {
            foreach ((array)$this->request->getBodyParam('elements', []) as $element) {
                $elementId = is_array($element) ? ($element['id'] ?? null) : $element;

                if (!$elementId) {
                    continue;
                }

                $seeds[] = [
                    'linkValue' => (int)$elementId,
                    'linkSiteId' => (int)((is_array($element) ? ($element['siteId'] ?? null) : null) ?: $siteId),
                ];
            }
        } else {
            $values = $this->request->getBodyParam('values', []);

            // Accept a raw textarea blob (one value per line) or a pre-split array.
            if (is_string($values)) {
                $values = preg_split('/\r\n|\r|\n/', $values) ?: [];
            }

            foreach ((array)$values as $value) {
                $value = trim((string)$value);

                if ($value === '') {
                    continue;
                }

                $seeds[] = ['linkValue' => $value];
            }
        }

        $view = $this->getView();
        $blocks = $field->getHydratedLinkBlocks($handle, $seeds, null, $requireBulkSupport);

        return $this->asJson([
            'blocks' => $blocks,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    public function actionBulkElementSelect(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->_requireOwnerElementAccess();

        $fieldId = $this->request->getRequiredParam('fieldId');
        $handle = (string)$this->request->getRequiredParam('handle');
        $limit = $this->request->getParam('limit');
        $limit = ($limit === null || $limit === '') ? null : (int)$limit;
        $siteId = (int)($this->request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);

        $field = Craft::$app->getFields()->getFieldById((int)$fieldId);

        if (!($field instanceof HyperField)) {
            throw new NotFoundHttpException('Field not found.');
        }

        // Relation/element pickers resolve their target site from the current site.
        if ($site = Craft::$app->getSites()->getSiteById($siteId)) {
            Craft::$app->getSites()->setCurrentSite($site);
        }

        $view = $this->getView();
        $html = $field->getBulkElementSelectHtml($handle, $limit);

        return $this->asJson([
            'html' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    public function actionInputSettings(): Response
    {
        $this->requireCpRequest();
        $this->_requireOwnerElementAccess();

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

        // Update the content on the link, passed from the field UI
        $linkType->setAttributes($data, false);

        // Advanced-tab relation fields (e.g. Entries) resolve site from the Link element /
        // current site. Stamp the owner entry's site so pickers are not stuck on the primary site.
        $siteId = (int)($this->request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);
        $linkType->siteId = $siteId;

        if ($site = Craft::$app->getSites()->getSiteById($siteId)) {
            Craft::$app->getSites()->setCurrentSite($site);
        }

        // Remove the first tab (already shown in the main field UI)
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
        $this->requireCpRequest();
        $this->_requireOwnerElementAccess();

        $variables = $this->request->post();
        unset($variables['action']);

        return $this->asSuccess(null, $variables);
    }

    public function actionPreviewEmbed(): Response
    {
        $this->requireCpRequest();
        $this->_requireOwnerElementAccess();

        $url = (string)$this->request->getParam('value');
        $fieldId = $this->request->getParam('fieldId');
        $linkTypeHandle = (string)$this->request->getParam('linkTypeHandle', '');

        // Allowlist / scheme gate before any network I/O (Astra H3-A03).
        $embedLink = $this->_resolveEmbedLinkType($fieldId, $linkTypeHandle);

        if ($embedLink) {
            if (!$embedLink->isEmbedUrlAllowed($url)) {
                return $this->asFailure(Craft::t('hyper', 'URL domain not allowed.'));
            }
        } else {
            $settings = Hyper::$plugin->getSettings();

            if ($settings->embedAllowedDomains && !$settings->doesUrlMatchDomain($url)) {
                return $this->asFailure(Craft::t('hyper', 'URL domain not allowed.'));
            }
        }

        $data = Embed::fetchEmbedData($url);

        if (isset($data['error'])) {
            return $this->asFailure($data['error']);
        }

        // Reject metadata whose final URL left the allowlist (open redirects).
        $finalUrl = is_string($data['url'] ?? null) ? (string)$data['url'] : $url;

        if ($embedLink) {
            if (!$embedLink->isEmbedUrlAllowed($finalUrl)) {
                return $this->asFailure(Craft::t('hyper', 'URL domain not allowed.'));
            }
        } else {
            $settings = Hyper::$plugin->getSettings();

            if ($settings->embedAllowedDomains && !$settings->doesUrlMatchDomain($finalUrl)) {
                return $this->asFailure(Craft::t('hyper', 'URL domain not allowed.'));
            }
        }

        // Final URL must also stay on a public host (defense in depth vs oEmbed lying).
        $finalHost = parse_url($finalUrl, PHP_URL_HOST);

        if (is_string($finalHost) && $finalHost !== '' && !\verbb\hyper\helpers\UrlSafety::isPublicFetchHost($finalHost)) {
            return $this->asFailure(Craft::t('hyper', 'Embed URL host is not allowed.'));
        }

        $html = $data['code'] ?? '';
        $preview = Embed::getPreviewHtml($html);

        return $this->asSuccess(null, ['data' => $data, 'preview' => $preview]);
    }

    private function _resolveEmbedLinkType(mixed $fieldId, string $linkTypeHandle): ?Embed
    {
        if (!$fieldId || $linkTypeHandle === '') {
            return null;
        }

        $field = Craft::$app->getFields()->getFieldById((int)$fieldId);

        if (!$field instanceof HyperField) {
            return null;
        }

        foreach ($field->getLinkTypes() as $linkType) {
            if ($linkType->handle === $linkTypeHandle && $linkType instanceof Embed) {
                return $linkType;
            }
        }

        return null;
    }

    /**
     * When the CP posts an owner elementId, require canSave on that element.
     * New/unsaved owners (no id yet) keep CP-login + CSRF only — same as native fields.
     */
    private function _requireOwnerElementAccess(): void
    {
        $elementId = $this->request->getParam('elementId')
            ?? $this->request->getBodyParam('elementId');

        if ($elementId === null || $elementId === '' || (int)$elementId <= 0) {
            return;
        }

        $siteId = (int)(
            $this->request->getParam('siteId')
            ?? $this->request->getBodyParam('siteId')
            ?: Craft::$app->getSites()->getCurrentSite()->id
        );

        /** @var ElementInterface|null $element */
        $element = Craft::$app->getElements()->getElementById((int)$elementId, null, $siteId);

        if (!$element) {
            throw new ForbiddenHttpException('Element not found.');
        }

        if (!Craft::$app->getElements()->canSave($element)) {
            throw new ForbiddenHttpException('User is not authorized to edit this element.');
        }
    }
}
