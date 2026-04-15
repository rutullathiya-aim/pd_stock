<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('America/New_York');

// Path to log file
$logFile = __DIR__ . '/cron_test_log1.txt';

// Message to write
$message = "Cron ran at: " . date('Y-m-d H:i:s') . " - PHP v" . PHP_VERSION . "\n";

echo "Trying to write...\n";

// Write to file
$result = file_put_contents($logFile, $message, FILE_APPEND);

if ($result === false) {
    echo "WRITE FAILED\n";
} else {
    echo "WRITE SUCCESS\n";
}
?>