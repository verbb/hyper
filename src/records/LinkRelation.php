<?php
namespace verbb\hyper\records;

use craft\db\ActiveRecord;

class LinkRelation extends ActiveRecord
{
    // Static Methods
    // =========================================================================

    public static function tableName(): string
    {
        return '{{%hyper_links}}';
    }
}
