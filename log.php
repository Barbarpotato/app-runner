<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CLI only']);
    exit;
}

$log_path = __DIR__ . '/logs/error.log';

if ($argc < 2 || !in_array($argv[1], ['read', 'clear'])) {
    echo "Usage: php log.php [read|clear]\n";
    exit(1);
}

if ($argv[1] === 'clear') {
    if (file_exists($log_path)) {
        file_put_contents($log_path, '');
        echo "Error log cleared.\n";
    } else {
        echo "No error log file found.\n";
    }
    exit;
}

// read
if (file_exists($log_path) && is_readable($log_path)) {
    $lines = file($log_path);
    echo implode('', array_slice($lines, -100));
} else {
    echo "No error log found.\n";
}