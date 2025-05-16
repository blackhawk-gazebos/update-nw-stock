<?php
// Wrap output in <pre> so HTML preserves newlines
header('Content-Type: text/html');
echo "<pre>";

// sync_full_catalog.php
// Debug version: shows first 10 BC SKUs and OMINS codes, lists matches with spacing.

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

// Optional filter parameter
$filter = isset($_GET['matches']) ? trim($_GET['matches']) : '';
$filter_param = '';
if ($filter !== '') {
    $filter_param = '&name:like=' . urlencode($filter);
    echo "Filtering BC products by name like '{$filter}'" . PHP_EOL . PHP_EOL;
}

// 1) Fetch BC products (+variants)
$storeHash   = getenv('BC_STORE_HASH');
$allProducts = [];
$page = 1;
do {
    echo "Fetching BC page {$page}..." . PHP_EOL;
    $url  = "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products"
          . "?include=variants&limit=250&page={$page}{$filter_param}";
    $resp = bc_request('GET', $url);
    $data = $resp['data'] ?? [];
    $allProducts = array_merge($allProducts, $data);
    $page++;
} while (count($data) === 250);

echo PHP_EOL . "Total BC products fetched: " . count($allProducts) . PHP_EOL;

// 2) Map BC SKU => IDs
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

// List first 10 BC SKUs
echo PHP_EOL . "First 10 BC SKUs:" . PHP_EOL;
$bcSkus = array_keys($bcMap);
foreach (array_slice($bcSkus, 0, 10) as $s) {
    echo " - {$s}" . PHP_EOL;
}

// 3) Fetch OMINS stock rows
echo PHP_EOL . "Fetching OMINS stock rows (tabledef 1050)..." . PHP_EOL;
$stockRows = $client->search($creds, ['url_params' => 'id=1047&limit=1000']);

echo PHP_EOL . "Raw OMINS stockRows:" . PHP_EOL;
print_r($stockRows);

echo PHP_EOL . (empty($stockRows) ? "No stock rows returned from OMINS." : "Total OMINS stock rows: " . count($stockRows)) . PHP_EOL;

// 4) Build OMINS code=>stock map
$ominsMap = [];
foreach ($stockRows as $row) {
    $prod = $client->getProduct($creds, $row['product_id']);
    $code = $prod['name'] ?? null;
    if ($code !== null) {
        $ominsMap[$code] = (int)$row['stock'];
    }
}

echo PHP_EOL . "OMINS codes found:" . PHP_EOL;
$ominsCodes = array_keys($ominsMap);
print_r($ominsCodes);

// List first 10 OMINS codes
echo PHP_EOL . "First 10 OMINS codes:" . PHP_EOL;
foreach (array_slice($ominsCodes, 0, 10) as $c) {
    echo " - {$c}" . PHP_EOL;
}

// 5) List matched SKUs
$common = array_intersect($bcSkus, $ominsCodes);

echo PHP_EOL . "Matched SKUs (present in both):" . PHP_EOL;
print_r(array_values($common));

echo PHP_EOL . "Total matches: " . count($common) . PHP_EOL;

echo "</pre>";
exit; // debug only
