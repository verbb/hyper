<?php
namespace verbb\hyper\records;

use craft\db\ActiveRecord;

class ElementCache extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    public static function tableName(): string
    {
        return '{{%hyper_element_cache}}';
    }
}
