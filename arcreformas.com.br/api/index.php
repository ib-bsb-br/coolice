<?php
declare(strict_types=1);

// Load the new centralized configuration and helper class.
require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/PKMSystem.php';

// Set security headers for all responses.
PKMSystem::setSecurityHeaders();

// Handle CORS pre-flight requests.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // The security headers are already set. emitJson will add CORS headers and exit.
    PKMSystem::emitJson(null, 204);
}

// Simple router
$request_uri = $_GET['q'] ?? '';
$parts = explode('/', $request_uri);
$resource = $parts[0] ?? null;
$resource_id = $parts[1] ?? null;

try {
    switch ($resource) {
        case 'files':
            require_once __DIR__ . '/files.php';
            handle_files_request($resource_id);
            break;

        case 'tasks':
            require_once __DIR__ . '/tasks.php';
            handle_tasks_request($resource_id);
            break;

        case 'links':
            require_once __DIR__ . '/links.php';
            handle_links_request($resource_id);
            break;

        case 'published':
            // A simple endpoint to fetch all published content for the Jekyll site
            $pdo = PKMSystem::getPDO();
            $stmt = $pdo->query("SELECT id, board_slug, text, updated_at FROM tasks WHERE is_published = 1 ORDER BY updated_at DESC");
            $published_tasks = $stmt->fetchAll();
            PKMSystem::emitJson(['tasks' => $published_tasks]);
            break;

        default:
            PKMSystem::emitJson(['error' => 'Resource not found.'], 404);
            break;
    }
} catch (Exception $e) {
    // Upgraded global error handler
    PKMSystem::logEvent('unhandled_exception', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    PKMSystem::emitJson(['error' => 'An internal server error occurred.'], 500);
}
