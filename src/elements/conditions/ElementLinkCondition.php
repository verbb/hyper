<?php
namespace verbb\hyper\elements\conditions;

use verbb\hyper\base\ElementLink;

use Craft;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementCondition;
use craft\fields\conditions\FieldConditionRuleInterface;
use craft\fields\conditions\GeneratedFieldConditionRule;
use craft\helpers\ArrayHelper;

use yii\base\InvalidConfigException;

use ReflectionClass;

class ElementLinkCondition extends ElementCondition
{
    // Properties
    // =========================================================================

    public ?string $targetElementType = null;


    // Public Methods
    // =========================================================================

    public function __construct(?string $elementType = null, array $config = [])
    {
        // Allow Craft::createObject(ElementLinkCondition::class, [LinkClass, ['targetElementType' => …]])
        if (isset($config['targetElementType'])) {
            $this->targetElementType = $config['targetElementType'];
            unset($config['targetElementType']);
        }

        parent::__construct($elementType, $config);
    }

    public function matchElement(ElementInterface $element): bool
    {
        if (!$element instanceof ElementLink) {
            return parent::matchElement($element);
        }

        // CP FLD matching should see the selected target even when disabled/pending.
        $linked = $element->getElement(null);

        foreach ($this->getConditionRules() as $rule) {
            try {
                if ($this->_ruleTargetsLink($rule)) {
                    if (!$rule->matchElement($element)) {
                        return false;
                    }
                    continue;
                }

                // Attribute / entry rules need the linked CMS element.
                if (!$linked || !$rule->matchElement($linked)) {
                    return false;
                }
            } catch (InvalidConfigException) {
                return false;
            }
        }

        return true;
    }


    // Protected Methods
    // =========================================================================

    protected function selectableConditionRules(): array
    {
        if ($this->targetElementType === null || !is_subclass_of($this->targetElementType, ElementInterface::class)) {
            return parent::selectableConditionRules();
        }

        // Reuse the target type’s rule menu (Entry → Section/Slug/Type/…), but bind field
        // layouts to the Hyper link layout so FIELDS = sibling link custom fields.
        $proxy = $this->targetElementType::createCondition();
        $proxy->queryParams = $this->queryParams;
        $proxy->fieldContext = $this->fieldContext;
        $proxy->setFieldLayouts($this->getFieldLayouts());

        $method = (new ReflectionClass($proxy))->getMethod('selectableConditionRules');
        $method->setAccessible(true);

        return $method->invoke($proxy);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['targetElementType'], 'safe'];

        return $rules;
    }

    protected function config(): array
    {
        return ArrayHelper::merge(parent::config(), $this->toArray(['targetElementType']));
    }


    // Private Methods
    // =========================================================================

    private function _ruleTargetsLink(object $rule): bool
    {
        // Link-layout custom fields + generated fields live on the Hyper link instance.
        return $rule instanceof FieldConditionRuleInterface
            || $rule instanceof GeneratedFieldConditionRule
            || $rule instanceof LinkValueConditionRule;
    }
}
