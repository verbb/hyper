<?php
namespace verbb\hyper\links;

use verbb\hyper\base\ElementLink;

use Craft;
use craft\elements\Asset as AssetElement;
use craft\elements\conditions\ElementConditionInterface;

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
