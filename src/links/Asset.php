<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;
use verbb\hyper\base\Link;

use Craft;
use craft\elements\Asset as AssetElement;
use craft\elements\conditions\ElementConditionInterface;
use craft\errors\InvalidSubpathException;
use craft\helpers\Assets;

class Asset extends ElementLink
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('app', 'Asset');
    }

    public static function elementType(): string
    {
        return AssetElement::class;
    }

    public static function supportsUriSelectorCriteria(): bool
    {
        return false;
    }


    // Properties
    // =========================================================================

    public ?string $defaultUploadLocationSource = null;
    public ?string $defaultUploadLocationSubpath = null;


    // Public Methods
    // =========================================================================

    public function getSettingsConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['defaultUploadLocationSource'] = $this->defaultUploadLocationSource;
        $values['defaultUploadLocationSubpath'] = $this->defaultUploadLocationSubpath;

        return $values;
    }

    public function getSettingsHtmlVariables(): array
    {
        $variables = parent::getSettingsHtmlVariables();
        $variables['volumeSourceOptions'] = $this->_getVolumeSourceOptions();

        return $variables;
    }

    public function getUploadSettings(): array
    {
        $settings = ['canUpload' => false];

        if (!$this->defaultUploadLocationSource || !str_starts_with($this->defaultUploadLocationSource, 'volume:')) {
            return $settings;
        }

        $volume = Craft::$app->getVolumes()->getVolumeByUid(substr($this->defaultUploadLocationSource, 7));
        $user = Craft::$app->getUser();

        if (!$volume || !$user->checkPermission('viewAssets:' . $volume->uid) || !$user->checkPermission('saveAssets:' . $volume->uid)) {
            return $settings;
        }

        // Upload paths describe the owning content, including when this link is
        // nested inside another transient Hyper link.
        $owner = $this->getOwner();

        while ($owner instanceof Link) {
            $owner = $owner->getOwner();
        }

        try {
            [$subpath, $folder] = Assets::resolveSubpath($volume, $this->defaultUploadLocationSubpath, $owner);
        } catch (InvalidSubpathException) {
            // An Asset link has no native Assets field to finalize temporary
            // uploads later. Wait for a resolvable destination instead.
            return $settings + ['uploadWarning' => Craft::t('hyper', 'The upload location could not be resolved. Save the entry or check the Default Upload Subpath setting.')];
        }

        $folder ??= Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($subpath, $volume);

        return [
            'canUpload' => true,
            'fsType' => $volume->getFs()::class,
            'uploadFolderId' => $folder->id,
        ];
    }

    public function getInputCriteria(): array
    {
        // AssetSelectInput reuses this site when rendering a newly uploaded chip.
        return parent::getInputCriteria() + ['siteId' => $this->ownerSiteId ?? $this->getOwner()?->siteId ?? Craft::$app->getSites()->getCurrentSite()->id];
    }


    // Protected Methods
    // =========================================================================

    protected function createSelectionCondition(): ?ElementConditionInterface
    {
        $condition = AssetElement::createCondition();
        $condition->queryParams = ['volume', 'volumeId', 'kind'];

        return $condition;
    }


    // Private Methods
    // =========================================================================

    private function _getVolumeSourceOptions(): array
    {
        $options = [
            ['label' => Craft::t('hyper', 'None'), 'value' => ''],
        ];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = [
                'label' => $volume->name,
                'value' => 'volume:' . $volume->uid,
            ];
        }

        return $options;
    }
}
