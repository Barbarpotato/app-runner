<?php

/**
 * install.php — one-time, UNAUTHENTICATED bootstrap endpoint.
 *
 * This is the HTTP equivalent of the CLI `php setup.php install <url>` flow
 * (create_app() + init_database()), for greenfield deployments where no secret
 * exists yet. From a single config URL it:
 *
 *   1. generates the app code (channels/ + Library/config.json) via create_app(),
 *   2. creates the business database and the auth database (+ admin user),
 *   3. writes _db_config.php with a freshly generated $setup_hmac_secret,
 *   4. creates the business tables and their foreign keys.
 *
 * ── ONE-TIME USE ──────────────────────────────────────────────────────────────
 * It is gated solely on the existence of _db_config.php. The moment that file
 * exists (i.e. the app is installed) this endpoint returns 403 and does nothing.
 * That is the entire access-control model: there is NO authentication, because
 * the HMAC secret does not exist until this very endpoint creates it.
 *
 * ⚠️  SECURITY: while _db_config.php is absent, ANYONE who can reach this URL can
 *     install the app, create databases, and mint the admin user + API secret.
 *     Deploy it only on a not-yet-installed instance, behind network controls,
 *     and DELETE this file once installation succeeds. The generated
 *     $setup_hmac_secret is returned ONCE in the response body — capture it.
 *
 * Reached directly, e.g. POST /app-runner/api/install.php.
 */

// This file lives in api/; the project root (where setup.php, templates/,
// Library/ and _db_config.php live) is one level up. create_app() and the
// templates use paths relative to the project root, so anchor the cwd there.
define('SETUP_ROOT', dirname(__DIR__));
chdir(SETUP_ROOT);

header('Content-Type: application/json; charset=utf-8');

/** Emit a JSON response and stop. */
function send_json($code, array $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

// ==============================
// ONE-TIME GATE — refuse once installed
// ==============================
if (file_exists(SETUP_ROOT . '/_db_config.php')) {
    send_json(403, [
        'ok'    => false,
        'error' => 'Access denied: the application is already installed (_db_config.php exists). The installer is one-time use.',
    ]);
}

// ==============================
// PARSE REQUEST
// ==============================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, ['ok' => false, 'error' => 'Method Not Allowed: use POST.']);
}

$request = json_decode(file_get_contents('php://input'), true);
if (!is_array($request)) {
    send_json(400, ['ok' => false, 'error' => 'Request body must be a JSON object.']);
}

// Every install parameter is REQUIRED in the body — no defaults. Each must be a
// non-empty string; the first missing/invalid one is reported.
$required = [
    'config_url',     // URL to fetch config.json from
    'db_host',        // MySQL host
    'db_name',        // business database name
    'db_user',        // MySQL username
    'db_pass',        // MySQL password
    'auth_db_name',   // auth database name
    'admin_username', // app-runner admin login
    'admin_password', // app-runner admin password
];
$values = [];
foreach ($required as $field) {
    $val = $request[$field] ?? null;
    if (!is_string($val) || $val === '') {
        send_json(400, ['ok' => false, 'error' => "Field '$field' is required and must be a non-empty string."]);
    }
    $values[$field] = $val;
}

$config_url     = $values['config_url'];
$host           = $values['db_host'];
$db             = $values['db_name'];
$user           = $values['db_user'];
$pass           = $values['db_pass'];
$auth_db        = $values['auth_db_name'];
$admin_username = $values['admin_username'];
$admin_password = $values['admin_password'];

// Fresh secret for the HTTP setup API HMAC handshake — returned once below.
$setup_hmac_secret = bin2hex(random_bytes(32));

// ==============================
// PRE-VALIDATE THE CONFIG URL (so a bad URL fails cleanly, before any writes)
// ==============================
$url_to_fetch = $config_url;
if (strpos($config_url, 'http://') !== 0 && strpos($config_url, 'https://') !== 0) {
    if (strpos($config_url, 'localhost') === false && strpos($config_url, '127.0.0.1') === false) {
        $url_to_fetch = 'https://' . $config_url;
    }
}
$config_content = @file_get_contents($url_to_fetch);
if ($config_content === false) {
    send_json(400, ['ok' => false, 'error' => "Failed to fetch config from URL '$url_to_fetch'."]);
}
$config = json_decode($config_content, true);
if (!is_array($config) || !isset($config['object_models'])) {
    send_json(400, ['ok' => false, 'error' => 'Fetched config is not valid JSON or is missing object_models.']);
}

// Load the canonical functions (create_app, map_field_type, generate_fk_constraint_name…).
// setup.php's CLI block is guarded by php_sapi_name() === 'cli', so over HTTP this
// only defines functions.
require_once SETUP_ROOT . '/setup.php';

// Required templates must be present.
foreach (['templates/_db_config.txt', 'templates/_setup_auth.txt'] as $tpl) {
    if (!file_exists(SETUP_ROOT . '/' . $tpl)) {
        send_json(500, ['ok' => false, 'error' => "Template '$tpl' not found."]);
    }
}

// ==============================
// STEP 1 — generate app code (channels/ + Library/config.json)
// ==============================
// create_app() echoes progress and may exit() on a hard error; swallow its output
// so the response stays JSON.
ob_start();
create_app($config_url);
ob_end_clean();

// ==============================
// STEP 2 — create databases + auth user, then write _db_config.php
// ==============================
try {
    // Connect without a database to create the business DB.
    $pdo_temp = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo_temp->exec("CREATE DATABASE IF NOT EXISTS `$db`");

    // Build the auth DB + tables + admin user from the template.
    $auth_sql = file_get_contents(SETUP_ROOT . '/templates/_setup_auth.txt');
    $auth_sql = str_replace('<auth_database_name>', $auth_db, $auth_sql);
    $auth_sql = str_replace('<admin_username>', $admin_username, $auth_sql);
    $auth_sql = str_replace('<admin_password_hash>', password_hash($admin_password, PASSWORD_DEFAULT), $auth_sql);
    $pdo_temp->exec($auth_sql);
    $pdo_temp = null;
} catch (\Throwable $e) {
    send_json(500, ['ok' => false, 'error' => 'Database/auth setup failed: ' . $e->getMessage()]);
}

// Render _db_config.php from the template (mirrors create_app()'s substitutions).
$template = file_get_contents(SETUP_ROOT . '/templates/_db_config.txt');
$template = str_replace('<HOST>', $host, $template);
$template = str_replace('<DATABASE_NAME>', $db, $template);
$template = str_replace('<USERNAME>', $user, $template);
$template = str_replace('<PASSWORD>', $pass, $template);
$template = str_replace('<AUTH_DATABASE_NAME>', $auth_db, $template);
$template = str_replace('<SETUP_HMAC_SECRET>', $setup_hmac_secret, $template);
$template = str_replace(
    '$dsn = "mysql:host=$host;dbname=$db;";',
    '$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";',
    $template
);

if (file_put_contents(SETUP_ROOT . '/_db_config.php', $template) === false) {
    send_json(500, ['ok' => false, 'error' => 'Failed to write _db_config.php.']);
}

// ==============================
// STEP 3 — create business tables + foreign keys from the config
// ==============================
// Re-open via the freshly written config so $pdo targets the business DB.
include SETUP_ROOT . '/_db_config.php';   // provides $pdo (business) + $auth_pdo

// Flatten object_models exactly as setup.php does.
$object_models = [];
foreach ($config['object_models'] as $models_array) {
    $object_models = array_merge($object_models, $models_array);
}
$models = [];
foreach ($object_models as $model_data) {
    if (isset($model_data['model']['name'], $model_data['model']['fields'])) {
        $models[] = $model_data['model'];
    }
    foreach (['grouping_objects', 'child_objects'] as $bucket) {
        if (isset($model_data[$bucket])) {
            foreach ($model_data[$bucket] as $sub) {
                if (isset($sub['name'], $sub['fields'])) {
                    $models[] = $sub;
                }
            }
        }
    }
}

$tables_created = [];
$fk_errors = [];
try {
    // First pass: tables without FKs.
    foreach ($models as $model) {
        $table_name = $model['name'];
        $fields_sql = [];
        foreach ($model['fields'] as $field) {
            $type = map_field_type($field['type']);
            $null = $field['compulsory'] ? 'NOT NULL' : 'NULL';
            $default = '';
            if (isset($field['default_value']) && $field['default_value'] !== null) {
                if (strtoupper((string) $field['default_value']) === 'CURRENT_TIMESTAMP') {
                    $default = 'DEFAULT CURRENT_TIMESTAMP';
                } elseif (is_bool($field['default_value'])) {
                    $default = $field['default_value'] ? 'DEFAULT 1' : 'DEFAULT 0';
                } else {
                    $default = "DEFAULT '" . addslashes($field['default_value']) . "'";
                }
            } elseif ($field['compulsory'] && in_array($field['type'], ['timestamp', 'datetime'], true)) {
                $default = 'DEFAULT CURRENT_TIMESTAMP';
            }
            $auto_increment = ($field['name'] === 'id') ? ' AUTO_INCREMENT' : '';
            $fields_sql[] = "`{$field['name']}` $type $null $default$auto_increment";
        }
        if (in_array('id', array_column($model['fields'], 'name'), true)) {
            $fields_sql[] = 'PRIMARY KEY (`id`)';
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$table_name` (\n" . implode(",\n", $fields_sql) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $tables_created[] = $table_name;
    }

    // Second pass: foreign keys (after every table exists).
    foreach ($models as $model) {
        if (!isset($model['relations'])) {
            continue;
        }
        foreach ($model['relations'] as $rel) {
            if (($rel['type'] ?? null) !== 'belongs_to') {
                continue;
            }
            $sql = "ALTER TABLE `{$model['name']}` ADD CONSTRAINT "
                 . generate_fk_constraint_name($model['name'], $rel['local_key'])
                 . " FOREIGN KEY (`{$rel['local_key']}`) REFERENCES `{$rel['target']}` (`{$rel['foreign_key']}`);";
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                $fk_errors[] = ['table' => $model['name'], 'message' => $e->getMessage()];
            }
        }
    }
} catch (\Throwable $e) {
    send_json(500, [
        'ok'             => false,
        'error'          => 'Table creation failed: ' . $e->getMessage(),
        'tables_created' => $tables_created,
    ]);
}

// ==============================
// DONE — return the secrets ONCE
// ==============================
send_json(200, [
    'ok'                => true,
    'message'           => 'Installation complete. SAVE the secret below — it is shown only once. Delete api/install.php now.',
    'databases'         => ['business' => $db, 'auth' => $auth_db],
    'tables_created'    => $tables_created,
    'foreign_key_errors'=> $fk_errors,
    'admin_username'    => $admin_username,
    'setup_hmac_secret' => $setup_hmac_secret,
]);

?>
