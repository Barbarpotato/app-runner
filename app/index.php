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

if (method_exists($controller, $method)) {
    $controller->$method();
} else {
    echo "Page not found";
}

?>