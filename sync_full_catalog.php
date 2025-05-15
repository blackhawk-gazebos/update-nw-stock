<?php
// sync_full_catalog.php
// A debug version: lists OMINS stock rows and matched SKUs against BC before syncing.

// Display all errors for debugging
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

// Optional: read a 'matches' GET parameter to filter product names
$filter = isset($_GET['matches']) ? trim($_GET['matches']) : '';
if ($filter !== '') {
    // sanitize filter for URL
    $filter_param = '&name:like=' . urlencode($filter);
    echo "Filtering BC products by name like '{$_GET['matches']}'..." . PHP_EOL;
} else {
    $filter_param = '';
}

// 1) Fetch all BigCommerce products (+variants)
$storeHash   = getenv('BC_STORE_HASH');
$allProducts = [];
$page = 1;
do {
    $url = "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products?include=variants&limit=250&page={$page}" . $filter_param;
    echo "Fetching BC page {$page}..." . PHP_EOL;
    $resp = bc_request('GET', $url);
    $data = $resp['data'] ?? [];
    $allProducts = array_merge($allProducts, $data);
    $page++;
} while(count($data) === 250);

echo "Total BC products fetched: " . count($allProducts) . PHP_EOL;

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

// 3) Fetch OMINS stock rows
echo "Fetching OMINS stock rows tabledef 1050..." . PHP_EOL;
$stockRows = $client->search($creds, ['url_params' => 'id=1050&limit=1000']);

echo "Raw stockRows from OMINS:" . PHP_EOL;
print_r($stockRows);

if (empty($stockRows)) {
    echo "No stock rows returned from OMINS." . PHP_EOL;
} else {
    echo "Total OMINS stock rows: " . count($stockRows) . PHP_EOL;
}

// 4) Build OMINS code=>stock map
$ominsMap = [];
foreach ($stockRows as $row) {
    $prod = $client->getProduct($creds, $row['product_id']);
    $code = $prod['name'] ?? null;
    if ($code !== null) {
        $ominsMap[$code] = (int)$row['stock'];
    }
}

echo "OMINS codes found (keys):" . PHP_EOL;
print_r(array_keys($ominsMap));

// 5) List matched SKUs between BC and OMINS
echo "\nMatched SKUs (present in both):" . PHP_EOL;
$common = array_keys(array_intersect_key($bcMap, $ominsMap));
print_r($common);

echo "\nTotal matches: " . count($common) . PHP_EOL;

exit; // debug complete; remove exit to perform real sync
