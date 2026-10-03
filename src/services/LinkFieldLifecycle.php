<?php
namespace verbb\hyper\services;

use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkCollectionInterface;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementCondition;
use craft\fields\Assets;
use craft\fields\Matrix;

use RuntimeException;
use yii\web\ForbiddenHttpException;

class LinkFieldLifecycle
{
    // Static Methods
    // =========================================================================

    public static function validateUploads(LinkCollectionInterface $links, ElementInterface $owner): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest()) {
            return;
        }

        self::_pendingUploads($links, $owner);
    }

    public static function finalizeUploads(LinkCollectionInterface $links, ElementInterface $owner): void
    {
        $pending = self::_pendingUploads($links, $owner);

        foreach ($pending as [$asset, , , $folder]) {
            if (!$folder) {
                continue;
            }

            $asset->avoidFilenameConflicts = true;

            if (!Craft::$app->getAssets()->moveAsset($asset, $folder)) {
                throw new RuntimeException('Unable to finalize a Hyper asset upload.');
            }
        }
    }

    private static function _pendingUploads(LinkCollectionInterface $links, ElementInterface $owner): array
    {
        if ($owner->getIsRevision() || $owner->propagating) {
            return [];
        }

        $pending = [];

        foreach ($links->getLinks() as $link) {
            if ($link instanceof LinkInterface) {
                self::_collectElementUploads($link, $owner, $pending);
            }
        }

        self::_assertUploadsAllowed($pending);

        return $pending;
    }

    private static function _collectElementUploads(ElementInterface $element, ElementInterface $owner, array &$pending): void
    {
        $assets = Craft::$app->getAssets();

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof Matrix) {
                foreach ($element->getFieldValue($field->handle)->all() as $nested) {
                    self::_collectElementUploads($nested, $owner, $pending);
                }
            } elseif ($field instanceof HyperField) {
                $nested = $element->getFieldValue($field->handle);

                if ($nested instanceof LinkCollectionInterface) {
                    foreach ($nested->getLinks() as $link) {
                        if ($link instanceof LinkInterface) {
                            self::_collectElementUploads($link, $owner, $pending);
                        }
                    }
                }
            } elseif (class_exists(\verbb\vizy\fields\VizyField::class) && $field instanceof \verbb\vizy\fields\VizyField) {
                $nodes = $element->getFieldValue($field->handle);

                if ($nodes instanceof \verbb\vizy\models\NodeCollection) {
                    self::_collectVizyUploads($nodes->getNodes(), $element, $owner, $pending);
                }
            } elseif ($field instanceof Assets) {
                $selected = $element->getFieldValue($field->handle)->all();
                $temporary = array_filter($selected, static fn($asset) => $asset instanceof Asset && $asset->volumeId === null);

                if (!$temporary) {
                    continue;
                }

                // Links are JSON values, not saved Craft elements. Calling the field's
                // afterElementSave would write relations using a synthetic/null owner ID.
                // Finalize only its uploaded files, through Craft's public storage APIs.
                $folder = $assets->getFolderById($field->resolveDynamicPathToFolderId($element));

                if (!$folder?->volumeId) {
                    if ($owner->getIsDraft()) {
                        foreach ($temporary as $asset) {
                            $pending[] = [$asset, $field, $element, null];
                        }

                        continue;
                    }

                    throw new RuntimeException('A Hyper asset upload folder could not be resolved. Use a stable link value such as {uid}, rather than a link element {id}.');
                }

                foreach ($temporary as $asset) {
                    $pending[] = [$asset, $field, $element, $folder];
                }
            }
        }
    }

    private static function _assertUploadsAllowed(array $pending): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest()) {
            return;
        }

        $userSession = Craft::$app->getUser();
        $identity = $userSession->getIdentity();

        if (!$identity) {
            throw new ForbiddenHttpException('User is not authorized to finalize a selected temporary asset.');
        }

        $temporaryFolder = Craft::$app->getAssets()->getUserTemporaryUploadFolder($identity);

        foreach ($pending as [$asset, $field, $element, $folder]) {
            if ($asset->folderId !== $temporaryFolder->id || $asset->uploaderId !== $identity->id) {
                throw new ForbiddenHttpException('User is not authorized to finalize a selected temporary asset.');
            }

            if (!$field->allowUploads
                || ($field->restrictFiles && !in_array($asset->kind, $field->allowedKinds ?? [], true))) {
                throw new ForbiddenHttpException('The selected temporary asset is not permitted by this Assets field.');
            }

            $selectionCondition = $field->getSelectionCondition();

            if ($selectionCondition && $folder) {
                $selectionCondition = clone $selectionCondition;
                $prospectiveAsset = clone $asset;
                $prospectiveAsset->setVolumeId($folder->volumeId);
                $prospectiveAsset->folderId = $folder->id;

                if ($selectionCondition instanceof ElementCondition) {
                    $selectionCondition->referenceElement = $element;
                }

                if (!$selectionCondition->matchElement($prospectiveAsset)) {
                    throw new ForbiddenHttpException('The selected temporary asset is not permitted by this Assets field.');
                }
            }

            if ($folder && !$userSession->checkPermission('saveAssets:' . $folder->getVolume()->uid)) {
                throw new ForbiddenHttpException('User is not authorized to save an asset to the resolved upload location.');
            }
        }
    }

    private static function _collectVizyUploads(array $nodes, ElementInterface $element, ElementInterface $owner, array &$pending): void
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

                self::_collectElementUploads($block, $owner, $pending);
            }

            self::_collectVizyUploads($node->getContent(), $element, $owner, $pending);
        }
    }

}
