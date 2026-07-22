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

        foreach ($query->with as $withToken) {
            if (!is_string($withToken)) {
                $remaining[] = $withToken;
                continue;
            }

            if ($this->_consumeHyperWithToken($withToken)) {
                continue;
            }

            $remaining[] = $withToken;
        }

        $query->with = array_values($remaining);
    }


    // Private Methods
    // =========================================================================

    private function _consumeHyperWithToken(string $withToken): bool
    {
        $segments = explode('.', $withToken);

        if (count($segments) < 2) {
            $field = $this->_getFieldByHandle($segments[0]);

            if ($field instanceof HyperField) {
                return true;
            }

            return false;
        }

        return $this->_walkWithSegments($segments, 0) !== null;
    }

    private function _walkWithSegments(array $segments, int $index): ?HyperField
    {
        $handle = $segments[$index] ?? null;

        if (!$handle) {
            return null;
        }

        $field = $this->_getFieldByHandle($handle);

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
            foreach ($field->getBlockTypes() as $blockType) {
                $layout = $blockType->getFieldLayout();

                if (!$layout) {
                    continue;
                }

                foreach ($layout->getCustomFields() as $nestedField) {
                    if ($nestedField->handle !== ($segments[$index + 1] ?? null)) {
                        continue;
                    }

                    if ($nestedField instanceof HyperField) {
                        $remaining = array_slice($segments, $index + 1);

                        return $this->_walkWithSegments($remaining, 0);
                    }

                    if ($index + 2 < count($segments)) {
                        $result = $this->_walkWithSegments($segments, $index + 1);

                        if ($result) {
                            return $result;
                        }
                    }
                }
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
