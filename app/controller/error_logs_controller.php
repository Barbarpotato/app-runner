<?php

class ErrorLogsController {
    private function logPath() {
        return ROOT_PATH . '/logs/error.log';
    }

    public function index() {
        $log_file = $this->logPath();
        $raw = file_exists($log_file) ? file_get_contents($log_file) : '';
        $entries = $this->parseEntries($raw);
        $fileSize = file_exists($log_file) ? filesize($log_file) : 0;

        include __DIR__ . '/../view/error_logs/index.php';
    }

    public function clear() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $log_file = $this->logPath();
            if (file_exists($log_file)) {
                file_put_contents($log_file, '');
            }
        }
        header('Location: ?action=error_logs');
        exit;
    }

    public function download() {
        $log_file = $this->logPath();
        if (!file_exists($log_file)) {
            http_response_code(404);
            echo 'No log file found.';
            exit;
        }
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="error.log"');
        header('Content-Length: ' . filesize($log_file));
        readfile($log_file);
        exit;
    }

    // splits the raw log on entry boundaries (either date format PHP's native
    // error_log or our own custom "[Y-m-d H:i:s]" prefix can produce) and
    // classifies each entry's severity for display, newest first.
    private function parseEntries($raw) {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}\]|\[\d{1,2}-[A-Za-z]{3}-\d{4})/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        $entries = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $part, $m);
            $timestamp = $m[1] ?? null;
            $message = $m[2] ?? $part;

            $level = 'info';
            if (stripos($part, 'fatal error') !== false || stripos($part, 'internal server error') !== false) {
                $level = 'fatal';
            } elseif (stripos($part, 'warning') !== false) {
                $level = 'warning';
            } elseif (stripos($part, 'error') !== false) {
                $level = 'error';
            }

            $entries[] = [
                'timestamp' => $timestamp,
                'message'   => $message,
                'level'     => $level,
                'raw'       => $part,
            ];
        }

        return array_reverse($entries);
    }
}
