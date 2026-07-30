<?php

include __DIR__ . '/../model/membership.php';
include __DIR__ . '/../csrf.php';
include __DIR__ . '/../../_db_config.php';

class MembershipController {

    // project_info.ownership_scopes, each entry enriched with 'suggestions' (object_ref: real
    // rows from the business DB; enum: the declared values; free_text: none).
    private function getScopesWithSuggestions() {
        global $pdo;
        $config = json_decode(file_get_contents(__DIR__ . '/../../Library/config.json'), true);
        $scopes = $config['project_info']['ownership_scopes'] ?? [];
        foreach ($scopes as &$scope) {
            $scope['suggestions'] = [];
            if (($scope['type'] ?? '') === 'object_ref' && !empty($scope['ref_object']) && !empty($scope['ref_field'])) {
                $table = $scope['ref_object'];
                $field = $scope['ref_field'];
                // Table/column names come from our own config.json (not user input) - safe to interpolate.
                $stmt = $pdo->query("SELECT DISTINCT `$field` FROM `$table` WHERE `$field` IS NOT NULL ORDER BY `$field` LIMIT 500");
                $scope['suggestions'] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), $field);
            } elseif (($scope['type'] ?? '') === 'enum') {
                $scope['suggestions'] = $scope['values'] ?? [];
            }
        }
        unset($scope);
        return $scopes;
    }

    // union of every channel's declared roles catalog (config.json channels[].roles),
    // e.g. ["operation", "spv operation"] on the "client" channel.
    private function getAllRoles() {
        $config = json_decode(file_get_contents(__DIR__ . '/../../Library/config.json'), true);
        $roles = [];
        foreach ($config['channels'] ?? [] as $channel) {
            foreach ($channel['roles'] ?? [] as $role) {
                $roles[$role] = true;
            }
        }
        return array_keys($roles);
    }

    // object_ref/enum values must match one of the scope's real domain (suggestions);
    // free_text is unrestricted. Returns an array of error strings, empty if all valid.
    private function validateOwnershipValues(array $scopes, array $ownership_values) {
        $errors = [];
        foreach ($scopes as $scope) {
            $type = $scope['type'] ?? 'free_text';
            if ($type === 'free_text') continue;
            $submitted = (array) ($ownership_values[$scope['name']] ?? []);
            $allowed = $scope['suggestions'] ?? [];
            foreach ($submitted as $value) {
                if (!in_array($value, $allowed, true)) {
                    $errors[] = "'{$value}' is not a valid value for '{$scope['name']}' ({$type}).";
                }
            }
        }
        return $errors;
    }

    public function index() {
        $membershipModel = new Membership();
        $memberships = $membershipModel->getAll();
        foreach ($memberships as &$m) {
            $m['ownership_values'] = $membershipModel->getOwnershipValues($m['id']);
            $m['roles'] = $membershipModel->getRoles($m['id']);
        }
        unset($m);
        include __DIR__ . '/../view/membership/index.php';
    }

    public function add() {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (!csrf_check($_POST['csrf'] ?? '')) {
                http_response_code(403);
                exit('Invalid CSRF token');
            }
            $ownership_values = [];
            if (!empty($_POST['ownership_values_json'])) {
                $decoded = json_decode($_POST['ownership_values_json'], true);
                if (is_array($decoded)) $ownership_values = $decoded;
            }
            $roles = array_filter((array) ($_POST['roles'] ?? []));
            $scopes = $this->getScopesWithSuggestions();
            $validationErrors = $this->validateOwnershipValues($scopes, $ownership_values);
            if (!empty($validationErrors)) {
                $error = implode(' ', $validationErrors);
                $existingOwnership = $ownership_values;
                $existingRoles = $roles;
                $allRoles = $this->getAllRoles();
                include __DIR__ . '/../view/membership/add.php';
                return;
            }
            $membershipModel = new Membership();
            try {
                $membershipModel->create(trim($_POST['member_identifier'] ?? ''), trim($_POST['label'] ?? ''), $ownership_values, $roles);
                header('Location: ?action=membership');
                exit;
            } catch (Exception $e) {
                $error = strpos($e->getMessage(), 'Duplicate entry') !== false
                    ? 'This member_identifier already has a membership.'
                    : 'Failed to create membership.';
                $existingOwnership = $ownership_values;
                $existingRoles = $roles;
                $allRoles = $this->getAllRoles();
                include __DIR__ . '/../view/membership/add.php';
                return;
            }
        }

        $scopes = $this->getScopesWithSuggestions();
        $existingOwnership = [];
        $existingRoles = [];
        $allRoles = $this->getAllRoles();
        include __DIR__ . '/../view/membership/add.php';
    }

    public function edit() {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            header('Location: ?action=membership');
            exit;
        }
        $membershipModel = new Membership();
        $membership = $membershipModel->getById($id);
        if (!$membership) {
            header('Location: ?action=membership');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (!csrf_check($_POST['csrf'] ?? '')) {
                http_response_code(403);
                exit('Invalid CSRF token');
            }
            $ownership_values = [];
            if (!empty($_POST['ownership_values_json'])) {
                $decoded = json_decode($_POST['ownership_values_json'], true);
                if (is_array($decoded)) $ownership_values = $decoded;
            }
            $roles = array_filter((array) ($_POST['roles'] ?? []));
            $scopes = $this->getScopesWithSuggestions();
            $validationErrors = $this->validateOwnershipValues($scopes, $ownership_values);
            if (!empty($validationErrors)) {
                $error = implode(' ', $validationErrors);
                $existingOwnership = $ownership_values;
                $existingRoles = $roles;
                $allRoles = $this->getAllRoles();
                include __DIR__ . '/../view/membership/edit.php';
                return;
            }
            $membershipModel->update($id, trim($_POST['label'] ?? ''), $ownership_values, $roles);
            header('Location: ?action=membership');
            exit;
        }

        $scopes = $this->getScopesWithSuggestions();
        $existingOwnership = $membershipModel->getOwnershipValues($id);
        $existingRoles = $membershipModel->getRoles($id);
        $allRoles = $this->getAllRoles();
        include __DIR__ . '/../view/membership/edit.php';
    }

    public function delete() {
        $id = $_GET['id'] ?? null;
        if (!csrf_check($_GET['csrf'] ?? '')) {
            http_response_code(403);
            exit('Invalid CSRF token');
        }
        if ($id) {
            $membershipModel = new Membership();
            $membershipModel->delete($id);
        }
        header('Location: ?action=membership');
        exit;
    }
}

?>
