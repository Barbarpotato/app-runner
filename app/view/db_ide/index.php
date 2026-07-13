<?php
$title = 'DB IDE';
$active = 'db_ide';
include __DIR__ . '/../header.php';
?>
    <style>
        .db-ide-sidebar {
            position: sticky;
            top: 1rem;
        }
        #tables {
            max-height: 65vh;
            overflow-y: auto;
        }
        #tables .list-group-item {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: .5rem;
            border-left: 3px solid transparent;
        }
        #tables .list-group-item:hover {
            background: #f1f3f9;
        }
        #tables .list-group-item.active-table {
            background: #eef0fd;
            border-left-color: #667eea;
            color: #4c4fd6;
            font-weight: 600;
        }
        #table-count-badge {
            font-weight: normal;
        }
        .db-ide-tabs .nav-link {
            cursor: pointer;
        }
        #table-wrap {
            overflow: auto;
            max-height: 55vh;
        }
        #table-wrap {
            border: 1px solid #e9ecef;
            border-radius: .5rem;
        }
        .result-grid {
            font-size: .85rem;
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        .result-grid tbody tr:nth-child(even) {
            background: #fafbfc;
        }
        .result-grid tbody tr:hover {
            background: #eef0fd !important;
        }
        .result-grid thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8f9fa;
            white-space: nowrap;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            padding: .6rem .75rem;
        }
        .result-grid thead th .sort-col {
            cursor: pointer;
            user-select: none;
        }
        .result-grid thead th .sort-col:hover {
            color: #667eea;
        }
        .result-grid thead tr.filter-row th {
            position: sticky;
            top: 30px;
            z-index: 2;
            background: #f8f9fa;
            padding: .35rem .5rem;
            border-bottom: 2px solid #e9ecef;
        }
        .result-grid thead tr.filter-row input {
            font-size: .78rem;
            padding: 3px 8px;
            border-radius: 1rem;
        }
        .result-grid td {
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: default;
            padding: .5rem .75rem;
            border-bottom: 1px solid #f1f3f5;
            border-right: none;
            vertical-align: middle;
        }
        .result-grid td.expanded {
            white-space: pre-wrap;
            max-width: none;
        }
        .result-grid pre {
            margin: 0;
            white-space: pre-wrap;
            max-height: 200px;
            overflow: auto;
            font-size: .78rem;
        }
        .db-ide-empty {
            padding: 3rem 1rem;
            text-align: center;
            color: #9aa0b4;
        }
        .db-ide-empty i {
            font-size: 2.2rem;
            margin-bottom: .5rem;
            display: block;
        }
        .spinner-overlay {
            display: none;
            align-items: center;
            gap: .5rem;
            color: #6c757d;
            font-size: .9rem;
        }
        .spinner-overlay.active {
            display: flex;
        }
        #structure-pane .badge-key {
            font-size: .7rem;
        }

        /* SQL editor + autocomplete */
        .sql-editor-wrap {
            position: relative;
        }
        #query {
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: .9rem;
            resize: vertical;
        }
        .query-hint {
            font-size: .78rem;
        }
        #sql-suggest {
            display: none;
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            z-index: 10;
            background: #fff;
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 .375rem .375rem;
            max-height: 220px;
            overflow-y: auto;
            box-shadow: 0 6px 12px rgba(0,0,0,.08);
        }
        .sql-suggest-item {
            padding: 5px 10px;
            font-size: .85rem;
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        .sql-suggest-item.active,
        .sql-suggest-item:hover {
            background: #eef0fd;
        }
    </style>

    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0"><i class="fas fa-database me-2"></i>DB IDE</h3>
            <span class="badge bg-light text-dark border">Read only</span>
        </div>

        <!-- ===== SQL QUERY SECTION (standalone, not tied to a table) ===== -->
        <div id="sql-section" class="mb-4">
            <div class="card">
                <div class="card-body">
                    <label class="form-label mb-0" for="query"><i class="fas fa-terminal me-1"></i>SQL Query</label>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="query-hint text-muted">SELECT only &middot; Ctrl/Cmd+Enter to run &middot; Tab/Enter to accept a suggestion</span>
                    </div>

                    <div class="sql-editor-wrap">
                        <textarea id="query" class="form-control" rows="6" spellcheck="false" autocomplete="off">SELECT * FROM your_table</textarea>
                        <div id="sql-suggest"></div>
                    </div>

                    <div class="d-flex align-items-center gap-2 mt-3">
                        <button class="btn btn-primary" id="run-btn" onclick="runQuery()"><i class="fas fa-play me-1"></i>Run Query</button>
                        <div class="spinner-overlay" id="query-spinner"><i class="fas fa-spinner fa-spin"></i>Running&hellip;</div>
                    </div>

                    <div id="error" class="alert alert-danger mt-3 py-2 mb-3" style="display:none;"></div>

                    <div id="sql-result-wrap" class="mt-3" style="display:none; overflow:auto; max-height:50vh; border:1px solid #e9ecef; border-radius:.5rem;">
                        <table class="table table-hover table-sm result-grid" id="sql-result-table">
                            <thead class="table-light"><tr id="sql-table-head"></tr></thead>
                            <tbody id="sql-table-body"></tbody>
                        </table>
                    </div>
                    <div id="sql-no-rows" class="db-ide-empty" style="display:none;">
                        <i class="fas fa-inbox"></i>No rows returned.
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== TABLE BROWSER SECTION ===== -->
        <div id="browser-section" class="row">
            <div class="col-md-3 mb-4">
                <div class="card db-ide-sidebar">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0">Tables</label>
                            <span id="table-count-badge" class="badge bg-secondary"></span>
                        </div>
                        <div class="input-group input-group-sm mb-3">
                            <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" id="table-filter" placeholder="Filter tables..." oninput="filterTables(this.value)" disabled>
                        </div>
                        <div id="tables" class="list-group list-group-flush"></div>
                        <div id="tables-loading" class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Loading tables&hellip;</div>
                    </div>
                </div>
            </div>

            <div class="col-md-9 mb-4">
                <div class="card">
                    <div class="card-header bg-white">
                        <ul class="nav nav-tabs card-header-tabs db-ide-tabs" id="db-ide-tabs">
                            <li class="nav-item"><a class="nav-link active" data-tab="browse" onclick="switchTab('browse')"><i class="fas fa-table me-1"></i>Browse</a></li>
                            <li class="nav-item"><a class="nav-link" data-tab="structure" onclick="switchTab('structure')"><i class="fas fa-list me-1"></i>Structure</a></li>
                        </ul>
                    </div>
                    <div class="card-body">

                        <div id="browser-error" class="alert alert-danger py-2 mb-3" style="display:none;"></div>

                        <!-- BROWSE PANE -->
                        <div id="browse-pane">
                            <div id="no-table-empty" class="db-ide-empty">
                                <i class="fas fa-hand-pointer"></i>
                                Pick a table on the left to browse its data.
                            </div>

                            <div id="browse-content" style="display:none;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span id="result-summary" class="text-muted small"></span>
                                    <div class="d-flex align-items-center gap-2">
                                        <label for="per-page" class="form-label mb-0 small text-muted">Rows:</label>
                                        <select id="per-page" class="form-select form-select-sm" style="width:auto;" onchange="onPerPageChange(this.value)">
                                            <option value="10">10</option>
                                            <option value="25">25</option>
                                            <option value="50" selected>50</option>
                                            <option value="100">100</option>
                                            <option value="200">200</option>
                                        </select>
                                        <button class="btn btn-primary btn-sm" onclick="applyFilters()"><i class="fas fa-filter me-1"></i>Filter</button>
                                        <button class="btn btn-outline-secondary btn-sm" onclick="clearFilters()"><i class="fas fa-times me-1"></i>Clear filters</button>
                                    </div>
                                </div>

                                <div id="table-wrap">
                                    <table class="table table-hover table-sm result-grid" id="result-table">
                                        <thead class="table-light">
                                            <tr id="table-head"></tr>
                                            <tr id="filter-row" class="filter-row"></tr>
                                        </thead>
                                        <tbody id="table-body"></tbody>
                                    </table>
                                </div>

                                <div id="no-rows" class="db-ide-empty" style="display:none;">
                                    <i class="fas fa-inbox"></i>No rows found.
                                </div>

                                <div id="pagination-bar" class="d-flex align-items-center mt-3">
                                    <button class="btn btn-outline-secondary btn-sm" id="prev-btn" onclick="prevPage()"><i class="fas fa-chevron-left"></i> Prev</button>
                                    <span id="page" class="mx-3 fw-semibold"></span>
                                    <button class="btn btn-outline-secondary btn-sm" id="next-btn" onclick="nextPage()">Next <i class="fas fa-chevron-right"></i></button>
                                </div>
                            </div>
                        </div>

                        <!-- STRUCTURE PANE -->
                        <div id="structure-pane" style="display:none;">
                            <div id="no-table-structure-empty" class="db-ide-empty">
                                <i class="fas fa-hand-pointer"></i>
                                Pick a table on the left to see its structure.
                            </div>
                            <div id="structure-content" style="display:none;">
                                <div id="structure-status" class="mb-3 text-muted small"></div>
                                <h6>Columns</h6>
                                <div class="table-responsive mb-4">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>
                                        </thead>
                                        <tbody id="structure-columns"></tbody>
                                    </table>
                                </div>
                                <h6>Indexes</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr><th>Key name</th><th>Column</th><th>Unique</th><th>Type</th></tr>
                                        </thead>
                                        <tbody id="structure-indexes"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>

<script>
let tables = [];
let currentTable = null;
let page = 1;
let perPage = 50;
let sortCol = null;
let sortDir = 'ASC';
let filters = {};
let pendingFilters = {};

// ===== BROWSER SUB-TABS (Browse vs Structure) =====
function switchTab(tab) {
    document.querySelectorAll('#db-ide-tabs .nav-link').forEach(a => a.classList.toggle('active', a.dataset.tab === tab));
    document.getElementById('browse-pane').style.display = tab === 'browse' ? 'block' : 'none';
    document.getElementById('structure-pane').style.display = tab === 'structure' ? 'block' : 'none';
    if (tab === 'structure' && currentTable) loadStructure();
}

// ===== FETCH TABLES =====
async function loadTables() {
    const res = await fetch("?action=db_ide&method=tables");
    tables = await res.json();
    document.getElementById("tables-loading").style.display = "none";
    document.getElementById("table-filter").disabled = false;
    document.getElementById("table-count-badge").innerText = tables.length;
    renderTables(tables);
    ensureColumnsLoaded();
}

function renderTables(list) {
    const el = document.getElementById("tables");
    if (!list.length) {
        el.innerHTML = '<div class="text-muted small px-1">No matching tables</div>';
        return;
    }
    el.innerHTML = list.map(t =>
        `<div class="list-group-item ${t === currentTable ? 'active-table' : ''}" onclick="openTable('${t}')">
            <i class="fas fa-table text-muted"></i> ${t}
        </div>`
    ).join('');
}

function filterTables(q) {
    renderTables(tables.filter(t => t.toLowerCase().includes(q.toLowerCase())));
}

// ===== OPEN TABLE =====
function openTable(t) {
    currentTable = t;
    page = 1;
    sortCol = null;
    sortDir = 'ASC';
    filters = {};
    pendingFilters = {};
    renderTables(tables);
    hideBrowserError();

    document.getElementById("no-table-empty").style.display = "none";
    document.getElementById("browse-content").style.display = "block";
    document.getElementById("no-table-structure-empty").style.display = "none";
    document.getElementById("structure-content").style.display = "none";

    loadData();

    const activeTab = document.querySelector('#db-ide-tabs .nav-link.active').dataset.tab;
    if (activeTab === 'structure') loadStructure();
}

// ===== LOAD DATA (BROWSE) =====
async function loadData() {
    if (!currentTable) return;

    const params = new URLSearchParams({
        table: currentTable,
        page: page,
        per_page: perPage,
    });
    if (sortCol) {
        params.set('sort', sortCol);
        params.set('dir', sortDir);
    }
    if (Object.keys(filters).length) {
        params.set('filters', JSON.stringify(filters));
    }

    const res = await fetch(`?action=db_ide&method=data&${params.toString()}`);
    const payload = await res.json();

    if (payload.error) {
        showBrowserError(payload.error);
        return;
    }

    renderBrowseTable(payload.rows);
    updatePagination(payload.total);
}

function onPerPageChange(val) {
    perPage = parseInt(val, 10);
    page = 1;
    loadData();
}

// ===== JSON DETECTOR =====
function renderCell(val) {
    if (val === null) return '<span class="text-muted fst-italic">NULL</span>';
    try {
        let obj = JSON.parse(val);
        if (typeof obj !== 'object') throw 0;
        return `<pre>${JSON.stringify(obj, null, 2)}</pre>`;
    } catch {
        return String(val).replace(/</g, '&lt;');
    }
}

function sortArrow(col) {
    if (sortCol !== col) return '';
    return sortDir === 'ASC' ? ' <i class="fas fa-sort-up"></i>' : ' <i class="fas fa-sort-down"></i>';
}

// ===== RENDER BROWSE TABLE =====
function renderBrowseTable(rows) {
    const table = document.getElementById("result-table");
    const noRows = document.getElementById("no-rows");

    if (!rows.length && page === 1) {
        table.style.display = "none";
        document.getElementById("pagination-bar").style.display = "none";
        noRows.style.display = "block";
        return;
    }

    noRows.style.display = "none";
    table.style.display = "table";
    document.getElementById("pagination-bar").style.display = "flex";

    const cols = rows.length ? Object.keys(rows[0]) : Object.keys(filters);

    document.getElementById("table-head").innerHTML = cols.map(c =>
        `<th><span class="sort-col" onclick="toggleSort('${c}')">${c}${sortArrow(c)}</span></th>`
    ).join('');

    document.getElementById("filter-row").innerHTML = cols.map(c =>
        `<th><input type="text" class="form-control form-control-sm" placeholder="filter" value="${filters[c] ? filters[c].replace(/"/g,'&quot;') : ''}" oninput="onFilterInput('${c}', this.value)" onkeydown="if(event.key==='Enter') applyFilters()"></th>`
    ).join('');

    document.getElementById("table-body").innerHTML = rows.map(row =>
        '<tr>' + Object.values(row).map(v => `<td title="click to expand" onclick="this.classList.toggle('expanded')">${renderCell(v)}</td>`).join('') + '</tr>'
    ).join('');
}

function toggleSort(col) {
    if (sortCol === col) {
        sortDir = sortDir === 'ASC' ? 'DESC' : 'ASC';
    } else {
        sortCol = col;
        sortDir = 'ASC';
    }
    page = 1;
    loadData();
}

function onFilterInput(col, val) {
    if (val) {
        pendingFilters[col] = val;
    } else {
        delete pendingFilters[col];
    }
}

function applyFilters() {
    filters = { ...pendingFilters };
    page = 1;
    loadData();
}

function clearFilters() {
    filters = {};
    pendingFilters = {};
    page = 1;
    loadData();
}

function updatePagination(total) {
    document.getElementById("result-summary").innerText =
        `${total} row${total === 1 ? '' : 's'} in ${currentTable}`;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    document.getElementById("page").innerText = `Page ${page} of ${totalPages}`;
    document.getElementById("prev-btn").disabled = page <= 1;
    document.getElementById("next-btn").disabled = page >= totalPages;
}

// ===== PAGINATION =====
function nextPage() {
    page++;
    loadData();
}
function prevPage() {
    if (page > 1) {
        page--;
        loadData();
    }
}

// ===== STRUCTURE =====
async function loadStructure() {
    if (!currentTable) return;
    document.getElementById("no-table-structure-empty").style.display = "none";
    document.getElementById("structure-content").style.display = "block";

    const res = await fetch(`?action=db_ide&method=structure&table=${encodeURIComponent(currentTable)}`);
    const data = await res.json();
    if (data.error) {
        showBrowserError(data.error);
        return;
    }

    const s = data.status || {};
    document.getElementById("structure-status").innerHTML =
        `Engine: <strong>${s.Engine ?? '-'}</strong> &middot; Rows: <strong>${s.Rows ?? '-'}</strong> &middot; ` +
        `Data size: <strong>${s.Data_length ? (s.Data_length/1024).toFixed(1) + ' KB' : '-'}</strong> &middot; ` +
        `Collation: <strong>${s.Collation ?? '-'}</strong>`;

    document.getElementById("structure-columns").innerHTML = data.columns.map(c => `
        <tr>
            <td>${c.Field}</td>
            <td><code>${c.Type}</code></td>
            <td>${c.Null}</td>
            <td>${c.Key ? `<span class="badge bg-secondary badge-key">${c.Key}</span>` : ''}</td>
            <td>${c.Default ?? '<span class="text-muted">NULL</span>'}</td>
            <td>${c.Extra}</td>
        </tr>
    `).join('');

    document.getElementById("structure-indexes").innerHTML = data.indexes.map(i => `
        <tr>
            <td>${i.Key_name}</td>
            <td>${i.Column_name}</td>
            <td>${i.Non_unique == 0 ? 'Yes' : 'No'}</td>
            <td>${i.Index_type}</td>
        </tr>
    `).join('') || '<tr><td colspan="4" class="text-muted">No indexes</td></tr>';
}

// ===== LOADING / ERROR HELPERS =====
function setLoading(isLoading) {
    document.getElementById("run-btn").disabled = isLoading;
    document.getElementById("query-spinner").classList.toggle("active", isLoading);
}

function showError(msg) {
    const el = document.getElementById("error");
    el.innerText = msg;
    el.style.display = "block";
}

function hideError() {
    document.getElementById("error").style.display = "none";
}

function showBrowserError(msg) {
    const el = document.getElementById("browser-error");
    el.innerText = msg;
    el.style.display = "block";
}

function hideBrowserError() {
    document.getElementById("browser-error").style.display = "none";
}

// ===== SQL TAB: RESULTS =====
async function runQuery() {
    const q = document.getElementById("query").value.trim();
    if (!q) return;

    hideSuggestions();
    setLoading(true);
    hideError();

    const res = await fetch("?action=db_ide&method=query", {
        method: "POST",
        body: new URLSearchParams({ q })
    });

    const data = await res.json();
    setLoading(false);

    const resultWrap = document.getElementById("sql-result-wrap");
    const noRows = document.getElementById("sql-no-rows");

    if (data.error) {
        showError(data.error);
        resultWrap.style.display = "none";
        noRows.style.display = "none";
        return;
    }

    if (!data.length) {
        resultWrap.style.display = "none";
        noRows.style.display = "block";
        return;
    }

    noRows.style.display = "none";
    resultWrap.style.display = "block";

    const cols = Object.keys(data[0]);
    document.getElementById("sql-table-head").innerHTML = cols.map(c => `<th>${c}</th>`).join('');
    document.getElementById("sql-table-body").innerHTML = data.map(row =>
        '<tr>' + Object.values(row).map(v => `<td title="click to expand" onclick="this.classList.toggle('expanded')">${renderCell(v)}</td>`).join('') + '</tr>'
    ).join('');
}

// ===== SQL TAB: AUTOCOMPLETE =====
const SQL_KEYWORDS = [
    'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'IN', 'LIKE', 'IS', 'NULL',
    'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT', 'OFFSET', 'JOIN', 'LEFT JOIN',
    'RIGHT JOIN', 'INNER JOIN', 'ON', 'AS', 'DISTINCT', 'COUNT', 'SUM', 'AVG',
    'MIN', 'MAX', 'ASC', 'DESC', 'BETWEEN', 'UNION', 'EXISTS', 'CASE', 'WHEN',
    'THEN', 'ELSE', 'END',
];

let columnsCache = {};      // table -> [colName, ...]
let allColumnsFlat = [];    // [{name, table}, ...]
let columnsLoaded = false;

async function ensureColumnsLoaded() {
    if (columnsLoaded || !tables.length) return;
    columnsLoaded = true;
    await Promise.all(tables.map(async t => {
        try {
            const cols = await fetch(`?action=db_ide&method=columns&table=${encodeURIComponent(t)}`).then(r => r.json());
            if (Array.isArray(cols)) {
                columnsCache[t] = cols.map(c => c.Field);
                cols.forEach(c => allColumnsFlat.push({ name: c.Field, table: t }));
            }
        } catch {}
    }));
}

const queryEl = document.getElementById('query');
const suggestBox = document.getElementById('sql-suggest');
let currentSuggestions = [];
let suggestIndex = -1;

queryEl.addEventListener('input', updateSuggestions);
queryEl.addEventListener('click', updateSuggestions);
queryEl.addEventListener('keydown', handleSuggestKeys);
queryEl.addEventListener('blur', () => setTimeout(hideSuggestions, 150));

queryEl.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
        e.preventDefault();
        runQuery();
    }
});

function getCurrentToken() {
    const pos = queryEl.selectionStart;
    const text = queryEl.value.slice(0, pos);
    const match = text.match(/[a-zA-Z0-9_.]*$/);
    return { token: match[0], start: pos - match[0].length, pos };
}

function updateSuggestions() {
    const { token } = getCurrentToken();
    if (!token) { hideSuggestions(); return; }

    ensureColumnsLoaded();

    const lastDot = token.lastIndexOf('.');
    let pool;

    if (lastDot !== -1) {
        const tablePart = token.slice(0, lastDot);
        const colPrefix = token.slice(lastDot + 1).toLowerCase();
        pool = (columnsCache[tablePart] || [])
            .filter(c => c.toLowerCase().startsWith(colPrefix))
            .map(c => ({ label: c, insert: tablePart + '.' + c, type: 'column' }));
    } else {
        const prefix = token.toLowerCase();
        const kw = SQL_KEYWORDS.filter(k => k.toLowerCase().startsWith(prefix)).map(k => ({ label: k, insert: k, type: 'keyword' }));
        const tbl = tables.filter(t => t.toLowerCase().startsWith(prefix)).map(t => ({ label: t, insert: t, type: 'table' }));
        const col = allColumnsFlat.filter(c => c.name.toLowerCase().startsWith(prefix)).map(c => ({ label: c.name, insert: c.name, type: 'column', hint: c.table }));
        pool = [...kw, ...tbl, ...col].slice(0, 15);
    }

    if (!pool.length) { hideSuggestions(); return; }
    currentSuggestions = pool;
    suggestIndex = 0;
    renderSuggestions();
}

function renderSuggestions() {
    const typeColor = { keyword: 'secondary', table: 'primary', column: 'info' };
    suggestBox.innerHTML = currentSuggestions.map((s, i) =>
        `<div class="sql-suggest-item ${i === suggestIndex ? 'active' : ''}" onmousedown="applySuggestion(${i})">
            <span class="badge bg-${typeColor[s.type]} me-2">${s.type}</span>${s.label}${s.hint ? ` <small class="text-muted ms-1">${s.hint}</small>` : ''}
        </div>`
    ).join('');
    suggestBox.style.display = 'block';
}

function hideSuggestions() {
    suggestBox.style.display = 'none';
    currentSuggestions = [];
    suggestIndex = -1;
}

function applySuggestion(i) {
    const s = currentSuggestions[i];
    if (!s) return;
    const { start, pos } = getCurrentToken();
    const before = queryEl.value.slice(0, start);
    const after = queryEl.value.slice(pos);
    const insertText = s.insert + ' ';
    queryEl.value = before + insertText + after;
    const newPos = before.length + insertText.length;
    queryEl.focus();
    queryEl.setSelectionRange(newPos, newPos);
    hideSuggestions();
}

function handleSuggestKeys(e) {
    if (suggestBox.style.display !== 'block') return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        suggestIndex = (suggestIndex + 1) % currentSuggestions.length;
        renderSuggestions();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        suggestIndex = (suggestIndex - 1 + currentSuggestions.length) % currentSuggestions.length;
        renderSuggestions();
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        if (suggestIndex >= 0) {
            e.preventDefault();
            applySuggestion(suggestIndex);
        }
    } else if (e.key === 'Escape') {
        hideSuggestions();
    }
}

// INIT
loadTables();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
