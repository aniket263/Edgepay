<?php

require_once "../config.php";
require_once "../cashfree.php";

header("Content-Type: application/json");

// ------------------------------------
// 1. Get EdgePay transaction reference
// ------------------------------------

$transaction_ref = $_GET["transaction_ref"] ?? "";

if (empty($transaction_ref)) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Transaction reference is required"
    ]);

    exit;
}

// ------------------------------------
// 2. Find transaction
// ------------------------------------

$stmt = $conn->prepare(
    "SELECT id, transaction_ref, cashfree_order_id, amount, status
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

// ------------------------------------
// 3. Get Cashfree order ID
// ------------------------------------

$order_id = $transaction["cashfree_order_id"];

if (empty($order_id)) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Cashfree order has not been created yet"
    ]);

    exit;
}

// ------------------------------------
// 4. Call Cashfree Payments API
// ------------------------------------

$url = $cashfree_base_url . "/orders/"
     . urlencode($order_id)
     . "/payments";

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "x-api-version: " . $cashfree_api_version,
    "x-client-id: " . $cashfree_app_id,
    "x-client-secret: " . $cashfree_secret_key,
    "Accept: application/json"
]);

curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);

$curl_error = curl_error($ch);
$curl_errno = curl_errno($ch);

$http_code = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

// ------------------------------------
// 5. Handle cURL error
// ------------------------------------

if ($response === false) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "Cashfree request failed",
        "curl_errno" => $curl_errno,
        "curl_error" => $curl_error
    ]);

    exit;
}

// ------------------------------------
// 6. Decode Cashfree response
// ------------------------------------

$payments = json_decode(
    $response,
    true
);

if ($http_code < 200 || $http_code >= 300) {

    http_response_code($http_code);

    echo json_encode([
        "status" => "error",
        "message" => "Cashfree payment verification failed",
        "http_code" => $http_code,
        "cashfree_response" => $payments
    ]);

    exit;
}

// ------------------------------------
// 7. Determine payment status
// ------------------------------------

$payment_status = "NOT_ATTEMPTED";

if (is_array($payments) && count($payments) > 0) {

    // Look for a successful payment first
    foreach ($payments as $payment) {

        if (($payment["payment_status"] ?? "") === "SUCCESS") {

            $payment_status = "SUCCESS";

            break;
        }
    }

    // If no SUCCESS, use the latest payment status
    if ($payment_status !== "SUCCESS") {

        $latest_payment = $payments[count($payments) - 1];

        $payment_status =
            $latest_payment["payment_status"] ?? "NOT_ATTEMPTED";
    }
}

// ------------------------------------
// 8. Update EdgePay transaction
// ------------------------------------

if ($payment_status === "SUCCESS") {

    $stmt = $conn->prepare(
        "UPDATE transactions
         SET status = 'SUCCESS',
             paid_at = CURRENT_TIMESTAMP
         WHERE transaction_ref = ?
         AND status = 'PENDING'"
    );

    $stmt->bind_param(
        "s",
        $transaction_ref
    );

    $stmt->execute();

} elseif (
    $payment_status === "FAILED" ||
    $payment_status === "USER_DROPPED" ||
    $payment_status === "CANCELLED" ||
    $payment_status === "VOID"
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
}

// ------------------------------------
// 9. Return verification result
// ------------------------------------

echo json_encode([
    "status" => "success",
    "transaction_ref" => $transaction_ref,
    "cashfree_order_id" => $order_id,
    "payment_status" => $payment_status,
    "payments" => $payments
]);

?>