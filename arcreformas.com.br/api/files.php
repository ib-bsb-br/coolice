<?php
declare(strict_types=1);

function sanitize_filename(string $filename): string {
    $filename = str_replace(['..', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $filename);
    $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);
    $filename = trim($filename, " .-_");
    return $filename ?: 'unnamed_file';
}

function notify_cut_engine(string $fileUrl, string $filename, string $type, int $size): void {
    if (!defined('CUT_WEBHOOK_URL') || CUT_WEBHOOK_URL === '') return;
    $payload = json_encode([
        'source'    => 'arcreformas.com.br',
        'type'      => $type, // file | text | image
        'url'       => $fileUrl,
        'filename'  => $filename,
        'size'      => $size,
        'timestamp' => time(),
    ]);
    Log::event('info', 'dependency_call_started', ['system' => 'cut_engine', 'url' => CUT_WEBHOOK_URL]);
    $ch = curl_init(CUT_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        Log::event('error', 'dependency_call_failed', ['system' => 'cut_engine', 'error' => curl_error($ch)]);
        error_log('CUT_WEBHOOK notify failed: ' . curl_error($ch));
    } else {
        Log::event('info', 'dependency_call_success', ['system' => 'cut_engine']);
    }
    curl_close($ch);
}

function handle_files_request(?string $id): void {
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
                emit_json(['error' => 'Missing file id for deletion.'], 400);
            } else {
                delete_file_by_id($id);
            }
            break;
        default:
            emit_json(['error' => 'Method not allowed for this resource.'], 405);
            break;
    }
}

function compute_files_etag_and_lastmod(array $files): array {
    $count = count($files);
    $totalSize = 0;
    $lastModTs = 0;
    foreach ($files as $f) {
        $totalSize += (int)$f['filesize'];
        $ts = strtotime((string)$f['created_at']);
        if ($ts > $lastModTs) $lastModTs = $ts;
    }
    $etag = '"' . md5($count . ':' . $totalSize . ':' . $lastModTs) . '"';
    $lastModHeader = gmdate('D, d M Y H:i:s', $lastModTs ?: time()) . ' GMT';
    return [$etag, $lastModHeader];
}

function get_all_files(): void {
    Log::event('info', 'file_list_requested');
    $pdo = get_pdo();
    $stmt = $pdo->query("SELECT id, filename, filesize, mime_type, created_at FROM files ORDER BY created_at DESC");
    $files = $stmt->fetchAll();

    // Conditional GET using ETag
    [$etag, $lastMod] = compute_files_etag_and_lastmod($files);
    $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($inm === $etag) {
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastMod);
        http_response_code(304);
        exit;
    }

    foreach ($files as &$file) {
        $file['url'] = FILE_PUBLIC_URL . rawurlencode($file['filename']);
        // Frontend compatibility
        $file['id'] = $file['id'];
        $file['name'] = $file['filename'];
        $file['size'] = $file['filesize'];
        $file['lastModified'] = $file['created_at'];
        $file['creationDate'] = $file['created_at']; // fix sorting by "Created" column
        $file['displayType'] = explode('/', $file['mime_type'])[0] ?? 'file';
    }
    unset($file);

    Log::event('info', 'file_list_success', ['count' => count($files)]);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastMod);
    emit_json(['status' => 'success', 'data' => $files]);
}

function upload_new_file(): void {
    Log::event('info', 'file_upload_started');

    if (!isset($_FILES['fileToUpload'])) {
        Log::event('warn', 'file_upload_failed', ['reason' => 'No file data in fileToUpload field']);
        emit_json(['error' => 'No file data received in fileToUpload field.'], 400);
        return;
    }

    $file = $_FILES['fileToUpload'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        Log::event('error', 'file_upload_failed', ['reason' => 'Upload error code', 'code' => $file['error']]);
        emit_json(['error' => 'File upload error code: ' . $file['error']], 400);
        return;
    }

    if (MAX_SERVER_FILE_SIZE > 0 && (int)$file['size'] > MAX_SERVER_FILE_SIZE) {
        emit_json(['error' => 'File too large. Max ' . number_format(MAX_SERVER_FILE_SIZE / (1024*1024), 0) . ' MB'], 413);
        return;
    }

    // Sanitize filename
    $client_filename = $file['name'] ?: ('capture_' . time());
    $pathinfo = pathinfo($client_filename);
    $base = sanitize_filename($pathinfo['filename'] ?? 'file');
    $ext = isset($pathinfo['extension']) && $pathinfo['extension'] !== '' ? '.' . strtolower($pathinfo['extension']) : '';

    // Append random to avoid collisions
    $unique_id = bin2hex(random_bytes(4)); // 8 hex chars
    $unique_filename = $base . '-' . $unique_id . $ext;
    $destination = UPLOAD_DIR . $unique_filename;

    // Determine mime type (fallback to finfo if needed)
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
            $pdo = get_pdo();
            $stmt = $pdo->prepare("INSERT INTO files (filename, filesize, mime_type) VALUES (?, ?, ?)");
            $stmt->execute([$unique_filename, (int)$file['size'], $mime]);
            $new_file_id = (int)$pdo->lastInsertId();

            // Capture -> Process: create a task in 'inbox'
            $file_url = FILE_PUBLIC_URL . rawurlencode($unique_filename);
            $taskText = "Process new file: [{$unique_filename}]({$file_url})";
            $taskPayload = json_encode(['op' => 'add', 'text' => $taskText]);

            $tasks_api_url = API_INTERNAL_URL . '/tasks/inbox';
            Log::event('info', 'dependency_call_started', ['system' => 'self:tasks_api', 'url' => $tasks_api_url]);
            $ch = curl_init($tasks_api_url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $taskPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $curl_response = curl_exec($ch);
            if ($curl_response === false) {
                Log::event('error', 'dependency_call_failed', ['system' => 'self:tasks_api', 'error' => curl_error($ch)]);
                error_log("Tasks API create task failed: " . curl_error($ch));
            } else {
                Log::event('info', 'dependency_call_success', ['system' => 'self:tasks_api']);
            }
            curl_close($ch);

            // Optional: notify cut engine webhook
            $upload_source = $_POST['upload_source'] ?? 'direct_upload';
            $typeKind = ($upload_source === 'paste_text') ? 'text' : (($upload_source === 'paste_image') ? 'image' : 'file');
            notify_cut_engine($file_url, $unique_filename, $typeKind, (int)$file['size']);

            Log::event('info', 'file_upload_success', [
                'file_id' => $new_file_id,
                'filename' => $unique_filename,
                'size' => (int)$file['size'],
                'mime' => $mime,
            ]);
            emit_json([
                'status' => 'success',
                'message' => 'File uploaded and task created: ' . htmlspecialchars($unique_filename),
                'id' => $new_file_id,
                'filename' => $unique_filename,
                'url' => $file_url
            ], 201);

        } catch (PDOException $e) {
            @unlink($destination);
            Log::event('error', 'file_upload_failed', ['reason' => 'DB insert failed', 'error' => $e->getMessage()]);
            emit_json(['error' => 'Failed to save file metadata to database.'], 500);
        }
    } else {
        Log::event('error', 'file_upload_failed', ['reason' => 'Move uploaded file failed', 'destination' => UPLOAD_DIR]);
        emit_json(['error' => 'Failed to move uploaded file. Check permissions for ' . UPLOAD_DIR], 500);
    }
}

function delete_file_by_id(string $id): void {
    Log::event('info', 'file_delete_started', ['id' => $id]);
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT filename FROM files WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        Log::event('warn', 'file_delete_failed', ['id' => $id, 'reason' => 'File not found in DB']);
        emit_json(['error' => 'File not found.'], 404);
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
        Log::event('error', 'file_delete_failed', ['id' => $id, 'reason' => 'DB delete failed', 'error' => $e->getMessage()]);
        emit_json(['error' => 'Failed to delete file record.'], 500);
        return;
    }

    if (is_file($path)) {
        if (!@unlink($path)) {
            // This is a warning, not a failure of the request itself.
            Log::event('warn', 'file_unlink_failed', ['id' => $id, 'filename' => $filename, 'path' => $path]);
            error_log("Warning: DB entry deleted but failed to unlink file: " . $path);
        }
    } else {
        Log::event('info', 'file_already_unlinked', ['id' => $id, 'filename' => $filename, 'path' => $path]);
        error_log("Info: DB entry deleted; file already missing: " . $path);
    }

    Log::event('info', 'file_delete_success', ['id' => $id, 'filename' => $filename]);
    emit_json(['status' => 'success', 'message' => 'Deleted', 'id' => $id, 'filename' => $filename]);
}
