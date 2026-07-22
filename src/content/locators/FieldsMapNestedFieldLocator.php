<?php
namespace verbb\hyper\content\locators;

use verbb\hyper\content\NestedFieldLocator;
use verbb\hyper\content\NestedValueLocation;
use verbb\hyper\fields\HyperField;

use craft\base\FieldInterface;
use craft\models\FieldLayout;

abstract class FieldsMapNestedFieldLocator implements NestedFieldLocator
{
    // Public Methods
    // =========================================================================

    public function findNestedFieldHandles(FieldInterface $hostField, HyperField $targetField): array
    {
        $handles = [];

        foreach ($this->_getNestedLayouts($hostField) as $layout) {
            if (!$layout) {
                continue;
            }

            foreach ($layout->getCustomFields() as $field) {
                if ($field->uid === $targetField->uid) {
                    $handles[] = $field->handle;
                }
            }
        }

        return array_values(array_unique($handles));
    }

    public function locateInHostValue(array &$hostValue, string $targetFieldHandle, string $pathPrefix): array
    {
        return $this->_locateFieldsMap($hostValue, $targetFieldHandle, $pathPrefix);
    }


    // Protected Methods
    // =========================================================================

    abstract protected function _getNestedLayouts(FieldInterface $hostField): iterable;

    protected function _locateFieldsMap(array &$node, string $targetFieldHandle, string $pathPrefix): array
    {
        $locations = [];

        foreach ($node as $key => &$child) {
            if (!is_array($child)) {
                continue;
            }

            $fields = &$child['fields'] ?? null;

            if (is_array($fields) && array_key_exists($targetFieldHandle, $fields)) {
                $path = $pathPrefix === '' ? "$key.fields.$targetFieldHandle" : "$pathPrefix.$key.fields.$targetFieldHandle";

                $locations[] = new NestedValueLocation(
                    hostValue: $node,
                    value: $fields[$targetFieldHandle],
                    jsonPath: $path,
                );
            }

            if ($this->_shouldRecurseIntoChild($key, $child)) {
                $childPath = $pathPrefix === '' ? (string)$key : "$pathPrefix.$key";
                $locations = array_merge($locations, $this->_locateFieldsMap($child, $targetFieldHandle, $childPath));
            }
        }

        return $locations;
    }

    protected function _shouldRecurseIntoChild(mixed $key, array $child): bool
    {
        if (isset($child['fields'])) {
            return false;
        }

        return true;
    }
}
