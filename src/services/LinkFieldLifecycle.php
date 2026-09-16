<?php
namespace verbb\hyper\services;

use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkCollectionInterface;

use Craft;
use craft\base\ElementInterface;
use craft\fields\Assets;
use craft\fields\Matrix;

use RuntimeException;

class LinkFieldLifecycle
{
    // Static Methods
    // =========================================================================

    public static function finalizeUploads(LinkCollectionInterface $links, ElementInterface $owner): void
    {
        if ($owner->getIsRevision() || $owner->propagating) {
            return;
        }

        foreach ($links->getLinks() as $link) {
            if ($link instanceof LinkInterface) {
                self::_finalizeElementUploads($link, $owner);
            }
        }
    }

    private static function _finalizeElementUploads(ElementInterface $element, ElementInterface $owner): void
    {
        $assets = Craft::$app->getAssets();

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof Matrix) {
                foreach ($element->getFieldValue($field->handle)->all() as $nested) {
                    self::_finalizeElementUploads($nested, $owner);
                }
            } elseif ($field instanceof HyperField) {
                $nested = $element->getFieldValue($field->handle);

                if ($nested instanceof LinkCollectionInterface) {
                    self::finalizeUploads($nested, $owner);
                }
            } elseif (class_exists(\verbb\vizy\fields\VizyField::class) && $field instanceof \verbb\vizy\fields\VizyField) {
                $nodes = $element->getFieldValue($field->handle);

                if ($nodes instanceof \verbb\vizy\models\NodeCollection) {
                    self::_finalizeVizyUploads($nodes->getNodes(), $element, $owner);
                }
            } elseif ($field instanceof Assets) {
                $selected = $element->getFieldValue($field->handle)->all();
                $temporary = array_filter($selected, static fn($asset) => $asset->volumeId === null);

                if (!$temporary) {
                    continue;
                }

                // Links are JSON values, not saved Craft elements. Calling the field's
                // afterElementSave would write relations using a synthetic/null owner ID.
                // Finalize only its uploaded files, through Craft's public storage APIs.
                $folder = $assets->getFolderById($field->resolveDynamicPathToFolderId($element));

                if (!$folder?->volumeId) {
                    if ($owner->getIsDraft()) {
                        continue;
                    }

                    throw new RuntimeException('A Hyper asset upload folder could not be resolved. Use a stable link value such as {uid}, rather than a link element {id}.');
                }

                foreach ($temporary as $asset) {
                    $asset->avoidFilenameConflicts = true;

                    if (!$assets->moveAsset($asset, $folder)) {
                        throw new RuntimeException('Unable to finalize a Hyper asset upload.');
                    }
                }
            }
        }
    }

    private static function _finalizeVizyUploads(array $nodes, ElementInterface $element, ElementInterface $owner): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof \verbb\vizy\nodes\VizyBlock) {
                $block = $node->getBlockElement($element);

                // Vizy reads stored layout UIDs through the node; its synthetic
                // element can still have only handle-keyed values.
                foreach ($block->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                    if ($field instanceof Assets || $field instanceof Matrix || $field instanceof HyperField || $field instanceof \verbb\vizy\fields\VizyField) {
                        $block->setFieldValue($field->handle, $node->getFieldValue($field->handle));
                    }
                }

                self::_finalizeElementUploads($block, $owner);
            }

            self::_finalizeVizyUploads($node->getContent(), $element, $owner);
        }
    }

}
