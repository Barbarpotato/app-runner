<?php $title = 'Error Logs - Dashboard'; $active = 'error_logs'; include __DIR__ . '/../header.php'; ?>
    <style>
        .log-toolbar .form-control {
            max-width: 320px;
        }
        .log-entry {
            border-left: 4px solid #adb5bd;
            border-radius: .375rem;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
            padding: .65rem .9rem;
            margin-bottom: .6rem;
        }
        .log-entry.level-fatal { border-left-color: #dc3545; }
        .log-entry.level-error { border-left-color: #fd7e14; }
        .log-entry.level-warning { border-left-color: #ffc107; }
        .log-entry.level-info { border-left-color: #6c757d; }
        .log-entry .log-timestamp {
            font-size: .75rem;
            color: #868e96;
            font-family: "SFMono-Regular", Consolas, monospace;
        }
        .log-entry .log-message {
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: .82rem;
            white-space: pre-wrap;
            word-break: break-word;
            margin-top: .25rem;
            max-height: 4.6em;
            overflow: hidden;
        }
        .log-entry .log-message.expanded {
            max-height: none;
        }
        .log-entry .log-expand-btn {
            font-size: .75rem;
            padding: 0;
        }
        .log-entry.hidden-by-filter {
            display: none;
        }
        #log-empty, #log-no-match {
            padding: 3rem 1rem;
            text-align: center;
            color: #9aa0b4;
        }
        #log-empty i, #log-no-match i {
            font-size: 2.2rem;
            margin-bottom: .5rem;
            display: block;
        }
    </style>

    <div class="container mt-4">
        <div class="row mb-3">
            <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h2 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Error Logs</h2>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light text-dark border"><?php echo count($entries); ?> entries</span>
                    <span class="badge bg-light text-dark border"><?php echo number_format($fileSize / 1024, 1); ?> KB</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 log-toolbar">
                    <div class="input-group input-group-sm" style="max-width:320px;">
                        <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                        <input type="text" id="log-search" class="form-control" placeholder="Search logs..." oninput="filterLogs(this.value)">
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <select id="level-filter" class="form-select form-select-sm" style="width:auto;" onchange="filterLogs(document.getElementById('log-search').value)">
                            <option value="">All levels</option>
                            <option value="fatal">Fatal</option>
                            <option value="error">Error</option>
                            <option value="warning">Warning</option>
                            <option value="info">Info</option>
                        </select>
                        <a href="?action=error_logs&method=download" class="btn btn-outline-secondary btn-sm"><i class="fas fa-download me-1"></i>Download</a>
                        <a href="?action=error_logs" class="btn btn-outline-secondary btn-sm"><i class="fas fa-rotate me-1"></i>Refresh</a>
                        <form method="POST" action="?action=error_logs&method=clear" onsubmit="return confirm('Clear the entire error log? This cannot be undone.');" class="d-inline">
                            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i>Clear log</button>
                        </form>
                    </div>
                </div>

                <?php if (empty($entries)): ?>
                    <div id="log-empty">
                        <i class="fas fa-inbox"></i>
                        No errors logged. Clean slate.
                    </div>
                <?php else: ?>
                    <div id="log-list">
                        <?php foreach ($entries as $i => $entry): ?>
                            <div class="log-entry level-<?php echo htmlspecialchars($entry['level']); ?>" data-search="<?php echo htmlspecialchars(strtolower($entry['raw'])); ?>" data-level="<?php echo htmlspecialchars($entry['level']); ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="log-timestamp"><?php echo htmlspecialchars($entry['timestamp'] ?? 'unknown time'); ?></span>
                                    <span class="badge bg-<?php echo $entry['level'] === 'fatal' ? 'danger' : ($entry['level'] === 'error' ? 'warning text-dark' : ($entry['level'] === 'warning' ? 'warning text-dark' : 'secondary')); ?>"><?php echo strtoupper($entry['level']); ?></span>
                                </div>
                                <div class="log-message" id="log-msg-<?php echo $i; ?>"><?php echo htmlspecialchars($entry['message']); ?></div>
                                <button type="button" class="btn btn-link log-expand-btn" onclick="toggleExpand(<?php echo $i; ?>)">Show more</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="log-no-match" style="display:none;">
                        <i class="fas fa-filter"></i>
                        No entries match your search.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<script>
function filterLogs(query) {
    const q = query.trim().toLowerCase();
    const level = document.getElementById('level-filter').value;
    const items = document.querySelectorAll('.log-entry');
    let visibleCount = 0;

    items.forEach(el => {
        const matchesText = !q || el.dataset.search.includes(q);
        const matchesLevel = !level || el.dataset.level === level;
        const show = matchesText && matchesLevel;
        el.classList.toggle('hidden-by-filter', !show);
        if (show) visibleCount++;
    });

    const noMatch = document.getElementById('log-no-match');
    if (noMatch) {
        noMatch.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

function toggleExpand(i) {
    const el = document.getElementById('log-msg-' + i);
    el.classList.toggle('expanded');
    const btn = el.nextElementSibling;
    btn.innerText = el.classList.contains('expanded') ? 'Show less' : 'Show more';
}
</script>

<?php include __DIR__ . '/../footer.php'; ?>
