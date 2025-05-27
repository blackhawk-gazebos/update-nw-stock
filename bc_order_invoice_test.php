<?php
// bc_order_invoice.php
// Secured endpoint: Receives BC order via Zapier, creates OMINS invoice via JSON-RPC, then attaches line items via direct cURL

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors','1');

// 0) Security: verify secret token
$secret = getenv('WEBHOOK_SECRET');
$token  = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;
if (!$secret || !$token || !hash_equals($secret, $token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error','message' => 'Forbidden']);
    exit;
}

// 1) Read raw payload
if (getenv('TEST_RAW_PAYLOAD')) {
    $raw = getenv('TEST_RAW_PAYLOAD');
} elseif (isset($_GET['raw'])) {
    $raw = $_GET['raw'];
} else {
    $raw = file_get_contents('php://input');
}

// 2) Decode JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid JSON']);
    exit;
}

// 3) Extract order object
$order = $data[0] ?? $data['data'][0] ?? $data['data'] ?? $data;

// 4) Parse shipping address (omitted for brevity)
// ... your existing parsing code ...

// 5) Setup RPC client
require_once 'jsonRPCClient.php';
require_once '00_creds.php'; // defines $sys_id, $username, $password, $api_url
$client = new jsonRPCClient($api_url, false);
$creds  = (object)['system_id'=>$sys_id,'username'=>$username,'password'=>$password];

// 6) Parse BC line items
$items = $order['line_items'] ?? json_decode(str_replace("'","\"",($order['products']??'[]')), true);

// 7) Lookup SKUs and build rows for RPC
$rows = [];
foreach ($items as $it) {
    $sku = trim($it['sku'] ?? '');
    $qty = (int)($it['quantity'] ?? 0);
    $price = (float)(\$it['price_inc_tax'] ?? \$it['price_ex_tax'] ?? 0);
    if ($sku && $qty>0) {
        try {
            $meta = $client->getProductbyName($creds,['name'=>$sku]);
            if (!empty($meta['id'])) {
                $rows[] = ['partnumber'=>$sku,'qty'=>$qty,'price'=>$price];
            }
        } catch (Exception $e) {
            // skip unmatched
        }
    }
}

// 8) Map shipping/customer fields (omitted)
$name    = trim(($order['shipping_addresses_first_name'] ?? '') . ' ' . ($order['shipping_addresses_last_name'] ?? ''));
$company = $order['shipping_addresses_company'] ?? '';
$street  = $order['shipping_addresses_street_1'] ?? '';
$city    = $order['shipping_addresses_city'] ?? '';
$zip     = $order['shipping_addresses_zip'] ?? '';
$state   = $order['shipping_addresses_state'] ?? '';
$country = $order['shipping_addresses_country'] ?? '';
$phone   = $order['shipping_addresses_phone'] ?? '';
$email   = $order['shipping_addresses_email'] ?? '';
error_log("📦 Shipping to: $name, $street, $city $zip, $country, $email");

// 9) Create invoice header via RPC
$params = [
    'promo_group_id'=>9,
    'orderdate'=>date('Y-m-d',strtotime($order['date_created']??'now')),
    'statusdate'=>date('Y-m-d'),
    'name'=>$name,
    'company'=>$company,
    'address'=>$street, 'city'=>$city, 'postcode'=>$zip,
    'state'=>$state,'country'=>$country,
    'phone'=>$phone,'mobile'=>$phone,'email'=>$email,
    'note'=>'BC Order #'.($order['id']??''),
];
$inv = $client->createOrder($creds,$params);
$invoiceId = is_array($inv)&&isset($inv['id'])?$inv['id']:$inv;

// 10) Attach line items via direct cURL
$cookie = getenv('OMINS_SESSION_COOKIE'); // e.g. 'PHPSESSID=...; omins_db=omins_12271'
$url    = "https://omins.snipesoft.net.nz/modules/omins/invoices_addedit.php?tableid=1041&id={$invoiceId}";

// Build POST data array
$post = [
    'is_pos'=>0, 'tableid'=>1041,
    'recordid'=>$invoiceId,'id'=>$invoiceId,
    'command'=>'save','omins_submit_system_id'=>$sys_id,
    'creation_type'=>'manual',
    'orderdate'=>date('d/m/Y'),
    'duedate'=>date('d/m/Y',strtotime('+1 day')),
    'statusdate'=>date('d/m/Y'),
    'promo_group_id'=>1,'type'=>'order','statuschanged'=>0,
    'lineitemschanged'=>1
];
// Inject each row as UI expects
foreach ($rows as $i=>$r) {
    $idx = $i+1;
    $post["upc_{$idx}"]           = $r['partnumber'];
    $post["partnumber_{$idx}"]    = $r['partnumber'];
    $post["ds-partnumber_{$idx}"] = $r['partnumber'];
    $post["qty_{$idx}"]           = $r['qty'];
    $post["price_{$idx}"]         = sprintf('$%.4f',$r['price']);
    $post["linenumber"]           = $idx;
}

$rawPost = http_build_query($post);
$cmd = "curl -s -X POST '{$url}' -H 'Cookie: {$cookie}' \
    -H 'Content-Type: application/x-www-form-urlencoded' \
    --data-raw '{$rawPost}'";
shell_exec($cmd);

// 11) Respond success
echo json_encode(['status'=>'success','invoice_id'=>$invoiceId]);
exit;

// EOF
