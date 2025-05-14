<?php
// stock_api.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'jsonRPCClient.php';
require_once '00_creds.php';

$sku = $_GET['sku'] ?? '';
if (! $sku) {
  http_response_code(400);
  echo json_encode(['error'=>'missing sku']);
  exit;
}

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
  'system_id'=>$sys_id,
  'username' =>$username,
  'password' =>$password,
];

try {
    // 1) Lookup by “code name” via getProductbyName (Omins calls it name)
    $meta = $client->getProductbyName($creds, ['name'=>$sku]);
    if (empty($meta['id'])) {
        throw new Exception("No product found with code/name “{$sku}”");
    }
    $product_id = $meta['id'];

    // 2) Fetch full product
    $product = $client->getProduct($creds, $product_id);

    // 3) Grab the instock (or fallback) field
    $stock = $product['instock']
           ?? $product['available']
           ?? 0;

    echo json_encode([
      'sku'   => $sku,
      'stock' => (int)$stock
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
      'error' => $e->getMessage()
    ]);
}
