<?php
declare(strict_types=1);
// Note: config.php and PKMSystem.php are loaded by index.php, so no require is needed here.

function handleTasksRequest(?string $board_slug): void {
    if (empty($board_slug)) {
        PKMSystem::emitJson(['error' => 'Board slug not specified.'], 400);
        return;
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $pdo = PKMSystem::getPDO();

    // Ensure board exists
    $stmt = $pdo->prepare("INSERT IGNORE INTO boards (slug, title) VALUES (?, ?)");
    $stmt->execute([$board_slug, 'Board: ' . htmlspecialchars($board_slug)]);

    switch ($method) {
        case 'GET':
            handleGetBoard($pdo, $board_slug);
            break;
        case 'POST':
            handleTaskOperation($pdo, $board_slug);
            break;
        default:
            PKMSystem::emitJson(['error' => 'Method not allowed'], 405);
    }
}

function handleGetBoard(PDO $pdo, string $board_slug): void {
    $stmt = $pdo->prepare("
        SELECT b.*,
               (SELECT COUNT(*) FROM tasks WHERE board_slug = b.slug) as task_count,
               (SELECT MAX(updated_at) FROM tasks WHERE board_slug = b.slug) as last_task_update
        FROM boards b
        WHERE b.slug = ?
    ");
    $stmt->execute([$board_slug]);
    $board = $stmt->fetch();

    if (!$board) {
        PKMSystem::emitJson(['error' => 'Board not found'], 404);
        return;
    }

    // Generate ETag based on board state
    $etag = md5($board['updated_at'] . $board['last_task_update'] . $board['task_count']);
    $lastModified = strtotime($board['updated_at']);

    if (PKMSystem::checkNotModified($etag, $lastModified)) {
        exit;
    }

    PKMSystem::setCacheHeaders($etag, $lastModified);

    // Fetch tasks
    $stmt = $pdo->prepare("
        SELECT id, text, is_done as done, is_published, sort_order, created_at as ts
        FROM tasks
        WHERE board_slug = ?
        ORDER BY sort_order ASC, created_at ASC
    ");
    $stmt->execute([$board_slug]);
    $tasks = $stmt->fetchAll();

    foreach ($tasks as &$task) {
        $task['done'] = (bool)$task['done'];
        $task['is_published'] = (bool)$task['is_published'];
        $task['ts'] = strtotime($task['ts']);
    }

    $board['tasks'] = $tasks;
    $board['created'] = strtotime($board['created_at']);
    $board['updated'] = strtotime($board['updated_at']);
    unset($board['created_at'], $board['updated_at'], $board['last_task_update'], $board['task_count']);

    PKMSystem::emitJson($board);
}

function handleTaskOperation(PDO $pdo, string $board_slug): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $op = $input['op'] ?? '';

    $pdo->beginTransaction();

    try {
        $result = null;

        switch ($op) {
            case 'add':
                $text = trim((string)($input['text'] ?? ''));
                if ($text !== '' && strlen($text) <= MAX_TEXT_LENGTH) {
                    $id = PKMSystem::generateId();
                    $stmt = $pdo->prepare("
                        INSERT INTO tasks (id, board_slug, text, sort_order)
                        VALUES (?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tasks t WHERE t.board_slug = ?))
                    ");
                    $stmt->execute([$id, $board_slug, $text, $board_slug]);
                    $result = ['id' => $id];

                    PKMSystem::logEvent('task_added', [
                        'board' => $board_slug,
                        'task_id' => $id,
                        'text' => $text
                    ]);
                }
                break;

            case 'toggle':
                $id = (string)($input['id'] ?? '');
                $stmt = $pdo->prepare("UPDATE tasks SET is_done = !is_done WHERE id = ? AND board_slug = ?");
                $stmt->execute([$id, $board_slug]);
                break;

            case 'edit':
                $id = (string)($input['id'] ?? '');
                $text = trim((string)($input['text'] ?? ''));
                if (strlen($text) <= MAX_TEXT_LENGTH) {
                    $stmt = $pdo->prepare("UPDATE tasks SET text = ? WHERE id = ? AND board_slug = ?");
                    $stmt->execute([$text, $id, $board_slug]);
                }
                break;

            case 'del':
                $id = (string)($input['id'] ?? '');
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND board_slug = ?");
                $stmt->execute([$id, $board_slug]);
                break;

            case 'title':
                $title = trim((string)($input['title'] ?? 'My Board'));
                if (strlen($title) <= MAX_TITLE_LENGTH) {
                    $stmt = $pdo->prepare("UPDATE boards SET title = ? WHERE slug = ?");
                    $stmt->execute([$title, $board_slug]);
                }
                break;

            case 'clear_done':
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE is_done = 1 AND board_slug = ?");
                $stmt->execute([$board_slug]);
                break;

            case 'set_all':
                $done = (bool)($input['done'] ?? false);
                $stmt = $pdo->prepare("UPDATE tasks SET is_done = ? WHERE board_slug = ?");
                $stmt->execute([$done, $board_slug]);
                break;

            case 'clear_all':
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE board_slug = ?");
                $stmt->execute([$board_slug]);
                break;

            case 'reorder':
                $order = $input['order'] ?? [];
                if (is_array($order)) {
                    $stmt = $pdo->prepare("UPDATE tasks SET sort_order = ? WHERE id = ? AND board_slug = ?");
                    foreach ($order as $index => $task_id) {
                        $stmt->execute([$index, $task_id, $board_slug]);
                    }
                }
                break;

            case 'publish':
                $id = (string)($input['id'] ?? '');
                $stmt = $pdo->prepare("UPDATE tasks SET is_published = 1 WHERE id = ? AND board_slug = ?");
                $stmt->execute([$id, $board_slug]);

                // Trigger GitHub workflow if configured
                if (!empty(GITHUB_TOKEN)) {
                    triggerGitHubWorkflow();
                }

                // Send webhook notification
                PKMSystem::sendWebhook(ENGINE_URL . '/?op=task_published', [
                    'board' => $board_slug,
                    'task_id' => $id
                ]);

                PKMSystem::logEvent('task_published', [
                    'board' => $board_slug,
                    'task_id' => $id
                ]);
                break;
        }

        // Update board timestamp
        $pdo->prepare("UPDATE boards SET updated_at = NOW() WHERE slug = ?")->execute([$board_slug]);
        $pdo->commit();

        // Return updated board state
        handleGetBoard($pdo, $board_slug);

    } catch (Exception $e) {
        $pdo->rollBack();
        PKMSystem::logEvent('task_error', [
            'board' => $board_slug,
            'operation' => $op,
            'error' => $e->getMessage()
        ]);
        PKMSystem::emitJson(['error' => 'Operation failed: ' . $e->getMessage()], 500);
    }
}

function triggerGitHubWorkflow(): void {
    $ch = curl_init();
    $url = "https://api.github.com/repos/" . GITHUB_REPO . "/actions/workflows/refresh-content.yml/dispatches";

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['ref' => 'main']),
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github.v3+json',
            'Authorization: Bearer ' . GITHUB_TOKEN,
            'User-Agent: PKM-System'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);

    curl_exec($ch);
    curl_close($ch);
}
