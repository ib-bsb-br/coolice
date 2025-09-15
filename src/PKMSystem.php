<?php
declare(strict_types=1);

// Helper Functions
class PKMSystem {
    private static ?PDO $pdo = null;
    private static ?string $requestId = null;
    private static ?float $requestStartTime = null;

    public static function initRequestContext(): void {
        if (self::$requestStartTime !== null) {
            return; // Already initialized
        }
        self::$requestStartTime = microtime(true);
        self::$requestId = bin2hex(random_bytes(8));

        self::logEvent('request_started', [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);
    }

    public static function getPDO(): PDO {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false
            ];
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
        return self::$pdo;
    }

    public static function sanitizeFilename(string $filename): string {
        $filename = preg_replace('/[^\w\s\-\.]/', '_', $filename);
        $filename = preg_replace('/\s+/', '_', $filename);
        $filename = trim($filename, '.-_');
        // Remove any directory separators
        $filename = str_replace(['/', '\\'], '_', $filename);
        // Replace any character that is not a word character, whitespace, dash, or dot with underscore (dots allowed except at start/end)
        $filename = preg_replace('/[^\w\s\-.]/', '_', $filename);
        // Replace whitespace with underscores
        $filename = preg_replace('/\s+/', '_', $filename);
        // Remove leading/trailing dots, dashes, and underscores
        $filename = trim($filename, '-_');
        // Prevent leading dots (hidden files)
        $filename = ltrim($filename, '.');
        // If filename is empty, use a default
        return $filename ?: 'unnamed_file';
    }

    public static function generateId(int $length = 8): string {
        return rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '=');
    }

    public static function setSecurityHeaders(): void {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function setCacheHeaders(string $etag = null, int $lastModified = null): void {
        if ($etag) {
            header('ETag: "' . $etag . '"');
        }
        if ($lastModified) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }
        header('Cache-Control: private, max-age=' . CACHE_TTL);
    }

    public static function checkNotModified(string $etag = null, int $lastModified = null): bool {
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';

        if ($etag && $ifNoneMatch === '"' . $etag . '"') {
            http_response_code(304);
            return true;
        }

        if ($lastModified && $ifModifiedSince) {
            $requestTime = strtotime($ifModifiedSince);
            if ($requestTime >= $lastModified) {
                http_response_code(304);
                return true;
            }
        }

        return false;
    }

    public static function emitJson($data, int $statusCode = 200): void {
        // Log the end of the request including duration
        $duration_ms = (self::$requestStartTime !== null)
            ? (microtime(true) - self::$requestStartTime) * 1000
            : -1;

        self::logEvent('request_finished', [
            'status_code' => $statusCode,
            'duration_ms' => (int)$duration_ms,
        ]);

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function logEvent(string $type, array $data): void {
        $event = [
            'id' => self::generateId(),
            'request_id' => self::$requestId,
            'timestamp' => time(),
            'type' => $type,
            'data' => $data
        ];

        $logFile = EVENT_LOG_FILE;

        // Rotate log if too large
        if (file_exists($logFile) && filesize($logFile) > EVENT_LOG_MAX_SIZE) {
            rename($logFile, $logFile . '.' . time());
        }

        file_put_contents($logFile, json_encode($event) . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function sendWebhook(string $url, array $data): bool {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Webhook-Secret: ' . WEBHOOK_SECRET
            ],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    public static function sendJsonPostRequest(string $url, array $payload, array $headers = [], int $timeout = 5, int $connectTimeout = 2): array {
        $ch = curl_init($url);
        $defaultHeaders = ['Content-Type: application/json'];
        $allHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => ($response !== false && $httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'response' => $response,
            'error' => $error
        ];
    }    
}
