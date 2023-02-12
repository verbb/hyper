<?php
namespace verbb\hyper\models;

use craft\models\FieldLayout as CraftFieldLayout;

class FieldLayout extends CraftFieldLayout
{
    // Properties
    // =========================================================================

    public function __construct($config = [])
    {
        $newConfig = [];

        foreach ($config as $info) {
            $newConfig['id'] = $info['layoutsId'] ?? null;
            $newConfig['type'] = $info['layoutsType'] ?? null;
            $newConfig['uid'] = $info['layoutsUid'] ?? null;

            $newConfig['tabs'][] = [
                'id' => $info['tabsId'] ?? null,
                'layoutId' => $info['tabsLayoutId'] ?? null,
                'name' => $info['tabsName'] ?? null,
                'elements' => $info['tabsElements'] ?? null,
                'sortOrder' => $info['tabsSortOrder'] ?? null,
                'uid' => $info['tabsUid'] ?? null,
            ];
        }

        parent::__construct($newConfig);
    }

}
