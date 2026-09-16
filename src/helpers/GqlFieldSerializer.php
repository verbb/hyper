<?php
namespace verbb\hyper\helpers;

use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkCollectionInterface;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\fields\ContentBlock;
use craft\fields\Matrix;
use craft\helpers\Json;
use craft\models\GqlSchema;

class GqlFieldSerializer
{
    // Static Methods
    // =========================================================================

    public static function serialize(ElementInterface $element, ?GqlSchema $schema, bool $useLayoutUids = false): array
    {
        $result = [];

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($schema && !$field->includeInGqlSchema($schema)) {
                continue;
            }

            $key = $useLayoutUids ? ($field->layoutElement?->uid ?? $field->handle) : $field->handle;
            $result[$key] = self::_serializeField($field, $element, $schema);
        }

        return $result;
    }

    private static function _serializeField(FieldInterface $field, ElementInterface $owner, ?GqlSchema $schema): mixed
    {
        $value = $owner->getFieldValue($field->handle);

        // Hyper owns embedded values without giving the nested element an ID.
        // Native ContentBlock serialization returns null for that unsaved element.
        if ($field instanceof ContentBlock && $value instanceof ElementInterface) {
            return ['fields' => self::serialize($value, $schema)];
        }

        $serialized = $field->serializeValue($value, $owner);

        if (!$schema) {
            return $serialized;
        }

        if ($field instanceof HyperField && $value instanceof LinkCollectionInterface) {
            foreach (array_values($value->getLinks()) as $index => $link) {
                $fields = self::serialize($link, $schema, true);
                unset($serialized[$index]['fields']);

                if ($fields) {
                    $serialized[$index]['fields'] = $fields;
                }
            }
        } elseif ($field instanceof Matrix || (class_exists(\benf\neo\Field::class) && $field instanceof \benf\neo\Field)) {
            $new = 0;

            foreach ($value->all() as $entry) {
                $key = $entry->id ?? 'new' . ++$new;
                $serialized[$key]['fields'] = self::serialize($entry, $schema);
            }
        } elseif (class_exists(\verbb\vizy\fields\VizyField::class) && $field instanceof \verbb\vizy\fields\VizyField && $value instanceof \verbb\vizy\models\NodeCollection) {
            $nodes = is_string($serialized) ? Json::decode($serialized) : $serialized;
            $sourceNodes = $value->getNodes();

            if ($field->editorMode === \verbb\vizy\fields\VizyField::MODE_BLOCKS) {
                $sourceNodes = array_filter($sourceNodes, static fn($node) => $node instanceof \verbb\vizy\nodes\VizyBlock);
            }

            $blocks = [];
            self::_collectVizyBlocks($sourceNodes, $blocks);
            self::_filterVizyNodes($nodes, $blocks, $owner, $schema);
            $serialized = is_string($serialized) ? Json::encode($nodes) : $nodes;
        }

        return $serialized;
    }

    private static function _collectVizyBlocks(array $nodes, array &$blocks): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof \verbb\vizy\nodes\VizyBlock) {
                $blocks[$node->getAttrs()['id'] ?? ''][] = $node;
            }

            self::_collectVizyBlocks($node->getContent(), $blocks);
        }
    }

    private static function _filterVizyNodes(array &$nodes, array &$blocks, ElementInterface $owner, GqlSchema $schema): void
    {
        foreach ($nodes as &$data) {
            if (($data['type'] ?? null) === 'vizyBlock') {
                $fields = [];
                $id = $data['attrs']['id'] ?? '';
                $node = isset($blocks[$id]) ? array_shift($blocks[$id]) : null;

                if ($node) {
                    $block = $node->getBlockElement($owner);

                    // Vizy resolves persisted layout keys through the node accessor.
                    foreach ($block->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                        if ($field->includeInGqlSchema($schema)) {
                            $block->setFieldValue($field->handle, $node->getFieldValue($field->handle));
                        }
                    }

                    foreach (self::serialize($block, $schema, true) as $key => $value) {
                        $fields[$key] = is_array($value) ? Json::encode($value) : $value;
                    }
                }

                $data['attrs']['values']['content']['fields'] = $fields;
            }

            if (isset($data['content']) && is_array($data['content'])) {
                self::_filterVizyNodes($data['content'], $blocks, $owner, $schema);
            }
        }
    }
}
