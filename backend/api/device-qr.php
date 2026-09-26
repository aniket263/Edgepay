<?php

require_once "../config.php";
require_once "../cashfree.php";
require_once "../../vendor/autoload.php";

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

$device_key = $_SERVER["HTTP_X_DEVICE_KEY"] ?? "";

if (
    empty($device_key) ||
    !hash_equals($edgepay_device_key, $device_key)
) {
    http_response_code(401);
    exit("Unauthorized device");
}

$transaction_ref = $_GET["transaction_ref"] ?? "";

if (empty($transaction_ref)) {
    http_response_code(400);
    exit("Transaction reference is required");
}

$stmt = $conn->prepare(
    "SELECT
        transaction_ref,
        payment_session_id,
        status
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
    exit("Transaction not found");
}

if ($transaction["status"] !== "PENDING") {
    http_response_code(400);
    exit("Transaction is not pending");
}

if (empty($transaction["payment_session_id"])) {
    http_response_code(400);
    exit("Payment session not available");
}


// Public EdgePay URL
$public_url = env_value(
    "EDGEPAY_PUBLIC_URL",
    "http://localhost:8000"
);

$checkout_url =
    rtrim($public_url, "/") .
    "/api/checkout.php?transaction_ref=" .
    urlencode($transaction_ref);


// Generate QR using Endroid QR Code v6
$builder = new Builder(
    writer: new PngWriter(),
    data: $checkout_url,
    size: 400,
    margin: 10
);

$qr_result = $builder->build();

header("Content-Type: " . $qr_result->getMimeType());

echo $qr_result->getString();

?>