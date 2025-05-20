<?php
// bc_order_invoice.php
// ———————————————————————————————
// Receives a BigCommerce order payload via POST,
// unwraps it, and creates an invoice in OMINS via JSON-RPC.
// ———————————————————————————————

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 1) Read & log the raw request
$raw = file_get_contents('php://input');
error_log("🛎️ Webhook payload: {$raw}");

// 2) Decode JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
    exit;
}

// 3) Unwrap array if necessary
if (isset($data[0]) && is_array($data[0])) {
    $order = $data[0];
} elseif (isset($data['data']) && is_array($data['data']) && isset($data['data'][0])) {
    $order = $data['data'][0];
} elseif (isset($data['data']) && is_array($data['data'])) {
    $order = $data['data'];
} else {
    $order = $data;
}

// 4) Bootstrap OMINS client
require_once 'jsonRPCClient.php';
require_once '00_creds.php';  // defines $sys_id, $username, $password, $api_url

$client = new jsonRPCClient($api_url, false);
$creds  = (object)[
    'system_id'=>$sys_id,
    'username' =>$username,
    'password' =>$password
];

// 5) Build invoice lines from BC items
$lines = [];
if (!empty($order['line_items']) && is_array($order['line_items'])) {
    foreach ($order['line_items'] as $item) {
        $sku   = $item['sku']   ?? '';
        $qty   = (int)($item['quantity'] ?? 0);
        $price = (float)($item['price_inc_tax'] ?? ($item['price_ex_tax'] ?? 0));
        if (!$sku || $qty < 1) continue;

        try {
            // Lookup OMINS product by code/name
            $meta = $client->getProductbyName($creds, ['name'=>$sku]);
            if (empty($meta['id'])) {
                error_log("⚠️ OMINS product not found for SKU {$sku}");
                continue;
            }
            $lines[] = [
                'product_id' => $meta['id'],
                'quantity'   => $qty,
                'unit_price' => $price,
            ];
        } catch (Exception $e) {
            error_log("⚠️ OMINS lookup error for SKU {$sku}: " . $e->getMessage());
            continue;
        }
    }
}

if (empty($lines)) {
    http_response_code(422);
    echo json_encode(['status'=>'error','message'=>'No valid line items found to invoice']);
    exit;
}

// 6) Gather shipping & customer info
$ship = $order['shipping_address'] ?? [];
$ship_to_name     = trim(($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? ''));
$ship_to_address1 = $ship['street_1'] ?? '';
$ship_to_city     = $ship['city'] ?? '';
$ship_to_postcode = $ship['zip'] ?? '';
$ship_to_phone    = $ship['phone'] ?? '';
$ship_to_email    = $ship['email'] ?? '';

$customerId = (int)($order['customer_id'] ?? 0);

// 7) Build RPC params
$params = [
    'customer_id'      => $customerId,
    'order_date'       => substr($order['date_created'] ?? '', 0, 10),
    'ship_to_name'     => $ship_to_name,
    'ship_to_address1' => $ship_to_address1,
    'ship_to_city'     => $ship_to_city,
    'ship_to_postcode' => $ship_to_postcode,
    'ship_to_phone'    => $ship_to_phone,
    'ship_to_email'    => $ship_to_email,
    'lines'            => $lines,
    'comments'         => "BigCommerce Order #" . ($order['id'] ?? ''),
];

// 8) Call createInvoice
try {
    $invoice = $client->createInvoice($creds, $params);
    error_log("✅ Created OMINS invoice ID: " . ($invoice['id'] ?? 'n/a'));

    http_response_code(200);
    echo json_encode([
      'status'     => 'success',
      'invoice_id' => $invoice['id'] ?? null
    ]);
} catch (Exception $e) {
    error_log("❌ Failed to create OMINS invoice: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
      'status'  => 'error',
      'message' => $e->getMessage()
    ]);
}
