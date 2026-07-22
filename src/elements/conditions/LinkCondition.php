<?php
namespace verbb\hyper\elements\conditions;

use craft\elements\conditions\ElementCondition;
use craft\errors\InvalidTypeException;
use craft\fields\conditions\FieldConditionRuleInterface;
use craft\fields\conditions\GeneratedFieldConditionRule;

class LinkCondition extends ElementCondition
{
    // Protected Methods
    // =========================================================================

    protected function selectableConditionRules(): array
    {
        $types = [
            LinkValueConditionRule::class,
        ];

        if ($this->elementType === null) {
            return $types;
        }

        // Same custom-field discovery as ElementCondition, without Element attribute rules.
        foreach ($this->getFieldLayouts() as $fieldLayout) {
            foreach ($fieldLayout->getCustomFieldElements() as $layoutElement) {
                $label = $layoutElement->label();
                if ($label === null) {
                    continue;
                }

                $field = $layoutElement->getField();
                $type = $field->getElementConditionRuleType();
                if ($type === null) {
                    continue;
                }

                if (is_string($type)) {
                    $type = ['class' => $type];
                }

                if (!is_subclass_of($type['class'], FieldConditionRuleInterface::class)) {
                    throw new InvalidTypeException($type['class'], FieldConditionRuleInterface::class);
                }

                $type['fieldUid'] = $field->uid;
                $type['layoutElementUid'] = $field->layoutElement->uid;
                $types[] = $type;
            }

            foreach ($fieldLayout->getGeneratedFields() as $field) {
                if (($field['name'] ?? '') !== '' && ($field['handle'] ?? '') !== '') {
                    $types[] = [
                        'class' => GeneratedFieldConditionRule::class,
                        'fieldUid' => $field['uid'],
                    ];
                }
            }
        }

        return $types;
    }
}
