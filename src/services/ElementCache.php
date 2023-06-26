<?php
namespace verbb\hyper\services;

use verbb\hyper\base\ElementLink;
use verbb\hyper\fields\HyperField;
use verbb\hyper\records\ElementCache as ElementCacheRecord;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\events\ElementEvent;
use craft\helpers\Db;

class ElementCache extends Component
{
    // Constants
    // =========================================================================

    private array $_fetchedCaches = [];
    private array $_preloadCache = [];


    // Public Methods
    // =========================================================================

    public function onSaveElement(ElementEvent $event): void
    {
        // Skip this when updating Craft is currently in progress
        if (Craft::$app->getIsInMaintenanceMode()) {
            return;
        }

        $element = $event->element;
        $isNew = $event->isNew;

        // We only care about already-existing elements and if they have a URL
        if ($isNew || !$element->getUrl()) {
            return;
        }

        // Update (but don't add) any cache item that matches this as a target
        $this->updateCache($element);
    }

    public function onDeleteElement(ElementEvent $event): void
    {
        $element = $event->element;

        $this->deleteCache($element);
    }

    public function addToRenderCache(int $elementId, int $siteId): void
    {
        $cacheKey = $elementId . ':' . $siteId;

        // Prevent adding the element twice
        if (array_key_exists($cacheKey, $this->_preloadCache)) {
            return;
        }

        $this->_preloadCache[$cacheKey] = [
            'sourceId' => $elementId,
            'sourceSiteId' => $siteId,
        ];
    }

    public function preloadCache(): void
    {
        $result = (new Query())
            ->select(['title', 'uri', 'sourceId', 'sourceSiteId', 'targetId', 'targetSiteId'])
            ->from(['{{%hyper_element_cache}}'])
            ->where(array_merge(['or'], array_values($this->_preloadCache)))
            ->all();

        foreach ($result as $value) {
            $cacheKey = $value['targetId'] . ':' . $value['targetSiteId'];

            $this->_fetchedCaches[$cacheKey] = [
                'title' => $value['title'],
                'uri' => $value['uri'],
                'id' => $value['targetId'],
                'siteId' => $value['targetSiteId'],
            ];
        }
    }

    public function getCache(mixed $id, ?int $siteId): ?array
    {
        // Check if locally cached already
        $cacheKey = $id . ':' . $siteId;

        if (array_key_exists($cacheKey, $this->_fetchedCaches)) {
            return $this->_fetchedCaches[$cacheKey];
        }

        $result = (new Query())
            ->select([
                'title',
                'uri',
                'targetId as id',
                'targetSiteId as siteId',
            ])
            ->from(['{{%hyper_element_cache}}'])
            ->where(['targetId' => $id, 'targetSiteId' => $siteId])
            ->one();

        // Cache regardless of result, it'll always be the same
        $this->_fetchedCaches[$cacheKey] = $result;

        return $result;
    }

    public function upsertCache(HyperField $field, ElementInterface $element): bool
    {
        $value = $element->getFieldValue($field->handle);

        foreach ($value->getLinks() as $link) {
            if ($link instanceof ElementLink) {
                $linkElement = $link->getElement();

                if ($linkElement) {
                    // Find or create the record
                    $record = ElementCacheRecord::findOne([
                        'fieldId' => $field->id,
                        'sourceId' => $element->id,
                        'sourceType' => get_class($element),
                        'sourceSiteId' => $element->siteId,
                        'targetId' => $linkElement->id,
                        'targetType' => get_class($linkElement),
                        'targetSiteId' => $linkElement->siteId,
                    ]) ?? new ElementCacheRecord();

                    $record->fieldId = $field->id;
                    $record->sourceId = $element->id;
                    $record->sourceType = get_class($element);
                    $record->sourceSiteId = $element->siteId;
                    $record->targetId = $linkElement->id;
                    $record->targetType = get_class($linkElement);
                    $record->targetSiteId = $linkElement->siteId;
                    $record->title = (string)$linkElement;
                    $record->uri = $linkElement->uri;

                    $record->save(false);
                }
            }
        }

        return true;
    }

    public function updateCache(ElementInterface $element): bool
    {
        // Find or create the record
        $record = ElementCacheRecord::findOne([
            'targetId' => $element->id,
            'targetSiteId' => $element->siteId,
        ]);

        if (!$record) {
            return false;
        }

        $record->title = (string)$element;
        $record->uri = $element->uri;

        $record->save(false);

        return true;
    }

    public function deleteCache(ElementInterface $element): bool
    {
        // Find or create the record
        $record = ElementCacheRecord::findOne([
            'targetId' => $element->id,
            'targetSiteId' => $element->siteId,
        ]);

        if (!$record) {
            return false;
        }

        $record->delete();

        return true;
    }

    public function clearCache(): void
    {
        Db::truncateTable('{{%hyper_element_cache}}');
    }

}
