<?php

// Self-contained entrypoint - not routed through Bootloader.php/the channel system (login
// isn't a Superlindey-exported channel, it's app-runner's own local infrastructure).

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_db_config.php';
require_once __DIR__ . '/_user_service_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$member_identifier = isset($body['member_identifier']) ? trim($body['member_identifier']) : '';
$password = isset($body['password']) ? $body['password'] : '';

if ($member_identifier === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'member_identifier and password are required']);
    exit;
}

$identity = resolve_user_identity($member_identifier, $password);
if (!$identity) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

global $auth_pdo;
$stmt = $auth_pdo->prepare("SELECT id FROM membership WHERE member_identifier = ?");
$stmt->execute([$identity]);
$membership = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$membership) {
    http_response_code(403);
    echo json_encode(['error' => 'No membership provisioned for this member']);
    exit;
}

// Single active session per member: kill this member's old session(s) before minting a new
// one (logging in elsewhere invalidates the previous session). Piggyback a global
// expired-row sweep on every login too, so the table stays bounded without a separate cron job.
// ponytail: sweep only runs when SOMEONE logs in - fine while login traffic is regular; a
// proper cron-based purge is the upgrade path if traffic ever goes quiet for long stretches.
$stmt = $auth_pdo->prepare("DELETE FROM membership_session WHERE membership_id = ?");
$stmt->execute([$membership['id']]);
$auth_pdo->exec("DELETE FROM membership_session WHERE expires_at < NOW()");

// Hard 2-hour expiry, no refresh mechanism - client re-logs in through this same endpoint
// (which re-hits user-services) once expired.
$session_token = bin2hex(random_bytes(32));
$expires_at = date('Y-m-d H:i:s', time() + 2 * 3600);

$stmt = $auth_pdo->prepare("INSERT INTO membership_session (membership_id, session_token, expires_at) VALUES (?, ?, ?)");
$stmt->execute([$membership['id'], $session_token, $expires_at]);

echo json_encode([
    'session_token' => $session_token,
    'expires_at' => $expires_at,
]);

?>
