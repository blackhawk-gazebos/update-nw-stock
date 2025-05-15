<?php
// sync_full_catalog.php

require 'jsonRPCClient.php';
require '00_creds.php';
require 'bc_helpers.php';   // your bc_request() & update_variant_stock()

$client      = new jsonRPCClient($api_url, false);
$creds       = (object)[ 'system_id'=>$sys_id, 'username'=>$username, 'password'=>$password ];
$storeHash   = getenv('BC_STORE_HASH');

// ————————————————
// 1) Fetch all BigCommerce SKUs + variant IDs
// ————————————————
$bcProducts = [];
$page = 1;
do {
    $url  = "https://api.bigcommerce.com/stores/{$storeHash}/v3/catalog/products"
          . "?include=variants&limit=250&page={$page}";
    $resp = bc_request('GET', $url);
    $data = $resp['data'] ?? [];

    foreach ($data as $p) {
        foreach ($p['variants'] as $v) {
            // map SKU → [ product_id, variant_id ]
            $bcProducts[$v['sku']] = [
                'product_id' => $p['id'],
                'variant_id' => $v['id']
            ];
        }
    }
    $page++;
} while (count($data) === 250);

// ————————————————
// 2) Fetch all OMINS stock rows in one go
// ————————————————
// (Replace 1050 with your actual stock‐table ID)
$allStockRows = $client->search($creds, [
    'url_params' => 'id=1050&sortit1=product_id&limit=1000'
]);

// map OMINS code/name → stock
$ominsStock = [];
foreach ($allStockRows as $row) {
    // You need the code/name, so fetch the product metadata
    $prod = $client->getProduct($creds, $row['product_id']);
    $code = $prod['name'];            // your “code” field
    $ominsStock[$code] = (int)$row['stock'];
}

// ————————————————
// 3) Cross-match and update BC
// ————————————————
foreach ($bcProducts as $sku => $ids) {
    if (! isset($ominsStock[$sku])) {
        // no matching OMINS item → skip
        continue;
    }
    $newStock = $ominsStock[$sku];
    update_variant_stock(
      $ids['product_id'],
      $ids['variant_id'],
      $newStock
    );
    echo "Synced {$sku} → {$newStock}\n";
}
