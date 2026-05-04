<?php
namespace App;

class Logger
{
    private static ?string $logFile = null;

    // Sets the file path for log output.
    // Creates the directory if it does not exist. If not called, logs are written to stderr only.
    public static function setLogFile(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        self::$logFile = $path;
    }

    // Logs an informational message.
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    // Logs an error message.
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    // Writes a formatted log line to stderr and optionally to a file.
    // FILE_APPEND and LOCK_EX prevent log lines from overlapping if multiple processes write to the same file simultaneously.
    private static function write(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $ctx       = empty($context) ? '' : ' ' . json_encode($context);
        $line      = "[{$timestamp}] {$level}: {$message}{$ctx}\n";

        fwrite(STDERR, $line);

        if (self::$logFile !== null) {
            file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
        }
    }
}