<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use Craft;
use craft\base\Field;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Entries as EntriesField;
use craft\fields\Matrix;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use DateTime;
use RuntimeException;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\Hyper;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Passive;
use verbb\hyper\links\Site as SiteLink;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkCollection;

class HyperFixtureFactory
{
    private static int $sequence = 0;

    public static function handle(string $prefix): string
    {
        self::$sequence++;

        return sprintf('%s%s%s', $prefix, self::$sequence, substr(StringHelper::UUID(), 0, 4));
    }

    /**
     * @param class-string<LinkInterface>|LinkInterface $linkTypeClass
     */
    public static function linkTypeConfig(string|LinkInterface $linkTypeClass, bool $enabled = true): array
    {
        $link = is_string($linkTypeClass)
            ? Hyper::$plugin->getLinks()->createLink($linkTypeClass)
            : $linkTypeClass;

        $link->enabled = $enabled;
        $link->handle = $link->handle ?: $link::typeKey();

        if (!$link->layoutUid) {
            $link->layoutUid = StringHelper::UUID();
        }

        if (!$link->layoutConfig) {
            $link->layoutConfig = $link::getDefaultFieldLayout()->getConfig();
        }

        return $link->getSettingsConfigForDb();
    }

    /**
     * @param array{
     *     handle?: string,
     *     multipleLinks?: bool,
     *     linkTypes?: list<class-string>,
     *     enabled?: bool,
     * } $options
     */
    public static function hyperField(array $options = []): HyperField
    {
        $handle = $options['handle'] ?? self::handle('hyperTestLinks');
        $multipleLinks = $options['multipleLinks'] ?? false;
        $linkTypeClasses = $options['linkTypes'] ?? [Url::class, EntryLink::class];

        $linkTypes = array_map(
            static fn(string $class): array => self::linkTypeConfig($class, $options['enabled'] ?? true),
            $linkTypeClasses,
        );

        $urlLink = Hyper::$plugin->getLinks()->createLink(Url::class);
        $defaultLinkType = $urlLink->handle ?: Url::typeKey();

        $field = new HyperField([
            'name' => StringHelper::titleize($handle),
            'handle' => $handle,
            'multipleLinks' => $multipleLinks,
            'linkTypes' => $linkTypes,
            'defaultLinkType' => $defaultLinkType,
            'translationMethod' => $options['translationMethod'] ?? Field::TRANSLATION_METHOD_NONE,
        ]);

        if (!Craft::$app->fields->saveField($field)) {
            throw new RuntimeException('Failed creating Hyper field fixture: ' . json_encode($field->getErrors()));
        }

        $savedField = Craft::$app->fields->getFieldByHandle($handle);

        if (!$savedField instanceof HyperField) {
            throw new RuntimeException("Hyper field fixture `{$handle}` could not be reloaded.");
        }

        return $savedField;
    }

    public static function entrySection(?HyperField $field = null, ?string $handle = null, bool $allSites = false): Section
    {
        $handle ??= self::handle('hyperTestEntries');
        $entryTypeHandle = $handle . 'Type';
        $entriesService = Craft::$app->getEntries();

        $entryType = new EntryType([
            'name' => StringHelper::titleize($entryTypeHandle),
            'handle' => $entryTypeHandle,
            'hasTitleField' => true,
        ]);

        if (!$entriesService->saveEntryType($entryType)) {
            throw new RuntimeException('Failed creating entry type fixture: ' . json_encode($entryType->getErrors()));
        }

        $section = new Section([
            'name' => StringHelper::titleize($handle),
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
        ]);
        $section->setEntryTypes([$entryType]);
        $sites = $allSites
            ? Craft::$app->getSites()->getAllSites()
            : [Craft::$app->getSites()->getPrimarySite()];
        $section->setSiteSettings(array_map(
            static fn(Site $site): Section_SiteSettings => new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => $handle . '/{slug}',
                'template' => '_hyper-test/entry',
            ]),
            $sites,
        ));

        if (!$entriesService->saveSection($section)) {
            throw new RuntimeException('Failed creating section fixture: ' . json_encode($section->getErrors()));
        }

        $savedSection = $entriesService->getSectionByHandle($handle);

        if (!$savedSection) {
            throw new RuntimeException("Section fixture `{$handle}` could not be reloaded.");
        }

        if ($field) {
            self::attachHyperFieldToSection($savedSection, $field);
        }

        return $savedSection;
    }

    public static function translatableEntrySection(HyperField $field, int $siteCount = 2): Section
    {
        self::ensureSites($siteCount);
        $sites = self::ensureSites($siteCount);
        $handle = self::handle('hyperTestTranslatable');
        $entryTypeHandle = $handle . 'Type';
        $entriesService = Craft::$app->getEntries();

        $entryType = new EntryType([
            'name' => StringHelper::titleize($entryTypeHandle),
            'handle' => $entryTypeHandle,
            'hasTitleField' => true,
        ]);

        if (!$entriesService->saveEntryType($entryType)) {
            throw new RuntimeException('Failed creating entry type fixture: ' . json_encode($entryType->getErrors()));
        }

        $section = new Section([
            'name' => StringHelper::titleize($handle),
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
        ]);
        $section->setEntryTypes([$entryType]);
        $section->setSiteSettings(array_map(
            static fn(Site $site): Section_SiteSettings => new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => $handle . '/{slug}',
                'template' => '_hyper-test/entry',
            ]),
            $sites,
        ));

        if (!$entriesService->saveSection($section)) {
            throw new RuntimeException('Failed creating translatable section fixture: ' . json_encode($section->getErrors()));
        }

        $savedSection = $entriesService->getSectionByHandle($handle);

        if (!$savedSection) {
            throw new RuntimeException("Translatable section fixture `{$handle}` could not be reloaded.");
        }

        self::attachHyperFieldToSection($savedSection, $field);

        return $savedSection;
    }

    public static function localizedEntryPair(Section $section, Site $primarySite, Site $secondarySite, string $title = 'Localized entry'): array
    {
        $primary = self::plainEntry($section, $title . ' primary', [], $primarySite);

        $secondary = $primary->getLocalized()
            ->siteId($secondarySite->id)
            ->status(null)
            ->one();

        if (!$secondary) {
            $secondary = Entry::find()
                ->siteId($secondarySite->id)
                ->where(['elements.canonicalId' => $primary->getCanonicalId()])
                ->status(null)
                ->one();
        }

        if (!$secondary) {
            throw new RuntimeException('Expected localized entry row for secondary site.');
        }

        return ['primary' => $primary, 'secondary' => $secondary];
    }

    public static function localizedEntryForSite(Entry $entry, Site $site): Entry
    {
        $localized = $entry->getLocalized()
            ->siteId($site->id)
            ->status(null)
            ->one();

        if (!$localized) {
            throw new RuntimeException(sprintf(
                'Expected localized entry for site %d (owner id %d).',
                $site->id,
                $entry->id,
            ));
        }

        return $localized;
    }

    public static function attachHyperFieldToSection(Section $section, HyperField $field): void
    {
        self::attachFieldToSection($section, $field);
    }

    public static function attachFieldToEntryType(EntryType $entryType, \craft\base\FieldInterface $field): void
    {
        $entriesService = Craft::$app->getEntries();
        $fieldLayout = $entryType->getFieldLayout() ?? new FieldLayout(['type' => Entry::class]);
        $tabs = $fieldLayout->getTabs();

        if (!$tabs) {
            $tabs = [
                new FieldLayoutTab([
                    'name' => Craft::t('app', 'Content'),
                    'layout' => $fieldLayout,
                ]),
            ];
        }

        $tab = $tabs[0];
        $elements = $tab->getElements();
        $elements[] = new CustomField($field);
        $tab->setElements($elements);
        $fieldLayout->setTabs($tabs);
        $entryType->setFieldLayout($fieldLayout);

        if (!$entriesService->saveEntryType($entryType)) {
            throw new RuntimeException('Failed attaching field to entry type: ' . json_encode($entryType->getErrors()));
        }
    }

    /**
     * @return array{matrix: Matrix, blockEntryType: EntryType, hyperField: HyperField}
     */
    public static function matrixFieldWithHyper(?HyperField $hyperField = null): array
    {
        $hyperField ??= self::hyperField(['handle' => self::handle('matrixHyper')]);

        $blockHandle = self::handle('matrixBlockType');
        $blockEntryType = new EntryType([
            'name' => StringHelper::titleize($blockHandle),
            'handle' => $blockHandle,
            'hasTitleField' => true,
        ]);

        if (!Craft::$app->getEntries()->saveEntryType($blockEntryType)) {
            throw new RuntimeException('Failed creating matrix block entry type: ' . json_encode($blockEntryType->getErrors()));
        }

        self::attachFieldToEntryType($blockEntryType, $hyperField);

        $matrixHandle = self::handle('matrixBlocks');
        $matrix = new Matrix([
            'name' => StringHelper::titleize($matrixHandle),
            'handle' => $matrixHandle,
        ]);
        $matrix->setEntryTypes([$blockEntryType]);

        if (!Craft::$app->fields->saveField($matrix)) {
            throw new RuntimeException('Failed creating Matrix field fixture: ' . json_encode($matrix->getErrors()));
        }

        $savedMatrix = Craft::$app->fields->getFieldByHandle($matrixHandle);

        if (!$savedMatrix instanceof Matrix) {
            throw new RuntimeException("Matrix field fixture `{$matrixHandle}` could not be reloaded.");
        }

        $savedBlockEntryType = Craft::$app->getEntries()->getEntryType($blockEntryType->id);

        if (!$savedBlockEntryType) {
            throw new RuntimeException("Matrix block entry type `{$blockHandle}` could not be reloaded.");
        }

        return [
            'matrix' => $savedMatrix,
            'blockEntryType' => $savedBlockEntryType,
            'hyperField' => Craft::$app->fields->getFieldById($hyperField->id) ?? $hyperField,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $linkPayloads
     */
    public static function entryWithMatrixHyperLink(
        Section $section,
        Matrix $matrixField,
        HyperField $hyperField,
        EntryType $blockEntryType,
        array $linkPayloads,
        ?string $title = null,
        ?Site $site = null,
    ): Entry {
        $site ??= Craft::$app->getSites()->getPrimarySite();
        $entry = self::plainEntry($section, $title ?? 'Matrix Hyper owner', [], $site);

        $entry->setFieldValue($matrixField->handle, [
            'entries' => [
                'new1' => [
                    'type' => $blockEntryType->handle,
                    'fields' => [
                        $hyperField->handle => $linkPayloads,
                    ],
                ],
            ],
            'sortOrder' => ['new1'],
        ]);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException('Failed saving matrix entry: ' . json_encode($entry->getErrors()));
        }

        return Entry::find()->id($entry->id)->siteId($site->id)->status(null)->one() ?? $entry;
    }

    public static function entrySectionWithField(\craft\base\FieldInterface $field, ?string $handle = null): Section
    {
        $section = self::entrySection(null, $handle);
        self::attachFieldToSection($section, $field);

        return Craft::$app->getEntries()->getSectionByHandle($section->handle) ?? $section;
    }

    public static function entriesField(?string $handle = null, ?Section $sourcesSection = null): EntriesField
    {
        $handle ??= self::handle('hyperRelatedEntries');

        $field = new EntriesField([
            'name' => StringHelper::titleize($handle),
            'handle' => $handle,
            'maxRelations' => 1,
        ]);

        if ($sourcesSection) {
            $field->sources = ['section:' . $sourcesSection->uid];
        }

        if (!Craft::$app->fields->saveField($field)) {
            throw new RuntimeException('Failed creating Entries field fixture: ' . json_encode($field->getErrors()));
        }

        $savedField = Craft::$app->fields->getFieldByHandle($handle);

        if (!$savedField instanceof EntriesField) {
            throw new RuntimeException("Entries field fixture `{$handle}` could not be reloaded.");
        }

        return $savedField;
    }

    public static function attachFieldToSection(Section $section, \craft\base\FieldInterface $field): void
    {
        $entriesService = Craft::$app->getEntries();
        $entryType = $entriesService->getEntryTypesBySectionId($section->id)[0] ?? null;

        if (!$entryType) {
            throw new RuntimeException("Section `{$section->handle}` has no entry types.");
        }

        $fieldLayout = $entryType->getFieldLayout() ?? new FieldLayout(['type' => Entry::class]);
        $tabs = $fieldLayout->getTabs();

        if (!$tabs) {
            $tabs = [
                new FieldLayoutTab([
                    'name' => Craft::t('app', 'Content'),
                    'layout' => $fieldLayout,
                ]),
            ];
        }

        $tab = $tabs[0];
        $elements = $tab->getElements();
        $elements[] = new CustomField($field);
        $tab->setElements($elements);
        $fieldLayout->setTabs($tabs);
        $entryType->setFieldLayout($fieldLayout);

        if (!$entriesService->saveEntryType($entryType)) {
            throw new RuntimeException('Failed attaching field to entry type: ' . json_encode($entryType->getErrors()));
        }
    }

    public static function plainEntry(Section $section, ?string $title = null, array $fieldValues = [], ?Site $site = null): Entry
    {
        $site ??= Craft::$app->getSites()->getPrimarySite();
        $entryType = Craft::$app->getEntries()->getEntryTypesBySectionId($section->id)[0] ?? null;

        if (!$entryType) {
            throw new RuntimeException("Section `{$section->handle}` has no entry types.");
        }

        $slug = self::handle('hyper-plain-entry');
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'siteId' => $site->id,
            'title' => $title ?? StringHelper::titleize($slug),
            'slug' => $slug,
            'postDate' => new DateTime(),
            'enabled' => true,
        ]);

        foreach ($fieldValues as $handle => $value) {
            $entry->setFieldValue($handle, $value);
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException('Failed creating plain entry: ' . json_encode($entry->getErrors()));
        }

        return $entry;
    }

    /**
     * @param array<int, array<string, mixed>> $linkPayloads
     */
    public static function entryWithLinks(Section $section, array $linkPayloads, ?string $title = null, ?Site $site = null): Entry
    {
        return self::entryWithLinkPayloads($section, $linkPayloads, $title, $site);
    }

    /**
     * @param array<int, array<string, mixed>> $linkPayloads
     */
    public static function entryWithLinkPayloads(Section $section, array $linkPayloads, ?string $title = null, ?Site $site = null): Entry
    {
        $site ??= Craft::$app->getSites()->getPrimarySite();
        $entryType = Craft::$app->getEntries()->getEntryTypesBySectionId($section->id)[0] ?? null;
        $field = self::hyperFieldOnSection($section);

        if (!$entryType || !$field) {
            throw new RuntimeException("Section `{$section->handle}` is missing entry type or Hyper field.");
        }

        $slug = self::handle('hyper-test-entry');
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'siteId' => $site->id,
            'title' => $title ?? StringHelper::titleize($slug),
            'slug' => $slug,
            'postDate' => new DateTime(),
            'enabled' => true,
        ]);
        $entry->setFieldValue($field->handle, self::normalizeLinkPayloads($linkPayloads, $field));

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException('Failed creating entry with Hyper links: ' . json_encode($entry->getErrors()));
        }

        return $entry;
    }

    public static function urlLinkPayload(string $url, ?string $text = null, ?string $handle = null): array
    {
        return array_filter([
            'type' => Url::class,
            'handle' => $handle ?? Url::typeKey(),
            'linkValue' => $url,
            'linkText' => $text,
            'newWindow' => false,
        ], static fn(mixed $value): bool => $value !== null);
    }

    public static function entryLinkPayload(Entry $target, ?string $text = null, ?string $handle = null): array
    {
        return array_filter([
            'type' => EntryLink::class,
            'handle' => $handle ?? EntryLink::typeKey(),
            'linkValue' => [$target->id],
            'linkText' => $text ?? $target->title,
            'newWindow' => false,
        ], static fn(mixed $value): bool => $value !== null);
    }

    public static function siteLinkPayload(Site $site, ?string $text = null, ?string $handle = null): array
    {
        return array_filter([
            'type' => SiteLink::class,
            'handle' => $handle ?? SiteLink::typeKey(),
            'linkValue' => $site->uid,
            'linkText' => $text ?? $site->name,
            'newWindow' => false,
        ], static fn(mixed $value): bool => $value !== null);
    }

    public static function passiveLinkPayload(?string $text = null, ?string $handle = null): array
    {
        // Omit newWindow — a boolean false is still "meaningful" for empty checks (#146 / #108).
        return array_filter([
            'type' => Passive::class,
            'handle' => $handle ?? Passive::typeKey(),
            'linkText' => $text,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param list<array<string, mixed>> $linkTypeConfigs
     */
    public static function hyperFieldWithLinkTypes(array $linkTypeConfigs, array $options = []): HyperField
    {
        $handle = $options['handle'] ?? self::handle('hyperTestLinks');
        $defaultLinkType = $options['defaultLinkType'] ?? ($linkTypeConfigs[0]['handle'] ?? null);

        $field = new HyperField([
            'name' => StringHelper::titleize($handle),
            'handle' => $handle,
            'multipleLinks' => $options['multipleLinks'] ?? false,
            'linkTypes' => $linkTypeConfigs,
            'defaultLinkType' => $defaultLinkType,
            'translationMethod' => $options['translationMethod'] ?? Field::TRANSLATION_METHOD_NONE,
        ]);

        if (!Craft::$app->fields->saveField($field)) {
            throw new RuntimeException('Failed creating Hyper field fixture: ' . json_encode($field->getErrors()));
        }

        $savedField = Craft::$app->fields->getFieldByHandle($handle);

        if (!$savedField instanceof HyperField) {
            throw new RuntimeException("Hyper field fixture `{$handle}` could not be reloaded.");
        }

        return $savedField;
    }

    /**
     * @return Entry[]
     */
    public static function entryTargets(int $count, ?Section $section = null, ?Site $site = null): array
    {
        $section ??= self::entrySection(self::hyperField());
        $entries = [];

        for ($i = 1; $i <= $count; $i++) {
            $entries[] = self::entryWithLinkPayloads(
                $section,
                [self::urlLinkPayload("https://example.test/target-{$i}", "Target {$i}")],
                sprintf('Hyper Target %03d', $i),
                $site,
            );
        }

        return $entries;
    }

    /**
     * @return Site[]
     */
    public static function ensureSites(int $count = 2): array
    {
        $sites = Craft::$app->getSites()->getAllSites();

        if (count($sites) >= $count) {
            return array_slice($sites, 0, $count);
        }

        $group = Craft::$app->getSites()->getAllGroups()[0] ?? null;

        if (!$group) {
            throw new RuntimeException('No site group available for multisite fixtures.');
        }

        while (count($sites) < $count) {
            $index = count($sites) + 1;
            $handle = self::handle('hyperTestSite');

            $site = new Site([
                'name' => 'Hyper Test Site ' . $index,
                'handle' => $handle,
                'groupId' => $group->id,
                'language' => 'en-US',
                'hasUrls' => true,
                'baseUrl' => "https://{$handle}.test",
            ]);

            if (!Craft::$app->getSites()->saveSite($site)) {
                throw new RuntimeException('Failed creating multisite fixture: ' . json_encode($site->getErrors()));
            }

            $sites = Craft::$app->getSites()->getAllSites();
        }

        return array_slice($sites, 0, $count);
    }

    /**
     * @return Entry[]
     */
    public static function entries(int $count, ?Section $section = null): array
    {
        $section ??= self::entrySection(self::hyperField());
        $entries = [];

        for ($i = 1; $i <= $count; $i++) {
            $entries[] = self::entryWithLinks(
                $section,
                [self::urlLinkPayload("https://example.test/page-{$i}", "Link {$i}")],
                sprintf('Hyper Test Entry %02d', $i),
            );
        }

        return $entries;
    }

    private static function hyperFieldOnSection(Section $section): ?HyperField
    {
        $entryType = Craft::$app->getEntries()->getEntryTypesBySectionId($section->id)[0] ?? null;
        $layout = $entryType?->getFieldLayout();

        if (!$layout) {
            return null;
        }

        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof HyperField) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $linkPayloads
     */
    private static function normalizeLinkPayloads(array $linkPayloads, HyperField $field): LinkCollection
    {
        return $field->normalizeValue($linkPayloads);
    }
}
