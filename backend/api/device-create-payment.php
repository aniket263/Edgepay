<?php

require_once "../config.php";
require_once "../cashfree.php";

header("Content-Type: application/json");

// --------------------------------------------------
// DEVICE AUTHENTICATION
// --------------------------------------------------

$device_key = $_SERVER["HTTP_X_DEVICE_KEY"] ?? "";

if (
    empty($device_key) ||
    !hash_equals($edgepay_device_key, $device_key)
) {
    http_response_code(401);

    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized device"
    ]);

    exit;
}


// --------------------------------------------------
// GET AMOUNT
// --------------------------------------------------

$amount = $_POST["amount"] ?? "";

if (!is_numeric($amount) || (float)$amount <= 0) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Invalid amount"
    ]);

    exit;
}

$amount = number_format(
    (float)$amount,
    2,
    ".",
    ""
);


// --------------------------------------------------
// MERCHANT
// --------------------------------------------------

$merchant_id = 1;

$stmt = $conn->prepare(
    "SELECT id
     FROM merchants
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "i",
    $merchant_id
);

$stmt->execute();

$result = $stmt->get_result();

$merchant = $result->fetch_assoc();

if (!$merchant) {

    http_response_code(404);

    echo json_encode([
        "status" => "error",
        "message" => "Merchant not found"
    ]);

    exit;
}


// --------------------------------------------------
// CREATE TRANSACTION REFERENCE
// --------------------------------------------------

$transaction_ref =
    "TXN_" .
    date("YmdHis") .
    "_" .
    strtoupper(
        bin2hex(random_bytes(4))
    );

$order_id = $transaction_ref;


// --------------------------------------------------
// INSERT LOCAL TRANSACTION
// --------------------------------------------------

$stmt = $conn->prepare(
    "INSERT INTO transactions
    (
        transaction_ref,
        merchant_id,
        amount,
        status
    )
    VALUES (?, ?, ?, 'PENDING')"
);

$stmt->bind_param(
    "sid",
    $transaction_ref,
    $merchant_id,
    $amount
);

$stmt->execute();


// --------------------------------------------------
// PUBLIC RETURN URL
// --------------------------------------------------

$public_url = rtrim(
    env_value(
        "EDGEPAY_PUBLIC_URL",
        "http://localhost:8000"
    ),
    "/"
);

$return_url =
    $public_url .
    "/api/payment-return.php?order_id={order_id}";


// --------------------------------------------------
// CASHFREE ORDER
// --------------------------------------------------

$url =
    $cashfree_base_url .
    "/orders";


$data = [

    "order_amount" =>
        (float)$amount,

    "order_currency" =>
        "INR",

    "order_id" =>
        $order_id,

    "customer_details" => [

        "customer_id" =>
            "edgepay_user",

        "customer_phone" =>
            "9876543210"
    ],

    "order_meta" => [

        "return_url" =>
            $return_url
    ]
];


$ch = curl_init($url);

curl_setopt_array($ch, [

    CURLOPT_RETURNTRANSFER =>
        true,

    CURLOPT_POST =>
        true,

    CURLOPT_POSTFIELDS =>
        json_encode($data),

    CURLOPT_HTTPHEADER => [

        "x-client-id: " .
            $cashfree_app_id,

        "x-client-secret: " .
            $cashfree_secret_key,

        "x-api-version: " .
            $cashfree_api_version,

        "Content-Type: application/json",

        "Accept: application/json"
    ],

    CURLOPT_TIMEOUT =>
        15
]);


$response =
    curl_exec($ch);

$http_code =
    curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

$curl_error =
    curl_error($ch);


// --------------------------------------------------
// CURL ERROR
// --------------------------------------------------

if ($response === false) {

    http_response_code(502);

    echo json_encode([

        "status" =>
            "error",

        "message" =>
            "Cashfree request failed",

        "error" =>
            $curl_error
    ]);

    exit;
}


// --------------------------------------------------
// CASHFREE RESPONSE
// --------------------------------------------------

$cashfree_response =
    json_decode(
        $response,
        true
    );


if (
    $http_code < 200 ||
    $http_code >= 300
) {

    http_response_code(
        $http_code
    );

    echo json_encode([

        "status" =>
            "error",

        "message" =>
            "Cashfree order creation failed",

        "cashfree_response" =>
            $cashfree_response
    ]);

    exit;
}


// --------------------------------------------------
// PAYMENT SESSION
// --------------------------------------------------

$payment_session_id =
    $cashfree_response[
        "payment_session_id"
    ] ?? null;

$cf_order_id =
    $cashfree_response[
        "cf_order_id"
    ] ?? null;


if (empty($payment_session_id)) {

    http_response_code(500);

    echo json_encode([

        "status" =>
            "error",

        "message" =>
            "Payment session ID not received"
    ]);

    exit;
}


// --------------------------------------------------
// SAVE CASHFREE DETAILS
// --------------------------------------------------

$stmt = $conn->prepare(

    "UPDATE transactions
     SET
        cashfree_order_id = ?,
        cashfree_cf_order_id = ?,
        payment_session_id = ?
     WHERE transaction_ref = ?"

);

$stmt->bind_param(

    "ssss",

    $order_id,

    $cf_order_id,

    $payment_session_id,

    $transaction_ref
);

$stmt->execute();


// --------------------------------------------------
// RESPONSE
// --------------------------------------------------

echo json_encode([

    "status" =>
        "success",

    "message" =>
        "EdgePay payment created",

    "transaction_ref" =>
        $transaction_ref,

    "amount" =>
        $amount,

    "cashfree_order_id" =>
        $order_id,

    "cashfree_cf_order_id" =>
        $cf_order_id,

    "payment_session_id" =>
        $payment_session_id
]);

?>