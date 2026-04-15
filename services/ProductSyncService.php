<?php

require_once __DIR__ . '/../helpers/Logger.php';

class ProductSyncService {

    private $shopify;
    private $locationId;

    public function __construct($shopify, $locationId) {
        $this->shopify = $shopify;
        $this->locationId = $locationId;
    }

    private function normalizeVendorData($vendorData, $valueField) {
        $normalized = [];
        foreach ($vendorData as $item) {
            
            if (!isset($item['product_code'])) {
                Logger::debug("Normalization Skip: Missing 'product_code' in vendor data item.");
                continue;
            }

            if (!isset($item[$valueField])) {
                Logger::debug("Normalization Skip: Missing '$valueField' for SKU: " . ($item['product_code'] ?? 'Unknown'));
                continue;
            }

            $sku = strtoupper(trim($item['product_code']));
            $value = $item[$valueField];

            // For prices, format as 2-decimal string
            if ($valueField === 'retail_price') {
                $value = number_format((float)$value, 2, '.', '');
            }

            // For inventory, ensure it is at least 0
            if ($valueField === 'available_qty') {
                $value = max(0, (int)$value);
            }

            $normalized[$sku] = [
                'sku' => $sku,
                'value' => $value
            ];
        }
        return array_values($normalized);
    }

    private function getSkuMap($vendorData) {

        $skuMap = [];
        $cursor = null;

        do {
            $query = [
                "query" => '
                query ($cursor: String) {
                productVariants(first: 250, after: $cursor) {
                    edges {
                    cursor
                    node {
                        id
                        sku
                        price
                        product { id }
                        inventoryItem { 
                        id 
                        inventoryLevel(locationId: "' . $this->locationId . '") {
                            onHand: quantities(names: ["on_hand"]) { quantity }
                        }
                        }
                    }
                    }
                    pageInfo {
                    hasNextPage
                    }
                }
                }',
                "variables" => [
                    "cursor" => $cursor
                ]
            ];

            $res = $this->shopify->graphql($query);

            $edges = $res['data']['productVariants']['edges'] ?? [];

            foreach ($edges as $edge) {
                $node = $edge['node'];

                $onHand = $node['inventoryItem']['inventoryLevel']['onHand'][0]['quantity'] ?? 0;

                $skuMap[$node['sku']] = [
                    'variant_id' => $node['id'],
                    'product_id' => $node['product']['id'],
                    'inventory_item_id' => $node['inventoryItem']['id'],
                    'current_price' => $node['price'],
                    'current_qty'   => (int)$onHand
                ];

                $cursor = $edge['cursor'];
            }

        } while ($res['data']['productVariants']['pageInfo']['hasNextPage']);

        return $skuMap;
    }

    public function syncUnified($vendorDataRaw) {
        
        // 1. Normalize data into quick-lookup maps
        $vPrices = [];
        foreach ($this->normalizeVendorData($vendorDataRaw, 'retail_price') as $item) {
            $vPrices[$item['sku']] = $item['value'];
        }

        $vQtys = [];
        foreach ($this->normalizeVendorData($vendorDataRaw, 'available_qty') as $item) {
            $vQtys[$item['sku']] = (int)$item['value'];
        }

        // Master list of all SKUs in the vendor file
        $vendorSkus = array_unique(array_merge(array_keys($vPrices), array_keys($vQtys)));

        // 2. Fetch current Shop state
        $skuMap = $this->getSkuMap($vendorDataRaw);

        $productChanges = [];
        $result = [
            'inventory' => ['successCount' => 0, 'failed' => [], 'skippedCount' => 0],
            'price'     => ['successCount' => 0, 'failed' => [], 'skippedCount' => 0],
            'totalNotFound' => 0
        ];

        // 3. Identify all needed changes based on Vendor SKUs
        foreach ($vendorSkus as $sku) {
            
            // A. Check if SKU exists in Shopify
            if (!isset($skuMap[$sku])) {
                $result['totalNotFound']++;
                Logger::sync("SKU: $sku | Status: NOT FOUND IN SHOPIFY");
                continue;
            }

            $map = $skuMap[$sku];
            $pId = $map['product_id'];
            $hasChange = false;
            
            $priceAuditStr = "Price: {$map['current_price']}";
            $qtyAuditStr = "Qty: {$map['current_qty']}";

            // B. Check Price Change
            if (isset($vPrices[$sku])) {
                $newPrice = $vPrices[$sku];
                if ($map['current_price'] !== $newPrice) {
                    $productChanges[$pId]['prices'][] = [
                        'id'    => $map['variant_id'],
                        'price' => $newPrice
                    ];
                    $priceAuditStr = "Price: {$map['current_price']} -> $newPrice";
                    $hasChange = true;
                } else {
                    $result['price']['skippedCount']++;
                }
            }

            // C. Check Qty Change
            if (isset($vQtys[$sku])) {
                $newQty = $vQtys[$sku];
                if ($map['current_qty'] !== $newQty) {
                    $productChanges[$pId]['inventory'][] = [
                        'inventoryItemId' => $map['inventory_item_id'],
                        'locationId'      => $this->locationId,
                        'quantity'        => $newQty
                    ];
                    $qtyAuditStr = "Qty: {$map['current_qty']} -> $newQty";
                    $hasChange = true;
                } else {
                    $result['inventory']['skippedCount']++;
                }
            }

            $numericPid = basename($pId);
            // --- Audit Logging ---
            if ($hasChange) {
                Logger::sync("SKU: $sku | PID: $numericPid | Status: CHANGED | $priceAuditStr | $qtyAuditStr");
            } else {
                Logger::sync("SKU: $sku | PID: $numericPid | Status: UNCHANGED | Price: {$map['current_price']} | Qty: {$map['current_qty']}");
            }
        }

        // 4. Batch Process Unified Mutations (50 products per request)
        $pIds = array_keys($productChanges);
        foreach (array_chunk($pIds, 50) as $batchIndex => $pBatch) {
            
            $aliasQueries = [];
            $variableDefs = [];
            $variables = [];
            $inventoryItemsInBatch = [];
            $priceItemsInBatch = []; // For tracking success counts

            foreach ($pBatch as $idx => $pId) {
                $pTag = "p$idx";
                $vTag = "v$idx";
                $mTag = "m$idx";

                if (!empty($productChanges[$pId]['prices'])) {
                    $variableDefs[] = "\${$pTag}: ID!, \${$vTag}: [ProductVariantsBulkInput!]!";
                    $aliasQueries[] = "{$mTag}: productVariantsBulkUpdate(productId: \${$pTag}, variants: \${$vTag}) { userErrors { message } }";
                    $variables[$pTag] = $pId;
                    $variables[$vTag] = $productChanges[$pId]['prices'];
                    $priceItemsInBatch[$mTag] = count($productChanges[$pId]['prices']);
                }

                if (!empty($productChanges[$pId]['inventory'])) {
                    foreach ($productChanges[$pId]['inventory'] as $invItem) {
                        $inventoryItemsInBatch[] = $invItem;
                    }
                }
            }

            if (!empty($inventoryItemsInBatch)) {
                $aliasQueries[] = "inv: inventorySetOnHandQuantities(input: \$invInput) { userErrors { message } }";
                $variableDefs[] = "\$invInput: InventorySetOnHandQuantitiesInput!";
                $variables['invInput'] = ['reason' => 'correction', 'setQuantities' => $inventoryItemsInBatch];
            }

            if (empty($aliasQueries)) {
                continue;
            }

            $mutation = [
                "query" => "mutation(" . implode(', ', $variableDefs) . ") { " . implode(' ', $aliasQueries) . " }",
                "variables" => $variables
            ];

            try {
                $res = $this->shopify->graphql($mutation);
                
                // Point 7: Global GraphQL Error Check
                if (!empty($res['errors'])) {
                    Logger::error("GraphQL Top-Level Errors in Batch $batchIndex: " . json_encode($res['errors']));
                    $result['inventory']['failed'][] = ['sku' => "Batch $batchIndex", 'error' => "Global Shopify Error"];
                    $result['price']['failed'][]     = ['sku' => "Batch $batchIndex", 'error' => "Global Shopify Error"];
                    continue; 
                }

                $data = $res['data'] ?? [];

                // Parse Price Results
                foreach ($pBatch as $idx => $pId) {
                    $mTag = "m$idx";
                    if (isset($data[$mTag])) {
                        $errors = $data[$mTag]['userErrors'] ?? [];
                        if (!empty($errors)) {
                            foreach ($errors as $e) {
                                $msg = $e['message'];
                                if (strpos(strtolower($msg), 'not found') !== false) {
                                    Logger::debug("Unified Price Skip (Product $pId): " . $msg);
                                } else {
                                    Logger::error("Unified Price Error (Product $pId): " . $msg);
                                    $result['price']['failed'][] = ['sku' => "Product $pId", 'error' => $msg];
                                }
                            }
                        } else {
                            // Point 1: isset() check
                            if (isset($priceItemsInBatch[$mTag])) {
                                $result['price']['successCount'] += $priceItemsInBatch[$mTag];
                            }
                        }
                    } else {
                        // Point 4: missing response handle
                        if (isset($productChanges[$pId]['prices'])) {
                            Logger::error("Missing price response alias for Product $pId");
                        }
                    }
                }

                // Parse Inventory Results
                if (isset($data['inv'])) {
                   $invErrors = $data['inv']['userErrors'] ?? [];
                   if (!empty($invErrors)) {
                       foreach ($invErrors as $e) {
                           $msg = $e['message'];
                           if (strpos(strtolower($msg), 'not found') !== false) {
                               Logger::debug("Unified Inventory Skip: " . $msg);
                           } else {
                               Logger::error("Unified Inventory Error: " . $msg);
                               $result['inventory']['failed'][] = ['sku' => "Batch $batchIndex", 'error' => $msg];
                           }
                       }
                   } else {
                       $result['inventory']['successCount'] += count($inventoryItemsInBatch);
                   }
                } else {
                    // Point 4: missing response handle for inventory
                    if (!empty($inventoryItemsInBatch)) {
                        Logger::error("Missing inventory response alias for Batch $batchIndex");
                    }
                }

            } catch (Exception $e) {
                Logger::error("Unified Batch Exception: " . $e->getMessage());
            }

            // Point 5: Safer rate limiting
            usleep(100000); 
        }

        $summaryText = "Inventory: Updated: {$result['inventory']['successCount']}, Failed: " . count($result['inventory']['failed']) . ", Not Found: {$result['totalNotFound']} | ";
        $summaryText .= "Price: Updated: {$result['price']['successCount']}, Failed: " . count($result['price']['failed']) . ", Not Found: {$result['totalNotFound']}";
        Logger::sync($summaryText);
        
        return $result;
    }
}