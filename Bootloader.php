<?php

// the db config and config json must be exist before run the app
if (!file_exists(__DIR__ . '/_db_config.php')) {
    include __DIR__ . '/app/info/not_setup_properly.php';
    exit;
}

if (!file_exists(__DIR__ . '/Library/config.json')) {
    include __DIR__ . '/app/info/not_setup_properly.php';
    exit;
}

include '_db_config.php';

// Autoloader for wrapper classes
spl_autoload_register(function ($class_name) {
    $file = __DIR__ . '/Library/wrapper/' . $class_name . '.php';
    if (file_exists($file)) {
        include $file;
    }
});

// Include the base LindseyEngine
include __DIR__ . '/Library/engine/_LindseyEngine.php';

// Lazy model container + config cache helper (kept separate from _LindseyEngine.php on purpose)
include __DIR__ . '/utils/lazy_load_engine.php';

// Override error_log to redirect all logs to project logs/error.log
$project_log_path = __DIR__ . '/logs/error.log';
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}
ini_set('log_errors', 1);
ini_set('error_log', $project_log_path);

class Bootloader {

    public function run() {
        try {
            // Load the configuration (cached - avoids re-parsing the 183KB JSON file every request)
            $config = lindsey_load_config(__DIR__ . '/Library/config.json');
            
            // Transaction management: commit on success, rollback on error
            $commit_on_shutdown = false;
            register_shutdown_function(function() use (&$commit_on_shutdown) {
                global $pdo;
                if (isset($pdo) && $pdo->inTransaction()) {
                    if ($commit_on_shutdown) {
                        $pdo->commit();
                    } else {
                        $pdo->rollBack();
                    }
                }
            });
            
            // Create global APP object for custom functions
            // check if the APP.php exist in the library/wrapper
            if (file_exists(__DIR__ . '/Library/wrapper/APP.php')) {
                global $APP;
                $APP = new APP();
            }

            // Iterate over each object_title in object_models
            foreach ($config['object_models'] as $title => $model_configs) {
                $all_models = [];
                foreach ($model_configs as $model_data) {
                    if (isset($model_data['model'])) {
                        $model = $model_data['model'];
                        if (isset($model['name'])) {
                            $all_models[] = ['name' => $model['name'], 'type' => 'main'];
                        }
                    }
                    if (isset($model_data['grouping_objects'])) {
                        foreach ($model_data['grouping_objects'] as $group) {
                            if (isset($group['name'])) {
                                $all_models[] = ['name' => $group['name'], 'type' => 'group'];
                            }
                        }
                    }
                    if (isset($model_data['child_objects'])) {
                        foreach ($model_data['child_objects'] as $child) {
                            if (isset($child['name'])) {
                                $all_models[] = ['name' => $child['name'], 'type' => 'child'];
                            }
                        }
                    }
                }

                $model_class_map = [];
                foreach ($all_models as $model_info) {
                    $obj = $model_info['name'];
                    $model_class_map[$obj] = str_replace(' ', '', ucwords(str_replace('_', ' ', $obj)));
                }

                // Create global object for this title - models are only instantiated on first access
                global $${title};
                $${title} = new LindseyLazyContainer($config, $model_class_map);
            }
            // Parse the request URI
            $requestUri = $_SERVER['REQUEST_URI'];

            // the request endpoint of uri must only have 4 parts take the middle parts for the endpoint
            // --> example: app_runner/channels/<channel_name>/<endpoint_name>
            $requestEndpoint = explode('/', $requestUri)[3];

            // Frontend interface routing (artefact_2 config source) — plain HTML/PHP
            // pages, no X-API-Key, no JSON envelope, no scope/ownership checks. Runs
            // BEFORE the API channel/auth flow below and exits on match, so a URL
            // segment can only ever resolve to one of channels/ or interfaces/.
            foreach ($config['interfaces'] ?? [] as $interface) {
                if ($requestEndpoint !== $interface['name']) {
                    continue;
                }
                $baseUrl = '/' . $interface['name'];
                $pos = strpos($requestUri, $baseUrl);
                $route = $pos !== false ? substr($requestUri, $pos + strlen($baseUrl) + 1) : '';
                $route = explode('?', $route)[0];

                if (strpos($route, '..') !== false || strpos($route, "\0") !== false) {
                    http_response_code(400);
                    echo 'Invalid route';
                    exit;
                }

                $filePath = 'interfaces/' . $interface['name'] . '/' . $route . '.php';
                if (!empty($route) && file_exists($filePath)) {
                    include $filePath;
                } else {
                    http_response_code(404);
                    echo '404 Not Found';
                }
                exit;
            }

            // Find the API channel
            $apiChannel = null;
            foreach ($config['channels'] as $channel) {
                // re routing request to the specific endpoint channel
                if ($channel['type'] === 'api' && $requestEndpoint ==  $channel["channel_name"]) {
                    // Set the API channel
                    header('Content-Type: application/json; charset=utf-8');
                    $apiChannel = $channel;
                    break;
                }
            }

            if (!$apiChannel) {
                http_response_code(500);
                echo json_encode(['error' => 'API channel not found in config']);
                exit;
            }

            //!!! dependent on the _setup_auth.txt
            // Check auth API key
            if (!isset($_SERVER['HTTP_X_API_KEY'])) {
                http_response_code(401);
                echo json_encode(['error' => 'X-API-Key header missing']);
                exit;
            }

            //!!! dependent on the superlindey application
            // get the headers x-pagination-data
            if(isset($_SERVER['HTTP_X_PAGINATION_DATA'])){
        
                $pagination_data = json_decode($_SERVER['HTTP_X_PAGINATION_DATA'], true);

                // validate the structure the user must send {"property" : "value",  "property" : "value"} in headers
                if (!is_array($pagination_data)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid X-Pagination-Data header format']);
                    exit;
                }

                // validate the structure the user must send {"property" : "value",  "property" : "value"} in headers
                foreach ($pagination_data as $key => $value) {
                    if (!is_string($key) || !is_string($value)) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid X-Pagination-Data header format']);
                        exit;
                    }
                }

                // the array must only contain per_page property and page_number property
                if (!isset($pagination_data['per_page']) || !isset($pagination_data['page_number'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid X-Pagination-Data header format']);
                    exit;
                }

                // make sure the value of prop is number
                if (!is_numeric($pagination_data['per_page']) || !is_numeric($pagination_data['page_number'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid X-Pagination-Data header format']);
                    exit;
                }

                // store the ownership data to global variable for later use
                $GLOBALS['pagination_data'] = $pagination_data; // store the pagination data to global variables
            } 

            //!!! dependent on the superlindey application
            // get the headers x-ownership-data
            if (isset($_SERVER['HTTP_X_OWNERSHIP_DATA'])) {
                $ownership_data = json_decode($_SERVER['HTTP_X_OWNERSHIP_DATA'], true);

                // validate the structure the user must send {"property" : "value",  "property" : "value"} in headers
                if (!is_array($ownership_data)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid X-Ownership-Data header format']);
                    exit;
                }

                // validate the structure the user must send {"property" : "value",  "property" : "value"} in headers
                foreach ($ownership_data as $key => $value) {
                    if (!is_string($key) || !is_string($value)) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid X-Ownership-Data header format']);
                        exit;
                    }
                }

                // store the ownership data to global variable for later use
                $GLOBALS['ownership_data'] = $ownership_data; // store the ownership data to global variables
            }

            $apiKey = $_SERVER['HTTP_X_API_KEY'];
            global $auth_pdo;
            $stmt = $auth_pdo->prepare("SELECT scopes, channel_list, ownership_data_binding FROM api_tokens WHERE token = ?");
            $stmt->execute([$apiKey]);
            $api_token_list = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$api_token_list) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                exit;
            }
            $scopes = $api_token_list['scopes'];
            $channel_allowed_list = json_decode($api_token_list['channel_list'], true);
            if (!is_array($channel_allowed_list)) $channel_allowed_list = [];
            $ownership_data_binding = json_decode($api_token_list['ownership_data_binding'] ?? '{}', true);
            if (!is_array($ownership_data_binding)) $ownership_data_binding = [];

            // validate the channel list allowed from api token to the user request route
            if(!in_array($apiChannel['channel_name'], $channel_allowed_list)){
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden: API token does not have access to this channel']);
                exit;
            }

            // validate the ownership data header against the ownership_data_binding column
            // ownership_data_binding format: {"member_number": ["M001", "M002"], "organization_number": ["*"]}
            // X-Ownership-Data header format: {"member_number": "M001", "organization_number": "ORG01"}
            // any unkown property from the X-Ownership-Data header will be rejected
            if (isset($_SERVER['HTTP_X_OWNERSHIP_DATA']) && !empty($ownership_data_binding)) {
                $header_ownership_data = json_decode($_SERVER['HTTP_X_OWNERSHIP_DATA'], true);
                if (!is_array($header_ownership_data)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden: Invalid X-Ownership-Data header format']);
                    exit;
                }
                foreach ($header_ownership_data as $key => $value) {
                    if (!array_key_exists($key, $ownership_data_binding)) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Forbidden: Invalid Ownership data']);
                        exit;
                    }
                    $allowed_values = $ownership_data_binding[$key];
                    if (!is_array($allowed_values)) $allowed_values = [];
                    if (in_array('*', $allowed_values)) continue;
                    if (!in_array($value, $allowed_values)) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Forbidden: Invalid Ownership data']);
                        exit;
                    }
                }
            }

            // validate the api channel allowed list
            if(!in_array($apiChannel['channel_name'], $channel_allowed_list)){
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden: API token does not have access to this channel']);
                exit;
            }

            // Construct the base URL from channel_name
            $baseUrl = '/' . $apiChannel['channel_name']; // e.g., "/channels/<channel_name>"

            // Find the position of the base URL
            $pos = strpos($requestUri, $baseUrl);
            if ($pos !== false) {
                // Extract the base path
                $basePath = substr($requestUri, 0, $pos + strlen($baseUrl) + 1); // +1 for trailing /
                $endpoint = substr($requestUri, strlen($basePath));
            } else {
                $endpoint = '';
            }

            // Remove query string
            $endpoint = explode('?', $endpoint)[0];

            // reject path traversal attempts before the endpoint is used to build a file path
            if (strpos($endpoint, '..') !== false || strpos($endpoint, "\0") !== false) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid endpoint']);
                exit;
            }

            // make sure the request is a post method
            $is_action_endpoint = strpos($endpoint, '_') === 0;

            // check if endpoint contains /api-spec
            $has_api_spec_endpoint = strpos($endpoint, '/api-spec') !== false;
            // if contains /api-spec seperated the /api-spec from the endpoint
            if($has_api_spec_endpoint ){
                $endpoint = explode('/api-spec', $endpoint)[0];
            }

            // POST ONLY
            // check if endpoint contains /form
            $has_form_endpoint = strpos($endpoint, '/form') !== false;
            // if contains /form seperated the /form from the endpoint
            if($has_form_endpoint && $_SERVER['REQUEST_METHOD'] == 'POST'){
                $endpoint = explode('/form', $endpoint)[0];
            }

            // **
            // check endpoint compatibility request method
            if(1){
                if ($is_action_endpoint && $_SERVER['REQUEST_METHOD'] !== 'POST') {
                    http_response_code(405);
                    echo json_encode(['error' => 'Method Not Allowed']);
                    return;
                }

                if(!$is_action_endpoint && $_SERVER['REQUEST_METHOD'] !== 'GET') {
                    http_response_code(405);
                    echo json_encode(['error' => 'Method Not Allowed']);
                    return;
                }
            }

            //!!! dependent on the _setup_auth.txt
            // Check scopes
            if(1){
                if ($scopes == 'write' && !$is_action_endpoint) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden: Write scope required for this endpoint']);
                    return;
                } elseif ($scopes == 'read' && $is_action_endpoint) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden: Read scope required for this endpoint']);
                    return;
                }
            }

            // Construct the file path based on the base URL
            $filePath = 'channels/' . ltrim($baseUrl, '/') . '/' . $endpoint . '.php'; // e.g., "channels/<channel_name>/<endpoint>"

            // Check if the file exists and include it
            if (!empty($endpoint) && file_exists($filePath)) {
                // Only write endpoints need a transaction - read (GET) endpoints don't mutate data
                global $pdo;
                if ($is_action_endpoint) {
                    $pdo->beginTransaction();
                    $commit_on_shutdown = true;
                }
                include $filePath;
                // If endpoint returned without exiting, commit now
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                    $commit_on_shutdown = false;
                }
            } else {
                // Endpoint not found
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint not found']);
            }
        } catch (Throwable $e) {
            // Rollback transaction if active
            $commit_on_shutdown = false;
            global $pdo;
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            // Log error to Apache error log
            $error_path = $e->getFile();
            $error_level = array(
                '/channels/' => 400,
                '/Library/wrapper/' => 422,
            );

            header('Content-Type: application/json; charset=utf-8');
            // default
            $response_code = 500;
            // check error level
            foreach ($error_level as $marker => $code) {
                if (strpos($error_path, $marker) !== false) {
                    $response_code = $code;
                    break;
                }
            }
            http_response_code($response_code);

            // Prepare log message
            $body = json_decode(file_get_contents('php://input'), true);
            $log_message = sprintf(
                "[%s] Error in channel '%s', endpoint '%s', token '%s': %s | Body: %s | Query: %s",
                date('Y-m-d H:i:s'),
                $apiChannel['channel_name'] ?? 'unknown',
                $endpoint ?? 'unknown',
                $apiKey ?? 'unknown',
                $e->getMessage(),
                json_encode($body),
                json_encode($_GET)
            );
            
            // Ensure logs directory exists
            $log_dir = __DIR__ . '/logs';
            if (!is_dir($log_dir)) {
                mkdir($log_dir, 0755, true);
            }
            
            // Write to project error log
            error_log($log_message . PHP_EOL, 3, $log_dir . '/error.log');
            
            // 400/422 are expected validation errors raised by channel/hook business logic - safe to expose.
            // 500 means an unexpected internal failure (e.g. raw DB error) - message may leak schema/query details, so keep it generic.
            $client_message = $response_code === 500 ? 'Internal server error' : $e->getMessage();
            echo json_encode(['error' => $client_message]);
            exit;
        }
    }
}

?>