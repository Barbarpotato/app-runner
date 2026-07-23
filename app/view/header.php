<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title ?? 'Dashboard'; ?></title>
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
            <a class="navbar-brand" href="?action=index"><i class="fas fa-tachometer-alt me-2"></i>App Runner</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active ?? '') == 'index' ? 'active' : ''; ?>" href="?action=index"><i class="fas fa-home me-1"></i>Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active ?? '') == 'error_logs' ? 'active' : ''; ?>" href="?action=error_logs"><i class="fas fa-exclamation-triangle me-1"></i>Error Logs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active ?? '') == 'api_tokens' ? 'active' : ''; ?>" href="?action=api_tokens"><i class="fas fa-key me-1"></i>API Tokens</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active ?? '') == 'membership' ? 'active' : ''; ?>" href="?action=membership"><i class="fas fa-users me-1"></i>Membership</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active ?? '') == 'db_ide' ? 'active' : ''; ?>" href="?action=db_ide"><i class="fas fa-database me-1"></i>DB IDE</a>
                    </li>
                </ul>
                <div class="d-flex align-items-center">
                    <span class="navbar-text me-3">
                        <i class="fas fa-user me-1"></i>Welcome, <?php echo htmlspecialchars($_SESSION['user']['username']); ?>
                    </span>
                    <a href="?action=logout" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt me-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>