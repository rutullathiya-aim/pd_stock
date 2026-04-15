<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/Auth.php';

Auth::requireLogin();
// --- Handle AJAX Requests (History Parsing) ---
if (isset($_GET['ajax']) && isset($_GET['file'])) {
    header('Content-Type: application/json');
    $fileRequested = $_GET['file'];
    $fileType = $_GET['type'] ?? 'sync';
    
    $realBase = realpath(SHOP_ROOT . '/logs');
    $subDir = '';
    if ($fileType === 'sync') $subDir = 'sync/';
    if ($fileType === 'vendor') $subDir = 'vendor/';
    
    $targetPath = realpath(SHOP_ROOT . '/logs/' . $subDir . $fileRequested);

    if ($targetPath === false || strpos($targetPath, $realBase) !== 0) {
        echo json_encode(['error' => 'Invalid file access.']);
        exit;
    }

    if (!file_exists($targetPath)) {
        echo json_encode(['error' => 'File not found.']);
        exit;
    }

    $content = file_get_contents($targetPath);

    if ($fileType === 'vendor') {
        echo json_encode(['raw' => json_decode($content, true)]);
    } else {
        $lines = explode("\n", $content);
        $parsedLines = [];
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            if (preg_match('/^\[(.*?)\] \[(.*?)\] SKU: (.*?) \| (?:PID: (.*?) \| )?Status: (.*?)(?: \| (.*))?$/', $line, $matches)) {
                $parsedLines[] = [
                    'time'    => $matches[1],
                    'level'   => $matches[2],
                    'sku'     => $matches[3],
                    'pid'     => !empty($matches[4]) ? $matches[4] : null,
                    'status'  => trim($matches[5]),
                    'details' => isset($matches[6]) ? trim($matches[6]) : ''
                ];
            } elseif (preg_match('/^\[(.*?)\] \[(.*?)\] (.*)$/', $line, $matches)) {
                $parsedLines[] = [
                    'time'    => $matches[1],
                    'level'   => $matches[2],
                    'status'  => 'SYSTEM',
                    'sku'     => '-',
                    'details' => $matches[3]
                ];
            } else {
                $parsedLines[] = ['raw' => $line];
            }
        }
        echo json_encode(['lines' => $parsedLines]);
    }
    exit;
}

// --- Sidebar Listing Logic ---
function getLogs($dir, $pattern) {
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/' . $pattern);
    if (!$files) return [];
    array_multisort(array_map('filemtime', $files), SORT_DESC, $files);
    return array_map('basename', $files);
}

$syncLogs   = getLogs(__DIR__ . '/logs/sync', 'sync-*.log');
$vendorLogs = getLogs(__DIR__ . '/logs/vendor', 'vendor_*.json');
$errorLogs  = getLogs(__DIR__ . '/logs', 'error-*.log');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Command Center | Production</title>
    <link rel="stylesheet" href="assets/css/global.css">
</head>
<body>

    <aside>
        <div class="aside-header">
            <a href="index.php"><img src="assets/images/Shoptrophies_Canadian_Logo.png" alt="ShopTrophies Logo" style="max-width: 80%; max-height: 50px; display: block; margin: 0 auto;"></a>
            <a href="logout.php" class="logout-btn">Log out</a>
        </div>

        <div class="log-section">
            <div class="section-title">Sync History</div>
            <?php foreach($syncLogs as $index => $file): ?>
                <div class="log-item <?= $index >= 10 ? 'hidden-log sync-hidden' : '' ?>" onclick="loadLog('<?= $file ?>', 'sync')"><?= str_replace(['sync-', '.log'], '', $file) ?></div>
            <?php endforeach; ?>
            <?php if(count($syncLogs) > 10): ?>
                <div class="show-more" onclick="showAll(this, 'sync-hidden')">Show all <?= count($syncLogs) ?> logs</div>
            <?php endif; ?>
        </div>

        <div class="log-section">
            <div class="section-title">Vendor Data</div>
            <?php foreach($vendorLogs as $index => $file): ?>
                <div class="log-item <?= $index >= 10 ? 'hidden-log vendor-hidden' : '' ?>" onclick="loadLog('<?= $file ?>', 'vendor')"><?= str_replace(['vendor_', '.json'], '', $file) ?></div>
            <?php endforeach; ?>
            <?php if(count($vendorLogs) > 10): ?>
                <div class="show-more" onclick="showAll(this, 'vendor-hidden')">Show all <?= count($vendorLogs) ?> logs</div>
            <?php endif; ?>
        </div>
    </aside>

    <main>
        <div class="control-bar">
            <a href="sync.php" class="sync-btn" onclick="return confirm('Do you really want to sync products now?');">Sync products Now</a>
            <div id="current-file" style="margin-top: 16px; font-size: 0.8rem; color: var(--muted);">Select a past run to view details</div>
            
            <div id="filter-controls" class="filter-controls" style="display: none;">
                <div style="position: relative; flex: 1; display: flex; align-items: center;">
                    <input type="text" id="search-sku" class="search-input" placeholder="Search SKU..." onkeyup="applyFilters(); toggleClearBtn();" style="width: 100%; padding-right: 30px;">
                    <a href="javascript:void(0)" id="clear-search" onclick="clearSearch()" style="position: absolute; right: 10px; color: var(--muted); font-size: 1.2rem; text-decoration: none; display: none;">&times;</a>
                </div>
                <div class="custom-select-wrapper">
                    <div class="custom-select" id="custom-status-select">
                        <span class="custom-select-trigger">All</span>
                        <div class="custom-options">
                            <span class="custom-option selected" data-value="">All</span>
                            <span class="custom-option" data-value="CHANGED">Changed</span>
                            <span class="custom-option" data-value="UNCHANGED">Unchanged</span>
                            <span class="custom-option" data-value="NOT FOUND IN SHOPIFY">Not found</span>
                        </div>
                    </div>
                    <input type="hidden" id="filter-status" value="">
                </div>
            </div>
        </div>

        <div class="content-area" id="main-content">
            <div style="text-align: center; padding-top: 50px; opacity: 0.5;">
                <p>Welcome to the Sync Command Center.</p>
                <p style="font-size: 0.9rem; margin-top: 8px;">Run a new synchronization or browse past history from the sidebar.</p>
            </div>
        </div>
    </main>

    <script>
        const SHOP_URL = 'https://<?= $shop ?>/admin/products';
    </script>
    <script src="assets/js/global.js"></script>
</body>
</html>
