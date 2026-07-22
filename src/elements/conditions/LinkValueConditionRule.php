<?php
namespace verbb\hyper\elements\conditions;

use verbb\hyper\base\Link;

use Craft;
use craft\base\conditions\BaseTextConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\App;

class LinkValueConditionRule extends BaseTextConditionRule implements ElementConditionRuleInterface
{
    // Public Methods
    // =========================================================================

    public function getLabel(): string
    {
        return Craft::t('hyper', 'Link Value');
    }

    public function getExclusiveQueryParams(): array
    {
        return ['linkValue'];
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        // No-op: Hyper FLD conditions only use matchElement() on in-memory link instances.
    }

    public function matchElement(ElementInterface $element): bool
    {
        if (!$element instanceof Link) {
            return false;
        }

        return $this->matchValue($this->_valueFromLink($element));
    }


    // Private Methods
    // =========================================================================

    private function _valueFromLink(Link $link): ?string
    {
        $value = $link->linkValue;

        if (is_array($value)) {
            return $link->getUrl();
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $parsed = App::parseEnv((string)$value);

        return $parsed !== '' ? $parsed : null;
    }
}
