<?php

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use verbb\hyper\http\EmbedClient;
class RecordingEmbedTransport extends EmbedClient
{
    public array $requests = [];
    public array $requestHeaders = [];
    public array $responses = [];
    public array $dns = [];
    protected function resolveHost(string $host): array
    {
        return $this->dns[$host] ?? [$host];
    }
    protected function requestPinned(RequestInterface $request, string $ip): ResponseInterface
    {
        $this->requests[] = ['url' => (string) $request->getUri(), 'ip' => $ip];
        $this->requestHeaders[] = $request->getHeaders();
        return array_shift($this->responses) ?? new Response(200, [], 'synthetic');
    }
}

it('pins both Curl forms of a trailing-dot host without changing ordinary hosts', function () {
    $client = new RecordingEmbedTransport();
    $method = new ReflectionMethod(EmbedClient::class, '_resolveEntries');
    $resolveEntries = static fn(string $host, int $port, string $address): array => $method->invoke($client, $host, $port, $address);

    expect($resolveEntries('example.test', 443, '93.184.215.14'))
        ->toBe(['example.test:443:93.184.215.14'])
        ->and($resolveEntries('example.test.', 443, '93.184.215.14'))
        ->toBe(['example.test.:443:93.184.215.14', 'example.test:443:93.184.215.14'])
        ->and($resolveEntries('example.test.', 443, '[2001:4860:4860::8888]'))
        ->toBe(['example.test.:443:[2001:4860:4860::8888]', 'example.test:443:[2001:4860:4860::8888]'])
        ->and($resolveEntries('example.test..', 443, '93.184.215.14'))
        ->toBe(['example.test..:443:93.184.215.14']);
});
it('does not send configured embed headers to another origin', function () {
    $client = new RecordingEmbedTransport(headerRules: [
        'https://origin.test' => ['X-Api-Key' => 'private-secret'],
    ]);
    $client->dns['origin.test'] = ['93.184.215.14'];
    $client->dns['attacker.test'] = ['93.184.215.14'];
    $client->responses = [
        new Response(302, ['Location' => 'https://attacker.test/collect']),
        new Response(200),
    ];
    $client->sendRequest(new Request('GET', 'https://origin.test'));
    expect($client->requestHeaders[0]['X-Api-Key'])->toBe(['private-secret']);
    expect($client->requestHeaders[1])->not->toHaveKey('X-Api-Key');
});
it('does not send configured embed headers to discovered oEmbed origins', function () {
    $client = new RecordingEmbedTransport(headerRules: [
        'https://origin.test' => ['X-Api-Key' => 'private-secret'],
    ]);
    $client->dns['origin.test'] = ['93.184.215.14'];
    $client->dns['attacker.test'] = ['93.184.215.14'];
    $client->responses = [
        new Response(200, ['Content-Type' => 'text/html'], '<html><head><link rel="alternate" type="application/json+oembed" href="https://attacker.test/oembed"></head></html>'),
        new Response(200, ['Content-Type' => 'application/json'], '{"version":"1.0","type":"link","title":"Synthetic title"}'),
    ];
    $embed = new Embed\Embed(new Embed\Http\Crawler($client));
    expect($embed->get('https://origin.test')->title)->toBe('Synthetic title');
    expect($client->requestHeaders[0]['X-Api-Key'])->toBe(['private-secret']);
    expect($client->requestHeaders[1])->not->toHaveKey('X-Api-Key');
});
it('reapplies only the destination origin headers across redirects', function () {
    $client = new RecordingEmbedTransport(headerRules: [
        'https://origin.test' => ['X-Api-Key' => 'origin-secret'],
        'https://api.test:8443' => ['X-Api-Key' => 'api-secret'],
    ]);
    $client->dns['origin.test'] = ['93.184.215.14'];
    $client->dns['api.test'] = ['93.184.215.14'];
    $client->responses = [
        new Response(302, ['Location' => '/same-origin']),
        new Response(302, ['Location' => 'https://api.test:8443/data']),
        new Response(200),
    ];
    $client->sendRequest(new Request('GET', 'https://origin.test'));
    expect($client->requestHeaders[0]['X-Api-Key'])->toBe(['origin-secret']);
    expect($client->requestHeaders[1]['X-Api-Key'])->toBe(['origin-secret']);
    expect($client->requestHeaders[2]['X-Api-Key'])->toBe(['api-secret']);
});
it('matches configured embed headers by exact scheme host and port', function () {
    $client = new RecordingEmbedTransport(headerRules: [
        'https://origin.test:8443' => ['X-Api-Key' => 'private-secret'],
    ]);
    $client->dns['origin.test'] = ['93.184.215.14'];
    $client->dns['child.origin.test'] = ['93.184.215.14'];
    $client->sendRequest(new Request('GET', 'https://origin.test'));
    $client->sendRequest(new Request('GET', 'https://child.origin.test:8443'));
    $client->sendRequest(new Request('GET', 'https://origin.test:8443'));
    expect($client->requestHeaders[0])->not->toHaveKey('X-Api-Key');
    expect($client->requestHeaders[1])->not->toHaveKey('X-Api-Key');
    expect($client->requestHeaders[2]['X-Api-Key'])->toBe(['private-secret']);
});
it('preserves provider authorization over configured authorization', function () {
    $client = new RecordingEmbedTransport(headerRules: [
        'https://api.twitter.com' => ['Authorization' => 'Bearer configured-token'],
    ]);
    $client->dns['api.twitter.com'] = ['104.244.42.1'];
    $client->sendRequest(new Request('GET', 'https://api.twitter.com/2/tweets/1', [
        'Authorization' => 'Bearer provider-token',
    ]));
    expect($client->requestHeaders[0]['Authorization'])->toBe(['Bearer provider-token']);
});
it('rejects unscoped or unsafe embed header configuration', function (array $rules) {
    expect(fn() => new RecordingEmbedTransport(headerRules: $rules))->toThrow(InvalidArgumentException::class);
})->with([
    'legacy flat headers' => [['Authorization' => 'Bearer private-secret']],
    'insecure origin' => [['http://origin.test' => ['X-Api-Key' => 'private-secret']]],
    'origin path' => [['https://origin.test/api' => ['X-Api-Key' => 'private-secret']]],
    'host override' => [['https://origin.test' => ['Host' => 'attacker.test']]],
    'header injection' => [['https://origin.test' => ['X-Api-Key' => "secret\r\nX-Injected: yes"]]],
]);
it('guards discovered oEmbed endpoints before dispatch', function () {
    $client = new RecordingEmbedTransport();
    $client->dns['public.example.test'] = ['93.184.215.14'];
    $client->responses = [new Response(200, ['Content-Type' => 'text/html'], '<html><head><link rel="alternate" type="application/json+oembed" href="http://127.0.0.1/synthetic"></head></html>')];
    $embed = new Embed\Embed(new Embed\Http\Crawler($client));
    expect(fn() => $embed->get('https://public.example.test')->title)->toThrow(RuntimeException::class, 'host is not allowed');
    expect($client->requests)->toHaveCount(1);
});
it('checks redirects and pins the validated DNS answer', function () {
    $client = new RecordingEmbedTransport(['allowed.test']);
    $client->dns['allowed.test'] = ['93.184.215.14'];
    $client->dns['elsewhere.test'] = ['93.184.215.14'];
    $client->responses = [new Response(302, ['Location' => 'https://elsewhere.test/blocked'])];
    expect(fn() => $client->sendRequest(new Request('GET', 'https://allowed.test')))->toThrow(RuntimeException::class, 'domain not allowed');
    expect($client->requests)->toBe([['url' => 'https://allowed.test', 'ip' => '93.184.215.14']]);
    $client = new RecordingEmbedTransport();
    $client->dns['allowed.test'] = ['93.184.215.14', '127.0.0.1'];
    expect(fn() => $client->sendRequest(new Request('GET', 'https://allowed.test')))->toThrow(RuntimeException::class);
    expect($client->requests)->toBeEmpty();
});
it('bounds redirect and request budgets and resolves relative redirects', function () {
    $client = new RecordingEmbedTransport();
    $client->dns['allowed.test'] = ['93.184.215.14'];
    $client->responses = [new Response(302, ['Location' => '../next?q=1']), new Response(200)];
    expect($client->sendRequest(new Request('GET', 'https://allowed.test/path/start'))->getHeaderLine('Content-Location'))->toBe('https://allowed.test/next?q=1');
    $client->responses = array_fill(0, 7, new Response(302, ['Location' => '/loop']));
    expect(fn() => $client->sendRequest(new Request('GET', 'https://allowed.test')))->toThrow(RuntimeException::class, 'redirect limit');
    $client->responses = [];
    expect(function () use ($client) {
        for ($i = 0; $i < 21; $i++) {
            $client->sendRequest(new Request('GET', 'https://allowed.test'));
        }
    })->toThrow(RuntimeException::class, 'budget exceeded');
});
it('guards opt-in image probes with the same crawler', function () {
    $settings = \verbb\hyper\Hyper::$plugin->getSettings();
    $old = $settings->resolveHiResEmbedImage;
    $settings->resolveHiResEmbedImage = true;
    try {
        $client = new RecordingEmbedTransport();
        $client->dns['public.example.test'] = ['93.184.215.14'];
        $client->responses = [new Response(200, ['Content-Type' => 'text/html'], '<html><head><meta property="og:image" content="http://127.0.0.1/synthetic-image"></head></html>')];
        $embed = new Embed\Embed(new Embed\Http\Crawler($client));
        $embed->getExtractorFactory()->addDetector('image', \verbb\hyper\helpers\EmbedImagesExtractor::class);
        expect(fn() => $embed->get('https://public.example.test')->image)->toThrow(RuntimeException::class, 'host is not allowed');
        expect($client->requests)->toHaveCount(1);
    } finally {
        $settings->resolveHiResEmbedImage = $old;
    }
});
