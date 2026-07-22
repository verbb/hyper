<?php
namespace verbb\hyper\content;

use verbb\hyper\fields\HyperField;

use Craft;

class NestedFieldPlacementFinder
{
    // Properties
    // =========================================================================

    private ElementContentStore $_store;


    // Public Methods
    // =========================================================================

    public function __construct(?ElementContentStore $store = null)
    {
        $this->_store = $store ?? new ElementContentStore();
    }

    public function findPlacements(HyperField $targetField): array
    {
        $placements = [];

        foreach (NestedFieldLocatorRegistry::all() as $locator) {
            $hostFieldClass = $locator::hostFieldClass();

            foreach (Craft::$app->getFields()->getAllFields(false) as $hostField) {
                if (!$hostField instanceof $hostFieldClass) {
                    continue;
                }

                $handles = $locator->findNestedFieldHandles($hostField, $targetField);

                if (!$handles) {
                    continue;
                }

                foreach ($this->_store->findLayoutUids($hostField) as $hostLayoutUid) {
                    foreach ($handles as $handle) {
                        $placements[] = new NestedFieldPlacement(
                            hostField: $hostField,
                            hostLayoutUid: $hostLayoutUid,
                            targetFieldHandle: $handle,
                            locator: $locator,
                        );
                    }
                }
            }
        }

        return $placements;
    }
}
