<?php
namespace verbb\hyper\fieldlayoutelements;

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
        $src = htmlspecialchars('data:text/html,' . rawurlencode($element->getHtml()));

        return Html::tag('iframe', '', ['src' => $src, 'height' => 200]);
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
