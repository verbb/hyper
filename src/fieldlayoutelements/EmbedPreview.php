<?php
namespace verbb\hyper\fieldlayoutelements;

use verbb\hyper\links\Embed;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseUiElement;
use craft\helpers\Cp;
use craft\helpers\Html;

class EmbedPreview extends BaseUiElement
{
    // Public Methods
    // =========================================================================

    public function formHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        $html = (string)$element->getHtml();

        if (!$html) {
            return null;
        }

        return Html::tag('div', Embed::getPreviewHtml($html), $this->containerAttributes($element, $static));
    }

    public function hasCustomWidth(): bool
    {
        return true;
    }


    // Protected Methods
    // =========================================================================
    
    protected function selectorLabel(): string
    {
        return Craft::t('hyper', 'Embed Preview');
    }

    protected function selectorIcon(): ?string
    {
        return '@appicons/hash.svg';
    }
}
