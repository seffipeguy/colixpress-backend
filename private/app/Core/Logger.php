<?php

namespace App\Core;

class Logger
{
    private static string $logFile = PRIVATE_PATH . '/logs/app.log';

    public static function log(string $level, string $message, array $context = []): void
    {
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        
        // Format: [2026-02-22 14:00:00] [INFO] Message {context}
        $logEntry = sprintf(
            "[%s] [%s] %s %s%s",
            $timestamp,
            strtoupper($level),
            $message,
            $contextJson,
            PHP_EOL
        );

        file_put_contents(self::$logFile, $logEntry, FILE_APPEND);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }
}
