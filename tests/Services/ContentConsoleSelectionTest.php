<?php

use craft\db\Query;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\console\controllers\ContentController;

it('explicit empty console owner selections do not rebuild unrelated relations', function(string $selection, string $action) {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Target');
    $owner = F::entryWithLinks($section, [F::entryLinkPayload($target)]);
    $where = ['ownerId' => $owner->id, 'fieldId' => $field->id];
    Craft::$app->db->createCommand()->delete('{{%hyper_links}}', $where)->execute();

    $controller = new class('content', verbb\hyper\Hyper::$plugin) extends ContentController {
        public string $output = '';
        public function stdout($string) { $this->output .= $string; }
    };
    $controller->field = $field->handle;
    $controller->elementIds = $selection;
    $controller->dryRun = false;
    $controller->includeNested = false;

    expect($controller->$action())->toBe(0);
    expect((int)(new Query())->from('{{%hyper_links}}')->where($where)->count())->toBe(0);
    expect($controller->output)->toContain($action === 'actionModify' ? 'Matched: 0' : 'Owners reconciled: 0');
})->with(['empty' => '', 'zero' => '0', 'whitespace' => ' '])->with(['actionModify', 'actionSyncRelations']);


it('console relation rebuilding still supports selected and omitted owner filters', function(bool $selected) {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Target');
    $owner = F::entryWithLinks($section, [F::entryLinkPayload($target)]);
    $other = F::entryWithLinks($section, [F::entryLinkPayload($target)]);
    Craft::$app->db->createCommand()->delete('{{%hyper_links}}', ['fieldId' => $field->id])->execute();

    $controller = new class('content', verbb\hyper\Hyper::$plugin) extends ContentController {
        public function stdout($string) {}
    };
    $controller->field = $field->handle;
    $controller->elementIds = $selected ? (string)$owner->id : null;
    $controller->dryRun = false;
    $controller->includeNested = false;
    expect($controller->actionSyncRelations())->toBe(0);
    $owners = (new Query())->select('ownerId')->from('{{%hyper_links}}')->where(['fieldId' => $field->id])->column();
    expect(array_map('intval', $owners))->toEqualCanonicalizing($selected ? [$owner->id] : [$owner->id, $other->id]);
})->with([true, false]);
