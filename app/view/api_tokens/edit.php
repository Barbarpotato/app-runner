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
    </script>
</body>
</html>
