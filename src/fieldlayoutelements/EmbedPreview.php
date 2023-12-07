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
        $html = (string)$element->getHtml();

        if (!$html) {
            return null;
        }

        // Check if this contains an iframe already, if not - create one
        if (!str_contains($html, '<iframe')) {
            $src = htmlspecialchars('data:text/html,' . rawurlencode($html));
            $html = Html::tag('iframe', '', ['src' => $src, 'height' => 200]);
        }

        $wrapper = Html::tag('div', $html, ['class' => 'hyper-iframe-container']);

        return Html::tag('div', $wrapper);
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
