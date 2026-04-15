<?php
require_once __DIR__ . '/../helpers/Logger.php';

class VendorService {

    private $apiUrl;
    private $apiKey;

    public function __construct($apiUrl=null, $apiKey=null) {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
    }

    public function fetchData($appEnv = 'production') {

        $data = [];

        if ($appEnv === 'test') {
            $filePath = __DIR__ . '/../response.json';
            if (!file_exists($filePath)) {
                throw new Exception("Test Mode Error: response.json file not found at $filePath");
            }
            $json = file_get_contents($filePath);
            $data = json_decode($json, true);
            if (!is_array($data)) {
                throw new Exception("Test Mode Error: Invalid JSON file structure");
            }
        } else {
            if (empty($this->apiUrl)) {
                throw new Exception("Production Mode Error: Vendor API URL is not configured.");
            }

            $data = $this->retry(function () {
                $ch = curl_init($this->apiUrl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "x-api-key: {$this->apiKey}",
                    "Accept: application/json"
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    throw new Exception("Vendor API Connection Error: " . curl_error($ch));
                }

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode >= 400) {
                    throw new Exception("Vendor API HTTP Error ($httpCode): $response");
                }

                $decoded = json_decode($response, true);
                if (!is_array($decoded)) {
                    throw new Exception("Vendor API Error: Invalid JSON response");
                }

                // --- Snapshot Archiving (Production Only) ---
                $vendorLogDir = SHOP_ROOT . '/logs/vendor';
                if (!is_dir($vendorLogDir)) {
                    mkdir($vendorLogDir, 0777, true);
                }
                $snapshotFile = $vendorLogDir . '/vendor_' . date('Y-m-d_H-i-s') . '.json';
                file_put_contents($snapshotFile, $response);
                Logger::info("Vendor Response Snapshot Saved: " . basename($snapshotFile));

                return $decoded;
            });
        }

        return $this->sanitizeAndDeduplicate($data);
    }

    private function sanitizeAndDeduplicate($data) {
        $cleanData = [];

        foreach ($data as $item) {
            // 1. Validate existence of required fields
            if (!isset($item['product_code']) || empty(trim($item['product_code']))) {
                Logger::debug("Vendor Data Skip: Missing SKU for item " . json_encode($item));
                continue;
            }

            $sku = strtoupper(trim($item['product_code']));

            // 2. Handle missing price/qty defaults or logging
            if (!isset($item['retail_price']) || !isset($item['available_qty'])) {
                Logger::debug("Vendor Data Skip: Missing price or quantity for SKU: $sku");
                continue;
            }

            // 3. Deduplicate (Last one wins)
            if (isset($cleanData[$sku])) {
                Logger::debug("Vendor Data Duplicate SKU ($sku): Overwriting with latest values.");
            }

            $cleanData[$sku] = [
                'product_code'  => $sku,
                'retail_price'  => (float)$item['retail_price'],
                'available_qty' => (int)$item['available_qty']
            ];
        }

        if (empty($cleanData)) {
            throw new Exception("Critical Error: Vendor data is empty after validation/sanitization.");
        }

        return array_values($cleanData);
    }

    // ================= RETRY =================
    private function retry($callback, $maxAttempts = 3) {
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            try {
                return $callback();
            } catch (Exception $e) {
                $attempt++;
                Logger::info("Vendor API Retry $attempt/$maxAttempts: " . $e->getMessage());
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep(pow(2, $attempt) * 500000);
            }
        }
    }
}
