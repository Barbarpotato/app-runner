<?php

include __DIR__ . '/../../_db_config.php';

class Membership {
    private $pdo;

    public function __construct() {
        global $auth_pdo;
        $this->pdo = $auth_pdo;
    }

    public function getAll() {
        return $this->pdo->query("SELECT * FROM membership ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM membership WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // scope_name => [value, value, ...]
    public function getOwnershipValues($membership_id) {
        $stmt = $this->pdo->prepare("SELECT scope_name, value FROM membership_ownership_value WHERE membership_id = ? ORDER BY scope_name, value");
        $stmt->execute([$membership_id]);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[$row['scope_name']][] = $row['value'];
        }
        return $grouped;
    }

    public function getRoles($membership_id) {
        $stmt = $this->pdo->prepare("SELECT role_name FROM membership_role WHERE membership_id = ? ORDER BY role_name");
        $stmt->execute([$membership_id]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'role_name');
    }

    public function create($member_identifier, $label, array $ownership_values, array $roles = []) {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO membership (member_identifier, label) VALUES (?, ?)");
            $stmt->execute([$member_identifier, $label]);
            $id = $this->pdo->lastInsertId();
            $this->saveOwnershipValues($id, $ownership_values);
            $this->saveRoles($id, $roles);
            $this->pdo->commit();
            return $id;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function update($id, $label, array $ownership_values, array $roles = []) {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE membership SET label = ? WHERE id = ?");
            $stmt->execute([$label, $id]);
            $del = $this->pdo->prepare("DELETE FROM membership_ownership_value WHERE membership_id = ?");
            $del->execute([$id]);
            $this->saveOwnershipValues($id, $ownership_values);
            $del = $this->pdo->prepare("DELETE FROM membership_role WHERE membership_id = ?");
            $del->execute([$id]);
            $this->saveRoles($id, $roles);
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function saveOwnershipValues($membership_id, array $ownership_values) {
        $stmt = $this->pdo->prepare("INSERT INTO membership_ownership_value (membership_id, scope_name, value) VALUES (?, ?, ?)");
        foreach ($ownership_values as $scope_name => $values) {
            foreach ((array) $values as $value) {
                $value = trim((string) $value);
                if ($value === '') continue;
                $stmt->execute([$membership_id, $scope_name, $value]);
            }
        }
    }

    private function saveRoles($membership_id, array $roles) {
        $stmt = $this->pdo->prepare("INSERT INTO membership_role (membership_id, role_name) VALUES (?, ?)");
        foreach (array_unique($roles) as $role_name) {
            $role_name = trim((string) $role_name);
            if ($role_name === '') continue;
            $stmt->execute([$membership_id, $role_name]);
        }
    }

    public function delete($id) {
        // membership_ownership_value + membership_session cascade via FK ON DELETE CASCADE
        $stmt = $this->pdo->prepare("DELETE FROM membership WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function getTotalCount() {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM membership")->fetchColumn();
    }
}

?>
