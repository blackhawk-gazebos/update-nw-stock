<?php
// bc_order_invoice_shipping_only.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 0) Security check
$secret = getenv('WEBHOOK_SECRET');
$token  = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;
if ($secret && (! $token || ! hash_equals($secret, $token))) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Forbidden']);
    exit;
}

// 1) Read & decode payload (an array of one shipping object)
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
    exit;
}

// Expect $data to be [ { shipping fields… } ]
if (! isset($data[0]) || ! is_array($data[0])) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Payload must be an array of shipping objects']);
    exit;
}
$ship = $data[0];

// 2) Map shipping fields into invoice header
$name    = trim(($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? ''));
$company = $ship['company']   ?? '';
$street  = $ship['street_1']  ?? '';
$city    = $ship['city']      ?? '';
$zip     = $ship['zip']       ?? '';
$state   = $ship['state']     ?? '';
$country = $ship['country']   ?? '';
$phone   = $ship['phone']     ?? '';
$email   = $ship['email']     ?? '';
$orderID = $ship['order_id']  ?? '';

// 3) Dates
// Since this payload has no date_created, we'll just use today in Auckland
$dt = new DateTime('now', new DateTimeZone('Pacific/Auckland'));
$orderDate   = $dt->format('Y-m-d');
$statusDate  = $orderDate;

// 4) Setup OMINS RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines $sys_id, $username, $password, $api_url
$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password,
];

// 5) Build createOrder params (no line items)
$params = [
    'promo_group_id'     => 9,            // or your desired group
    'orderdate'          => $orderDate,
    'statusdate'         => $statusDate,
    'name'               => $name,
    'company'            => $company,
    'address'            => $street,
    'city'               => $city,
    'postcode'           => $zip,
    'state'              => $state,
    'country'            => $country,
    'phone'              => $phone,
    'mobile'             => $phone,
    'email'              => $email,
    'type'               => 'invoice',
    'note'               => "BC Shipping Only Invoice {$orderID}",
    'specialinstructions' => "https://store-va5pcinq8p.mybigcommerce.com/manage/orders?keywords={$orderID}",
    // no items:
    'lineitemschanged'   => 0,
];

// 6) Call createOrder
try {
    $inv = $client->createOrder($creds, $params);
    echo json_encode([
        'status'     => 'success',
        'invoice_id' => $inv['id'] ?? null,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ]);
}
