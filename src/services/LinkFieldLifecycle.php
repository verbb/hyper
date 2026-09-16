<?php
namespace verbb\hyper\services;

use verbb\hyper\base\LinkInterface;
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
}
