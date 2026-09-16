<?php

use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\helpers\Html;
use craft\helpers\ProjectConfig as PC;
use craft\models\FieldLayout;
use Tests\Support\CpActionRequest;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use verbb\hyper\models\LinkTypeConfig;
use verbb\hyper\services\LinkTypeConfigs;

function layoutValidationConfig(): array
{
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $elements = $tab->getElements();
    foreach (['alphaSummary', 'betaSummary'] as $alias) {
        $custom = new PlainText(['name' => $alias, 'handle' => F::handle('hyperLayout')]);
        expect(Craft::$app->fields->saveField($custom))->toBeTrue();
        $elements[] = new CustomField($custom, ['handle' => $alias]);
    }
    $tab->setElements($elements);
    $url = new Url();
    $url->setFieldLayout($layout);
    return F::linkTypeConfig($url);
}

function layoutValidationAliases(array $config, array $aliases): array
{
    $index = 0;
    foreach ($config['layoutConfig']['tabs'] as &$tab) {
        foreach ($tab['elements'] as &$element) {
            if (isset($element['fieldUid'])) {
                $element['handle'] = $aliases[$index++];
            }
        }
        unset($element);
    }
    unset($tab);
    return $config;
}

function persistedLayoutAliases(string $uid): array
{
    return array_map(fn($field) => $field->handle, (new \craft\services\Fields())->getLayoutByUid($uid)->getCustomFields());
}

it('rejects invalid submitted layouts without changing settings and accepts a corrected retry', function(string $mode) {
    $config = layoutValidationConfig();
    $isField = str_starts_with($mode, 'field');
    if ($isField) {
        $model = F::hyperFieldWithLinkTypes([$config]);
        if ($mode === 'field-local') {
            $model->context = 'matrixBlockType:' . $model->uid;
            expect(Craft::$app->fields->saveField($model))->toBeTrue();
        }
        $config = $model->getLinkTypes()[0]->getSettingsConfigForDb();
        $path = 'fields.' . $model->uid;
    } else {
        $model = new LinkTypeConfig(['name' => 'Validated layout', 'handle' => F::handle('layoutConfig'), 'linkTypes' => [$config]]);
        expect(Hyper::$plugin->linkTypeConfigs->saveConfig($model))->toBeTrue();
        $config = $model->linkTypes[0];
        $path = LinkTypeConfigs::PROJECT_CONFIG_PATH . '.' . $model->uid;
    }
    $uid = $config['layoutUid'];
    $before = Craft::$app->projectConfig->get($path);
    try {
        foreach ([false, true] as $valid) {
            $aliases = $valid ? ['gammaSummary', 'deltaSummary'] : ['sameSummary', 'sameSummary'];
            $submitted = layoutValidationAliases($config, $aliases);
            expect(FieldLayout::createFromConfig($submitted['layoutConfig'])->validate())->toBe($valid);
            if (str_ends_with($mode, '-cp')) {
                if ($isField) {
                    $settings = $model->getSettings();
                    $settings['linkTypes'] = [$submitted];
                    $response = CpActionRequest::run('save-field', User::find()->admin()->one(), [
                        'type' => $model::class, 'fieldId' => $model->id, 'name' => $model->name,
                        'handle' => $model->handle, 'translationMethod' => $model->translationMethod,
                        'types' => [Html::id($model::class) => $settings],
                    ], controllerClass: \craft\controllers\FieldsController::class);
                } else {
                    $response = CpActionRequest::run('save-link-type-config', User::find()->admin()->one(), [
                        'uid' => $model->uid, 'name' => $model->name, 'handle' => $model->handle,
                        'linkTypes' => [$submitted],
                    ], controllerClass: \verbb\hyper\controllers\PluginController::class);
                }
                expect($response->statusCode)->toBe($valid ? 200 : 400);
                if (!$valid) {
                    expect($response->data['errors']['linkTypes'] ?? [])->not->toBeEmpty();
                }
            } else {
                $model->clearErrors();
                if ($isField) {
                    $model->setLinkTypes([$submitted]);
                    $saved = Craft::$app->fields->saveField($model);
                } else {
                    $model->linkTypes = [$submitted];
                    $saved = Hyper::$plugin->linkTypeConfigs->saveConfig($model);
                }
                expect($saved)->toBe($valid);
                if (!$valid) {
                    expect($model->getErrors('linkTypes'))->not->toBeEmpty();
                }
            }
            expect(persistedLayoutAliases($uid))->toBe($valid ? $aliases : ['alphaSummary', 'betaSummary']);
            if (!$valid) {
                expect(Craft::$app->projectConfig->get($path))->toBe($before);
            }
            if ($isField) {
                $fresh = (new \craft\services\Fields())->getFieldById($model->id);
                $freshLayout = FieldLayout::createFromConfig($fresh->getLinkTypes()[0]->layoutConfig);
                expect(array_map(fn($field) => $field->handle, $freshLayout->getCustomFields()))->toBe($valid ? $aliases : ['alphaSummary', 'betaSummary']);
            }
        }
    } finally {
        if (!$isField) {
            Hyper::$plugin->linkTypeConfigs->deleteConfig($model->uid);
        }
    }
})->with(['field-direct', 'field-local', 'shared-direct', 'field-cp', 'shared-cp']);

it('rejects invalid layout project config before changing field settings', function() {
    $field = F::hyperFieldWithLinkTypes([layoutValidationConfig()]);
    $path = 'fields.' . $field->uid;
    $before = Craft::$app->projectConfig->get($path);
    $config = PC::unpackAssociativeArrays($before);
    $link = $config['settings']['linkTypes'][0];
    $config['settings']['linkTypes'][0] = layoutValidationAliases($link, ['sameSummary', 'sameSummary']);
    $config['settings'] = PC::packAssociativeArrays($config['settings']);
    expect(fn() => Craft::$app->projectConfig->set($path, $config))->toThrow(\yii\base\InvalidConfigException::class);
    expect(Craft::$app->projectConfig->get($path))->toBe($before);
    $fresh = (new \craft\services\Fields())->getFieldById($field->id);
    expect($fresh->getLinkTypes()[0]->layoutConfig)->toEqual($link['layoutConfig']);
    expect(persistedLayoutAliases($link['layoutUid']))->toBe(['alphaSummary', 'betaSummary']);
});

it('validates all layouts before persisting any of a batch', function() {
    $first = F::hyperFieldWithLinkTypes([layoutValidationConfig()])->getLinkTypes()[0]->getSettingsConfigForDb();
    $second = F::hyperFieldWithLinkTypes([layoutValidationConfig()])->getLinkTypes()[0]->getSettingsConfigForDb();
    expect(fn() => Hyper::$plugin->service->saveField([
        layoutValidationAliases($first, ['gammaSummary', 'deltaSummary']),
        layoutValidationAliases($second, ['sameSummary', 'sameSummary']),
    ]))->toThrow(\yii\base\InvalidConfigException::class);
    foreach ([$first, $second] as $config) {
        expect(persistedLayoutAliases($config['layoutUid']))->toBe(['alphaSummary', 'betaSummary']);
    }
});


it('rolls back earlier layouts if a host save hook rejects a later layout', function() {
    $first = F::hyperFieldWithLinkTypes([layoutValidationConfig()])->getLinkTypes()[0]->getSettingsConfigForDb();
    $second = F::hyperFieldWithLinkTypes([layoutValidationConfig()])->getLinkTypes()[0]->getSettingsConfigForDb();
    $reject = function(\craft\events\FieldLayoutEvent $event) use ($second) {
        if ($event->layout->uid === $second['layoutUid']) {
            $invalid = layoutValidationAliases($second, ['sameSummary', 'sameSummary']);
            $event->layout->setTabs(FieldLayout::createFromConfig($invalid['layoutConfig'])->getTabs());
        }
    };
    \yii\base\Event::on(\craft\services\Fields::class, \craft\services\Fields::EVENT_BEFORE_SAVE_FIELD_LAYOUT, $reject);
    try {
        expect(fn() => Hyper::$plugin->service->saveField([
            layoutValidationAliases($first, ['gammaSummary', 'deltaSummary']),
            layoutValidationAliases($second, ['gammaSummary', 'deltaSummary']),
        ]))->toThrow(\yii\base\InvalidConfigException::class);
        foreach ([$first, $second] as $config) {
            expect(persistedLayoutAliases($config['layoutUid']))->toBe(['alphaSummary', 'betaSummary']);
        }
    } finally {
        \yii\base\Event::off(\craft\services\Fields::class, \craft\services\Fields::EVENT_BEFORE_SAVE_FIELD_LAYOUT, $reject);
    }
});

it('preserves non-global layouts when the owning field settings fail validation', function(string $invalid) {
    $field = F::hyperFieldWithLinkTypes([layoutValidationConfig()]);
    $field->context = 'matrixBlockType:' . $field->uid;
    expect(Craft::$app->fields->saveField($field))->toBeTrue();
    $config = $field->getLinkTypes()[0]->getSettingsConfigForDb();
    $changed = layoutValidationAliases($config, ['gammaSummary', 'deltaSummary']);
    if ($invalid === 'disabled') {
        $changed['enabled'] = false;
    } else {
        $field->maxLinks = -1;
    }
    $field->setLinkTypes([$changed]);
    expect(Craft::$app->fields->saveField($field))->toBeFalse();
    expect($field->getErrors())->not->toBeEmpty();
    expect(persistedLayoutAliases($config['layoutUid']))->toBe(['alphaSummary', 'betaSummary']);
})->with(['disabled', 'negative maximum']);
