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
// GET TRANSACTION REFERENCE
// --------------------------------------------------

$transaction_ref = $_GET["transaction_ref"] ?? "";

if (empty($transaction_ref)) {
    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Transaction reference is required"
    ]);

    exit;
}


// --------------------------------------------------
// GET TRANSACTION
// --------------------------------------------------

$stmt = $conn->prepare(
    "SELECT
        transaction_ref,
        cashfree_order_id,
        amount,
        status,
        created_at,
        paid_at,
        dispensed_at
     FROM transactions
     WHERE transaction_ref = ?
     LIMIT 1"
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


// --------------------------------------------------
// ALREADY DISPENSED
// --------------------------------------------------

if (!empty($transaction["dispensed_at"])) {

    echo json_encode([
        "status" => "success",
        "transaction_ref" => $transaction_ref,
        "amount" => $transaction["amount"],
        "payment_status" => "SUCCESS",
        "dispense" => false,
        "message" => "Payment already dispensed"
    ]);

    exit;
}


// --------------------------------------------------
// 5-MINUTE EXPIRY CHECK
// --------------------------------------------------

$age_stmt = $conn->prepare(
    "SELECT TIMESTAMPDIFF(
        SECOND,
        created_at,
        NOW()
    ) AS age_seconds
    FROM transactions
    WHERE transaction_ref = ?"
);

$age_stmt->bind_param("s", $transaction_ref);
$age_stmt->execute();

$age_result = $age_stmt->get_result();
$age_data = $age_result->fetch_assoc();

$age_seconds = (int)($age_data["age_seconds"] ?? 0);

if (
    $transaction["status"] === "PENDING" &&
    $age_seconds >= 300
) {

    $expire_stmt = $conn->prepare(
        "UPDATE transactions
         SET status = 'EXPIRED'
         WHERE transaction_ref = ?
           AND status = 'PENDING'"
    );

    $expire_stmt->bind_param(
        "s",
        $transaction_ref
    );

    $expire_stmt->execute();

    echo json_encode([
        "status" => "success",
        "transaction_ref" => $transaction_ref,
        "amount" => $transaction["amount"],
        "payment_status" => "EXPIRED",
        "dispense" => false
    ]);

    exit;
}


// --------------------------------------------------
// ALREADY EXPIRED
// --------------------------------------------------

if ($transaction["status"] === "EXPIRED") {

    echo json_encode([
        "status" => "success",
        "transaction_ref" => $transaction_ref,
        "amount" => $transaction["amount"],
        "payment_status" => "EXPIRED",
        "dispense" => false
    ]);

    exit;
}


// --------------------------------------------------
// ALREADY FAILED
// --------------------------------------------------

if ($transaction["status"] === "FAILED") {

    echo json_encode([
        "status" => "success",
        "transaction_ref" => $transaction_ref,
        "amount" => $transaction["amount"],
        "payment_status" => "FAILED",
        "dispense" => false
    ]);

    exit;
}


// --------------------------------------------------
// CASHFREE PAYMENT STATUS
// --------------------------------------------------

$url =
    $cashfree_base_url .
    "/orders/" .
    urlencode($transaction["cashfree_order_id"]) .
    "/payments";


$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_HTTPHEADER => [
        "x-client-id: " . $cashfree_app_id,
        "x-client-secret: " . $cashfree_secret_key,
        "x-api-version: " . $cashfree_api_version,
        "Accept: application/json"
    ],

    CURLOPT_TIMEOUT => 15
]);

$response = curl_exec($ch);

$http_code = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

$curl_error = curl_error($ch);


// --------------------------------------------------
// CASHFREE CONNECTION ERROR
// --------------------------------------------------

if ($response === false) {

    http_response_code(502);

    echo json_encode([
        "status" => "error",
        "message" => "Cashfree request failed",
        "error" => $curl_error
    ]);

    exit;
}


// --------------------------------------------------
// CASHFREE RESPONSE
// --------------------------------------------------

$payments = json_decode(
    $response,
    true
);

if (
    $http_code < 200 ||
    $http_code >= 300
) {

    http_response_code(502);

    echo json_encode([
        "status" => "error",
        "message" => "Cashfree API error",
        "http_code" => $http_code
    ]);

    exit;
}


// --------------------------------------------------
// DETERMINE PAYMENT STATUS
// --------------------------------------------------

$payment_status = "NOT_ATTEMPTED";

if (
    is_array($payments) &&
    count($payments) > 0
) {

    $latest_payment = $payments[0];

    $payment_status =
        strtoupper(
            $latest_payment["payment_status"]
            ?? "NOT_ATTEMPTED"
        );
}


// --------------------------------------------------
// SUCCESS PAYMENT
// --------------------------------------------------

if ($payment_status === "SUCCESS") {

    $stmt = $conn->prepare(
        "UPDATE transactions
         SET
            status = 'SUCCESS',
            paid_at = COALESCE(
                paid_at,
                CURRENT_TIMESTAMP
            ),
            dispensed_at = CURRENT_TIMESTAMP
         WHERE transaction_ref = ?
           AND status = 'PENDING'
           AND dispensed_at IS NULL"
    );

    $stmt->bind_param(
        "s",
        $transaction_ref
    );

    $stmt->execute();

    $dispense =
        ($stmt->affected_rows === 1);


    echo json_encode([
        "status" => "success",
        "transaction_ref" => $transaction_ref,
        "amount" => $transaction["amount"],
        "payment_status" => "SUCCESS",
        "dispense" => $dispense
    ]);

    exit;
}


// --------------------------------------------------
// FAILED PAYMENT
// --------------------------------------------------

if (
    $payment_status === "FAILED" ||
    $payment_status === "USER_DROPPED"
) {

    $stmt = $conn->prepare(
        "UPDATE transactions
         SET status = 'FAILED'
         WHERE transaction_ref = ?
           AND status = 'PENDING'"
    );

    $stmt->bind_param(
        "s",
        $transaction_ref
    );

    $stmt->execute();

    echo json_encode([
        "status" => "success",
        "transaction_ref" => $transaction_ref,
        "amount" => $transaction["amount"],
        "payment_status" => "FAILED",
        "dispense" => false
    ]);

    exit;
}


// --------------------------------------------------
// PENDING / NOT ATTEMPTED
// --------------------------------------------------

echo json_encode([
    "status" => "success",
    "transaction_ref" => $transaction_ref,
    "amount" => $transaction["amount"],
    "payment_status" => $payment_status,
    "dispense" => false
]);

?>