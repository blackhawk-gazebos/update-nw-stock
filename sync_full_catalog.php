<?php
// sync_full_catalog.php
// Full catalog sync with debug: improved pagination for BC and OMINS, lists first 10 OMINS matches, updates BC variants.

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

// Read optional filters
$filter     = isset($_GET['matches'])     ? trim($_GET['matches'])       : '';
$promoGroup = isset($_GET['promo_group']) ? trim($_GET['promo_group'])   : '';

if ($filter !== '') {
    echo "Filtering BC products by name like '{$filter}'" . PHP_EOL;
}
if ($promoGroup !== '') {
    echo "Filtering OMINS products by promo group name like '%{$promoGroup}%'." . PHP_EOL;
}
echo PHP_EOL;

// 1) Fetch all BC products with variants using proper pagination
$storeHash = getenv('BC_STORE_HASH');
$allProducts = [];
$page = 1;
$bcNameParam = $filter ? '&name:like=' . urlencode($filter) : '';

// First page to get total pages
echo "Fetching BC page 1..." . PHP_EOL;
$url  = "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products?include=variants&limit=250&page=1{$bcNameParam}";
$resp = bc_request('GET', $url);
$data = $resp['data'] ?? [];
$allProducts = array_merge($allProducts, $data);

// Determine total pages from response meta
$total_pages = $resp['meta']['pagination']['total_pages'] ?? 1;
echo "Fetched " . count($data) . " products on page 1 of {$total_pages}." . PHP_EOL;

// Fetch remaining pages
for ($page = 2; $page <= $total_pages; $page++) {
    echo "Fetching BC page {$page}..." . PHP_EOL;
    $url  = "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products?include=variants&limit=250&page={$page}{$bcNameParam}";
    $resp = bc_request('GET', $url);
    $data = $resp['data'] ?? [];
    $allProducts = array_merge($allProducts, $data);
    echo "Fetched " . count($data) . " products on page {$page}." . PHP_EOL;
}

echo PHP_EOL . "Total BC products fetched: " . count($allProducts) . PHP_EOL . PHP_EOL;

// Map BC SKU => [product_id, variant_id]
$bcMap = [];
foreach ($allProducts as $prod) {
    foreach ($prod['variants'] as $var) {
        if (!empty($var['sku'])) {
            $bcMap[$var['sku']] = [
                'product_id' => $prod['id'],
                'variant_id' => $var['id'],
            ];
        }
    }
}
echo "Mapped " . count($bcMap) . " SKUs to BC IDs." . PHP_EOL . PHP_EOL;

// 2) Fetch all OMINS stock rows across pages
$stockTableId = 1047;
$pageOffset   = 0;
$limit        = 200;  // OMINS max page size
$allStockRows = [];
echo "Fetching OMINS stock rows (tabledef {$stockTableId}) in pages..." . PHP_EOL;

do {
    // include sort to ensure consistent pagination
    $urlParams = "id={$stockTableId}&sortit1=product_id&limit={$limit}&start={$pageOffset}";
    echo "  - page start={$pageOffset}
";
    $rows = $client->search($creds, ['url_params' => $urlParams]);
    if (!$rows) break;
    $count = count($rows);
    $allStockRows = array_merge($allStockRows, $rows);
    $pageOffset += $count;
} while ($count === $limit);

echo "Total OMINS stock rows fetched: " . count($allStockRows) . PHP_EOL . PHP_EOL;

// 3) Build OMINS code=>stock map with promo_group filter
$ominsMap = [];
foreach ($allStockRows as $row) {
    $pid = $row['product_id'] ?? $row['prod_id'] ?? null;
    if (!$pid) continue;
    $product = $client->getProduct($creds, $pid);
    $code    = $product['name'] ?? null;
    $pgId    = $product['promo_group_id'] ?? null;
    if (!$code) continue;
    if ($promoGroup !== '') {
        $rule = $client->getPromotionRule($creds, ['id' => $pgId]);
        $ruleName = $rule['name'] ?? '';
        if (stripos($ruleName, $promoGroup) === false) continue;
    }
    $ominsMap[$code] = (int)($row['stock'] ?? 0);
}
echo "Built OMINS code=>stock map with " . count($ominsMap) . " entries." . PHP_EOL . PHP_EOL;

// 4) List first 10 OMINS codes after filters
echo "First 10 OMINS codes (code => stock):" . PHP_EOL;
$first10Omins = array_slice($ominsMap, 0, 10, true);
foreach ($first10Omins as $code => $stock) {
    echo " - {$code} => {$stock}" . PHP_EOL;
}
echo PHP_EOL;

// 5) Cross-sync BC variants
$updated = 0;
foreach ($bcMap as $sku => $ids) {
    if (!isset($ominsMap[$sku])) continue;
    $newStock  = $ominsMap[$sku];
    $resp      = update_variant_stock($ids['product_id'], $ids['variant_id'], $newStock);
    $variantId = $resp['data'][0]['id'] ?? 'n/a';
    echo "Synced SKU {$sku} to {$newStock} (variant ID: {$variantId})" . PHP_EOL;
    $updated++;
}
echo PHP_EOL . "Done. Updated {$updated} variants." . PHP_EOL;
echo "</pre>";
