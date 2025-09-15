<?php
declare(strict_types=1);

// Database Configuration (Original approach with enhancements)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'pkm_system');
define('DB_USER', getenv('DB_USER') ?: 'pkm_user');
define('DB_PASS', getenv('DB_PASS') ?: 'pkm_password');

// Storage Configuration (Hybrid approach)
// These paths are relative to the project root, assuming `src` is in the root.
define('UPLOAD_DIR', realpath(__DIR__ . '/../storage') . '/');
define('TEMP_DIR', realpath(__DIR__ . '/../temp') . '/');
define('DATA_DIR', realpath(__DIR__ . '/../data') . '/');

// Service URLs
define('INBOX_URL', 'https://arcreformas.com.br');
define('WORKSHOP_URL', 'https://memor.ia.br');
define('ENGINE_URL', 'https://cut.ia.br');
define('GALLERY_URL', 'https://ib-bsb-br.github.io');
define('API_INTERNAL_URL', getenv('API_INTERNAL_URL') ?: 'https://arcreformas.com.br/api');
define('CUT_WEBHOOK_URL', getenv('CUT_WEBHOOK_URL') ?: '');

// API Configuration
define('API_BASE_URL', '/api');
define('API_TIMEOUT', 30);
define('MAX_FILE_SIZE', 500 * 1024 * 1024); // 500MB
define('MAX_TEXT_LENGTH', 10000);
define('MAX_TITLE_LENGTH', 200);

// Security Configuration
define('GITHUB_TOKEN', getenv('GITHUB_TOKEN') ?: '');
define('GITHUB_REPO', 'ib-bsb-br/ib-bsb-br.github.io');

// Webhook Secret: Use environment variable if available, otherwise generate and persist one.
$webhook_secret = getenv('WEBHOOK_SECRET');
if (empty($webhook_secret)) {
    $secret_file = DATA_DIR . 'webhook_secret.txt';
    if (file_exists($secret_file)) {
        $webhook_secret = trim(file_get_contents($secret_file));
    } else {
        $webhook_secret = bin2hex(random_bytes(32));
        file_put_contents($secret_file, $webhook_secret);
        chmod($secret_file, 0600); // Secure the file
    }
}
define('WEBHOOK_SECRET', $webhook_secret);

// Event Bus Configuration
define('EVENT_LOG_FILE', DATA_DIR . 'events.ndjson');
define('EVENT_LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB

// Cache Configuration
define('CACHE_ENABLED', true);
define('CACHE_TTL', 3600); // 1 hour

// Initialize directories
foreach ([UPLOAD_DIR, TEMP_DIR, DATA_DIR] as $dir) {
    if (!is_dir($dir)) {
        // Suppress errors with @ in case of a race condition where another process creates the directory.
        if (!@mkdir($dir, 0755, true)) {
            // After attempting, check again. If it's still not there, log the error.
            if (!is_dir($dir)) {
                error_log("Failed to create directory: " . $dir);
            }
        }
    }
}
