<?php
declare(strict_types=1);

// --- GENERAL HELPERS ---

function send_cors_headers(): void {
    header('X-Content-Type-Options: nosniff');
    // Allow cors based on whitelist; fall back to *
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (ALLOWED_ORIGINS === '*') {
        header('Access-Control-Allow-Origin: *');
    } else {
        $allowed = array_filter(array_map('trim', explode(',', ALLOWED_ORIGINS)));
        if ($origin && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            // Fail-closed to no origin by default (or pick a safe default)
            header('Access-Control-Allow-Origin: https://memor.ia.br');
        }
        header('Vary: Origin'); // ensure proper caching per origin
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            emit_json(['error' => 'Database connection failed.']);
            exit(1);
        }
    }
    return $pdo;
}

function emit_json(mixed $data, int $statusCode = 200): void {
    global $request_start_time;
    $duration_ms = (microtime(true) - ($request_start_time ?? microtime(true))) * 1000;

    // The logger may not be loaded if there was a very early exit (e.g. in config)
    if (class_exists('Log')) {
        Log::event('info', 'request_finished', [
            'status_code' => $statusCode,
            'duration_ms' => (int)$duration_ms,
        ]);
    }

    http_response_code($statusCode);
    send_cors_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function handle_cors_preflight(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        send_cors_headers();
        http_response_code(204); // No Content
        exit;
    }
}

function id(int $length = 6): string {
    return substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $length)), 0, $length);
}
