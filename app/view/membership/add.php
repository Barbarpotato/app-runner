<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Membership - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .navbar-brand { font-weight: bold; }
        .card { border: none; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .value-tag {
            display: inline-flex; align-items: center; gap: 4px;
            background: #e9ecef; border-radius: 4px; padding: 2px 6px;
            font-size: 0.85em; margin: 2px;
        }
        .value-tag .remove-value { cursor: pointer; color: #dc3545; font-size: 1em; font-weight: bold; line-height: 1; }
        .value-tag .remove-value:hover { color: #a71d2a; }
        .value-input-row { display: flex; gap: 4px; margin-top: 4px; }
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
                        <h5><i class="fas fa-plus me-2"></i>Add Membership</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <form method="POST" id="membershipForm">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="ownership_values_json" id="ownershipValuesJson">
                            <div class="mb-3">
                                <label for="member_identifier" class="form-label">Member Identifier</label>
                                <input type="text" class="form-control" id="member_identifier" name="member_identifier" value="<?php echo htmlspecialchars($_POST['member_identifier'] ?? ''); ?>" placeholder="Reference to the member in user-services (not validated here)" required>
                            </div>
                            <div class="mb-3">
                                <label for="label" class="form-label">Label <span class="text-muted">(optional note)</span></label>
                                <input type="text" class="form-control" id="label" name="label" value="<?php echo htmlspecialchars($_POST['label'] ?? ''); ?>">
                            </div>
                            <hr>
                            <h6>Ownership Values</h6>
                            <p class="form-text text-muted">Type a value and press Enter or click Add. Suggestions are shown for <code>object_ref</code>/<code>enum</code> scopes.</p>
                            <?php if (empty($scopes)): ?>
                                <p class="text-muted">No ownership scopes declared for this project (<code>project_info.ownership_scopes</code> is empty).</p>
                            <?php endif; ?>
                            <?php foreach ($scopes as $scope): $sname = $scope['name']; ?>
                            <div class="mb-3 scope-row" data-scope="<?php echo htmlspecialchars($sname); ?>">
                                <label class="form-label">
                                    <?php echo htmlspecialchars($sname); ?>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($scope['type']); ?></span>
                                </label>
                                <div class="value-tags">
                                    <?php foreach (($existingOwnership[$sname] ?? []) as $v): ?>
                                    <span class="value-tag" data-value="<?php echo htmlspecialchars($v); ?>">
                                        <?php echo htmlspecialchars($v); ?>
                                        <span class="remove-value" onclick="removeValue(this)">&times;</span>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="value-input-row">
                                    <input type="text" class="form-control form-control-sm scope-value-input"
                                        <?php if (!empty($scope['suggestions'])): ?>list="datalist_<?php echo htmlspecialchars($sname); ?>"<?php endif; ?>
                                        placeholder="Type a value and press Enter or click Add">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addScopeValue(this)">Add Value</button>
                                </div>
                                <?php if (!empty($scope['suggestions'])): ?>
                                <datalist id="datalist_<?php echo htmlspecialchars($sname); ?>">
                                    <?php foreach ($scope['suggestions'] as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <div class="d-flex justify-content-between">
                                <a href="?action=membership" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>Back
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>Create Membership
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
        function getRowValues(row) {
            var tags = row.querySelectorAll('.value-tag');
            var values = [];
            for (var i = 0; i < tags.length; i++) values.push(tags[i].getAttribute('data-value'));
            return values;
        }

        function addScopeValue(btn) {
            var row = btn.closest('.scope-row');
            var input = row.querySelector('.scope-value-input');
            var val = input.value.trim();
            if (!val) return;
            if (getRowValues(row).indexOf(val) !== -1) { input.value = ''; return; }
            var tagsContainer = row.querySelector('.value-tags');
            var tag = document.createElement('span');
            tag.className = 'value-tag';
            tag.setAttribute('data-value', val);
            tag.innerHTML = val + '<span class="remove-value" onclick="removeValue(this)">&times;</span>';
            tagsContainer.appendChild(tag);
            input.value = '';
            input.focus();
        }

        function removeValue(btn) {
            btn.closest('.value-tag').remove();
        }

        document.getElementById('membershipForm').addEventListener('submit', function() {
            var result = {};
            document.querySelectorAll('.scope-row').forEach(function(row) {
                // Commit any value still sitting in the input (user typed but never clicked
                // Add/pressed Enter) so it isn't silently dropped on submit.
                var input = row.querySelector('.scope-value-input');
                if (input && input.value.trim()) {
                    addScopeValue(row.querySelector('.value-input-row .btn'));
                }
                var scope = row.getAttribute('data-scope');
                result[scope] = getRowValues(row);
            });
            document.getElementById('ownershipValuesJson').value = JSON.stringify(result);
        });

        document.addEventListener('keydown', function(e) {
            if (e.target && e.target.classList.contains('scope-value-input') && e.key === 'Enter') {
                e.preventDefault();
                addScopeValue(e.target.closest('.scope-row').querySelector('.value-input-row .btn'));
            }
        });
    </script>
</body>
</html>
