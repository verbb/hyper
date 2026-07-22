<?php
namespace verbb\hyper\controllers;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\helpers\Plugin;
use verbb\hyper\models\LinkTypeConfig;
use verbb\hyper\models\Settings;
use verbb\hyper\services\LinkTypeConfigs;

use Craft;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;

use yii\web\NotFoundHttpException;
use yii\web\Response;

class PluginController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionSettings(): Response
    {
        /* @var Settings $settings */
        $settings = Hyper::$plugin->getSettings();

        return $this->renderTemplate('hyper/settings', [
            'settings' => $settings,
        ]);
    }

    public function actionLinkTypeConfigs(): Response
    {
        return $this->renderTemplate('hyper/settings/link-type-configs', [
            'configs' => Hyper::$plugin->getLinkTypeConfigs()->getAllConfigs(),
        ]);
    }

    public function actionEditLinkTypeConfig(?string $uid = null, ?LinkTypeConfig $config = null): Response
    {
        Plugin::registerFieldAssets();

        $configsService = Hyper::$plugin->getLinkTypeConfigs();

        // Preserve an injected submitted model and its validation errors after a failed save.
        if (!$config && $uid) {
            $config = $configsService->getConfigByUid($uid);

            if (!$config) {
                throw new NotFoundHttpException('Link type config not found.');
            }
        } elseif (!$config) {
            $config = new LinkTypeConfig([
                'handle' => '',
                'linkTypes' => $configsService->createStockSerializedLinkTypes(),
            ]);
        }

        $shell = new HyperField([
            'linkTypeConfig' => LinkTypeConfigs::CUSTOM_HANDLE,
            'linkTypes' => $config->linkTypes,
        ]);

        $linkTypes = $shell->getLinkTypeSettingsForHtml();
        $registeredLinkTypes = array_map(static function(string $linkTypeClass): array {
            return [
                'label' => $linkTypeClass::displayName(),
                'value' => $linkTypeClass,
            ];
        }, Hyper::$plugin->getLinks()->getAllLinkTypes());

        $view = Craft::$app->getView();

        return $this->renderTemplate('hyper/settings/link-type-config', [
            'config' => $config,
            'isNew' => !$uid,
            'linkTypes' => $linkTypes,
            'registeredLinkTypes' => $registeredLinkTypes,
            'settingsConfig' => [
                'fieldId' => null,
                'registeredLinkTypes' => $registeredLinkTypes,
                'namespacedName' => $view->namespaceInputName('__PREFIX__'),
                'namespacedId' => $view->namespaceInputId('__PREFIX__'),
                'linkTypeTemplates' => array_map(static function(array $linkType): array {
                    return [
                        'type' => $linkType['type'],
                        'displayName' => $linkType['displayName'] ?? null,
                        'htmlTemplate' => $linkType['htmlTemplate'] ?? null,
                        'jsTemplate' => $linkType['jsTemplate'] ?? null,
                        'layoutConfig' => $linkType['layoutConfig'] ?? null,
                        'layoutUid' => $linkType['layoutUid'] ?? null,
                    ];
                }, $linkTypes),
            ],
        ]);
    }

    public function actionSaveLinkTypeConfig(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $uid = $this->request->getBodyParam('uid') ?: StringHelper::UUID();
        $name = (string)$this->request->getRequiredBodyParam('name');
        $handle = (string)($this->request->getBodyParam('handle') ?: StringHelper::toHandle($name));
        $linkTypes = $this->request->getBodyParam('linkTypes') ?: [];

        if (!is_array($linkTypes)) {
            $linkTypes = [];
        }

        foreach ($linkTypes as &$linkType) {
            if (!is_array($linkType)) {
                continue;
            }

            if (isset($linkType['layoutConfig']) && is_string($linkType['layoutConfig'])) {
                $linkType['layoutConfig'] = Json::decodeIfJson($linkType['layoutConfig']);
            }
        }
        unset($linkType);

        $config = Hyper::$plugin->getLinkTypeConfigs()->getConfigByUid($uid) ?? new LinkTypeConfig();
        $config->uid = $uid;
        $config->name = $name;
        $config->handle = $handle;
        $config->linkTypes = $linkTypes;

        if (!Hyper::$plugin->getLinkTypeConfigs()->saveConfig($config)) {
            return $this->asModelFailure(
                $config,
                Craft::t('hyper', 'Couldn’t save link type config.'),
                'config',
            );
        }

        return $this->asSuccess(Craft::t('hyper', 'Link type config saved.'), [
            'config' => $config,
            'redirect' => UrlHelper::cpUrl('hyper/settings/link-type-configs/' . $config->uid),
        ]);
    }

    public function actionDeleteLinkTypeConfig(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $uid = (string)$this->request->getRequiredParam('id');
        $configs = Hyper::$plugin->getLinkTypeConfigs();
        $config = $configs->getConfigByUid($uid);

        if (!$config || !$config->canDelete()) {
            return $this->asJson([
                'error' => Craft::t('hyper', 'This link type config cannot be deleted.'),
            ]);
        }

        $configs->deleteConfig($uid);

        return $this->asJson(['success' => true]);
    }

    public function actionReorderLinkTypeConfigs(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();

        $uids = Json::decode($this->request->getRequiredBodyParam('ids'));

        if (is_array($uids) && Hyper::$plugin->getLinkTypeConfigs()->reorderConfigs($uids)) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson([
            'error' => Craft::t('hyper', 'Couldn’t reorder link type configs.'),
        ]);
    }
}
