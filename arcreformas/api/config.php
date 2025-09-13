<?php
declare(strict_types=1);

// --- IMPORTANT: EDIT THESE DETAILS ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name'); // e.g., user_db1
define('DB_USER', 'your_database_user'); // e.g., user_db1
define('DB_PASS', 'your_database_password');
// ------------------------------------

// --- FILE STORAGE CONFIGURATION ---
// Note: UPLOAD_DIR is now relative to the project root for consistency.
define('UPLOAD_DIR', __DIR__ . '/../../storage_arcreformas/');
define('FILE_PUBLIC_URL', 'https://arcreformas.com.br/files/');

// Base URL for internal server-to-server API calls (Tasks API)
define('API_INTERNAL_URL', 'https://arcreformas.com.br/api');

// --- SECURITY & CORS ---
define('ALLOWED_ORIGINS', getenv('ALLOWED_ORIGINS') ?: '*'); // e.g., "https://arcreformas.com.br,https://memor.ia.br,https://cut.ia.br"

// --- UPLOAD LIMIT (server-side) ---
define('MAX_SERVER_FILE_SIZE', (int)(getenv('MAX_SERVER_FILE_SIZE') ?: (500 * 1024 * 1024))); // default 500MB

// --- OPTIONAL CUT ENGINE INTEGRATION ---
define('CUT_WEBHOOK_URL', getenv('CUT_WEBHOOK_URL') ?: '');          // e.g., https://cut.ia.br/?op=new-item
define('CUT_PUBLISH_URL', getenv('CUT_PUBLISH_URL') ?: '');          // e.g., https://cut.ia.br/?op=publish_md

// --- GITHUB PUBLISHING CONFIGURATION ---
define('GITHUB_TOKEN', getenv('GITHUB_TOKEN') ?: 'your_github_personal_access_token_here');
define('GITHUB_REPO', 'ib-bsb-br/ib-bsb-br.github.io');
define('GITHUB_WORKFLOW_ID', 'refresh-content.yml');

if (empty(getenv('GITHUB_TOKEN')) && GITHUB_TOKEN === 'your_github_personal_access_token_here') {
    error_log('[WARNING] GITHUB_TOKEN is not set via environment variable and the fallback placeholder is being used. GitHub publishing will fail.');
}

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    if (!mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        error_log('Failed to create upload directory: ' . UPLOAD_DIR);
    }
}
