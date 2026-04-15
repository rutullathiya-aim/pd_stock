<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/Auth.php';

Auth::requireLogin();

require_once __DIR__ . '/helpers/Logger.php';
require_once __DIR__ . '/clients/ShopifyClient.php';
require_once __DIR__ . '/services/VendorService.php';
require_once __DIR__ . '/services/ProductSyncService.php';

// Trigger sync if run=1 passed via GET or if running from CLI (Cron)
if (isset($_GET['run']) || php_sapi_name() === 'cli') {
    // Harden output to prevent Warnings from corrupting JSON
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        ob_start();
        ignore_user_abort(true);
    }
    try {
        // --- Concurrency Lock ---
        $lockFile = SHOP_ROOT . '/logs/sync.lock';
        $lockFp = fopen($lockFile, 'w+');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            throw new Exception("Sync already in progress. Please wait for current run to finish.");
        }

        $shopify = new ShopifyClient($shop, $accessToken, $apiVersion);
        $vendor  = new VendorService($vendorApiUrl, $vendorApiKey);
        $sync    = new ProductSyncService($shopify, $locationId);

        $vendorData = $vendor->fetchData($appEnv);
        $results = $sync->syncUnified($vendorData);

        echo json_encode(['success' => true, 'results' => $results]);
    } catch (Throwable $e) {
        Logger::error("Global Sync Failure: " . $e->getMessage());
        if (php_sapi_name() !== 'cli') {
            ob_clean();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } finally {
        if (isset($lockFp) && $lockFp) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            if (file_exists($lockFile)) @unlink($lockFile);
        }
        Logger::cleanup();
        if (php_sapi_name() !== 'cli') {
            ob_end_flush();
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync in Progress | Shopify Master</title>
    <link rel="stylesheet" href="assets/css/global.css">
</head>
<body onload="startSync()" class="centered-layout">

<div class="container" id="sync-container">
    <a href="index.php"><img src="assets/images/Shoptrophies_Canadian_Logo.png" alt="ShopTrophies Logo" style="max-width: 80%; max-height: 50px; display: block; margin: 0 auto; margin-bottom: 50px;"></a>
    <div class="loader" id="main-loader"></div>
    <h1 id="main-title">Syncing Products</h1>
    <p id="main-text">Communicating with Vendor APIs and Shopify. Please keep this tab open.</p>

    <div id="finished-card">
        <div class="stats">
            <div class="stat-line"><label>Inventory Updated</label><span id="inv-count">0</span></div>
            <div class="stat-line"><label>Prices Updated</label><span id="price-count">0</span></div>
            <div class="stat-line"><label>SKU Not Found</label><span id="not-found-count">0</span></div>
        </div>
        <h2 id="status-title" style="color: var(--text); margin-bottom: 20px;">Sync Finished</h2>
        <a href="index.php" class="btn">Return home</a>
    </div>
</div>

<script src="assets/js/global.js"></script>

</body>
</html>
