<?php
namespace verbb\hyper\services;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\Matrix;

class LinkedElementEagerLoader extends Component
{
    // Public Methods
    // =========================================================================

    public function parseWithPaths(ElementQueryInterface $query): void
    {
        if (!$query->with || !is_array($query->with)) {
            return;
        }

        $remaining = [];
        $fieldsByHandle = [];

        // Handles can be overridden per layout; the global field registry has only originals.
        foreach ($query->getFieldLayouts() as $layout) {
            foreach ($layout->getCustomFields() as $field) {
                $fieldsByHandle[$field->handle][] = $field;
            }
        }

        foreach ($query->with as $withToken) {
            if (!is_string($withToken)) {
                $remaining[] = $withToken;
                continue;
            }

            if ($this->_consumeHyperWithToken($withToken, $fieldsByHandle)) {
                continue;
            }

            $remaining[] = $withToken;
        }

        $query->with = array_values($remaining);
    }


    // Private Methods
    // =========================================================================

    private function _consumeHyperWithToken(string $withToken, array $fieldsByHandle): bool
    {
        $segments = explode('.', $withToken);
        $fields = $fieldsByHandle[$segments[0]] ?? [];

        if ($fields === [] && ($field = $this->_getFieldByHandle($segments[0]))) {
            $fields[] = $field;
        }

        $consumed = false;

        foreach ($fields as $field) {
            // Register every matching layout, rather than letting the first alias win.
            if ($this->_walkWithSegments($segments, 0, $field) !== null) {
                $consumed = true;
            }
        }

        return $consumed;
    }

    private function _walkWithSegments(array $segments, int $index, ?FieldInterface $field = null): ?HyperField
    {
        $handle = $segments[$index] ?? null;

        if (!$handle) {
            return null;
        }

        $field ??= $this->_getFieldByHandle($handle);

        if (!$field instanceof FieldInterface) {
            return null;
        }

        if ($field instanceof HyperField) {
            $suffix = implode('.', array_slice($segments, $index + 1));

            if ($suffix === '' || $suffix === 'linkedElements') {
                return $field;
            }

            $linkedElementsPrefix = 'linkedElements.';

            if (str_starts_with($suffix, $linkedElementsPrefix)) {
                $targetWith = substr($suffix, strlen($linkedElementsPrefix));

                if ($targetWith !== '') {
                    Hyper::$plugin->getLinkRelations()->registerLinkedElementWith($field->id, $targetWith);
                }

                return $field;
            }

            return $field;
        }

        if ($field instanceof Matrix) {
            $matched = null;

            // Craft 5 Matrix uses entry types — getBlockTypes() was removed (Astra H3-A11).
            foreach ($field->getEntryTypes() as $entryType) {
                $layout = $entryType->getFieldLayout();

                if (!$layout) {
                    continue;
                }

                foreach ($layout->getCustomFields() as $nestedField) {
                    if ($nestedField->handle !== ($segments[$index + 1] ?? null)) {
                        continue;
                    }

                    if ($nestedField instanceof HyperField || $index + 2 < count($segments)) {
                        $result = $this->_walkWithSegments($segments, $index + 1, $nestedField);

                        if ($result) {
                            // Different entry types can expose different fields under one alias.
                            $matched = $result;
                        }
                    }
                }
            }

            if ($matched) {
                return $matched;
            }
        }

        if ($index + 1 < count($segments)) {
            return $this->_walkWithSegments($segments, $index + 1);
        }

        return null;
    }

    private function _getFieldByHandle(string $handle): ?FieldInterface
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);

        return $field instanceof FieldInterface ? $field : null;
    }
}
