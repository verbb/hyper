<?php
namespace verbb\hyper\records;

use craft\db\ActiveRecord;

class FieldCache extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    public static function tableName(): string
    {
        return '{{%hyper_field_cache}}';
    }
}
