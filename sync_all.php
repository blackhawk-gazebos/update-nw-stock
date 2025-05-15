<?php
// sync_all.php

// ——— Config & Helpers ———
$storeHash   = getenv('BC_STORE_HASH');
$accessToken = getenv('BC_ACCESS_TOKEN');
$proxyBase   = 'https://inventory-rpc-demo.onrender.com/stock_api.php';

function bc_request($method, $url, $body=null) {
    global $accessToken;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Auth-Token: {$accessToken}",
        "Accept: application/json",
        "Content-Type: application/json",
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

function get_all_products() {
    global $storeHash;
    $all = [];
    $page = 1;
    do {
        $url  = "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products?limit=250&page={$page}";
        $resp = bc_request('GET', $url);
        $data = $resp['data'] ?? [];
        $all  = array_merge($all, $data);
        $page++;
    } while (count($data) === 250);  // more pages?
    return $all;
}

function update_variant_stock($productId, $variantId, $newStock) {
    global $storeHash;
    $url  = "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products/{$productId}/variants/{$variantId}";
    $body = json_encode(['inventory_level' => $newStock]);
    return bc_request('PUT', $url, $body);
}

// ——— Main Sync Loop ———
$products = get_all_products();
foreach ($products as $prod) {
    // single-SKU products will have sku on data; multi-variant will need extra GET
    $sku = $prod['sku'] ?? null;
    $pId = $prod['id'];
    if (! $sku) {
        // fetch variants and sync each one
        $vars = bc_request('GET',
            "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products/{$pId}/variants"
        )['data'];
        foreach ($vars as $v) {
            sync_one_variant($pId, $v['id'], $v['sku']);
        }
    } else {
        // single-variant product
        $variantId = $prod['variants'][0]['id'] ?? null;
        if ($variantId) sync_one_variant($pId, $variantId, $sku);
    }
}

function sync_one_variant($productId, $variantId, $sku) {
    global $proxyBase;
    // 1) get OMINS stock
    $json = file_get_contents("{$proxyBase}?sku=" . urlencode($sku));
    $data = json_decode($json, true);
    $stock = isset($data['stock']) ? (int)$data['stock'] : null;
    if ($stock === null) {
        echo "❌ SKU {$sku}: no stock data\n";
        return;
    }
    // 2) update BigCommerce
    $resp = update_variant_stock($productId, $variantId, $stock);
    echo "✅ SKU {$sku}: set to {$stock}\n";
}
