<?php
namespace verbb\hyper\controllers;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\helpers\Fields;
use verbb\hyper\links\Embed;

use Craft;
use craft\helpers\Json;
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
        $request = Craft::$app->getRequest();

        $fieldLayoutUid = $request->getParam('layoutUid');
        $fieldIds = $request->getParam('fieldIds');
        $type = $request->getParam('type');
        $layoutConfig = $request->getParam('layout', []);

        $fieldLayout = $type::getDefaultFieldLayout();

        if ($fieldLayoutUid) {
            if ($existingFieldLayout = Hyper::$plugin->getService()->getFieldLayoutByUid($fieldLayoutUid)) {
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

    public function actionInputSettings(): Response
    {
        $this->requireCpRequest();

        $fieldId = $this->request->getRequiredParam('fieldId');
        $data = Json::decode($this->request->getRequiredParam('data'));
        $field = Craft::$app->getFields()->getFieldById($fieldId);

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
                    ->content($form->render());
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
        $link = new Embed();
        $link->linkValue = $this->request->getParam('value');
        $values = $link->getSerializedValues();

        return $this->asJson($values['linkValue']);
    }
}
