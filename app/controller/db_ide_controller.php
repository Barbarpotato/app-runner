<?php

include __DIR__ . '/../model/db_ide.php';

class DbIdeController {
    public function index() {
        include __DIR__ . '/../view/db_ide/index.php';
    }

    public function tables() {
        header("Content-Type: application/json");
        $model = new DbIde();
        echo json_encode($model->tables());
        exit;
    }

    public function columns() {
        header("Content-Type: application/json");
        $model = new DbIde();
        $t = $_GET['table'] ?? '';
        if (!$model->validTable($t)) {
            echo json_encode(["error" => "Unknown table"]);
            exit;
        }
        echo json_encode($model->columns($t));
        exit;
    }

    public function structure() {
        header("Content-Type: application/json");
        $model = new DbIde();
        $t = $_GET['table'] ?? '';
        if (!$model->validTable($t)) {
            echo json_encode(["error" => "Unknown table"]);
            exit;
        }
        echo json_encode([
            "columns" => $model->columns($t),
            "indexes" => $model->indexes($t),
            "status"  => $model->status($t),
        ]);
        exit;
    }

    public function data() {
        header("Content-Type: application/json");
        $model = new DbIde();
        $t = $_GET['table'] ?? '';
        if (!$model->validTable($t)) {
            echo json_encode(["error" => "Unknown table"]);
            exit;
        }

        $page = intval($_GET['page'] ?? 1);
        $orderBy = $_GET['sort'] ?? null;
        $orderDir = $_GET['dir'] ?? 'ASC';

        $allowedPerPage = [10, 25, 50, 100, 200];
        $perPage = intval($_GET['per_page'] ?? 50);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 50;
        }

        $filters = [];
        if (!empty($_GET['filters'])) {
            $decoded = json_decode($_GET['filters'], true);
            if (is_array($decoded)) {
                $filters = $decoded;
            }
        }

        echo json_encode([
            "rows"  => $model->data($t, $page, $orderBy, $orderDir, $filters, $perPage),
            "total" => $model->count($t, $filters),
        ]);
        exit;
    }

    public function query() {
        header("Content-Type: application/json");
        $model = new DbIde();
        $q = $_POST['q'] ?? '';

        if (!$model->isSafeSelect($q)) {
            echo json_encode(["error" => "Only SELECT allowed"]);
            exit;
        }

        try {
            echo json_encode($model->query($q));
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
        }
        exit;
    }
}

?>
