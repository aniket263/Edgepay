<?php

require_once "../config.php";
require_once "../cashfree.php";

header("Content-Type: application/json");

// Read raw webhook body
$raw_body = file_get_contents("php://input");

// Read Cashfree webhook headers
$signature = $_SERVER["HTTP_X_WEBHOOK_SIGNATURE"] ?? "";
$timestamp = $_SERVER["HTTP_X_WEBHOOK_TIMESTAMP"] ?? "";

if (empty($signature) || empty($timestamp)) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Missing webhook signature or timestamp"
    ]);

    exit;
}

// Generate expected signature
$signature_data = $timestamp . $raw_body;

$expected_signature = base64_encode(
    hash_hmac(
        "sha256",
        $signature_data,
        $cashfree_secret_key,
        true
    )
);

// Verify signature
if (!hash_equals($expected_signature, $signature)) {

    http_response_code(401);

    echo json_encode([
        "status" => "error",
        "message" => "Invalid webhook signature"
    ]);

    exit;
}

// Decode payload
$data = json_decode($raw_body, true);

if (!$data) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Invalid JSON payload"
    ]);

    exit;
}

// Get event information
$event_type = $data["type"] ?? "";
$order_id = $data["data"]["order"]["order_id"] ?? "";

if (empty($order_id)) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Order ID missing"
    ]);

    exit;
}

// Find our local transaction
$stmt = $conn->prepare(
    "SELECT id, transaction_ref, status, amount
     FROM transactions
     WHERE transaction_ref = ?
        OR cashfree_order_id = ?
     LIMIT 1"
);

$stmt->bind_param(
    "ss",
    $order_id,
    $order_id
);

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

// Determine payment status
$payment_status =
    $data["data"]["payment"]["payment_status"]
    ?? "";


// Successful payment
if (
    $payment_status === "SUCCESS" ||
    str_contains(
        strtoupper($event_type),
        "SUCCESS"
    )
) {

    // Idempotent update:
    // only change PENDING transactions
    $stmt = $conn->prepare(
        "UPDATE transactions
         SET status = 'SUCCESS',
             paid_at = CURRENT_TIMESTAMP
         WHERE transaction_ref = ?
           AND status = 'PENDING'"
    );

    $stmt->bind_param(
        "s",
        $transaction["transaction_ref"]
    );

    $stmt->execute();

    echo json_encode([
        "status" => "success",
        "message" => "Payment marked successful",
        "transaction_ref" =>
            $transaction["transaction_ref"]
    ]);

    exit;
}


// Failed payment
if (
    $payment_status === "FAILED" ||
    str_contains(
        strtoupper($event_type),
        "FAILED"
    )
) {

    $stmt = $conn->prepare(
        "UPDATE transactions
         SET status = 'FAILED'
         WHERE transaction_ref = ?
           AND status = 'PENDING'"
    );

    $stmt->bind_param(
        "s",
        $transaction["transaction_ref"]
    );

    $stmt->execute();

    echo json_encode([
        "status" => "success",
        "message" => "Payment marked failed",
        "transaction_ref" =>
            $transaction["transaction_ref"]
    ]);

    exit;
}


// Other events such as pending
echo json_encode([
    "status" => "success",
    "message" => "Webhook received",
    "event_type" => $event_type,
    "transaction_ref" =>
        $transaction["transaction_ref"]
]);

?>