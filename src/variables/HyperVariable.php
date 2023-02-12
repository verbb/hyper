<?php
namespace verbb\hyper\variables;

use verbb\hyper\Hyper;

use craft\elements\db\ElementQueryInterface;

class HyperVariable
{
    // Public Methods
    // =========================================================================

    public function getPlugin(): Hyper
    {
        return Hyper::$plugin;
    }

    public function getRelatedElements(array $params = []): ?ElementQueryInterface
    {
        return Hyper::$plugin->getService()->getRelatedElementsQuery($params);
    }
    
}