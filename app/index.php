<?php

// the db config and config json must be exist before run the app
if (!file_exists(__DIR__ . '/../_db_config.php')) {
    include __DIR__ . '/info/not_setup_properly.php';
    exit;
}

if (!file_exists(__DIR__ . '/../Library/config.json')) {
    include __DIR__ . '/info/not_setup_properly.php';
    exit;
}

session_start();

define('ROOT_PATH', dirname(__DIR__));

$log_dir = ROOT_PATH . '/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
ini_set('log_errors', 1);
ini_set('display_errors', 0);
ini_set('error_log', $log_dir . '/error.log');

// catches fatals that Throwable can't (out of memory, max execution time)
register_shutdown_function(function() use ($log_dir) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log(sprintf(
            "[%s] Fatal error: %s in %s:%d" . PHP_EOL,
            date('Y-m-d H:i:s'), $err['message'], $err['file'], $err['line']
        ), 3, $log_dir . '/error.log');
    }
});

$action = $_GET['action'] ?? 'index';

if (isset($_SESSION['user'])) {
    // Logged in
    if ($action == 'api_tokens') {
        include __DIR__ . '/controller/api_tokens_controller.php';
        $controller = new ApiTokensController();
        $method = $_GET['method'] ?? 'index';
    } elseif ($action == 'error_logs') {
        include __DIR__ . '/controller/error_logs_controller.php';
        $controller = new ErrorLogsController();
        $method = $_GET['method'] ?? 'index';
    } elseif ($action == 'db_ide') {
        include __DIR__ . '/controller/db_ide_controller.php';
        $controller = new DbIdeController();
        $method = $_GET['method'] ?? 'index';
    } else {
        include __DIR__ . '/controller/index_controller.php';
        $controller = new IndexController();
        $method = $action;
    }
} else {
    // Not logged in, route to login controller
    include __DIR__ . '/controller/login_controller.php';
    $controller = new LoginController();
    $method = $action;
}

try {
    if (method_exists($controller, $method)) {
        $controller->$method();
    } else {
        echo "Page not found";
    }
} catch (Throwable $e) {
    error_log(sprintf(
        "[%s] Error in app dashboard action '%s', method '%s': %s in %s:%d" . PHP_EOL,
        date('Y-m-d H:i:s'), $action, $method, $e->getMessage(), $e->getFile(), $e->getLine()
    ), 3, $log_dir . '/error.log');

    http_response_code(500);
    $is_json = false;
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type: application/json') === 0) {
            $is_json = true;
            break;
        }
    }
    echo $is_json ? json_encode(['error' => 'Internal server error']) : 'Internal server error';
}

?>