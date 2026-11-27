<?php

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use craft\models\Section;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\console\controllers\ContentController;
use verbb\hyper\fields\HyperField;
use verbb\hyper\http\EmbedClient;
use verbb\hyper\Hyper;
use verbb\hyper\links\Embed;
use verbb\hyper\links\Url;

/** Sends every embed request to the local router, through the production Curl transport. */
class RefreshEmbedsRoutedClient extends EmbedClient
{
    public static int $port = 0;
    public static array $requests = [];

    protected function resolveHost(string $host): array
    {
        return ['93.184.215.14'];
    }

    protected function requestPinned(RequestInterface $request, string $ip): ResponseInterface
    {
        self::$requests[] = (string)$request->getUri();
        $local = $request->getUri()->withHost('127.0.0.1')->withPort(self::$port);

        return parent::requestPinned($request->withUri($local), '127.0.0.1');
    }
}

function refreshEmbedsController(): ContentController
{
    return new class('content', Hyper::$plugin) extends ContentController {
        public string $output = '';
        public function stdout($string) { $this->output .= $string; return strlen($string); }
        public function stderr($string) { $this->output .= $string; return strlen($string); }
    };
}

function refreshEmbedsRun(HyperField $field, bool $dryRun, array $settings = []): ContentController
{
    $controller = refreshEmbedsController();
    $controller->field = $field->handle;
    $controller->dryRun = $dryRun;

    foreach ($settings as $name => $value) {
        $controller->$name = $value;
    }

    expect($controller->actionRefreshEmbeds())->toBe(0);

    return $controller;
}

function refreshEmbedsRaw(int $elementId, HyperField $field): array
{
    $entry = Entry::find()->id($elementId)->drafts(null)->revisions(null)->status(null)->one();
    $uid = $entry->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $content = Json::decode((string)(new Query())->select('content')->from(Table::ELEMENTS_SITES)
        ->where(['elementId' => $elementId, 'siteId' => $entry->siteId])->scalar()) ?? [];

    return $content[$uid] ?? [];
}

/** An Embed link whose stored metadata was written without going through Hyper 3's fetch. */
function refreshEmbedsStoredEntry(Section $section, HyperField $field, array $linkValue): Entry
{
    $entry = F::plainEntry($section, 'Embed owner', [$field->handle => [
        ['type' => Embed::class, 'handle' => Embed::typeKey(), 'linkValue' => ['url' => $linkValue['url']]],
    ]]);
    $uid = $entry->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $where = ['elementId' => $entry->id, 'siteId' => $entry->siteId];
    $content = Json::decode((string)(new Query())->select('content')->from(Table::ELEMENTS_SITES)->where($where)->scalar());
    $content[$uid][0]['linkValue'] = $linkValue;
    Craft::$app->getDb()->createCommand()->update(Table::ELEMENTS_SITES, ['content' => new \yii\db\JsonExpression($content)], $where)->execute();

    // The command also refreshes revisions. Removing the one saved above keeps each test's counts to its entry.
    foreach (Entry::find()->revisionOf($entry->id)->status(null)->all() as $revision) {
        Craft::$app->getElements()->deleteElement($revision, true);
    }

    return Entry::find()->id($entry->id)->one();
}

function refreshEmbedsLegacyValue(string $url): array
{
    // Hyper 1 and 2 stored the whole fetch result, code included, without a signature.
    return [
        'url' => $url,
        'title' => 'Legacy title',
        'providerName' => 'Legacy',
        'code' => '<iframe src="https://legacy.test/embed/1"></iframe>',
    ];
}

function refreshEmbedsLink(int $elementId, HyperField $field): Embed
{
    $entry = Entry::find()->id($elementId)->drafts(null)->revisions(null)->status(null)->one();

    return $entry->getFieldValue($field->handle)->getLinks()[0];
}

beforeEach(function() {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if (!$socket) {
        throw new RuntimeException($error);
    }

    RefreshEmbedsRoutedClient::$port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    RefreshEmbedsRoutedClient::$requests = [];
    fclose($socket);

    $GLOBALS['refreshEmbedsServer'] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . RefreshEmbedsRoutedClient::$port, __DIR__ . '/../Support/embed-router.php'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $GLOBALS['refreshEmbedsPipes']);

    for ($i = 0; $i < 50; $i++) {
        if ($s = @fsockopen('127.0.0.1', RefreshEmbedsRoutedClient::$port)) {
            fclose($s);
            break;
        }

        usleep(20000);
    }

    Craft::$container->set(EmbedClient::class, RefreshEmbedsRoutedClient::class);
});

afterEach(function() {
    Craft::$container->clear(EmbedClient::class);

    if (isset($GLOBALS['refreshEmbedsPipes'][0]) && is_resource($GLOBALS['refreshEmbedsPipes'][0])) {
        fclose($GLOBALS['refreshEmbedsPipes'][0]);
    }

    if (is_resource($GLOBALS['refreshEmbedsServer'] ?? null)) {
        proc_terminate($GLOBALS['refreshEmbedsServer']);
        proc_close($GLOBALS['refreshEmbedsServer']);
    }
});

it('lists unsigned embed links in a dry run without fetching or saving', function() {
    $field = F::hyperField(['linkTypes' => [Embed::class]]);
    $section = F::entrySection($field);
    $legacy = refreshEmbedsStoredEntry($section, $field, refreshEmbedsLegacyValue('http://synthetic.test/embed-page'));
    $signed = refreshEmbedsStoredEntry($section, $field, Embed::prepareEmbedData(['url' => 'http://synthetic.test/signed', 'code' => '<p>Signed</p>']));
    $before = [refreshEmbedsRaw($legacy->id, $field), refreshEmbedsRaw($signed->id, $field)];

    $controller = refreshEmbedsRun($field, dryRun: true);

    expect($controller->output)
        ->toContain("Would refresh element {$legacy->id}, site {$legacy->siteId}: http://synthetic.test/embed-page")
        ->not->toContain("element {$signed->id}")
        ->toContain("Embed links checked: 2\nAlready signed: 1\nWould refresh: 1\nFailed: 0");
    expect(RefreshEmbedsRoutedClient::$requests)->toBe([]);
    expect([refreshEmbedsRaw($legacy->id, $field), refreshEmbedsRaw($signed->id, $field)])->toBe($before);
});

it('re-fetches embed links whose stored data is not validly signed', function(Closure $linkValue) {
    $field = F::hyperField(['linkTypes' => [Embed::class]]);
    $entry = refreshEmbedsStoredEntry(F::entrySection($field), $field, $linkValue());
    expect(refreshEmbedsLink($entry->id, $field)->getHtml())->toBeNull();

    $controller = refreshEmbedsRun($field, dryRun: false);

    $link = refreshEmbedsLink($entry->id, $field);
    expect($controller->output)->toContain("Refreshed element {$entry->id}, site {$entry->siteId}: http://synthetic.test/embed-page")
        ->toContain('Refreshed: 1');
    expect($link->hasTrustedEmbedData())->toBeTrue();
    expect($link->getIframeSrc())->toBe('https://player.test/embed/1');
    expect((string)$link->getHtml())->not->toContain('<script');
    expect($link->getLinkText())->toBe('Synthetic video');

    // The stored metadata is exactly what the control panel preview fetches and signs.
    expect(refreshEmbedsRaw($entry->id, $field)[0]['linkValue'])->toEqual(Embed::fetchEmbedData('http://synthetic.test/embed-page'));
})->with([
    'Hyper 2 code' => fn() => refreshEmbedsLegacyValue('http://synthetic.test/embed-page'),
    'URL only' => fn() => ['url' => 'http://synthetic.test/embed-page'],
    'invalid signature' => fn() => ['url' => 'http://synthetic.test/embed-page', 'code' => '<p>Forged</p>', '_hyperEmbedSignature' => 'forged'],
]);

it('refreshes unsigned embed links in drafts and revisions and fetches each URL once', function() {
    $field = F::hyperField(['linkTypes' => [Embed::class]]);
    $entry = refreshEmbedsStoredEntry(F::entrySection($field), $field, refreshEmbedsLegacyValue('http://synthetic.test/embed-page'));
    $draft = Craft::$app->getDrafts()->createDraft($entry, User::find()->admin()->one()->id, 'Legacy draft');
    $revisionId = Craft::$app->getRevisions()->createRevision($entry, force: true);

    $controller = refreshEmbedsRun($field, dryRun: false);

    expect(refreshEmbedsLink($entry->id, $field)->hasTrustedEmbedData())->toBeTrue();
    expect(refreshEmbedsLink($draft->id, $field)->getIframeSrc())->toBe('https://player.test/embed/1');
    expect(refreshEmbedsLink($revisionId, $field)->getIframeSrc())->toBe('https://player.test/embed/1');
    expect($controller->output)->toContain("Refreshed element {$draft->id}")->toContain("Refreshed element {$revisionId}");
    expect(array_filter(RefreshEmbedsRoutedClient::$requests, fn($url) => str_contains($url, 'embed-page')))->toHaveCount(1);
});

it('leaves validly signed embed data untouched', function() {
    $field = F::hyperField(['linkTypes' => [Embed::class]]);
    $entry = refreshEmbedsStoredEntry(F::entrySection($field), $field, Embed::prepareEmbedData([
        'url' => 'http://synthetic.test/embed-page',
        'title' => 'Signed title',
        'code' => '<iframe src="https://signed.test/embed/1"></iframe>',
    ]));
    $before = Craft::$app->getDb()->createCommand('SELECT [[content]] FROM {{%elements_sites}} WHERE [[elementId]] = :id', [':id' => $entry->id])->queryScalar();

    $controller = refreshEmbedsRun($field, dryRun: false);

    expect($controller->output)->toContain("Embed links checked: 1\nAlready signed: 1\nRefreshed: 0\nFailed: 0");
    expect(RefreshEmbedsRoutedClient::$requests)->toBe([]);
    expect(Craft::$app->getDb()->createCommand('SELECT [[content]] FROM {{%elements_sites}} WHERE [[elementId]] = :id', [':id' => $entry->id])->queryScalar())->toBe($before);
    expect(refreshEmbedsLink($entry->id, $field)->getIframeSrc())->toBe('https://signed.test/embed/1');
});

it('reports a failed fetch for its link and continues with the others', function() {
    $field = F::hyperField(['linkTypes' => [Embed::class]]);
    $section = F::entrySection($field);
    $failing = refreshEmbedsStoredEntry($section, $field, refreshEmbedsLegacyValue('http://synthetic.test/oversize'));
    $working = refreshEmbedsStoredEntry($section, $field, refreshEmbedsLegacyValue('http://synthetic.test/embed-page'));
    $before = refreshEmbedsRaw($failing->id, $field);

    $controller = refreshEmbedsRun($field, dryRun: false);

    expect($controller->output)
        ->toMatch("/Couldn’t refresh element {$failing->id}, site {$failing->siteId}: http:\/\/synthetic\.test\/oversize: .*size limit/")
        ->toContain("Refreshed element {$working->id}")
        ->toContain("Refreshed: 1\nFailed: 1");
    expect(refreshEmbedsLink($working->id, $field)->hasTrustedEmbedData())->toBeTrue();

    // Nothing else changed on the failed link's owner, so its row was not rewritten.
    expect(refreshEmbedsRaw($failing->id, $field))->toBe($before);
});

it('refuses embed URLs outside the allowed domains without a request', function() {
    $settings = Hyper::$plugin->getSettings();
    $domains = $settings->embedAllowedDomains;
    $settings->embedAllowedDomains = ['allowed.test'];

    try {
        $field = F::hyperField(['linkTypes' => [Embed::class]]);
        $entry = refreshEmbedsStoredEntry(F::entrySection($field), $field, refreshEmbedsLegacyValue('http://synthetic.test/embed-page'));
        $message = "Couldn’t refresh element {$entry->id}, site {$entry->siteId}: http://synthetic.test/embed-page: URL domain not allowed.";

        expect(refreshEmbedsRun($field, dryRun: true)->output)->toContain($message)->toContain("Would refresh: 0\nFailed: 1");
        expect(refreshEmbedsRun($field, dryRun: false)->output)->toContain($message)->toContain("Refreshed: 0\nFailed: 1");
        expect(RefreshEmbedsRoutedClient::$requests)->toBe([]);
    } finally {
        $settings->embedAllowedDomains = $domains;
    }
});

it('limits the refresh to the selected elements', function() {
    $field = F::hyperField(['linkTypes' => [Embed::class]]);
    $section = F::entrySection($field);
    $selected = refreshEmbedsStoredEntry($section, $field, refreshEmbedsLegacyValue('http://synthetic.test/embed-page'));
    $other = refreshEmbedsStoredEntry($section, $field, refreshEmbedsLegacyValue('http://synthetic.test/embed-page'));

    $controller = refreshEmbedsRun($field, dryRun: false, settings: ['elementIds' => (string)$selected->id]);

    expect($controller->output)->toContain('Refreshed: 1');
    expect(refreshEmbedsLink($selected->id, $field)->hasTrustedEmbedData())->toBeTrue();
    expect(refreshEmbedsLink($other->id, $field)->hasTrustedEmbedData())->toBeFalse();
});

it('refreshes saved links of a disabled Embed link type, which still render', function() {
    $field = F::hyperField(['linkTypes' => [Url::class, Embed::class]]);
    $entry = refreshEmbedsStoredEntry(F::entrySection($field), $field, refreshEmbedsLegacyValue('http://synthetic.test/embed-page'));
    // A field needs one enabled link type.
    $field->setLinkTypes([F::linkTypeConfig(Url::class), F::linkTypeConfig(Embed::class, false)]);
    expect(Craft::$app->getFields()->saveField($field))->toBeTrue();

    refreshEmbedsRun($field, dryRun: false);

    expect(refreshEmbedsLink($entry->id, $field)->getIframeSrc())->toBe('https://player.test/embed/1');
});

it('checks every Hyper field with an Embed link type when no field is given', function() {
    $first = F::hyperField(['linkTypes' => [Embed::class]]);
    $second = F::hyperField(['linkTypes' => [Embed::class]]);
    $urlOnly = F::hyperField(['linkTypes' => [Url::class]]);
    $firstEntry = refreshEmbedsStoredEntry(F::entrySection($first), $first, refreshEmbedsLegacyValue('http://synthetic.test/embed-page'));
    $secondEntry = refreshEmbedsStoredEntry(F::entrySection($second), $second, refreshEmbedsLegacyValue('http://synthetic.test/embed-page'));

    $controller = refreshEmbedsController();
    $controller->dryRun = false;
    expect($controller->actionRefreshEmbeds())->toBe(0);

    expect($controller->output)
        ->toContain("Checking Embed links in “{$first->handle}”")
        ->toContain("Checking Embed links in “{$second->handle}”")
        ->not->toContain($urlOnly->handle);
    expect(refreshEmbedsLink($firstEntry->id, $first)->hasTrustedEmbedData())->toBeTrue();
    expect(refreshEmbedsLink($secondEntry->id, $second)->hasTrustedEmbedData())->toBeTrue();
});

it('refreshes unsigned embed links in Matrix nested entries', function() {
    $field = F::hyperField(['linkTypes' => [Embed::class]]);
    $matrix = F::matrixFieldWithHyper($field);
    $section = F::entrySection();
    F::attachFieldToSection($section, $matrix['matrix']);
    $owner = F::entryWithMatrixHyperLink($section, $matrix['matrix'], $matrix['hyperField'], $matrix['blockEntryType'], [
        ['type' => Embed::class, 'handle' => Embed::typeKey(), 'linkValue' => ['url' => 'http://synthetic.test/embed-page']],
    ]);
    $nestedId = (int)Entry::find()->ownerId($owner->id)->fieldId($matrix['matrix']->id)->status(null)->ids()[0];
    $nested = Entry::find()->id($nestedId)->status(null)->one();
    $uid = $nested->getFieldLayout()->getFieldByHandle($field->handle)->layoutElement->uid;
    $where = ['elementId' => $nestedId, 'siteId' => $nested->siteId];
    $content = Json::decode((string)(new Query())->select('content')->from(Table::ELEMENTS_SITES)->where($where)->scalar());
    $content[$uid][0]['linkValue'] = refreshEmbedsLegacyValue('http://synthetic.test/embed-page');
    Craft::$app->getDb()->createCommand()->update(Table::ELEMENTS_SITES, ['content' => new \yii\db\JsonExpression($content)], $where)->execute();

    expect(refreshEmbedsRun($field, dryRun: true, settings: ['includeNested' => false])->output)->toContain('Embed links checked: 0');

    refreshEmbedsRun($field, dryRun: false);

    expect(refreshEmbedsLink($nestedId, $field)->getIframeSrc())->toBe('https://player.test/embed/1');
});
