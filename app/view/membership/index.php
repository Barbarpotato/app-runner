<?php $title = 'Membership - Dashboard'; $active = 'membership'; include __DIR__ . '/../header.php'; ?>
    <style>
        .table th { background-color: #f8f9fa; border-top: none; }
        .btn-action { margin-right: 5px; }
        .value-tag { display: inline-block; background: #e9ecef; border-radius: 4px; padding: 1px 6px; font-size: 0.8em; margin: 1px; }
    </style>

    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h2><i class="fas fa-users me-2"></i>Membership</h2>
                    <a href="?action=membership&method=add" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i>Add Membership
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
                                        <th>Member Identifier</th>
                                        <th>Label</th>
                                        <th>Ownership Values</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($memberships)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center">No memberships found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($memberships as $m): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($m['member_identifier']); ?></td>
                                                <td><?php echo htmlspecialchars($m['label'] ?? ''); ?></td>
                                                <td>
                                                    <?php if (empty($m['ownership_values'])): ?>
                                                        <span class="text-muted">&mdash;</span>
                                                    <?php else: ?>
                                                        <?php foreach ($m['ownership_values'] as $scope_name => $values): ?>
                                                            <div><strong><?php echo htmlspecialchars($scope_name); ?>:</strong>
                                                                <?php foreach ($values as $v): ?>
                                                                    <span class="value-tag"><?php echo htmlspecialchars($v); ?></span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($m['created_at']); ?></td>
                                                <td>
                                                    <a href="?action=membership&method=edit&id=<?php echo $m['id']; ?>" class="btn btn-sm btn-warning btn-action">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <a href="?action=membership&method=delete&id=<?php echo $m['id']; ?>&csrf=<?php echo htmlspecialchars(csrf_token()); ?>" class="btn btn-sm btn-danger btn-action" onclick="return confirm('Are you sure you want to delete this membership?')">
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
