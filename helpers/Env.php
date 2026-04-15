<?php

class Env {
    
    private static $data = null;

    private static function load() {
        if (self::$data !== null) {
            return;
        }

        $path = __DIR__ . '/../.env';
        
        if (!file_exists($path)) {
            self::$data = [];
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) < 2) continue;
            
            $name  = trim($parts[0]);
            $value = trim($parts[1]);

            // Strip surrounding quotes
            if (preg_match('/^["\'](.*)["\']$/', $value, $matches)) {
                $value = $matches[1];
            }

            self::$data[$name] = $value;
        }
    }

    public static function get($name, $default = null) {
        self::load();
        return self::$data[$name] ?? $default;
    }
}
