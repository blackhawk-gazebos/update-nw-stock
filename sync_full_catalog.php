<?php
// sync_jute.php (verbose)
// —————————————————————————————
// Sync “jute” SKUs from OMINS into BC, with first-10 feedback.
// —————————————————————————————

header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';    // $sys_id, $username, $password, $api_url
require_once 'bc_helpers.php';  // bc_request(), update_variant_stock()

// ——— Setup OMINS client ———
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
  'system_id' => $sys_id,
  'username'  => $username,
  'password'  => $password,
];

// ——— 1) Fetch all “jute” SKUs from BC ———
$storeHash = getenv('BC_STORE_HASH');
$limit     = 250;
$page      = 1;
$bcMap     = [];  // sku => [product_id, variant_id]

do {
    $url = "https://api.bigcommerce.com/stores/{$storeHash}"
         . "/v3/catalog/products"
         . "?include=variants&limit={$limit}&page={$page}"
         . "&name:like=" . urlencode('jute');

    $resp = bc_request('GET', $url);
    $products = $resp['data'] ?? [];
    $total_pages = $resp['meta']['pagination']['total_pages'] ?? 1;

    foreach ($products as $p) {
        foreach ($p['variants'] as $v) {
            $sku = $v['sku'] ?? '';
            if ($sku) {
                $bcMap[$sku] = [
                    'product_id' => $p['id'],
                    'variant_id' => $v['id'],
                ];
            }
        }
    }
    echo "BC page {$page}/{$total_pages}: fetched " . count($products) . " products\n";
    $page++;
} while ($page <= $total_pages);

$total = count($bcMap);
echo "\nTotal BC SKUs to sync: {$total}\n";

// —– Feedback: first 10 BC SKUs —–
/*
echo "\nFirst 10 BC SKUs:\n";
$bcSkus = array_keys($bcMap);
foreach (array_slice($bcSkus, 0, 10) as $i => $sku) {
    echo sprintf(" %2d. %s\n", $i+1, $sku);
} */ 

// ——— 2) Lookup in OMINS — collect matches ———
$foundMap = [];  // sku => instock
foreach ($bcMap as $sku => $_ids) {
    try {
        $meta = $client->getProductbyName($creds, ['name' => $sku]);
        if (empty($meta['id'])) {
            continue;
        }
        $prod  = $client->getProduct($creds, $meta['id']);
        $stock = $prod['instock'] ?? $prod['available'] ?? 0;
        $foundMap[$sku] = $stock;
    }
    catch (Exception $e) {
        // skip on error
    }
}

$matched = count($foundMap);
echo "\nOMINS SKUs found in BC list: {$matched}\n";


// —– Feedback: first 10 matched SKUs —–
/*
echo "\nFirst 10 matched SKUs (OMINS → stock):\n";
$matchedSkus = array_keys($foundMap);
foreach (array_slice($matchedSkus, 0, 10) as $i => $sku) {
    printf(" %2d. %s => %d\n", $i+1, $sku, $foundMap[$sku]);
}
*/

// ——— 3) Push updates to BC ———
echo "\nSyncing inventory levels:\n";
$updated = 0;
foreach ($foundMap as $sku => $stock) {
    $ids = $bcMap[$sku];
    try {
        $resp = update_variant_stock(
            $ids['product_id'],
            $ids['variant_id'],
            (int)$stock
        );
        $vid = $resp['data'][0]['id'] ?? 'n/a';
        echo "· SKU {$sku}: set variant {$vid} → {$stock}\n";
        $updated++;
    } catch (Exception $e) {
        echo "! Error for {$sku}: {$e->getMessage()}\n";
    }
}

echo "\nDone. Variants updated: {$updated}\n";
