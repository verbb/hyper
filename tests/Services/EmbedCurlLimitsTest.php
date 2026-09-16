<?php

use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use verbb\hyper\http\EmbedClient;
it('enforces received byte limits through the real curl transport', function () {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) {
        throw new RuntimeException($error);
    }
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../Support/embed-router.php'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    try {
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            if ($s = @fsockopen('127.0.0.1', $port)) {
                fclose($s);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        expect($ready)->toBeTrue();
        $client = new class($port) extends EmbedClient
        {
            public function __construct(private int $port)
            {
                parent::__construct();
            }
            protected function resolveHost(string $host): array
            {
                return ['93.184.215.14'];
            }
            protected function requestPinned(RequestInterface $request, string $ip): ResponseInterface
            {
                // Substitute only the connection destination; exercise production Curl callbacks.
                $local = $request->getUri()->withHost('127.0.0.1')->withPort($this->port);
                return parent::requestPinned($request->withUri($local), '127.0.0.1');
            }
        };
        expect((string) $client->sendRequest(new Request('GET', 'http://synthetic.test/ok'))->getBody())->toBe('{"synthetic":true}');
        expect(fn() => $client->sendRequest(new Request('GET', 'http://synthetic.test/oversize', ['Range' => 'bytes=0-0'])))->toThrow(RuntimeException::class, 'size limit');
        expect(fn() => $client->sendRequest(new Request('GET', 'http://synthetic.test/compressed')))->toThrow(RuntimeException::class, 'size limit');
        expect(fn() => $client->sendRequest(new Request('GET', 'http://synthetic.test/headers')))->toThrow(RuntimeException::class, 'size limit');
    } finally {
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        proc_terminate($process);
        proc_close($process);
    }
});
