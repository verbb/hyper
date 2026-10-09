<?php
namespace verbb\hyper\migrations;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;

use Craft;
use craft\db\Migration;

class m260912_000000_rebuild_link_relations_from_content extends Migration
{
    // Properties
    // =========================================================================

    /**
     * Set when m250703_010000 completed this rebuild earlier in the same process.
     * Sites that ran that migration in an earlier release, or resume after a
     * failure in a new process, still rebuild here.
     */
    public static bool $rebuiltByBackfill = false;


    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        if (self::$rebuiltByBackfill) {
            self::$rebuiltByBackfill = false;

            return true;
        }

        // Legacy caches are disposable and collapse repeated targets. Stored owner
        // content is authoritative, including sites and every field placement.
        // Clear obsolete rows too, including empty fields no longer visited by scans.
        $this->delete('{{%hyper_links}}');

        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if ($field instanceof HyperField) {
                Hyper::$plugin->getContent()->reconcileRelations($field);
                (new LegacyContentRelationRebuilder())->rebuild($field);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
