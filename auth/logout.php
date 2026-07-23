<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$session_token = $_SERVER['HTTP_SESSION_TOKEN'] ?? '';
if ($session_token === '') {
    http_response_code(400);
    echo json_encode(['error' => 'session_token header missing']);
    exit;
}

global $auth_pdo;
$stmt = $auth_pdo->prepare("DELETE FROM membership_session WHERE session_token = ?");
$stmt->execute([$session_token]);

echo json_encode(['ok' => true]);

?>
