<?php
namespace verbb\hyper\migrations;

use verbb\hyper\Hyper;
use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;

use Craft;
use craft\db\Migration;

class m261124_000000_rebuild_disabled_link_type_relations extends Migration
{
    // Public Methods
    // =========================================================================

    public function safeUp(): bool
    {
        // A full rebuild earlier in this upgrade already indexed these links.
        if (m260912_000000_rebuild_link_relations_from_content::$rebuiltInProcess) {
            return true;
        }

        // 3.0.0–3.0.7 treated saved links of disabled types as unsupported, so their element
        // relations were never indexed. Rebuild only the fields that can hold them.
        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if (!$field instanceof HyperField || !$this->_hasDisabledElementLinkType($field)) {
                continue;
            }

            $time = $this->beginCommand("rebuild Hyper relations for {$field->handle} (field {$field->id}) with disabled link types");
            Hyper::$plugin->getContent()->reconcileRelations($field);
            $this->endCommand($time);
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }


    // Private Methods
    // =========================================================================

    private function _hasDisabledElementLinkType(HyperField $field): bool
    {
        foreach ($field->getLinkTypes() as $linkType) {
            if ($linkType instanceof ElementLink && !$linkType->enabled) {
                return true;
            }
        }

        return false;
    }
}
