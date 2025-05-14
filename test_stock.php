<?php
// 1) Suppress deprecation notices
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once 'jsonRPCClient.php';
require_once '00_creds.php';

$client = new jsonRPCClient($api_url, $debug);

// build your credentials object
$creds = new stdClass;
$creds->system_id = $sys_id;
$creds->username  = $username;
$creds->password  = $password;

// ← adjust to the real product ID you want to check
$product_id = 4762;

try {
    // call getProduct(), not getStockLevel()
    $product = $client->getProduct($creds, $product_id);
    if (! $product) {
        throw new Exception("No product found with ID {$product_id}");
    }

    // … after your getProduct() call …
//    echo "<pre>";
//    print_r($product);
//    echo "</pre>";
//    exit;   // stop here so you can inspect the output

    
    // most APIs return the on-hand count in 'stock'
    echo "Product “{$product['name']}” (ID {$product_id}) has "
       . $product['instock'] . " units on hand\n";

} catch (Exception $e) {
    echo "Error fetching stock: " . $e->getMessage() . "\n";
}
