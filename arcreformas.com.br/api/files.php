<?php
declare(strict_types=1);
// Note: config.php and PKMSystem.php are loaded by index.php

function notify_cut_engine(string $fileUrl, string $filename, string $type, int $size): void
{
    if (!defined('CUT_WEBHOOK_URL') || CUT_WEBHOOK_URL === '') {
        return;
    }
    $payload = [
        'source'    => 'arcreformas.com.br',
        'type'      => $type,
        'url'       => $fileUrl,
        'filename'  => $filename,
        'size'      => $size,
        'timestamp' => time(),
    ];

    PKMSystem::logEvent('dependency_call_started', ['system' => 'cut_engine', 'url' => CUT_WEBHOOK_URL]);
    $success = PKMSystem::sendWebhook(CUT_WEBHOOK_URL, $payload);

    if ($success) {
        PKMSystem::logEvent('dependency_call_success', ['system' => 'cut_engine']);
    } else {
        PKMSystem::logEvent('dependency_call_failed', ['system' => 'cut_engine']);
        error_log('CUT_WEBHOOK notify failed. Check PKMSystem::sendWebhook implementation and logs.');
    }
}

function handle_files_request(?string $id): void
{
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            get_all_files();
            break;
        case 'POST':
            upload_new_file();
            break;
        case 'DELETE':
            if (!$id) {
                PKMSystem::emitJson(['error' => 'Missing file id for deletion.'], 400);
            } else {
                delete_file_by_id($id);
            }
            break;
        default:
            PKMSystem::emitJson(['error' => 'Method not allowed for this resource.'], 405);
            break;
    }
}

function compute_files_etag_and_lastmod(array $files): array
{
    $count = count($files);
    $totalSize = 0;
    $lastModTs = 0;
    foreach ($files as $f) {
        $totalSize += (int)$f['filesize'];
        $ts = strtotime((string)$f['created_at']);
        if ($ts > $lastModTs) {
            $lastModTs = $ts;
        }
    }
    $etag = md5($count . ':' . $totalSize . ':' . $lastModTs);
    return [$etag, $lastModTs];
}

function get_all_files(): void
{
    PKMSystem::logEvent('file_list_requested', []);
    $pdo = PKMSystem::getPDO();
    // The new schema includes original_name, which is useful here.
    $stmt = $pdo->query("SELECT id, filename, original_name, filesize, mime_type, created_at FROM files ORDER BY created_at DESC");
    $files = $stmt->fetchAll();

    // Conditional GET using ETag and Last-Modified
    [$etag, $lastModTs] = compute_files_etag_and_lastmod($files);
    if (PKMSystem::checkNotModified($etag, $lastModTs)) {
        return; // Exits with 304 Not Modified
    }

    // Define a public URL for files, falling back to a default if not set.
    $public_url_base = defined('FILE_PUBLIC_URL') ? FILE_PUBLIC_URL : '/files/';

    foreach ($files as &$file) {
        $file['url'] = $public_url_base . rawurlencode($file['filename']);
        // Frontend compatibility fields
        $file['name'] = $file['original_name'] ?: $file['filename'];
        $file['size'] = (int)$file['filesize'];
        $file['lastModified'] = strtotime($file['created_at']);
        $file['creationDate'] = $file['created_at'];
        $file['displayType'] = explode('/', $file['mime_type'])[0] ?? 'file';
    }
    unset($file);

    PKMSystem::logEvent('file_list_success', ['count' => count($files)]);
    PKMSystem::setCacheHeaders($etag, $lastModTs);
    PKMSystem::emitJson(['status' => 'success', 'data' => $files]);
}

function create_task_for_file(string $filename, string $file_url): void
{
    // Capture -> Process: create a task in 'inbox'
    $taskText = "Process new file: [{$filename}]({$file_url})";
    $taskPayload = ['op' => 'add', 'text' => $taskText];
$tasks_api_url = INBOX_URL . API_BASE_URL . '/tasks/inbox';

    PKMSystem::logEvent('dependency_call_started', ['system' => 'self:tasks_api', 'url' => $tasks_api_url]);

    // Using a simple cURL for the internal call.
    $ch = curl_init($tasks_api_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($taskPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 2
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        PKMSystem::logEvent('dependency_call_failed', ['system' => 'self:tasks_api', 'error' => curl_error($ch)]);
        error_log("Tasks API create task failed: " . curl_error($ch));
    } else {
        PKMSystem::logEvent('dependency_call_success', ['system' => 'self:tasks_api']);
    }
    curl_close($ch);
}

function upload_new_file(): void
{
    PKMSystem::logEvent('file_upload_started', []);

    if (!isset($_FILES['fileToUpload'])) {
        PKMSystem::logEvent('file_upload_failed', ['reason' => 'No file data in fileToUpload field']);
        PKMSystem::emitJson(['error' => 'No file data received in fileToUpload field.'], 400);
        return;
    }

    $file = $_FILES['fileToUpload'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        PKMSystem::logEvent('file_upload_failed', ['reason' => 'Upload error code', 'code' => $file['error']]);
        PKMSystem::emitJson(['error' => 'File upload error code: ' . $file['error']], 400);
        return;
    }

    $max_size = defined('MAX_FILE_SIZE') ? MAX_FILE_SIZE : 500 * 1024 * 1024;
    if ($max_size > 0 && (int)$file['size'] > $max_size) {
        PKMSystem::emitJson(['error' => 'File too large. Max ' . number_format($max_size / (1024 * 1024), 0) . ' MB'], 413);
        return;
    }

    $client_filename = $file['name'] ?: ('capture_' . time());
    $pathinfo = pathinfo($client_filename);
    $base = PKMSystem::sanitizeFilename($pathinfo['filename'] ?? 'file');
    $ext = isset($pathinfo['extension']) && $pathinfo['extension'] !== '' ? '.' . strtolower($pathinfo['extension']) : '';

    $unique_filename = $base . '-' . PKMSystem::generateId(4) . $ext;
    $destination = UPLOAD_DIR . $unique_filename;

    $mime = $file['type'] ?: 'application/octet-stream';
    if ($mime === 'application/octet-stream' && is_readable($file['tmp_name'])) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = @finfo_file($finfo, $file['tmp_name']);
            if ($detected) $mime = $detected;
            @finfo_close($finfo);
        }
    }

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        try {
            $pdo = PKMSystem::getPDO();
            $stmt = $pdo->prepare("INSERT INTO files (filename, original_name, filesize, mime_type, file_path) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$unique_filename, $client_filename, (int)$file['size'], $mime, $destination]);
            $new_file_id = (int)$pdo->lastInsertId();

            $public_url_base = defined('FILE_PUBLIC_URL') ? FILE_PUBLIC_URL : '/files/';
            $file_url = $public_url_base . rawurlencode($unique_filename);

            create_task_for_file($unique_filename, $file_url);

            $upload_source = $_POST['upload_source'] ?? 'direct_upload';
            $typeKind = ($upload_source === 'paste_text') ? 'text' : (($upload_source === 'paste_image') ? 'image' : 'file');
            notify_cut_engine($file_url, $unique_filename, $typeKind, (int)$file['size']);

            PKMSystem::logEvent('file_upload_success', [
                'file_id' => $new_file_id, 'filename' => $unique_filename, 'size' => (int)$file['size'], 'mime' => $mime,
            ]);
            PKMSystem::emitJson([
                'status' => 'success', 'message' => 'File uploaded: ' . htmlspecialchars($unique_filename),
                'id' => $new_file_id, 'filename' => $unique_filename, 'url' => $file_url
            ], 201);

        } catch (PDOException $e) {
            @unlink($destination);
            PKMSystem::logEvent('file_upload_failed', ['reason' => 'DB insert failed', 'error' => $e->getMessage()]);
            PKMSystem::emitJson(['error' => 'Failed to save file metadata to database.'], 500);
        }
    } else {
        PKMSystem::logEvent('file_upload_failed', ['reason' => 'Move uploaded file failed', 'destination' => UPLOAD_DIR]);
        PKMSystem::emitJson(['error' => 'Failed to move uploaded file. Check permissions for ' . UPLOAD_DIR], 500);
    }
}

function delete_file_by_id(string $id): void
{
    PKMSystem::logEvent('file_delete_started', ['id' => $id]);
    $pdo = PKMSystem::getPDO();
    $stmt = $pdo->prepare("SELECT filename FROM files WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        PKMSystem::logEvent('file_delete_failed', ['id' => $id, 'reason' => 'File not found in DB']);
        PKMSystem::emitJson(['error' => 'File not found.'], 404);
        return;
    }

    $filename = $row['filename'];
    $path = UPLOAD_DIR . $filename;

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM files WHERE id = ?");
        $del->execute([$id]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        PKMSystem::logEvent('file_delete_failed', ['id' => $id, 'reason' => 'DB delete failed', 'error' => $e->getMessage()]);
        PKMSystem::emitJson(['error' => 'Failed to delete file record.'], 500);
        return;
    }

    if (is_file($path)) {
        if (!@unlink($path)) {
            PKMSystem::logEvent('file_unlink_failed', ['id' => $id, 'filename' => $filename, 'path' => $path]);
            error_log("Warning: DB entry deleted but failed to unlink file: " . $path);
        }
    } else {
        PKMSystem::logEvent('file_already_unlinked', ['id' => $id, 'filename' => $filename, 'path' => $path]);
    }

    PKMSystem::logEvent('file_delete_success', ['id' => $id, 'filename' => $filename]);
    PKMSystem::emitJson(['status' => 'success', 'message' => 'Deleted', 'id' => $id, 'filename' => $filename]);
}
