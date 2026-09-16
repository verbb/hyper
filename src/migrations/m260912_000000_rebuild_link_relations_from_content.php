<?php
namespace verbb\hyper\migrations;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;

use Craft;
use craft\db\Migration;

class m260912_000000_rebuild_link_relations_from_content extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        // Legacy caches are disposable and collapse repeated targets. Stored owner
        // content is authoritative, including sites and every field placement.
        // Clear obsolete rows too, including empty fields no longer visited by scans.
        $this->delete('{{%hyper_links}}');

        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if ($field instanceof HyperField) {
                Hyper::$plugin->getContent()->reconcileRelations($field);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
