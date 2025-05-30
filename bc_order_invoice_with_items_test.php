<?php
// bc_order_invoice_with_items_rpc.php
// Creates an OMINS invoice (header) then adds each line item via addOrderItem()

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 0) Security check (optional)
$secret = getenv('WEBHOOK_SECRET');
$token  = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;
if ($secret && (! $token || ! hash_equals($secret, $token))) {
    http_response_code(403);
    error_log("❌ Forbidden: invalid token");
    echo json_encode(['status'=>'error','message'=>'Forbidden']);
    exit;
}

// 1) Read & decode webhook payload
$raw = file_get_contents('php://input');
error_log("📥 Payload: {$raw}");
$data = json_decode($raw, true);
if (json_last_error()) {
    http_response_code(400);
    error_log("❌ Invalid JSON: " . json_last_error_msg());
    echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
    exit;
}
// unwrap Zapier nested payload
if (isset($data['']) && is_string($data[''])) {
    $data = json_decode($data[''], true) ?: $data;
}

// 2) Extract products & shipping
$products    = $data['products'] ?? [];
$shippingArr = $data['shipping']  ?? [];
if (isset($products[0]) && is_array($products[0]) && !isset($products[0]['sku'])) {
    $products = $products[0];
}
$ship = $shippingArr[0] ?? [];

error_log("📦 Parsed " . count($products) . " products");
error_log("🚚 Shipping: " . print_r($ship, true));

// 3) Determine dates & order ID
$orderId    = $data['order_id'] ?? ($products[0]['order_id'] ?? null);
$dateRaw    = $data['date_created'] ?? null;
if ($dateRaw) {
    try { $dt = new DateTime($dateRaw); }
    catch (Exception $e) { $dt = new DateTime('now', new DateTimeZone('UTC')); }
} else {
    $dt = new DateTime('now', new DateTimeZone('UTC'));
}
$dt->setTimezone(new DateTimeZone('Pacific/Auckland'));
$orderDate = $dt->format('Y-m-d');

error_log("📅 OrderDate: {$orderDate}, OrderID: {$orderId}");

// 4) Bootstrap JSON-RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // $api_url, $sys_id, $username, $password
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password,
];

// 5) Build & send createOrder header
$header = [
    'promo_group_id'   => 1,
    'orderdate'        => $orderDate,
    'statusdate'       => $orderDate,
    'name'             => trim(($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? '')),
    'company'          => $ship['company'] ?? '',
    'address'          => $ship['street_1'] ?? '',
    'city'             => $ship['city'] ?? '',
    'postcode'         => $ship['zip'] ?? '',
    'state'            => $ship['state'] ?? '',
    'country'          => $ship['country'] ?? '',
    'phone'            => $ship['phone'] ?? '',
    'mobile'           => $ship['phone'] ?? '',
    'email'            => $ship['email'] ?? '',
    'type'             => 'invoice',
    'note'             => $orderId ? "BC Order #{$orderId}" : "BC Order",
    'lineitemschanged' => 0,
];

error_log("🛠️ createOrder() header:\n" . print_r($header, true));

try {
    $res = $client->createOrder($creds, $header);
    error_log("🎯 createOrder response: " . print_r($res, true));
    if (is_array($res) && isset($res['id'])) {
        $invId = $res['id'];
    } elseif (is_numeric($res)) {
        $invId = (int)$res;
    } else {
        throw new Exception("No invoice ID in createOrder response");
    }
    error_log("✅ Created invoice ID: {$invId}");
} catch (Exception $e) {
    http_response_code(500);
    error_log("❌ createOrder error: " . $e->getMessage());
    echo json_encode([
        'status'=>'error','stage'=>'createOrder','message'=>$e->getMessage(),'raw'=>$res
    ]);
    exit;
}

// 6) Loop through products and call addOrderItem()
$added = [];
foreach ($products as $idx => $p) {
    $line = $idx + 1;

    $itemParams = [
        'invoice_id'  => $invId,                                            // 1st arg
        'template_id' => 0,                                                // 2nd arg
        'qty'         => intval($p['quantity'] ?? 1),                      // 3rd arg
        'price'       => number_format(floatval($p['price_inc_tax'] ?? 0), 4, '.', ''), // 5th arg
        'shipping'    => '0.0000',                                         // 6th arg (must be non-empty)
        'description' => $p['name_customer'] ?? ($p['name'] ?? ''),        // 7th arg
        // you can also pass 'tax_zone', 'notes', etc. if needed
    ];

    error_log("🛠️ addOrderItem() #{$line} params:\n" . print_r($itemParams, true));
    try {
        $iRes = $client->addOrderItem($creds, $itemParams);
        error_log("🎯 addOrderItem() response: " . print_r($iRes, true));
        $added[] = ['line'=>$line,'res'=>$iRes];
    } catch (Exception $e) {
        error_log("❌ addOrderItem() error on line {$line}: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'status'=>'error',
            'stage'=>"addOrderItem_line{$line}",
            'message'=>$e->getMessage()
        ]);
        exit;
    }
}


// 7) All done!
echo json_encode([
    'status'     => 'success',
    'invoice_id' => $invId,
    'items'      => $added
]);
