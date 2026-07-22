<?php
namespace verbb\hyper\services;

use verbb\hyper\content\adapters\HyperFieldAdapter;
use verbb\hyper\content\ContentRef;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\content\ModifyResult;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\links\Site as SiteLink;
use verbb\hyper\models\LinkCollection;
use verbb\hyper\models\LinkInstance;
use verbb\hyper\services\LinkTypeConfigs;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\events\DeleteSiteEvent;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;

class Content extends Component
{
    // Public Methods
    // =========================================================================

    public function modify(
        HyperField $field,
        callable $transform,
        ?ModifyOptions $options = null,
    ): ModifyResult {
        $options ??= new ModifyOptions();
        $adapter = new HyperFieldAdapter($field);
        $store = new ElementContentStore($options->db);

        $result = $store->eachFieldValue($field, function(ContentRef $ref, ModifyOptions $modifyOptions, ModifyResult $result) use ($adapter, $transform, $field, $options) {
            $decoded = $adapter->decode($ref->value, $ref);
            $existingEncoded = $adapter->encode($decoded, $ref);
            $newValue = $transform($decoded, $ref);

            if ($newValue === null) {
                $newValue = new LinkCollection($field, []);
            }

            $encoded = $adapter->encode($newValue, $ref);

            if ($this->_serializedValuesEqual($existingEncoded, $encoded)) {
                return false;
            }

            if (!$options->dryRun) {
                $ref->value = $encoded;
                $result->recordModification($ref, $newValue);
            }

            return true;
        }, $options);

        if (!$options->dryRun && $options->syncRelations) {
            foreach ($result->modifications as $modification) {
                if ($modification['value'] instanceof LinkCollection) {
                    $this->_syncRelations($field, $modification['ref'], $modification['value']);
                }
            }
        }

        return $result;
    }

    public function modifyLinkInstances(
        HyperField $field,
        callable $transform,
        ?ModifyOptions $options = null,
    ): ModifyResult {
        return $this->modify($field, function(LinkCollection $collection, ContentRef $ref) use ($field, $transform) {
            $links = $collection->getLinks();
            $changed = false;

            foreach ($links as $key => $link) {
                $instance = LinkInstance::fromLink($link, $field);
                $before = $instance->toSerialized();
                $newInstance = $transform($instance, $ref);

                if (!$newInstance instanceof LinkInstance) {
                    continue;
                }

                if ($newInstance->toSerialized() === $before) {
                    continue;
                }

                $newLink = Hyper::$plugin->getLinks()->createLinkFromInstance($field, $newInstance);

                if ($newLink) {
                    $links[$key] = $newLink;
                    $changed = true;
                }
            }

            return $changed ? $collection->withLinks($links) : $collection;
        }, $options);
    }

    public function pruneDeletedSite(DeleteSiteEvent $event): void
    {
        $this->pruneDeletedSiteUid($event->site->uid);
    }

    public function pruneDeletedSiteUid(string $siteUid): void
    {
        $this->_pruneHyperFieldSettings($siteUid);
        $this->_pruneLinkTypeConfigs($siteUid);
        $this->_pruneElementContent($siteUid);
    }


    // Private Methods
    // =========================================================================

    private function _pruneHyperFieldSettings(string $siteUid): void
    {
        $fieldsService = Craft::$app->getFields();

        foreach ($fieldsService->getAllFields(false) as $field) {
            if (!$field instanceof HyperField) {
                continue;
            }

            // Shared-config fields — scrubbed via _pruneLinkTypeConfigs.
            if (!$field->hasCustomLinkTypes()) {
                continue;
            }

            $linkTypeConfigs = [];
            $changed = false;

            foreach ($field->getLinkTypes() as $linkType) {
                if ($linkType instanceof SiteLink && is_array($linkType->sites)) {
                    $filtered = array_values(array_filter(
                        $linkType->sites,
                        static fn(mixed $uid): bool => $uid !== $siteUid,
                    ));

                    if ($filtered !== $linkType->sites) {
                        $linkType->sites = $filtered;
                        $changed = true;
                    }
                }

                $linkTypeConfigs[] = $linkType->getSettingsConfigForDb();
            }

            if ($changed) {
                $field->setLinkTypes($linkTypeConfigs);
                $fieldsService->saveField($field);
            }
        }
    }

    private function _pruneLinkTypeConfigs(string $siteUid): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $all = $projectConfig->get(LinkTypeConfigs::PROJECT_CONFIG_PATH) ?? [];

        if (!is_array($all)) {
            return;
        }

        foreach ($all as $uid => $configData) {
            if (!is_array($configData)) {
                continue;
            }

            $linkTypes = ProjectConfigHelper::unpackAssociativeArrays($configData['linkTypes'] ?? []);
            [$linkTypes, $changed] = $this->_scrubSiteFromLinkTypeSettingsList($linkTypes, $siteUid);

            if (!$changed) {
                continue;
            }

            $configData['linkTypes'] = ProjectConfigHelper::packAssociativeArrays($linkTypes);

            $projectConfig->set(
                LinkTypeConfigs::PROJECT_CONFIG_PATH . '.' . $uid,
                $configData,
                'Prune deleted site from Hyper link type config',
            );
        }
    }

    private function _pruneElementContent(string $siteUid): void
    {
        $options = new ModifyOptions(
            syncRelations: false,
            contentContains: $siteUid,
        );

        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if (!$field instanceof HyperField) {
                continue;
            }

            if (!$this->_fieldHasSiteLinkType($field)) {
                continue;
            }

            $this->modifyLinkInstances($field, function(LinkInstance $instance) use ($siteUid, $field): LinkInstance {
                $linkType = $field->getLinkTypeByHandle($instance->linkTypeHandle);

                if (!$linkType instanceof SiteLink) {
                    return $instance;
                }

                if ($instance->linkValue !== $siteUid) {
                    return $instance;
                }

                $instance->linkValue = null;

                return $instance;
            }, $options);
        }
    }

    private function _fieldHasSiteLinkType(HyperField $field): bool
    {
        foreach ($field->getLinkTypes() as $linkType) {
            if ($linkType instanceof SiteLink) {
                return true;
            }
        }

        return false;
    }

    private function _scrubSiteFromLinkTypeSettingsList(array $linkTypes, string $siteUid): array
    {
        $changed = false;

        foreach ($linkTypes as $key => $linkType) {
            if (($linkType['type'] ?? '') !== SiteLink::class) {
                continue;
            }

            $sites = $linkType['sites'] ?? null;

            if (!is_array($sites)) {
                continue;
            }

            $filtered = array_values(array_filter(
                $sites,
                static fn(mixed $uid): bool => $uid !== $siteUid,
            ));

            if ($filtered === $sites) {
                continue;
            }

            $linkTypes[$key]['sites'] = $filtered;
            $changed = true;
        }

        return [$linkTypes, $changed];
    }

    private function _syncRelations(HyperField $field, ContentRef $ref, LinkCollection $collection): void
    {
        $owner = $this->_resolveRelationOwner($ref);

        if (!$owner) {
            return;
        }

        Hyper::$plugin->getLinkRelations()->syncFromLinkCollection($field, $owner, $collection);
    }

    private function _resolveRelationOwner(ContentRef $ref): ?ElementInterface
    {
        if (preg_match('/\.(\d+)\.fields\./', $ref->jsonPath, $matches)) {
            $blockOwner = Craft::$app->getElements()->getElementById((int)$matches[1], $ref->siteId);

            if ($blockOwner) {
                return $blockOwner;
            }
        }

        return Craft::$app->getElements()->getElementById($ref->elementId, $ref->siteId);
    }

    private function _serializedValuesEqual(mixed $left, mixed $right): bool
    {
        return Json::encode($left) === Json::encode($right);
    }
}
