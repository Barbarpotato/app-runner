<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit API Token - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .navbar-brand { font-weight: bold; }
        .card { border: none; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .binding-table th { background-color: #f8f9fa; border-top: none; font-size: 0.85em; text-transform: uppercase; color: #666; }
        .binding-table td { vertical-align: middle; }
        .value-tag {
            display: inline-flex; align-items: center; gap: 4px;
            background: #e9ecef; border-radius: 4px; padding: 2px 6px;
            font-size: 0.85em; margin: 2px;
        }
        .value-tag .remove-value { cursor: pointer; color: #dc3545; font-size: 1em; font-weight: bold; line-height: 1; }
        .value-tag .remove-value:hover { color: #a71d2a; }
        .value-input-row { display: flex; gap: 4px; margin-top: 4px; }
        .dup-error { color: #dc3545; font-size: 0.8em; margin-top: 2px; display: none; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="?action=index"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a>
            <div class="d-flex align-items-center">
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i>Welcome, <?php echo htmlspecialchars($_SESSION['user']['username']); ?>
                </span>
                <a href="?action=logout" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-edit me-2"></i>Edit API Token</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="tokenForm">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="ownership_data_binding_json" id="ownershipDataBindingJson">
                            <div class="mb-3">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($token['name']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="scopes" class="form-label">Scopes</label>
                                <select class="form-select" id="scopes" name="scopes" required>
                                    <option value="read" <?php echo $token['scopes'] == 'read' ? 'selected' : ''; ?>>Read</option>
                                    <option value="write" <?php echo $token['scopes'] == 'write' ? 'selected' : ''; ?>>Write</option>
                                    <option value="all" <?php echo $token['scopes'] == 'all' ? 'selected' : ''; ?>>All</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Channel List</label>
                                <?php
                                $channels = $config['channels'] ?? [];
                                $selected_channels = json_decode($token['channel_list'] ?? '[]', true);
                                if (!is_array($selected_channels)) $selected_channels = [];
                                foreach ($channels as $channel):
                                    $is_checked = in_array($channel['channel_name'], $selected_channels) ? 'checked' : '';
                                ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="channel_list[]" value="<?php echo htmlspecialchars($channel['channel_name']); ?>" id="channel_<?php echo htmlspecialchars($channel['channel_name']); ?>" <?php echo $is_checked; ?>>
                                    <label class="form-check-label" for="channel_<?php echo htmlspecialchars($channel['channel_name']); ?>">
                                        <?php echo htmlspecialchars($channel['channel_name']); ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Token</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="token" value="<?php echo htmlspecialchars($token['token']); ?>" readonly>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleToken">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="form-text text-muted">Token cannot be changed.</small>
                            </div>
                            <hr>
                            <div class="mb-3">
                                <label class="form-label">Ownership Data Binding</label>
                                <p class="form-text text-muted">
                                    Each row is a JSON key. Click <strong>+ Add Value</strong> to append individual values to that key's array.
                                    Use the <strong>*</strong> button for a wildcard value. Duplicate values within the same key are not allowed.
                                </p>
                                <div class="table-responsive">
                                    <table class="table table-bordered binding-table" id="bindingTable">
                                        <thead>
                                            <tr>
                                                <th style="width: 30%">Key</th>
                                                <th style="width: 50%">Values</th>
                                                <th style="width: 10%">Wildcard</th>
                                                <th style="width: 10%">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="bindingRows">
                                            <?php
                                            $existingBinding = json_decode($token['ownership_data_binding'] ?? '{}', true) ?: [];
                                            if (!empty($existingBinding)):
                                                foreach ($existingBinding as $key => $values):
                                            ?>
                                            <tr class="data-row" data-key-row>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm binding-key" name="binding_keys[]" value="<?php echo htmlspecialchars($key); ?>" placeholder="e.g. member_number" required>
                                                </td>
                                                <td>
                                                    <div class="value-tags">
                                                        <?php foreach ($values as $v): ?>
                                                        <span class="value-tag" data-value="<?php echo htmlspecialchars($v); ?>">
                                                            <?php echo htmlspecialchars($v); ?>
                                                            <span class="remove-value" title="Remove" onclick="removeValue(this)">&times;</span>
                                                        </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="value-input-row">
                                                        <input type="text" class="form-control form-control-sm binding-value-input" placeholder="Type a value and press Enter or click Add">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addValue(this)">Add Value</button>
                                                    </div>
                                                    <div class="dup-error">This value already exists in this key.</div>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-outline-warning btn-sm" title="Add wildcard *" onclick="addWildcard(this)">*</button>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)" title="Remove row">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php
                                                endforeach;
                                            else:
                                            ?>
                                            <tr class="data-row" data-key-row>
                                                <td>
                                                    <input type="text" class="form-control form-control-sm binding-key" name="binding_keys[]" placeholder="e.g. member_number">
                                                </td>
                                                <td>
                                                    <div class="value-tags"></div>
                                                    <div class="value-input-row">
                                                        <input type="text" class="form-control form-control-sm binding-value-input" placeholder="Type a value and press Enter or click Add">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addValue(this)">Add Value</button>
                                                    </div>
                                                    <div class="dup-error">This value already exists in this key.</div>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-outline-warning btn-sm" title="Add wildcard *" onclick="addWildcard(this)">*</button>
                                                </td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)" title="Remove row">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addKeyRow()">
                                    <i class="fas fa-plus me-1"></i>Add Key Row
                                </button>
                            </div>
                            <div class="d-flex justify-content-between">
                                <a href="?action=api_tokens" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>Back
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>Update Token
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('toggleToken').addEventListener('click', function() {
            var tokenInput = document.getElementById('token');
            var icon = this.querySelector('i');
            if (tokenInput.type === 'password') {
                tokenInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                tokenInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        function getRowValues(row) {
            var tags = row.querySelectorAll('.value-tag');
            var values = [];
            for (var i = 0; i < tags.length; i++) {
                values.push(tags[i].getAttribute('data-value'));
            }
            return values;
        }

        function refreshValueInputState(row) {
            var input = row.querySelector('.binding-value-input');
            var addBtn = row.querySelector('.value-input-row .btn');
            var values = getRowValues(row);
            if (input) {
                if (values.indexOf('*') !== -1) {
                    input.disabled = true;
                    input.placeholder = 'Wildcard * is active — remove it to add values';
                    if (addBtn) addBtn.disabled = true;
                } else {
                    input.disabled = false;
                    input.placeholder = 'Type a value and press Enter or click Add';
                    if (addBtn) addBtn.disabled = false;
                }
            }
        }

        function addValue(btn) {
            var row = btn.closest('tr');
            var input = row.querySelector('.binding-value-input');
            var val = input.value.trim();
            if (!val) return;
            var existing = getRowValues(row);
            if (existing.indexOf(val) !== -1) {
                row.querySelector('.dup-error').style.display = 'block';
                input.focus();
                return;
            }
            row.querySelector('.dup-error').style.display = 'none';
            var tagsContainer = row.querySelector('.value-tags');
            var tag = document.createElement('span');
            tag.className = 'value-tag';
            tag.setAttribute('data-value', val);
            tag.innerHTML = val + '<span class="remove-value" title="Remove" onclick="removeValue(this)">&times;</span>';
            tagsContainer.appendChild(tag);
            input.value = '';
            input.focus();
        }

        function addWildcard(btn) {
            var row = btn.closest('tr');
            var tagsContainer = row.querySelector('.value-tags');
            var existing = getRowValues(row);
            if (existing.indexOf('*') !== -1) return;
            var tag = document.createElement('span');
            tag.className = 'value-tag';
            tag.setAttribute('data-value', '*');
            tag.innerHTML = '*' + '<span class="remove-value" title="Remove" onclick="removeValue(this)">&times;</span>';
            tagsContainer.appendChild(tag);
            refreshValueInputState(row);
        }

        function removeValue(btn) {
            btn.closest('.value-tag').remove();
            var row = btn.closest('tr');
            refreshValueInputState(row);
        }

        function removeRow(btn) {
            btn.closest('tr').remove();
        }

        function addKeyRow() {
            var tbody = document.getElementById('bindingRows');
            var tr = document.createElement('tr');
            tr.className = 'data-row';
            tr.setAttribute('data-key-row', '');
            tr.innerHTML =
                '<td><input type="text" class="form-control form-control-sm binding-key" name="binding_keys[]" placeholder="e.g. member_number"></td>' +
                '<td><div class="value-tags"></div><div class="value-input-row"><input type="text" class="form-control form-control-sm binding-value-input" placeholder="Type a value and press Enter or click Add"><button type="button" class="btn btn-outline-secondary btn-sm" onclick="addValue(this)">Add Value</button></div><div class="dup-error">This value already exists in this key.</div></td>' +
                '<td class="text-center"><button type="button" class="btn btn-outline-warning btn-sm" title="Add wildcard *" onclick="addWildcard(this)">*</button></td>' +
                '<td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)" title="Remove row"><i class="fas fa-trash"></i></button></td>';
            tbody.appendChild(tr);
            refreshValueInputState(tr);
        }

        // Initialize wildcard state for pre-existing rows on page load
        document.addEventListener('DOMContentLoaded', function() {
            var rows = document.querySelectorAll('#bindingRows tr[data-key-row]');
            for (var i = 0; i < rows.length; i++) {
                refreshValueInputState(rows[i]);
            }
        });

        // Before submit, collect all key-value pairs into a JSON hidden field
        document.getElementById('tokenForm').addEventListener('submit', function() {
            var binding = {};
            var rows = document.querySelectorAll('#bindingRows tr[data-key-row]');
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var keyInput = row.querySelector('.binding-key');
                var key = keyInput ? keyInput.value.trim() : '';
                if (!key) continue;
                var tags = row.querySelectorAll('.value-tag');
                var values = [];
                for (var k = 0; k < tags.length; k++) {
                    values.push(tags[k].getAttribute('data-value'));
                }
                binding[key] = values;
            }
            document.getElementById('ownershipDataBindingJson').value = JSON.stringify(binding);
        });

        // Allow Enter key to add a value
        document.addEventListener('keydown', function(e) {
            if (e.target && e.target.classList.contains('binding-value-input') && e.key === 'Enter') {
                e.preventDefault();
                addValue(e.target);
            }
        });
    </script>
</body>
</html>
