<?php

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use verbb\hyper\http\EmbedClient;
class RecordingEmbedTransport extends EmbedClient
{
    public array $requests = [];
    public array $responses = [];
    public array $dns = [];
    protected function resolveHost(string $host): array
    {
        return $this->dns[$host] ?? [$host];
    }
    protected function requestPinned(RequestInterface $request, string $ip): ResponseInterface
    {
        $this->requests[] = ['url' => (string) $request->getUri(), 'ip' => $ip];
        return array_shift($this->responses) ?? new Response(200, [], 'synthetic');
    }
}
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
