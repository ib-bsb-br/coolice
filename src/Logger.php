<?php
declare(strict_types=1);

class Log {
    private static ?string $requestId = null;
    private static string $serviceName = 'arcreformas-api';
    private static string $environment = 'production'; // This could be loaded from an env var

    public static function setRequestId(string $id): void {
        self::$requestId = $id;
    }

    /**
     * Writes a structured log event to stdout.
     *
     * @param string $level The log level (e.g., INFO, ERROR, DEBUG).
     * @param string $message The primary log message.
     * @param array<mixed> $context Optional key-value pairs for additional context.
     */
    public static function event(string $level, string $message, array $context = []): void {
        $logRecord = [
            'timestamp' => gmdate('Y-m-d\TH:i:s') . '.' . sprintf('%03d', (int)((microtime(true) * 1000) % 1000)) . 'Z',
            'level' => strtoupper($level),
            'service' => self::$serviceName,
            'env' => self::$environment,
            'request_id' => self::$requestId,
            'message' => $message,
        ];

        // Merge context, ensuring it doesn't overwrite core fields
        if (!empty($context)) {
            $logRecord['context'] = $context;
        }

        // Write to STDOUT, and log to error_log if it fails
        $logLine = json_encode($logRecord, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $bytesWritten = fwrite(STDOUT, $logLine);
        if ($bytesWritten === false || $bytesWritten < strlen($logLine)) {
            // Avoid logging the full log line to prevent leaking sensitive data
            $truncatedMsg = mb_substr($message, 0, 100) . (mb_strlen($message) > 100 ? '...' : '');
            error_log('Failed to write log to STDOUT. Level: ' . strtoupper($level) . ', Message: ' . $truncatedMsg);
        }
    }
}
