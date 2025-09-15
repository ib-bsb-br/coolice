<?php
declare(strict_types=1);

// Helper Functions
class PKMSystem {
    private static ?PDO $pdo = null;

    public static function getPDO(): PDO {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true
            ];
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
        return self::$pdo;
    }

    public static function sanitizeFilename(string $filename): string {
        $filename = preg_replace('/[^\w\s\-\.]/', '_', $filename);
        $filename = preg_replace('/\s+/', '_', $filename);
        $filename = trim($filename, '.-_');
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
}
