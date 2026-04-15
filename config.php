<?php
date_default_timezone_set('America/New_York');

require_once __DIR__ . '/helpers/Env.php';
define('SHOP_ROOT', __DIR__);

$shop = Env::get('SHOPIFY_SHOP');
$accessToken = Env::get('SHOPIFY_ACCESS_TOKEN');
$locationId = Env::get('SHOPIFY_LOCATION_ID');

$vendorApiUrl = Env::get('VENDOR_API_URL');
$vendorApiKey = Env::get('VENDOR_API_KEY');

$apiVersion = Env::get('SHOPIFY_API_VERSION', '2024-01');
$appEnv = Env::get('APP_ENV', 'production');
$memoryLimit = Env::get('MEMORY_LIMIT', '512M');

// Set System Limits
ini_set('memory_limit', $memoryLimit);
set_time_limit(0);