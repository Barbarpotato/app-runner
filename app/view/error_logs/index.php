<?php $title = 'Error Logs - Dashboard'; $active = 'error_logs'; include __DIR__ . '/../header.php'; ?>
    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <h2><i class="fas fa-exclamation-triangle me-2"></i>Error Logs</h2>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <pre style="max-height: 600px; overflow-y: auto;"><?php 
                        $log_file = ROOT_PATH . '/logs/error.log';
                        if (file_exists($log_file)):
                            echo htmlspecialchars(file_get_contents($log_file));
                        else:
                            echo 'No error log found.';
                        endif;
                        ?></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php include __DIR__ . '/../footer.php'; ?>