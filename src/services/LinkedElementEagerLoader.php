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

            $containerPath = $this->_consumeHyperWithToken($withToken, $fieldsByHandle);

            if ($containerPath !== null) {
                if ($containerPath !== '') {
                    $remaining[] = $containerPath;
                }

                continue;
            }

            $remaining[] = $withToken;
        }

        $query->with = array_values($remaining);
    }


    // Private Methods
    // =========================================================================

    private function _consumeHyperWithToken(string $withToken, array $fieldsByHandle): ?string
    {
        $segments = explode('.', $withToken);
        $fields = $fieldsByHandle[$segments[0]] ?? [];

        if ($fields === [] && ($field = $this->_getFieldByHandle($segments[0]))) {
            $fields[] = $field;
        }

        $hyperIndex = null;

        foreach ($fields as $field) {
            // Register every matching layout, rather than letting the first alias win.
            $index = $this->_walkWithSegments($segments, 0, $field);

            if ($index !== null) {
                $hyperIndex = max($hyperIndex ?? 0, $index);
            }
        }

        // Keep native containing fields so Craft can load nested owners in a batch.
        return $hyperIndex === null ? null : implode('.', array_slice($segments, 0, $hyperIndex));
    }

    private function _walkWithSegments(array $segments, int $index, ?FieldInterface $field = null): ?int
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
                Hyper::$plugin->getLinkRelations()->registerLinkedElementWith($field->id, '');

                return $index;
            }

            $linkedElementsPrefix = 'linkedElements.';

            if (str_starts_with($suffix, $linkedElementsPrefix)) {
                $targetWith = substr($suffix, strlen($linkedElementsPrefix));

                if ($targetWith !== '') {
                    Hyper::$plugin->getLinkRelations()->registerLinkedElementWith($field->id, $targetWith);
                }

                return $index;
            }

            return $index;
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

                        if ($result !== null) {
                            // Different entry types can expose different fields under one alias.
                            $matched = $result;
                        }
                    }
                }
            }

            if ($matched !== null) {
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
