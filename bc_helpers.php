<?php
/**
 * bc_helpers.php
 *
 * BigCommerce API helper functions.
 *
 * Requires environment variables:
 *   - BC_STORE_HASH
 *   - BC_ACCESS_TOKEN
 */

// Load credentials
$storeHash   = getenv('BC_STORE_HASH');
$accessToken = getenv('BC_ACCESS_TOKEN');

/**
 * Perform an HTTP request to the BigCommerce API.
 *
 * @param string      $method HTTP method (GET, POST, PUT, DELETE)
 * @param string      $url    Full URL to call
 * @param string|null $body   Optional raw JSON body
 * @return array              Decoded JSON response
 * @throws Exception          On network or JSON parse errors
 */
function bc_request(string $method, string $url, ?string $body = null): array {
    global $accessToken;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Auth-Token: {$accessToken}",
        "Accept: application/json",
        "Content-Type: application/json",
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if ($err = curl_error($ch)) {
        curl_close($ch);
        throw new Exception("BigCommerce API error: {$err}");
    }
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Failed to parse JSON: " . json_last_error_msg());
    }
    return $decoded;
}

/**
 * Update the inventory level for a specific product variant.
 *
 * @param int $productId ID of the product in BigCommerce
 * @param int $variantId ID of the variant to update
 * @param int $newStock  New inventory level to set
 * @return array         BigCommerce API response
 */
function update_variant_stock(int $productId, int $variantId, int $newStock): array {
    global $storeHash;
    $url  = "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products/{$productId}/variants/{$variantId}";
    $body = json_encode(['inventory_level' => $newStock]);
    return bc_request('PUT', $url, $body);
}
