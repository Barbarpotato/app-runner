<?php

/**
 * setup.php (api) — HTTP front-end for schema migration (the CLI `setup.php
 * update` flow). Fresh installs are handled separately by api/install.php.
 *
 * It exposes schema migration over HTTP as a stateless, two-phase handshake:
 *
 *   Phase 1 "plan"  — fetch the config from a URL, diff it against the live DB,
 *                     and return the proposed DDL statements (each with an id)
 *                     plus a signed `plan_token`. Nothing is written.
 *   Phase 2 "apply" — the caller echoes back the `plan_token` and the list of
 *                     action ids it approves. The server RE-computes the diff
 *                     from scratch, re-derives the token to prove the plan has
 *                     not drifted, then executes only the approved statements
 *                     and (when the whole plan is approved) regenerates the app
 *                     code from the same config.
 *
 * Auth is an HMAC handshake (no sessions, no API-key table): every request is
 * signed with the shared secret `$setup_hmac_secret` from _db_config.php. See
 * SETUP.md for the full contract and client examples.
 *
 * This file is reached directly (Apache serves real files before the channel
 * router in .htaccess), e.g. POST /app-runner/api/setup.php.
 */

// This file lives in api/, but setup.php and _db_config.php live in the project
// root one level up, so resolve every dependency against the root, not __DIR__.
define('SETUP_ROOT', dirname(__DIR__));

// Relative paths inside setup.php (templates/, channels/, Library/) resolve
// against the project root, so anchor the working directory there.
chdir(SETUP_ROOT);

header('Content-Type: application/json; charset=utf-8');

// Maximum allowed clock skew between client timestamp and server (seconds).
// Doubles as the replay window — a captured request is unusable after this.
const SETUP_API_MAX_SKEW = 300;

/** Emit a JSON response and stop. */
function respond($code, array $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

// ==============================
// BOOTSTRAP — DB + secret
// ==============================
if (!file_exists(SETUP_ROOT . '/_db_config.php')) {
    respond(500, ['error' => 'Setup incomplete: _db_config.php not found. Run the CLI installer first.']);
}
// Provides $pdo, $auth_pdo and $setup_hmac_secret. On a DB connection failure it
// emits its own 500 JSON and exits, so there is nothing to catch here.
include SETUP_ROOT . '/_db_config.php';

if (empty($setup_hmac_secret) || $setup_hmac_secret === '<SETUP_HMAC_SECRET>') {
    respond(500, ['error' => 'Setup API disabled: $setup_hmac_secret is not configured in _db_config.php.']);
}

// Reuse the canonical schema/diff/codegen logic. setup.php's CLI block is guarded
// by php_sapi_name() === 'cli', so requiring it over HTTP only loads its functions.
require_once SETUP_ROOT . '/setup.php';

// ==============================
// HMAC HANDSHAKE — authenticate the request
// ==============================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Method Not Allowed: use POST.']);
}

$raw_body  = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_SETUP_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_SETUP_SIGNATURE'] ?? '';

if ($timestamp === '' || $signature === '') {
    respond(401, ['error' => 'Missing X-Setup-Timestamp or X-Setup-Signature header.']);
}
if (!ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > SETUP_API_MAX_SKEW) {
    respond(401, ['error' => 'Request timestamp is invalid or outside the allowed window.']);
}

// signature = HMAC-SHA256( timestamp . "\n" . rawBody ). Signing the timestamp +
// the exact body authenticates the caller and pins the payload against tampering.
$expected_signature = hash_hmac('sha256', $timestamp . "\n" . $raw_body, $setup_hmac_secret);
if (!hash_equals($expected_signature, (string) $signature)) {
    respond(401, ['error' => 'Invalid request signature.']);
}

// ==============================
// PARSE + VALIDATE REQUEST
// ==============================
$request = json_decode($raw_body, true);
if (!is_array($request)) {
    respond(400, ['error' => 'Request body must be a JSON object.']);
}

$action     = $request['action']     ?? null;   // "plan" | "apply"
$config_url = $request['config_url'] ?? null;

if (!in_array($action, ['plan', 'apply'], true)) {
    respond(400, ['error' => "Field 'action' must be 'plan' or 'apply'."]);
}
if (!is_string($config_url) || $config_url === '') {
    respond(400, ['error' => "Field 'config_url' is required."]);
}

// ==============================
// FETCH CONFIG + COMPUTE DIFF (both phases — stateless)
// ==============================
$config = fetch_setup_config($config_url);          // ['content' => string, 'data' => array]
$object_models = flatten_object_models($config['data']);

$actions = compute_schema_diff($pdo, $object_models);   // ordered array of SQL strings

// plan_token binds the approved ids to the exact set of statements the caller saw.
// It is keyed by the secret, so the client cannot forge or alter it. Recomputed
// identically on apply: any drift in the diff changes the token and is rejected.
$plan_token = make_plan_token($actions, $setup_hmac_secret);

$proposed = [];
foreach ($actions as $id => $sql) {
    $proposed[] = ['id' => $id, 'sql' => $sql];
}

// ------------------------------
// PHASE 1: plan
// ------------------------------
if ($action === 'plan') {
    respond(200, [
        'up_to_date'  => empty($actions),
        'plan_token'  => $plan_token,
        'actions'     => $proposed,
    ]);
}

// ------------------------------
// PHASE 2: apply
// ------------------------------
$submitted_token = $request['plan_token'] ?? null;
$approve         = $request['approve']    ?? null;

if (!is_string($submitted_token) || !hash_equals($plan_token, $submitted_token)) {
    // Either a forged token or the schema changed since the plan was issued.
    respond(409, [
        'error'      => 'Plan token mismatch: the schema has changed since planning. Re-run plan.',
        'plan_token' => $plan_token,
        'actions'    => $proposed,
    ]);
}
if (!is_array($approve)) {
    respond(400, ['error' => "Field 'approve' must be an array of action ids."]);
}

// Normalise + validate the approved ids against the freshly computed plan.
$approved_ids = [];
foreach ($approve as $id) {
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
        respond(400, ['error' => "Field 'approve' must contain integer action ids."]);
    }
    $id = (int) $id;
    if (!array_key_exists($id, $actions)) {
        respond(400, ['error' => "Approved id $id is not part of the current plan."]);
    }
    $approved_ids[$id] = true;
}

// Execute approved statements in plan order (creates precede their FK additions).
// NOTE: MySQL DDL is non-transactional (each statement auto-commits), so this is
// best-effort sequential execution, not an atomic migration — results are reported
// per statement.
$results = [];
$all_ok  = true;
foreach ($actions as $id => $sql) {
    if (!isset($approved_ids[$id])) {
        $results[] = ['id' => $id, 'sql' => $sql, 'status' => 'skipped'];
        continue;
    }
    try {
        $pdo->exec($sql);
        $results[] = ['id' => $id, 'sql' => $sql, 'status' => 'executed'];
    } catch (\Throwable $e) {
        $all_ok = false;
        $results[] = ['id' => $id, 'sql' => $sql, 'status' => 'error', 'message' => $e->getMessage()];
    }
}

// Regenerate the app code (channels/ + Library/) from the same config ONLY when the
// caller approved the entire plan — otherwise the generated code could reference
// columns the approved-subset migration did not create. create_app() re-fetches the
// URL and rewrites Library/config.json so the running app matches the new schema.
$code_regenerated = false;
$regen_note = null;
$full_plan_approved = count($approved_ids) === count($actions);
if ($full_plan_approved && $all_ok) {
    create_app($config_url);
    $code_regenerated = true;
} elseif (!$full_plan_approved) {
    $regen_note = 'Partial approval: app code was NOT regenerated. Approve the full plan to sync code with schema.';
} elseif (!$all_ok) {
    $regen_note = 'One or more statements failed: app code was NOT regenerated.';
}

respond($all_ok ? 200 : 207, [
    'applied'          => $results,
    'code_regenerated' => $code_regenerated,
    'note'             => $regen_note,
]);


// ==============================
// HELPERS
// ==============================

/**
 * Fetch and decode the config artefact from a URL, mirroring create_app()'s
 * protocol handling (leave localhost as-is, default others to https://).
 * Returns ['content' => rawJson, 'data' => decodedArray] or ends the request.
 */
function fetch_setup_config($url) {
    $url_to_fetch = $url;
    if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
        if (strpos($url, 'localhost') === false && strpos($url, '127.0.0.1') === false) {
            $url_to_fetch = 'https://' . $url;
        }
    }

    $content = @file_get_contents($url_to_fetch);
    if ($content === false) {
        respond(400, ['error' => "Failed to fetch config from URL '$url_to_fetch'."]);
    }
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['object_models'])) {
        respond(400, ['error' => 'Fetched config is not valid JSON or is missing object_models.']);
    }
    return ['content' => $content, 'data' => $data];
}

/** Flatten config.object_models (keyed by title) into a single list, as setup.php does. */
function flatten_object_models(array $config) {
    $object_models = [];
    foreach ($config['object_models'] as $models_array) {
        $object_models = array_merge($object_models, $models_array);
    }
    return $object_models;
}

/** Deterministic token binding the exact ordered statement set to the secret. */
function make_plan_token(array $actions, $secret) {
    $canonical = json_encode(array_values($actions));
    return hash_hmac('sha256', $canonical, $secret);
}

?>
