<?php

include __DIR__ . '/../model/api_token.php';

class IndexController {
    public function index() {
        $apiTokenModel = new ApiToken();
        $apiTokenCount = $apiTokenModel->getTotalCount();

        include __DIR__ . '/../view/index.php';
    }

    public function logout() {
        session_start();
        session_destroy();
        header('Location: ?action=login');
        exit;
    }
}