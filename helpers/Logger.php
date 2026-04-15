<?php
class Logger {
    private static $level = 'ERROR';
    private static $currentSyncFile = null;
    private static function file() {
        $dir = SHOP_ROOT . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir . '/error-' . date('Y-m-d') . '.log';
    }

    public static function error($message) {
        self::log("ERROR", $message);
    }

    public static function info($message) {
        self::log("INFO", $message);
    }

    public static function debug($message) {
        self::log("DEBUG", $message);
    }

    public static function sync($message) {
        $logDir = SHOP_ROOT . '/logs/sync';
        if (!is_dir($logDir)) {
            if (!@mkdir($logDir, 0777, true)) {
                error_log("ShopifySync: Could not create log directory: $logDir");
            }
        }

        if (self::$currentSyncFile === null) {
            self::$currentSyncFile = $logDir . '/sync-' . date('Y-m-d_H-i-s') . '.log';
        }

        $formattedMessage = "[" . date('Y-m-d H:i:s') . "] [SYNC] " . $message . PHP_EOL;
        if (!@file_put_contents(self::$currentSyncFile, $formattedMessage, FILE_APPEND)) {
            error_log("ShopifySync: Could not write to log file: " . self::$currentSyncFile);
        }
    }

    private static function log($level, $message, $fileOverride = null) {

        $levels = [
            'DEBUG' => 1,
            'INFO'  => 2,
            'SYNC'  => 3,
            'ERROR' => 4,
        ];


        if ($fileOverride === null && isset($levels[$level]) && $levels[$level] < $levels[self::$level]) {
            return;
        }

        $targetFile = $fileOverride ?: self::file();

        file_put_contents(
            $targetFile,
            "[" . date('Y-m-d H:i:s') . "] [$level] $message\n",
            FILE_APPEND
        );
    }

    /**
     * Delete log files older than a certain number of days
     */
    public static function cleanup($days = 7) {
        $logDir = SHOP_ROOT . '/logs';
        if (!is_dir($logDir)) return;

        $threshold = time() - ($days * 86400);
        $files = array_merge(
            glob($logDir . '/error-*.log'),
            glob($logDir . '/sync/sync-*.log')
        );

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $threshold) {
                unlink($file);
                self::info("Cleaned up old log file: " . basename($file));
            }
        }

        // --- Cleanup Vendor Snapshots (shorter retention: 7 days) ---
        $vendorLogDir = $logDir . '/vendor';
        if (is_dir($vendorLogDir)) {
            $vendorThreshold = time() - (7 * 86400);
            $vendorFiles = glob($vendorLogDir . '/*.json');
            foreach ($vendorFiles as $file) {
                if (is_file($file) && filemtime($file) < $vendorThreshold) {
                    unlink($file);
                }
            }
        }
    }
}
