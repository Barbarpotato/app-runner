<?php
$title = 'Dashboard';
$active = 'index';
include __DIR__ . '/header.php';
?>
    <style>
        .card:hover {
            transform: translateY(-5px);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .stat-card .card-body {
            text-align: center;
        }
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .welcome-card {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
    </style>

    <div class="container mt-4">

        <div class="mb-4">
            <div class="card-body">
                <h3 class="card-title">Welcome to the App Runner</h3>
                <p class="card-text">Here you can update the app.json file, see the error logs, and manage your API tokens. Use the navigation menu to access different sections of the dashboard.</p>
            </div>
        </div>


        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="stat-icon">
                            <i class="fas fa-key"></i>
                        </div>
                        <h5 class="card-title">API Tokens</h5>
                        <p class="card-text display-4"><?php echo $apiTokenCount; ?></p>
                    </div>
                </div>
            </div>
        </div>


    </div>

<?php include __DIR__ . '/footer.php'; ?>