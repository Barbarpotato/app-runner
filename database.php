<?php
session_start();

// ponytail: reuses the same session gate as app/index.php - no separate auth system
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit('Forbidden');
}

// ===== CONFIG =====
$HOST = "mysql";
$DB   = "test";
$USER = "root";
$PASS = "root";

// ===== CONNECT =====
function db() {
    global $HOST, $DB, $USER, $PASS;
    return new PDO(
        "mysql:host=$HOST;dbname=$DB",
        $USER,
        $PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

// ===== SAFE QUERY =====
function safe($q) {
    return preg_match('/^\s*SELECT/i', $q);
}

// only allow operating on tables that actually exist in this DB
function valid_table($pdo, $t) {
    static $tables = null;
    if ($tables === null) {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    }
    return in_array($t, $tables, true);
}

// ===== API MODE =====
if (isset($_GET['api'])) {
    header("Content-Type: application/json");
    $pdo = db();

    $action = $_GET['api'];

    if ($action === "tables") {
        echo json_encode(
            $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN)
        );
        exit;
    }

    if ($action === "columns") {
        $t = $_GET['table'] ?? '';
        if (!valid_table($pdo, $t)) {
            echo json_encode(["error" => "Unknown table"]);
            exit;
        }
        echo json_encode(
            $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC)
        );
        exit;
    }

    if ($action === "data") {
        $t = $_GET['table'] ?? '';
        if (!valid_table($pdo, $t)) {
            echo json_encode(["error" => "Unknown table"]);
            exit;
        }
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        echo json_encode(
            $pdo->query("SELECT * FROM `$t` LIMIT $limit OFFSET $offset")
                ->fetchAll(PDO::FETCH_ASSOC)
        );
        exit;
    }

    if ($action === "query") {
        $q = $_POST['q'] ?? '';

        if (!safe($q)) {
            echo json_encode(["error" => "Only SELECT allowed"]);
            exit;
        }

        try {
            $res = $pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($res);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>PHP DB IDE</title>
<style>
body {
    margin:0;
    font-family: Arial;
    display:flex;
    background:#0f172a;
    color:#e2e8f0;
}
.sidebar {
    width:250px;
    background:#020617;
    height:100vh;
    overflow:auto;
    padding:10px;
}
.sidebar input {
    width:100%;
    padding:8px;
    margin-bottom:10px;
    background:#0f172a;
    border:none;
    color:white;
}
.table-item {
    padding:8px;
    cursor:pointer;
}
.table-item:hover {
    background:#1e293b;
}
.main {
    flex:1;
    padding:20px;
}
textarea {
    width:100%;
    height:100px;
    background:black;
    color:#00ffcc;
}
button {
    padding:8px 12px;
    margin-top:5px;
    cursor:pointer;
}
table {
    width:100%;
    border-collapse: collapse;
    margin-top:10px;
    font-size:12px;
}
th, td {
    border:1px solid #334155;
    padding:6px;
}
th {
    background:#1e293b;
    position:sticky;
    top:0;
}
pre {
    margin:0;
    white-space:pre-wrap;
}
.pagination {
    margin-top:10px;
}
</style>
</head>
<body>

<div class="sidebar">
<input placeholder="Filter tables..." oninput="filterTables(this.value)">
<div id="tables"></div>
</div>

<div class="main">

<h2>🧠 PHP DB IDE (Read Only)</h2>

<textarea id="query">SELECT * FROM your_table</textarea>
<br>
<button onclick="runQuery()">Run Query</button>

<div id="error" style="color:red;"></div>

<div id="table"></div>

<div class="pagination">
<button onclick="prev()">Prev</button>
<span id="page">1</span>
<button onclick="next()">Next</button>
</div>

</div>

<script>
let tables = [];
let currentTable = null;
let page = 1;

// ===== FETCH TABLES =====
async function loadTables() {
    const res = await fetch("?api=tables");
    tables = await res.json();
    renderTables(tables);
}

function renderTables(list) {
    const el = document.getElementById("tables");
    el.innerHTML = list.map(t =>
        `<div class="table-item" onclick="openTable('${t}')">${t}</div>`
    ).join('');
}

function filterTables(q) {
    renderTables(tables.filter(t => t.toLowerCase().includes(q.toLowerCase())));
}

// ===== OPEN TABLE =====
async function openTable(t) {
    currentTable = t;
    page = 1;
    loadData();
}

// ===== LOAD DATA =====
async function loadData() {
    if (!currentTable) return;

    const [data, columns] = await Promise.all([
        fetch(`?api=data&table=${currentTable}&page=${page}`).then(r=>r.json()),
        fetch(`?api=columns&table=${currentTable}`).then(r=>r.json())
    ]);

    renderTable(data, columns);
    document.getElementById("page").innerText = page;
}

// ===== JSON DETECTOR =====
function renderCell(val) {
    try {
        let obj = JSON.parse(val);
        return `<pre>${JSON.stringify(obj, null, 2)}</pre>`;
    } catch {
        return val;
    }
}

// ===== RENDER TABLE =====
function renderTable(data, columns) {
    if (!data.length) {
        document.getElementById("table").innerHTML = "No data";
        return;
    }

    let html = "<table><thead><tr>";

    columns.forEach(c => {
        html += `<th>${c.Field}<br><small>${c.Type}</small></th>`;
    });

    html += "</tr></thead><tbody>";

    data.forEach(row => {
        html += "<tr>";
        Object.values(row).forEach(v => {
            html += `<td>${renderCell(v)}</td>`;
        });
        html += "</tr>";
    });

    html += "</tbody></table>";

    document.getElementById("table").innerHTML = html;
}

// ===== PAGINATION =====
function next() {
    page++;
    loadData();
}
function prev() {
    if (page > 1) {
        page--;
        loadData();
    }
}

// ===== QUERY =====
async function runQuery() {
    const q = document.getElementById("query").value;

    const res = await fetch("?api=query", {
        method: "POST",
        body: new URLSearchParams({ q })
    });

    const data = await res.json();

    if (data.error) {
        document.getElementById("error").innerText = data.error;
        return;
    }

    document.getElementById("error").innerText = "";
    renderTable(data, Object.keys(data[0] || {}).map(k => ({Field:k, Type:""})));
}

// INIT
loadTables();
</script>

</body>
</html>