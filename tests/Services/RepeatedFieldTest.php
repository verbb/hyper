<?php

use craft\db\Query;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\content\ElementContentStore;
use verbb\hyper\content\ModifyOptions;
use verbb\hyper\migrations\MigrateCraftLinkContent;

function repeatedHyperFixture(): array
{
    $field = F::hyperField();
    $section = F::entrySection($field);
    $type = Craft::$app->entries->getEntryTypesBySectionId($section->id)[0];
    $layout = $type->getFieldLayout();
    $tabs = $layout->getTabs();
    $tabs[0]->setElements([...$tabs[0]->getElements(), new CustomField($field, ['handle' => 'secondHyper'])]);
    $layout->setTabs($tabs);
    $type->setFieldLayout($layout);
    expect(Craft::$app->entries->saveEntryType($type))->toBeTrue();
    $a = F::plainEntry($section, 'A');
    $b = F::plainEntry($section, 'B');
    $owner = F::plainEntry($section, 'Owner', [
        $field->handle => [F::entryLinkPayload($a, 'First')],
        'secondHyper' => [F::entryLinkPayload($b, 'Second')],
    ]);
    return [$field, $owner, $a, $b];
}

function repeatedHyperTargets($field, $owner): array
{
    return array_map('intval', (new Query())->select('targetId')->from('{{%hyper_links}}')
        ->where(['ownerId' => $owner->id, 'ownerSiteId' => $owner->siteId, 'fieldId' => $field->id])
        ->orderBy('sortOrder')->column());
}

it('indexes all repeated placements on saves and retains siblings when one is cleared', function() {
    [$field, $owner, $a, $b] = repeatedHyperFixture();
    expect(repeatedHyperTargets($field, $owner))->toBe([$a->id, $b->id]);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    expect(repeatedHyperTargets($field, $owner))->toBe([$a->id, $b->id]);
    $owner->setFieldValue($field->handle, []);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    expect(repeatedHyperTargets($field, $owner))->toBe([$b->id]);
    $owner->setFieldValue('secondHyper', []);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    expect(repeatedHyperTargets($field, $owner))->toBe([]);
});

it('preserves both repeated occurrences of the same target', function() {
    [$field, $owner, $a] = repeatedHyperFixture();
    $owner->setFieldValue('secondHyper', [F::entryLinkPayload($a)]);
    expect(Craft::$app->elements->saveElement($owner))->toBeTrue();
    expect(repeatedHyperTargets($field, $owner))->toBe([$a->id, $a->id]);
});
