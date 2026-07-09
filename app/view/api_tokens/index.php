<?php $title = 'API Tokens - Dashboard'; $active = 'api_tokens'; include __DIR__ . '/../header.php'; ?>
    <style>
        .table th { background-color: #f8f9fa; border-top: none; }
        .btn-action { margin-right: 5px; }
        .json-preview { font-family: monospace; font-size: 0.85em; color: #555; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>

    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h2><i class="fas fa-key me-2"></i>API Tokens</h2>
                    <a href="?action=api_tokens&method=add" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i>Add Token
                    </a>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Token</th>
                                        <th>Scopes</th>
                                        <th>Ownership Data Binding</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tokens)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No API tokens found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($tokens as $token): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($token['name']); ?></td>
                                                <td>
                                                    <code><?php echo htmlspecialchars(substr($token['token'], 0, 20) . '...'); ?></code>
                                                </td>
                                                <td><?php echo htmlspecialchars($token['scopes']); ?></td>
                                                <td>
                                                    <?php
                                                        $bindingData = json_decode($token['ownership_data_binding'] ?? '{}', true) ?: [];
                                                        if (empty($bindingData)):
                                                    ?>
                                                        <span class="text-muted">—</span>
                                                    <?php else: ?>
                                                        <span class="json-preview" title="<?php echo htmlspecialchars($token['ownership_data_binding']); ?>">
                                                            <?php echo htmlspecialchars($token['ownership_data_binding']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($token['created_at']); ?></td>
                                                <td>
                                                    <a href="?action=api_tokens&method=edit&id=<?php echo $token['id']; ?>" class="btn btn-sm btn-warning btn-action">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <a href="?action=api_tokens&method=delete&id=<?php echo $token['id']; ?>&csrf=<?php echo htmlspecialchars(csrf_token()); ?>" class="btn btn-sm btn-danger btn-action" onclick="return confirm('Are you sure you want to delete this token?')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/../footer.php'; ?>