<?php
// sync_full_catalog.php
// Full catalog sync: pulls selected SKUs from BigCommerce, fetches OMINS stock, and updates BC variants.

header('Content-Type: text/html');
echo "<pre>";
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';    // provides $sys_id, $username, $password, $api_url
require_once 'bc_helpers.php';  // provides bc_request() and update_variant_stock()

// Build OMINS client and credentials
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id' => $sys_id,
    'username'  => $username,
    'password'  => $password,
];

// Optional: filter BC products by name contains 'matches'
$filter = isset($_GET['matches']) ? trim($_GET['matches']) : '';
$filterParam = '';
if ($filter !== '') {
    $filterParam = '&name:like=' . urlencode($filter);
    echo "Filtering BC products by name like '{$filter}'" . PHP_EOL . PHP_EOL;
}

// 1) Fetch all BC products with variants in pages
$storeHash   = getenv('BC_STORE_HASH');
$allProducts = [];
$page = 1;
do {
    echo "Fetching BC page {$page}..." . PHP_EOL;
    $url = "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products"
         . "?include=variants&limit=250&page={$page}{$filterParam}";
    $resp = bc_request('GET', $url);
    $data = $resp['data'] ?? [];
    $allProducts = array_merge($allProducts, $data);
    $page++;
} while (count($data) === 250);

echo PHP_EOL . "Total BC products fetched: " . count($allProducts) . PHP_EOL;

// 2) Map BC SKU => [product_id, variant_id]
$bcMap = [];
foreach ($allProducts as $prod) {
    foreach ($prod['variants'] as $var) {
        $sku = $var['sku'] ?? '';
        if ($sku) {
            $bcMap[$sku] = [
                'product_id' => $prod['id'],
                'variant_id' => $var['id'],
            ];
        }
    }
}
echo "Mapped " . count($bcMap) . " SKUs to BC IDs." . PHP_EOL;

// 3) Fetch OMINS stock rows (correct tabledef)
$stockTableId = 1047;  // adjust to your actual stock table ID
$limit = 1000;
echo PHP_EOL . "Fetching OMINS stock rows tabledef {$stockTableId}..." . PHP_EOL;
$stockRows = $client->search($creds, [ 'url_params' => "id={$stockTableId}&limit={$limit}" ]);

if (empty($stockRows)) {
    echo "No stock rows returned from OMINS. Check tabledef and permissions." . PHP_EOL;
    echo "</pre>";
    exit;
}
echo "Total OMINS stock rows: " . count($stockRows) . PHP_EOL;

// 4) Build OMINS code=>stock map with guard
$ominsMap = [];
foreach ($stockRows as $row) {
    // handle different possible key names for product reference
    $pid = $row['product_id'] ?? $row['prod_id'] ?? null;
    if (! $pid) {
        continue;  // skip rows without a valid product ID
    }
    // fetch product code/name
    $prod = $client->getProduct($creds, $pid);
    $code = $prod['name'] ?? null;
    if ($code) {
        $ominsMap[$code] = (int)($row['stock'] ?? 0);
    }
}
echo "Built OMINS code=>stock map with " . count($ominsMap) . " entries." . PHP_EOL;

// 5) Cross-sync BC variants with OMINS stock
$updated = 0;
foreach ($bcMap as $sku => $ids) {
    if (! isset($ominsMap[$sku])) {
        continue;
    }
    $newStock = $ominsMap[$sku];
    $resp = update_variant_stock($ids['product_id'], $ids['variant_id'], $newStock);
    $variantId = $resp['data'][0]['id'] ?? 'n/a';
    echo "Synced SKU {$sku} to {$newStock} (variant ID: {$variantId})" . PHP_EOL;
    $updated++;
}

echo PHP_EOL . "Done. Updated {$updated} variants." . PHP_EOL;
echo "</pre>";
