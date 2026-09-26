<?php

require_once "../config.php";
require_once "../cashfree.php";

header("Content-Type: application/json");


/*
|--------------------------------------------------------------------------
| DEVICE AUTHENTICATION
|--------------------------------------------------------------------------
*/

$device_key = $_SERVER["HTTP_X_DEVICE_KEY"] ?? "";

if (!hash_equals($edgepay_device_key, $device_key)) {

    http_response_code(401);

    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized device"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| GET AMOUNT FROM ESP32
|--------------------------------------------------------------------------
*/

$amount = $_POST["amount"] ?? "";

if (
    empty($amount) ||
    !is_numeric($amount) ||
    $amount <= 0
) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Valid amount is required"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| FORMAT AMOUNT
|--------------------------------------------------------------------------
*/

$amount = number_format(
    (float)$amount,
    2,
    ".",
    ""
);


/*
|--------------------------------------------------------------------------
| MERCHANT
|--------------------------------------------------------------------------
*/

$merchant_id = 1;


/*
|--------------------------------------------------------------------------
| CREATE EDGEPAY TRANSACTION
|--------------------------------------------------------------------------
*/

$transaction_ref =
    "TXN_" .
    date("YmdHis") .
    "_" .
    strtoupper(
        bin2hex(
            random_bytes(4)
        )
    );


$upi_payload = "";


$stmt = $conn->prepare(
    "INSERT INTO transactions
     (
        transaction_ref,
        merchant_id,
        amount,
        status,
        upi_payload
     )
     VALUES
     (
        ?,
        ?,
        ?,
        'PENDING',
        ?
     )"
);


$stmt->bind_param(
    "sids",
    $transaction_ref,
    $merchant_id,
    $amount,
    $upi_payload
);


if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Failed to create transaction"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| CREATE CASHFREE ORDER
|--------------------------------------------------------------------------
*/

$order_id = $transaction_ref;


$data = [

    "order_amount" =>
        (float)$amount,

    "order_currency" =>
        "INR",

    "order_id" =>
        $order_id,

    "customer_details" => [

        "customer_id" =>
            "edgepay_device",

        "customer_phone" =>
            "9876543210"

    ],

    "order_meta" => [

        "return_url" =>
            "http://localhost:8000/api/payment-return.php?order_id={order_id}"

    ]

];


$json_data = json_encode($data);


/*
|--------------------------------------------------------------------------
| CASHFREE API REQUEST
|--------------------------------------------------------------------------
*/

$url =
    $cashfree_base_url .
    "/orders";


$ch = curl_init($url);


curl_setopt(
    $ch,
    CURLOPT_POST,
    true
);


curl_setopt(
    $ch,
    CURLOPT_HTTPHEADER,
    [

        "x-client-id: " .
            $cashfree_app_id,

        "x-client-secret: " .
            $cashfree_secret_key,

        "x-api-version: " .
            $cashfree_api_version,

        "Content-Type: application/json",

        "Accept: application/json"

    ]
);


curl_setopt(
    $ch,
    CURLOPT_POSTFIELDS,
    $json_data
);


curl_setopt(
    $ch,
    CURLOPT_RETURNTRANSFER,
    true
);


curl_setopt(
    $ch,
    CURLOPT_CONNECTTIMEOUT,
    10
);


curl_setopt(
    $ch,
    CURLOPT_TIMEOUT,
    30
);


/*
|--------------------------------------------------------------------------
| EXECUTE REQUEST
|--------------------------------------------------------------------------
*/

$response =
    curl_exec($ch);


$curl_error =
    curl_error($ch);


$curl_errno =
    curl_errno($ch);


$http_code =
    curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );





/*
|--------------------------------------------------------------------------
| CURL ERROR
|--------------------------------------------------------------------------
*/

if ($response === false) {

    http_response_code(500);

    echo json_encode([

        "status" =>
            "error",

        "message" =>
            "Cashfree request failed",

        "curl_errno" =>
            $curl_errno,

        "curl_error" =>
            $curl_error

    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| DECODE CASHFREE RESPONSE
|--------------------------------------------------------------------------
*/

$response_data =
    json_decode(
        $response,
        true
    );


/*
|--------------------------------------------------------------------------
| CASHFREE ERROR
|--------------------------------------------------------------------------
*/

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

        "http_code" =>
            $http_code,

        "cashfree_response" =>
            $response_data

    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| GET CASHFREE ORDER DETAILS
|--------------------------------------------------------------------------
*/

$cashfree_order_id =
    $response_data["order_id"]
    ?? $order_id;


$cashfree_cf_order_id =
    $response_data["cf_order_id"]
    ?? null;


$payment_session_id =
    $response_data["payment_session_id"]
    ?? null;


/*
|--------------------------------------------------------------------------
| VERIFY PAYMENT SESSION
|--------------------------------------------------------------------------
*/

if (empty($payment_session_id)) {

    http_response_code(500);

    echo json_encode([

        "status" =>
            "error",

        "message" =>
            "Cashfree did not return a payment session ID",

        "cashfree_response" =>
            $response_data

    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| SAVE CASHFREE DETAILS
|--------------------------------------------------------------------------
*/

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

    $cashfree_order_id,

    $cashfree_cf_order_id,

    $payment_session_id,

    $transaction_ref

);


if (!$stmt->execute()) {

    http_response_code(500);

    echo json_encode([

        "status" =>
            "error",

        "message" =>
            "Failed to save Cashfree order details"

    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| SUCCESS RESPONSE TO ESP32
|--------------------------------------------------------------------------
*/

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
        $cashfree_order_id,

    "cashfree_cf_order_id" =>
        $cashfree_cf_order_id,

    "payment_session_id" =>
        $payment_session_id

]);

?>