<?php

require_once "../config.php";
require_once "../cashfree.php";

header("Content-Type: application/json");

$transaction_ref = $_GET["transaction_ref"] ?? "";

if (empty($transaction_ref)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Transaction reference is required"
    ]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT id, transaction_ref, amount, status
     FROM transactions
     WHERE transaction_ref = ?"
);

$stmt->bind_param("s", $transaction_ref);
$stmt->execute();

$result = $stmt->get_result();
$transaction = $result->fetch_assoc();

if (!$transaction) {
    http_response_code(404);
    echo json_encode([
        "status" => "error",
        "message" => "Transaction not found"
    ]);
    exit;
}

if ($transaction["status"] !== "PENDING") {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Transaction is not pending",
        "current_status" => $transaction["status"]
    ]);
    exit;
}

$order_id = $transaction["transaction_ref"];
$amount = (float) $transaction["amount"];

$data = [
    "order_amount" => $amount,
    "order_currency" => "INR",

    "order_id" => $order_id,

    "customer_details" => [
        "customer_id" => "edgepay_user",
        "customer_phone" => "9876543210"
    ],

    "order_meta" => [
        "return_url" =>
            "http://localhost:8000/api/payment-return.php?order_id={order_id}"
    ]
];

$json_data = json_encode($data);

$url = $cashfree_base_url . "/orders";

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_POST, true);

curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "x-client-id: " . $cashfree_app_id,
    "x-client-secret: " . $cashfree_secret_key,
    "x-api-version: " . $cashfree_api_version,
    "Content-Type: application/json",
    "Accept: application/json"
]);

curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);

$curl_error = curl_error($ch);
$curl_errno = curl_errno($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "cURL request failed",
        "curl_errno" => $curl_errno,
        "curl_error" => $curl_error,
        "url" => $url
    ]);

    exit;
}

$response_data = json_decode($response, true);

if ($http_code < 200 || $http_code >= 300) {

    http_response_code($http_code);

    echo json_encode([
        "status" => "error",
        "message" => "Cashfree order creation failed",
        "http_code" => $http_code,
        "cashfree_response" => $response_data,
        "raw_response" => $response
    ]);

    exit;
}


/* -----------------------------------------
   GET IMPORTANT CASHFREE VALUES
----------------------------------------- */

$cashfree_order_id =
    $response_data["order_id"] ?? $order_id;

$cashfree_cf_order_id =
    $response_data["cf_order_id"] ?? null;

$payment_session_id =
    $response_data["payment_session_id"] ?? null;


/* -----------------------------------------
   SAVE CASHFREE DATA IN DATABASE
----------------------------------------- */

$stmt = $conn->prepare(
    "UPDATE transactions
     SET cashfree_order_id = ?,
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

$stmt->execute();


/* -----------------------------------------
   RESPONSE
----------------------------------------- */

echo json_encode([
    "status" => "success",
    "message" => "Cashfree order created",

    "transaction_ref" => $transaction_ref,

    "cashfree_order_id" =>
        $cashfree_order_id,

    "cashfree_cf_order_id" =>
        $cashfree_cf_order_id,

    "payment_session_id" =>
        $payment_session_id,

    "http_code" =>
        $http_code,

    "cashfree_response" =>
        $response_data
]);

?>