<?php

use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\controllers\FieldsController;
use verbb\hyper\Hyper;
use verbb\hyper\links\Url;
use yii\web\ForbiddenHttpException;

it('hydrates a fresh JSON-owned Matrix entry and rejects unrelated field layouts', function () {
    $fixture = F::matrixFieldWithHyper();
    $matrix = $fixture['matrix'];
    $inner = $fixture['hyperField'];
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($matrix, ['uid' => StringHelper::UUID()])]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $request = new class(['isCpRequest' => true, 'isConsoleRequest' => false]) extends craft\web\Request {
        public function getMethod(): string { return 'POST'; }
    };
    $body = [
        'hyperFieldId' => $field->id,
        'fieldId' => $matrix->id,
        'entryTypeId' => $fixture['blockEntryType']->id,
        'siteId' => Craft::$app->sites->primarySite->id,
        'entryData' => ['id' => 123, 'uid' => 'untrusted-identity', 'fields' => [
            $inner->handle => json_encode([['linkTypeHandle' => 'url', 'linkValue' => 'https://example.test/copied']]),
            'hyperData' => ['authoring-only' => 'discard'],
        ]],
    ];
    $originalRequest = Craft::$app->request;
    $originalUser = Craft::$app->user;
    $identity = craft\elements\User::find()->admin()->one();
    $webUser = new class extends craft\console\User { public string $idParam = '__id'; };
    $webUser->setIdentity($identity);
    Craft::$app->set('request', $request);
    Craft::$app->set('user', $webUser);
    try {
        $controller = new FieldsController('fields', Hyper::$plugin);
        $method = new ReflectionMethod($controller, '_matrixEntryFromRequest');
        $request->setBodyParams($body);
        [$entry, $resolved] = $method->invoke($controller);
        expect($resolved->id)->toBe($matrix->id);
        expect($entry->id)->toBeNull();
        expect($entry->uid)->not->toBe('untrusted-identity');
        expect($entry->getFieldValue($inner->handle)->getUrl())->toBe('https://example.test/copied');
        $other = F::matrixFieldWithHyper();
        $request->setBodyParams([...$body, 'fieldId' => $other['matrix']->id]);
        expect(fn() => $method->invoke($controller))->toThrow(ForbiddenHttpException::class);
        $request->setBodyParams([...$body, 'entryTypeId' => $other['blockEntryType']->id]);
        expect(fn() => $method->invoke($controller))->toThrow(ForbiddenHttpException::class);
    } finally {
        Craft::$app->set('request', $originalRequest);
        Craft::$app->set('user', $originalUser);
    }
});
