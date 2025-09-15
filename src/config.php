<?php
declare(strict_types=1);

// Database Configuration (Original approach with enhancements)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'pkm_system');
define('DB_USER', getenv('DB_USER') ?: 'pkm_user');
define('DB_PASS', getenv('DB_PASS') ?: 'pkm_password');

// Storage Configuration (Hybrid approach)
// These paths are relative to the project root, assuming `src` is in the root.
define('UPLOAD_DIR', dirname(__DIR__) . '/storage/');
define('TEMP_DIR', dirname(__DIR__) . '/temp/');
define('DATA_DIR', dirname(__DIR__) . '/data/');

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
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: bin2hex(random_bytes(16)));

// Event Bus Configuration
define('EVENT_LOG_FILE', DATA_DIR . 'events.ndjson');
define('EVENT_LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB

// Cache Configuration
define('CACHE_ENABLED', true);
define('CACHE_TTL', 3600); // 1 hour

// Initialize directories
foreach ([UPLOAD_DIR, TEMP_DIR, DATA_DIR] as $dir) {
    if (!is_dir($dir)) {
        // Check if mkdir fails and the directory doesn't exist.
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            // Log error if directory creation fails.
            error_log("Failed to create directory: " . $dir);
        }
    }
}
