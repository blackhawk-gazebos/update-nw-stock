<?php
// sync_full_catalog.php
// A full catalog sync: pulls every SKU from BigCommerce, fetches OMINS stock, and updates BC.

// Display all errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // provides $sys_id, $username, $password, $api_url
require_once 'bc_helpers.php'; // provides bc_request() and update_variant_stock()

// Build Omins client and creds
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password
];

// 1) Fetch all BigCommerce products (+variants) in pages
$storeHash = getenv('BC_STORE_HASH');
$allProducts = [];
$page = 1;
do {
    $url  = "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products?include=variants&limit=250&page={$page}";
    $resp = bc_request('GET', $url);
    $data = $resp['data'] ?? [];
    $allProducts = array_merge($allProducts, $data);
    $page++;
} while(count($data) === 250);

if (empty($allProducts)) {
    echo "No products found in BigCommerce.\n";
    exit;
}

echo "Fetched " . count($allProducts) . " products from BigCommerce.\n";

// 2) Build a map of BC SKU => [product_id, variant_id]\$bcMap = [];
foreach ($allProducts as $prod) {
    foreach ($prod['variants'] as $var) {
        $sku = $var['sku'];
        if ($sku) {
            $bcMap[$sku] = [
                'product_id' => $prod['id'],
                'variant_id' => $var['id']
            ];
        }
    }
}

echo "Mapped " . count($bcMap) . " SKUs to product+variant IDs.\n";

// 3) Bulk fetch OMINS stock rows (tabledef id 1050, adjust if needed)
$stockRows = $client->search($creds, ['url_params' => 'id=1050&limit=1000']);

if (empty($stockRows)) {
    echo "No stock rows returned from OMINS.\n";
    exit;
}

echo "Fetched " . count($stockRows) . " stock rows from OMINS.\n";

// Map OMINS code/name => instock
$ominsMap = [];
foreach ($stockRows as $row) {
    // fetch product to read its code (name)
    $prod = $client->getProduct($creds, $row['product_id']);
    $code = $prod['name'] ?? null;
    if ($code) {
        $ominsMap[$code] = (int)$row['stock'];
    }
}

echo "Built OMINS code=>stock map with " . count($ominsMap) . " entries.\n";

// 4) Cross-sync
$updated = 0;
foreach ($bcMap as $sku => $ids) {
    if (! isset($ominsMap[$sku])) {
        continue; // skip SKUs not in OMINS
    }
    $stock = $ominsMap[$sku];
    $resp  = update_variant_stock($ids['product_id'], $ids['variant_id'], $stock);
    echo "Synced SKU {$sku} to {$stock} (response variant_id: " . ($resp['data'][0]['id'] ?? 'n/a') . ")\n";
    $updated++;
}

echo "\nDone. Updated {$updated} variants.\n";
