<?php

class ShopifyClient {

    private $shop;
    private $accessToken;
    private $apiVersion;

    public function __construct($shop, $accessToken, $apiVersion = '2024-01') {
        $this->shop = $shop;
        $this->accessToken = $accessToken;
        $this->apiVersion = $apiVersion;
    }

    public function graphql($payload) {

        $url = "https://{$this->shop}/admin/api/{$this->apiVersion}/graphql.json";
        $maxAttempts = 5;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            // Wait before retry (Exponential Backoff: 2s, 4s, 8s, 16s...)
            if ($attempt > 1) {
                $delay = pow(2, $attempt);
                usleep($delay * 1000000); 
            }

            $ch = curl_init($url);
            $responseHeaders = [];

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Shopify-Access-Token: {$this->accessToken}",
                "Content-Type: application/json",
                "User-Agent: ShopifySyncMaster/1.0"
            ]);

            // Track Response Headers for Rate Limiting
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) return $len;
                $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);
                return $len;
            });

            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errNo = curl_errno($ch);
            $errMsg = curl_error($ch);
            curl_close($ch);

            // 1. Handle Connection Errors
            if ($errNo) {
                if ($attempt < $maxAttempts) {
                    continue;
                }
                throw new Exception("Shopify cURL Connection Error ($errNo): $errMsg");
            }

            // 2. Handle Rate Limiting (429)
            if ($httpCode === 429) {
                $retryAfter = isset($responseHeaders['retry-after']) ? (int)$responseHeaders['retry-after'] : (2 * $attempt);
                Logger::info("Shopify Throttled (429). Retrying after {$retryAfter}s...");
                sleep($retryAfter);
                continue;
            }

            // 3. Handle Other HTTP Errors
            if ($httpCode >= 400) {
                throw new Exception("Shopify API HTTP Error ($httpCode): $response");
            }

            $data = json_decode($response, true);
            
            // 4. Handle GraphQL Top-Level Errors
            if (isset($data['errors'])) {
                $messages = [];
                foreach ($data['errors'] as $error) {
                    $msg = $error['message'] ?? 'Unknown GraphQL Error';
                    if (isset($error['extensions']['code'])) {
                        $msg .= " (" . $error['extensions']['code'] . ")";
                    }
                    $messages[] = $msg;
                }
                throw new Exception("Shopify GraphQL Errors: " . implode('; ', $messages));
            }

            return $data;
        }
    }
}