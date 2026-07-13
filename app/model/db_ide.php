<?php

include __DIR__ . '/../../_db_config.php';

class DbIde {
    private $pdo;

    public function __construct() {
        include __DIR__ . '/../../_db_config.php';
        global $pdo;
        $this->pdo = $pdo;
    }

    public function tables() {
        return $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function validTable($t) {
        static $tables = null;
        if ($tables === null) {
            $tables = $this->tables();
        }
        return in_array($t, $tables, true);
    }

    public function columns($t) {
        return $this->pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function columnNames($t) {
        return array_column($this->columns($t), 'Field');
    }

    public function indexes($t) {
        return $this->pdo->query("SHOW INDEX FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function status($t) {
        return $this->pdo->query("SHOW TABLE STATUS LIKE '$t'")->fetch(PDO::FETCH_ASSOC);
    }

    // $filters: [column => value] equality filters, values are bound (safe from injection)
    // $orderBy/$orderDir are whitelisted against real column names / ASC|DESC before use
    public function data($t, $page, $orderBy = null, $orderDir = 'ASC', $filters = [], $perPage = 50) {
        $limit = $perPage;
        $offset = (max(1, $page) - 1) * $limit;

        $validColumns = $this->columnNames($t);
        $where = '';
        $params = [];

        if (!empty($filters)) {
            $clauses = [];
            foreach ($filters as $col => $val) {
                if (!in_array($col, $validColumns, true) || $val === '') continue;
                $clauses[] = "`$col` LIKE ?";
                $params[] = "%$val%";
            }
            if ($clauses) {
                $where = " WHERE " . implode(' AND ', $clauses);
            }
        }

        $order = '';
        if ($orderBy !== null && in_array($orderBy, $validColumns, true)) {
            $dir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $order = " ORDER BY `$orderBy` $dir";
        }

        $stmt = $this->pdo->prepare("SELECT * FROM `$t`$where$order LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count($t, $filters = []) {
        $validColumns = $this->columnNames($t);
        $where = '';
        $params = [];

        if (!empty($filters)) {
            $clauses = [];
            foreach ($filters as $col => $val) {
                if (!in_array($col, $validColumns, true) || $val === '') continue;
                $clauses[] = "`$col` LIKE ?";
                $params[] = "%$val%";
            }
            if ($clauses) {
                $where = " WHERE " . implode(' AND ', $clauses);
            }
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `$t`$where");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function isSafeSelect($q) {
        return preg_match('/^\s*SELECT/i', $q);
    }

    public function query($q) {
        return $this->pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>
