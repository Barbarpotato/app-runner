<?php

// ponytail: no session here - this is the API entrypoint (auth via X-API-Key
// header in Bootloader.php), sessions are only used by app/index.php's admin panel.
// Starting a session here forced a file write + lock on every API request for nothing.

// Root API Bootloader
include 'Bootloader.php';

$bootloader = new Bootloader();
$bootloader->run();

?>