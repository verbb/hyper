<?php
// Local test server only. The streaming response deliberately ignores Range.
if (str_contains($_SERVER['REQUEST_URI'], 'compressed')) {
    header('Content-Encoding: gzip');
    header('Content-Type: text/plain');
    echo gzencode(str_repeat('x', 3 * 1024 * 1024));
} elseif (str_contains($_SERVER['REQUEST_URI'], 'headers')) {
    for ($i = 0; $i < 80; $i++) header('X-Synthetic-' . $i . ': ' . str_repeat('x', 1024));
    echo '{}';
} elseif (str_contains($_SERVER['REQUEST_URI'], 'oversize')) {
    header('Content-Type: text/plain');
    for ($i = 0; $i < 2200; $i++) echo str_repeat('x', 1024);
} elseif (str_contains($_SERVER['REQUEST_URI'], 'embed-oembed')) {
    header('Content-Type: application/json');
    echo json_encode([
        'version' => '1.0',
        'type' => 'video',
        'title' => 'Synthetic video',
        'provider_name' => 'Synthetic',
        'html' => '<iframe src="https://player.test/embed/1" width="640" height="360"></iframe><script>alert(1)</script>',
    ]);
} elseif (str_contains($_SERVER['REQUEST_URI'], 'embed-page')) {
    header('Content-Type: text/html');
    echo '<html><head><title>Synthetic page</title><link rel="alternate" type="application/json+oembed" href="http://synthetic.test/embed-oembed"></head><body></body></html>';
} else {
    header('Content-Type: application/json');
    echo '{"synthetic":true}';
}
