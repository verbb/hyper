<?php

declare(strict_types=1);

namespace Tests\Support;

use Craft;
use craft\db\Query;
use verbb\hyper\fields\HyperField;
use verbb\hyper\records\LinkRelation as LinkRelationRecord;

class ResetTestDatabase
{
    public static function resetHyperData(): void
    {
        $db = Craft::$app->getDb();

        if ($db->driverName === 'mysql') {
            $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();
        } elseif ($db->driverName === 'sqlite') {
            $db->createCommand('PRAGMA foreign_keys = OFF')->execute();
        }

        try {
            foreach (LinkRelationRecord::find()->all() as $record) {
                $record->delete();
            }

            if ($db->tableExists('{{%hyper_element_cache}}')) {
                $db->createCommand()->delete('{{%hyper_element_cache}}')->execute();
            }

            foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
                if (!$field instanceof HyperField) {
                    continue;
                }

                if (!str_starts_with($field->handle, 'hyperTest')) {
                    continue;
                }

                Craft::$app->getFields()->deleteField($field);
            }

            $entryIds = (new Query())
                ->select(['elements.id'])
                ->from(['{{%elements}} elements'])
                ->innerJoin('{{%entries}} entries', '[[entries.id]] = [[elements.id]]')
                ->innerJoin('{{%sections}} sections', '[[sections.id]] = [[entries.sectionId]]')
                ->where(['like', 'sections.handle', 'hyperTest', false])
                ->column();

            foreach ($entryIds as $entryId) {
                $entry = Craft::$app->getElements()->getElementById((int)$entryId);

                if ($entry) {
                    Craft::$app->getElements()->deleteElement($entry, true);
                }
            }

            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                if (!str_starts_with($site->handle, 'hyperTestSite')) {
                    continue;
                }

                Craft::$app->getSites()->deleteSite($site);
            }
        } finally {
            if ($db->driverName === 'mysql') {
                $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();
            } elseif ($db->driverName === 'sqlite') {
                $db->createCommand('PRAGMA foreign_keys = ON')->execute();
            }
        }
    }
}
