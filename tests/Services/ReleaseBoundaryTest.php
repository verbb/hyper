<?php

use craft\elements\Entry;
use craft\models\ReadOnlyProjectConfigData;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use Tests\Support\Performance\QueryProfiler;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\services\LinkTypeConfigs;
use verbb\hyper\migrations\m261001_000000_default_link_type_config;

it('creates the default config when an earlier beta has the same schema version but no configs', function() {
    $pc = Craft::$app->getProjectConfig();
    $path = LinkTypeConfigs::PROJECT_CONFIG_PATH;
    $original = $pc->get($path);
    $external = new ReflectionProperty($pc, '_externalConfig');
    $savedExternal = $external->getValue($pc);
    try {
        $pc->set($path, []);
        $external->setValue($pc, new ReadOnlyProjectConfigData(['plugins' => ['hyper' => ['schemaVersion' => '1.5.1']]]));
        expect((new m261001_000000_default_link_type_config())->safeUp())->toBeTrue();
        expect($pc->get($path))->not->toBeEmpty();
    } finally {
        $external->setValue($pc, $savedExternal);
        $pc->set($path, $original);
    }
});

it('waits for incoming default config without creating a competing identity', function() {
    $pc = Craft::$app->getProjectConfig();
    $path = LinkTypeConfigs::PROJECT_CONFIG_PATH;
    $original = $pc->get($path);
    $uid = craft\helpers\StringHelper::UUID();
    $incoming = [$uid => ['name' => 'Default', 'handle' => 'default', 'linkTypes' => [F::linkTypeConfig(Url::class)]]];
    $external = new ReflectionProperty($pc, '_externalConfig');
    $savedExternal = $external->getValue($pc);
    try {
        $pc->set($path, []);
        $external->setValue($pc, new ReadOnlyProjectConfigData(['plugins' => ['hyper' => ['schemaVersion' => '1.5.1', 'linkTypeConfigs' => $incoming]]]));
        expect((new m261001_000000_default_link_type_config())->safeUp())->toBeTrue();
        expect($pc->get($path))->toBe([]);
        $pc->set($path, $incoming);
        expect(array_keys($pc->get($path)))->toBe([$uid]);
    } finally {
        $external->setValue($pc, $savedExternal);
        $pc->set($path, $original);
    }
});

it('dispatches Craft element methods and attached behavior methods on links', function() {
    $link = new Url();
    $link->attachBehavior('example', new class extends yii\base\Behavior {
        public function greeting(string $name, string $suffix): string { return $name . $suffix; }
    });
    expect($link->greeting('Editor', '!'))->toBe('Editor!');
    $caption = new craft\fields\PlainText(['name' => 'Caption', 'handle' => F::handle('hyperCaption')]);
    expect(Craft::$app->fields->saveField($caption))->toBeTrue();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new craft\fieldlayoutelements\CustomField($caption)]);
    $link->setFieldLayout($layout);
    expect($link->{'isFieldEmpty:' . $caption->handle}())->toBeTrue();
    $link->setFieldValue($caption->handle, 'Caption');
    expect($link->{'isFieldEmpty:' . $caption->handle}())->toBeFalse();
});

it('does not expose protected link methods through magic calls', function() {
    $link = new class extends Url {
        protected function exampleSecret(): string { return 'private'; }
    };
    expect(fn() => $link->exampleSecret())->toThrow(yii\base\UnknownMethodException::class);
});

it('keeps destination batching available while rendering an error page', function() {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $ownerIds = [];
    foreach (range(1, 4) as $i) {
        $target = F::plainEntry($section, 'Target ' . $i);
        $ownerIds[] = F::entryWithLinks($section, [F::entryLinkPayload($target)])->id;
    }
    $original = Craft::$app->getResponse();
    try {
        $counts = [];
        foreach ([200, 404] as $status) {
            Craft::$app->set('response', new yii\web\Response(['statusCode' => $status]));
            Hyper::$plugin->getLinkRelations()->resetRequestState();
            $profile = QueryProfiler::profile(function() use ($ownerIds, $field) {
                return array_map(fn($owner) => $owner->getFieldValue($field->handle)->getUrl(), Entry::find()->id($ownerIds)->all());
            });
            expect($profile['resultSize'])->toBe(4);
            $counts[$status] = $profile['queries'];
        }
        expect($counts[404])->toBeLessThanOrEqual($counts[200]);
    } finally {
        Craft::$app->set('response', $original);
    }
});

it('refuses renaming a saved link type handle without losing authored links', function(bool $shared) {
    $type = F::linkTypeConfig(new Url(['handle' => 'promotion', 'isCustom' => true]));
    $config = null;
    if ($shared) {
        $config = new verbb\hyper\models\LinkTypeConfig(['name' => 'Promotion links', 'handle' => F::handle('promotionConfig'), 'linkTypes' => [$type]]);
        expect(Hyper::$plugin->linkTypeConfigs->saveConfig($config))->toBeTrue();
        $field = F::hyperField();
        $field->useLinkTypeConfig($config->handle);
        expect(Craft::$app->fields->saveField($field))->toBeTrue();
    } else {
        $field = F::hyperFieldWithLinkTypes([$type]);
    }
    try {
        $owner = F::plainEntry(F::entrySection($field), 'Promotion owner', [$field->handle => [[
            'linkTypeHandle' => 'promotion', 'linkValue' => 'https://example.test/offer', 'linkText' => 'Offer',
        ]]]);
        $type['handle'] = 'renamedPromotion';
        if ($shared) {
            $config->linkTypes = [$type];
            $saved = Hyper::$plugin->linkTypeConfigs->saveConfig($config);
        } else {
            $field->setLinkTypes([$type]);
            $saved = Craft::$app->fields->saveField($field);
        }
        Craft::$app->fields->refreshFields();
        // Read persisted content with the saved settings, without Craft's cached layout field.
        $content = craft\helpers\Json::decode((new craft\db\Query())->select('content')->from('{{%elements_sites}}')
            ->where(['elementId' => $owner->id, 'siteId' => $owner->siteId])->scalar());
        $placementUid = $owner->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
        $freshField = Craft::$app->fields->getFieldById($field->id);
        $links = $freshField->normalizeValue($content[$placementUid], $owner);
        expect($links->getUrl())->toBe('https://example.test/offer');
        expect($saved)->toBeFalse();
        expect(($shared ? $config : $field)->getErrors('linkTypes'))->not->toBeEmpty();
        $type['handle'] = 'promotion';
        $type['label'] = 'Updated label';
        if ($shared) {
            $config->linkTypes = [$type];
            expect(Hyper::$plugin->linkTypeConfigs->saveConfig($config))->toBeTrue();
        } else {
            $field->setLinkTypes([$type]);
            expect(Craft::$app->fields->saveField($field))->toBeTrue();
        }
    } finally {
        Craft::$app->fields->deleteField($field);
        if ($config) {
            Hyper::$plugin->linkTypeConfigs->deleteConfig($config->uid);
        }
    }
})->with([false, true]);

it('applies an incoming config and its referencing field with administrative changes disabled', function() {
    $pc = Craft::$app->projectConfig;
    $templateField = F::hyperField(['linkTypes' => [Url::class]]);
    $original = $pc->get();
    $configUid = craft\helpers\StringHelper::UUID();
    $fieldUid = craft\helpers\StringHelper::UUID();
    $fieldConfig = $pc->get('fields.' . $templateField->uid);
    $fieldConfig['handle'] = F::handle('hyperTestIncoming');
    $fieldConfig['name'] = 'Incoming shared links';
    $fieldConfig['settings']['linkTypeConfig'] = $configUid;
    $fieldConfig['settings']['linkTypes'] = [];
    $incoming = $original;
    $incoming['plugins']['hyper']['linkTypeConfigs'][$configUid] = [
        'name' => 'Incoming links', 'handle' => 'incomingLinks', 'linkTypes' => [F::linkTypeConfig(Url::class)],
    ];
    $incoming['fields'][$fieldUid] = $fieldConfig;
    $external = new ReflectionProperty($pc, '_externalConfig');
    $savedExternal = $external->getValue($pc);
    $applying = new ReflectionProperty($pc, '_applyingExternalChanges');
    $savedApplying = $applying->getValue($pc);
    $general = Craft::$app->config->general;
    $allowAdminChanges = $general->allowAdminChanges;
    $readOnly = $pc->readOnly;
    try {
        $external->setValue($pc, new ReadOnlyProjectConfigData($incoming));
        $general->allowAdminChanges = false;
        $pc->readOnly = true;
        expect((new m261001_000000_default_link_type_config())->safeUp())->toBeTrue();
        $pc->applyConfigChanges($incoming);
        Craft::$app->fields->refreshFields();
        $field = Craft::$app->fields->getFieldByUid($fieldUid);
        expect($field)->toBeInstanceOf(verbb\hyper\fields\HyperField::class);
        expect($field->getSettings()['linkTypeConfig'])->toBe($configUid);
        expect($field->normalizeValue([F::urlLinkPayload('https://example.test/deployed')])->getUrl())->toBe('https://example.test/deployed');
        expect(Hyper::$plugin->linkTypeConfigs->getConfigByUid($configUid)->canDelete())->toBeFalse();
        Hyper::$plugin->linkTypeConfigs->deleteConfig($configUid);
        expect($pc->get(LinkTypeConfigs::PROJECT_CONFIG_PATH . '.' . $configUid))->not->toBeNull();
    } finally {
        $general->allowAdminChanges = $allowAdminChanges;
        $pc->readOnly = $readOnly;
        $external->setValue($pc, new ReadOnlyProjectConfigData($original));
        $pc->applyConfigChanges($original);
        $external->setValue($pc, $savedExternal);
        $applying->setValue($pc, $savedApplying);
        Craft::$app->fields->refreshFields();
    }
});


it('keeps a saved custom handle read-only while allowing the initial handle to be chosen', function() {
    $view = Craft::$app->view;
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);
    try {
        foreach ([false, true] as $isNew) {
            $link = new Url(['handle' => 'promotion', 'label' => 'Promotion', 'isCustom' => true, 'isNew' => $isNew]);
            $html = $view->renderTemplate('hyper/field/_link-type-settings', [
                'field' => new verbb\hyper\fields\HyperField(), 'linkType' => $link, 'isCustom' => true,
            ]);
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $input = (new DOMXPath($dom))->query('//input[@data-handle-field]')->item(0);
            expect($input)->not->toBeNull();
            expect($input->hasAttribute('readonly'))->toBe(!$isNew);
        }
    } finally {
        $view->setTemplateMode($mode);
    }
});
