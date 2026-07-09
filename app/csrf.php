<?php

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check($token) {
    return hash_equals($_SESSION['csrf'] ?? '', $token ?? '');
}

?>
