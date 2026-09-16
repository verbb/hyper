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
} else {
    header('Content-Type: application/json');
    echo '{"synthetic":true}';
}
