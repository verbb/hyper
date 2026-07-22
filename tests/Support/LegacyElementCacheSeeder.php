<?php

declare(strict_types=1);

namespace Tests\Support;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;

/**
 * Creates legacy hyper_element_cache rows for upgrade/backfill and perf baselines only.
 */
final class LegacyElementCacheSeeder
{
    public static function seedFromOwner(HyperField $field, ElementInterface $element): void
    {
        if ($element->isProvisionalDraft || ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        if (class_exists('verbb\vizy\elements\Block') && $element instanceof \verbb\vizy\elements\Block) {
            return;
        }

        self::ensureLegacyTable();

        $value = $element->getFieldValue($field->handle);
        $handledTargetKeys = [];

        $currentRows = (new Query())
            ->from(['{{%hyper_element_cache}}'])
            ->where([
                'fieldId' => $field->id,
                'sourceId' => $element->id,
                'sourceType' => get_class($element),
                'sourceSiteId' => $element->siteId,
            ])
            ->all();

        foreach ($value->getLinks() as $link) {
            if (!$link instanceof ElementLink) {
                continue;
            }

            $linkElement = $link->getElement();

            if (!$linkElement) {
                continue;
            }

            $targetKey = $linkElement->id . ':' . $linkElement->siteId;
            $handledTargetKeys[] = $targetKey;

            $existingId = (new Query())
                ->select(['id'])
                ->from(['{{%hyper_element_cache}}'])
                ->where([
                    'fieldId' => $field->id,
                    'sourceId' => $element->id,
                    'sourceType' => get_class($element),
                    'sourceSiteId' => $element->siteId,
                    'targetId' => $linkElement->id,
                    'targetType' => get_class($linkElement),
                    'targetSiteId' => $linkElement->siteId,
                ])
                ->scalar();

            $now = Db::prepareDateForDb(new \DateTime());

            if ($existingId) {
                Craft::$app->getDb()->createCommand()
                    ->update('{{%hyper_element_cache}}', [
                        'title' => (string)$linkElement,
                        'uri' => $linkElement->uri,
                        'dateUpdated' => $now,
                    ], ['id' => $existingId])
                    ->execute();

                continue;
            }

            Craft::$app->getDb()->createCommand()
                ->insert('{{%hyper_element_cache}}', [
                    'fieldId' => $field->id,
                    'sourceId' => $element->id,
                    'sourceType' => get_class($element),
                    'sourceSiteId' => $element->siteId,
                    'targetId' => $linkElement->id,
                    'targetType' => get_class($linkElement),
                    'targetSiteId' => $linkElement->siteId,
                    'title' => (string)$linkElement,
                    'uri' => $linkElement->uri,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])
                ->execute();
        }

        foreach ($currentRows as $currentRow) {
            $targetKey = $currentRow['targetId'] . ':' . $currentRow['targetSiteId'];

            if (!in_array($targetKey, $handledTargetKeys, true)) {
                Craft::$app->getDb()->createCommand()
                    ->delete('{{%hyper_element_cache}}', ['id' => $currentRow['id']])
                    ->execute();
            }
        }
    }

    public static function ensureLegacyTable(): void
    {
        $db = Craft::$app->getDb();

        if ($db->tableExists('{{%hyper_element_cache}}')) {
            return;
        }

        $db->createCommand()->createTable('{{%hyper_element_cache}}', [
            'id' => 'pk',
            'fieldId' => 'integer',
            'sourceId' => 'integer',
            'sourceSiteId' => 'integer',
            'sourceType' => 'string(255)',
            'targetId' => 'integer',
            'targetSiteId' => 'integer',
            'targetType' => 'string(255)',
            'title' => 'string(255)',
            'uri' => 'string(255)',
            'dateCreated' => 'datetime NOT NULL',
            'dateUpdated' => 'datetime NOT NULL',
            'uid' => 'char(36) NOT NULL',
        ])->execute();
    }
}
