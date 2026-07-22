/**
 * Seed Hyper field + entries for docs screenshots (promo-style CP crops).
 *
 * Echoes JSON: fieldId, settingsRoute, entryEditRoute, urlHandle, entryHandle.
 * Note: no opening PHP tag — @verbb/docs-screenshots injects this into a bootstrap.
 */

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\db\Table;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Asset;
use verbb\hyper\links\Category;
use verbb\hyper\links\Custom;
use verbb\hyper\links\Email;
use verbb\hyper\links\Embed;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Passive;
use verbb\hyper\links\Phone;
use verbb\hyper\links\Site as SiteLink;
use verbb\hyper\links\Url;
use verbb\hyper\links\User;

const DOCS_FIELD_HANDLE = 'docsScreenshotHyperDemo';
const DOCS_SECTION_HANDLE = 'docsScreenshotHyper';
const DOCS_PAGES_SECTION_HANDLE = 'docsScreenshotPages';
const DOCS_SUBTITLE_HANDLE = 'docsScreenshotSubtitle';
const DOCS_ENTRY_HANDLE = 'docs-screenshot-entry';
const DOCS_ABOUT_HANDLE = 'docs-screenshot-about-us';

function docsScreenshotSite(): Site
{
    return Craft::$app->getSites()->getPrimarySite();
}

function docsScreenshotAdminPath(): string
{
    return Craft::$app->getConfig()->getGeneral()->cpTrigger ?: 'admin';
}

function docsScreenshotSection(string $handle, string $name): Section
{
    $entriesService = Craft::$app->getEntries();
    $section = $entriesService->getSectionByHandle($handle);
    $site = docsScreenshotSite();

    if (!$section) {
        $entryType = new EntryType([
            'name' => $name,
            'handle' => $handle . 'Type',
            'hasTitleField' => true,
        ]);

        if (!$entriesService->saveEntryType($entryType)) {
            throw new RuntimeException('Unable to save entry type: ' . Json::encode($entryType->getErrors()));
        }

        $section = new Section([
            'name' => $name,
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
        ]);
        $section->setEntryTypes([$entryType]);
        $section->setSiteSettings([
            new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => true,
                'uriFormat' => $handle . '/{slug}',
                'template' => '_docs-screenshot/entry',
            ]),
        ]);

        if (!$entriesService->saveSection($section)) {
            throw new RuntimeException('Unable to save section: ' . Json::encode($section->getErrors()));
        }

        $section = $entriesService->getSectionByHandle($handle);
    }

    if (!$section) {
        throw new RuntimeException("Section `{$handle}` could not be reloaded.");
    }

    return $section;
}

function docsScreenshotSubtitleField(): PlainText
{
    $fields = Craft::$app->getFields();
    $field = $fields->getFieldByHandle(DOCS_SUBTITLE_HANDLE);

    if ($field instanceof PlainText) {
        return $field;
    }

    $field = new PlainText([
        'name' => 'Subtitle',
        'handle' => DOCS_SUBTITLE_HANDLE,
        'instructions' => '',
    ]);

    if (!$fields->saveField($field)) {
        throw new RuntimeException('Unable to save Subtitle field: ' . Json::encode($field->getErrors()));
    }

    $saved = $fields->getFieldByHandle(DOCS_SUBTITLE_HANDLE);

    if (!$saved instanceof PlainText) {
        throw new RuntimeException('Subtitle field could not be reloaded.');
    }

    return $saved;
}

/**
 * Default Content + Advanced layout, optionally appending a Craft field to Content.
 */
function docsScreenshotLayoutConfig(?PlainText $extraField = null): array
{
    $layout = Url::getDefaultFieldLayout();

    if ($extraField) {
        $tabs = $layout->getTabs();
        $contentTab = $tabs[0] ?? null;

        if ($contentTab instanceof FieldLayoutTab) {
            $elements = $contentTab->getElements();
            $elements[] = new CustomField($extraField, ['width' => 100]);
            $contentTab->setElements($elements);
            $layout->setTabs($tabs);
        }
    }

    return $layout->getConfig();
}

function docsScreenshotLinkTypeConfig(
    string $type,
    string $label,
    string $handle,
    bool $enabled,
    ?array $layoutConfig = null,
    array $extra = [],
): array {
    $link = \verbb\hyper\Hyper::$plugin->getLinks()->createSettingsPrototype(array_merge([
        'type' => $type,
        'label' => $label,
        'handle' => $handle,
        'enabled' => $enabled,
        'layoutUid' => StringHelper::UUID(),
        'layoutConfig' => $layoutConfig ?? docsScreenshotLayoutConfig(),
        'isCustom' => !str_starts_with($handle, 'default-'),
    ], $extra));

    return $link->getSettingsConfigForDb();
}

function docsScreenshotHyperField(PlainText $subtitleField): HyperField
{
    $fields = Craft::$app->getFields();
    $existing = $fields->getFieldByHandle(DOCS_FIELD_HANDLE);

    if ($existing instanceof HyperField) {
        // Re-save so layout/content stay aligned with this seed script.
        $field = $existing;
    } else {
        $field = new HyperField([
            'name' => 'Hyper Demo',
            'handle' => DOCS_FIELD_HANDLE,
        ]);
    }

    $urlHandle = Url::typeKey();
    $entryHandle = EntryLink::typeKey();
    $defaultLayout = docsScreenshotLayoutConfig();
    $entryLayout = docsScreenshotLayoutConfig($subtitleField);

    // Match the classic promo sidebar order / enablement as closely as current types allow.
    $linkTypes = [
        docsScreenshotLinkTypeConfig(Asset::class, 'Asset', Asset::typeKey(), false),
        docsScreenshotLinkTypeConfig(Category::class, 'Category', Category::typeKey(), true),
        docsScreenshotLinkTypeConfig(Custom::class, 'Custom', Custom::typeKey(), false),
        docsScreenshotLinkTypeConfig(Email::class, 'Email', Email::typeKey(), false),
        docsScreenshotLinkTypeConfig(Embed::class, 'Embed', Embed::typeKey(), false),
        // Keep Entry enabled so the multi-link input screenshot can show an Entry row.
        docsScreenshotLinkTypeConfig(EntryLink::class, 'Entry', $entryHandle, true, $entryLayout, [
            'sources' => '*',
        ]),
        docsScreenshotLinkTypeConfig(EntryLink::class, 'Blog Entries', 'blog-entries', true, $entryLayout, [
            'sources' => '*',
            'isCustom' => true,
        ]),
        docsScreenshotLinkTypeConfig(EntryLink::class, 'Featured Content', 'featured-content', true, $entryLayout, [
            'sources' => '*',
            'isCustom' => true,
        ]),
        docsScreenshotLinkTypeConfig(Url::class, 'URL', $urlHandle, true, $defaultLayout),
        docsScreenshotLinkTypeConfig(User::class, 'User', User::typeKey(), false),
        // Keep remaining registered types present but off so settings UI does not re-append them as enabled.
        docsScreenshotLinkTypeConfig(Passive::class, 'Passive', Passive::typeKey(), false),
        docsScreenshotLinkTypeConfig(Phone::class, 'Phone', Phone::typeKey(), false),
        docsScreenshotLinkTypeConfig(SiteLink::class, 'Site', SiteLink::typeKey(), false),
    ];

    $field->name = 'Hyper Demo';
    $field->multipleLinks = true;
    $field->newWindow = true;
    $field->defaultLinkType = $urlHandle;
    $field->defaultNewWindow = false;
    $field->viewMode = 'blocks';
    $field->setLinkTypes($linkTypes);

    if (!$fields->saveField($field)) {
        throw new RuntimeException('Unable to save Hyper field: ' . Json::encode($field->getErrors()));
    }

    $saved = $fields->getFieldByHandle(DOCS_FIELD_HANDLE);

    if (!$saved instanceof HyperField) {
        throw new RuntimeException('Hyper field could not be reloaded.');
    }

    return $saved;
}

function docsScreenshotAttachField(Section $section, HyperField $field): void
{
    $entriesService = Craft::$app->getEntries();
    $entryType = $entriesService->getEntryTypesBySectionId($section->id)[0] ?? null;

    if (!$entryType) {
        throw new RuntimeException("Section `{$section->handle}` has no entry types.");
    }

    $layout = $entryType->getFieldLayout() ?? new FieldLayout(['type' => Entry::class]);
    $tabs = $layout->getTabs();

    if (!$tabs) {
        $tabs = [
            new FieldLayoutTab([
                'name' => Craft::t('app', 'Content'),
                'layout' => $layout,
            ]),
        ];
    }

    $tab = $tabs[0];
    $elements = array_values(array_filter(
        $tab->getElements(),
        static function($element) use ($field) {
            return !($element instanceof CustomField && $element->getField()?->id === $field->id);
        },
    ));
    $elements[] = new CustomField($field);
    $tab->setElements($elements);
    $layout->setTabs($tabs);
    $entryType->setFieldLayout($layout);

    if (!$entriesService->saveEntryType($entryType)) {
        throw new RuntimeException('Unable to attach Hyper field: ' . Json::encode($entryType->getErrors()));
    }
}

function docsScreenshotUpsertEntry(
    Section $section,
    string $slug,
    string $title,
    array $fieldValues = [],
): Entry {
    $site = docsScreenshotSite();
    $entryType = Craft::$app->getEntries()->getEntryTypesBySectionId($section->id)[0] ?? null;

    if (!$entryType) {
        throw new RuntimeException("Section `{$section->handle}` has no entry types.");
    }

    $entry = Entry::find()
        ->sectionId($section->id)
        ->slug($slug)
        ->siteId($site->id)
        ->status(null)
        ->one();

    if (!$entry) {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'siteId' => $site->id,
            'slug' => $slug,
            'enabled' => true,
        ]);
    }

    $entry->title = $title;
    $entry->enabled = true;

    foreach ($fieldValues as $handle => $value) {
        $entry->setFieldValue($handle, $value);
    }

    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException('Unable to save entry: ' . Json::encode($entry->getErrors()));
    }

    // Ensure elements_sites.title is populated for element-select chips.
    if ($title !== '') {
        Db::update(Table::ELEMENTS_SITES, [
            'title' => $title,
        ], [
            'elementId' => $entry->id,
            'siteId' => $site->id,
        ], [], false);
    }

    $reloaded = Entry::find()->id($entry->id)->siteId($site->id)->status(null)->one();

    return $reloaded ?? $entry;
}

$adminPath = docsScreenshotAdminPath();
$subtitleField = docsScreenshotSubtitleField();
$pagesSection = docsScreenshotSection(DOCS_PAGES_SECTION_HANDLE, 'Pages');
$hyperSection = docsScreenshotSection(DOCS_SECTION_HANDLE, 'Hyper');
$field = docsScreenshotHyperField($subtitleField);
docsScreenshotAttachField($hyperSection, $field);

$about = docsScreenshotUpsertEntry($pagesSection, DOCS_ABOUT_HANDLE, 'About Us');

// Element selects key off the title column — force a reload so chips never show "Untitled".
$about = Entry::find()->id($about->id)->siteId(docsScreenshotSite()->id)->status(null)->one() ?? $about;

if ($about->title !== 'About Us') {
    $about->title = 'About Us';

    if (!Craft::$app->getElements()->saveElement($about)) {
        throw new RuntimeException('Unable to retitle About Us entry: ' . Json::encode($about->getErrors()));
    }
}

$urlHandle = Url::typeKey();
$entryHandle = EntryLink::typeKey();

$demo = docsScreenshotUpsertEntry($hyperSection, DOCS_ENTRY_HANDLE, 'Demo', [
    $field->handle => [
        [
            'type' => Url::class,
            'handle' => $urlHandle,
            'linkValue' => 'https://craftcms.com',
            'linkText' => 'Check out Craft CMS',
            'newWindow' => true,
        ],
        [
            'type' => EntryLink::class,
            'handle' => $entryHandle,
            'linkValue' => [$about->id],
            // Leave blank so the CP shows the Link Text placeholder (e.g. Read more).
            'linkText' => '',
            'newWindow' => false,
            'fields' => [
                DOCS_SUBTITLE_HANDLE => "We're a well-oiled machine",
            ],
        ],
    ],
]);

$entryEditUrl = $demo->getCpEditUrl();
$entryEditPath = parse_url((string)$entryEditUrl, PHP_URL_PATH) ?: "/{$adminPath}/entries/{$hyperSection->handle}/{$demo->id}-{$demo->slug}";

echo Json::encode([
    'fieldId' => (int)$field->id,
    'fieldHandle' => $field->handle,
    'settingsRoute' => "/{$adminPath}/settings/fields/edit/{$field->id}",
    'entryEditRoute' => $entryEditPath,
    'urlHandle' => $urlHandle,
    'entryHandle' => $entryHandle,
], JSON_THROW_ON_ERROR);
