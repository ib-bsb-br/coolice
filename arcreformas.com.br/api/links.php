<?php
declare(strict_types=1);
// Note: config.php and PKMSystem.php are loaded by index.php

function handle_links_request(?string $slug): void
{
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            if (empty($slug)) {
                PKMSystem::emitJson(['error' => 'Link slug not specified.'], 400);
            } else {
                get_long_link($slug);
            }
            break;
        case 'POST':
            create_short_link();
            break;
        default:
            PKMSystem::emitJson(['error' => 'Method not allowed for this resource.'], 405);
            break;
    }
}

function get_long_link(string $slug): void
{
    $pdo = PKMSystem::getPDO();
    $stmt = $pdo->prepare("SELECT url FROM links WHERE slug = ?");
    $stmt->execute([$slug]);
    $result = $stmt->fetch();

    if ($result) {
        // Update view count
        $update_stmt = $pdo->prepare("UPDATE links SET views = views + 1 WHERE slug = ?");
        $update_stmt->execute([$slug]);
        PKMSystem::emitJson($result);
    } else {
        PKMSystem::emitJson(['error' => 'Link not found.'], 404);
    }
}

function create_short_link(): void
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $url = trim((string)($input['url'] ?? ''));

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        PKMSystem::emitJson(['error' => 'Invalid URL provided.'], 400);
        return;
    }

    $pdo = PKMSystem::getPDO();
    // Using a more random, slightly shorter ID. Collision is highly unlikely.
    // The table schema allows for up to 10 chars.
    $slug = PKMSystem::generateId(6);

    try {
        $stmt = $pdo->prepare("INSERT INTO links (slug, url) VALUES (?, ?)");
        $stmt->execute([$slug, $url]);

        PKMSystem::emitJson([
            'status' => 'success',
            'slug' => $slug,
            'short_url' => (defined('ENGINE_URL') ? rtrim(ENGINE_URL, '/') : '') . '/?s=' . $slug,
            'long_url' => $url
        ], 201);

    } catch (PDOException $e) {
        // Handle potential slug collision, though unlikely
        if ($e->getCode() == '23000') { // Integrity constraint violation
             PKMSystem::emitJson(['error' => 'Could not generate a unique short link. Please try again.'], 409);
        } else {
            PKMSystem::emitJson(['error' => 'Failed to create short link in database.'], 500);
        }
    }
}
