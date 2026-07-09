<?php

include __DIR__ . '/../model/api_token.php';
include __DIR__ . '/../csrf.php';

class ApiTokensController {
    public function index() {
        $apiTokenModel = new ApiToken();
        $tokens = $apiTokenModel->getAll();
        include __DIR__ . '/../view/api_tokens/index.php';
    }

    public function add() {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (!csrf_check($_POST['csrf'] ?? '')) {
                http_response_code(403);
                exit('Invalid CSRF token');
            }
            $channel_list = isset($_POST['channel_list']) ? $_POST['channel_list'] : [];
            $data = [
                'token' => bin2hex(random_bytes(20)), // Generate random token
                'name' => $_POST['name'],
                'scopes' => $_POST['scopes'],
                'channel_list' => $channel_list
            ];
            $apiTokenModel = new ApiToken();
            $id = $apiTokenModel->create($data);
            header('Location: ?action=api_tokens&method=edit&id=' . $id);
            exit;
        }

        $config = json_decode(file_get_contents(__DIR__ . '/../../Library/config.json'), true);
        include __DIR__ . '/../view/api_tokens/add.php';
    }

    public function edit() {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header('Location: ?action=api_tokens');
            exit;
        }
        $apiTokenModel = new ApiToken();
        $token = $apiTokenModel->getById($id);
        if (!$token) {
            header('Location: ?action=api_tokens');
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (!csrf_check($_POST['csrf'] ?? '')) {
                http_response_code(403);
                exit('Invalid CSRF token');
            }
            $channel_list = isset($_POST['channel_list']) ? $_POST['channel_list'] : [];

            // Build ownership_data_binding JSON from the hidden field populated by JS on submit
            $ownership_data_binding = [];
            if (!empty($_POST['ownership_data_binding_json'])) {
                $decoded = json_decode($_POST['ownership_data_binding_json'], true);
                if (is_array($decoded)) {
                    $ownership_data_binding = $decoded;
                }
            }

            $data = [
                'name' => $_POST['name'],
                'scopes' => $_POST['scopes'],
                'channel_list' => $channel_list,
                'ownership_data_binding' => $ownership_data_binding
            ];
            $apiTokenModel->update($id, $data);
            header('Location: ?action=api_tokens');
            exit;
        }
        $config = json_decode(file_get_contents(__DIR__ . '/../../Library/config.json'), true);
        // Decode existing ownership_data_binding for the form
        $existingBinding = json_decode($token['ownership_data_binding'] ?? '{}', true) ?: [];
        include __DIR__ . '/../view/api_tokens/edit.php';
    }

    public function delete() {
        $id = $_GET['id'] ?? null;
        if (!csrf_check($_GET['csrf'] ?? '')) {
            http_response_code(403);
            exit('Invalid CSRF token');
        }
        if ($id) {
            $apiTokenModel = new ApiToken();
            $apiTokenModel->delete($id);
        }
        header('Location: ?action=api_tokens');
        exit;
    }
}

?>
