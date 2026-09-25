<?php

require_once "../config.php";
require_once "../cashfree.php";
require_once __DIR__ . "/../../vendor/autoload.php";

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;


/*
|--------------------------------------------------------------------------
| DEVICE AUTHENTICATION
|--------------------------------------------------------------------------
*/

$device_key = $_SERVER["HTTP_X_DEVICE_KEY"] ?? "";

if (!hash_equals($edgepay_device_key, $device_key)) {

    http_response_code(401);

    header("Content-Type: application/json");

    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized device"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| GET TRANSACTION
|--------------------------------------------------------------------------
*/

$transaction_ref = $_GET["transaction_ref"] ?? "";

if (empty($transaction_ref)) {

    http_response_code(400);

    header("Content-Type: application/json");

    echo json_encode([
        "status" => "error",
        "message" => "Transaction reference is required"
    ]);

    exit;
}


$stmt = $conn->prepare(
    "SELECT transaction_ref,
            amount,
            status,
            payment_session_id
     FROM transactions
     WHERE transaction_ref = ?"
);

$stmt->bind_param("s", $transaction_ref);
$stmt->execute();

$result = $stmt->get_result();
$transaction = $result->fetch_assoc();


if (!$transaction) {

    http_response_code(404);

    header("Content-Type: application/json");

    echo json_encode([
        "status" => "error",
        "message" => "Transaction not found"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| PAYMENT SESSION CHECK
|--------------------------------------------------------------------------
*/

if (empty($transaction["payment_session_id"])) {

    http_response_code(400);

    header("Content-Type: application/json");

    echo json_encode([
        "status" => "error",
        "message" => "Cashfree payment session not available"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| ONLY PENDING TRANSACTIONS
|--------------------------------------------------------------------------
*/

if ($transaction["status"] !== "PENDING") {

    http_response_code(400);

    header("Content-Type: application/json");

    echo json_encode([
        "status" => "error",
        "message" => "Transaction is not pending",
        "current_status" => $transaction["status"]
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| CHECKOUT URL
|--------------------------------------------------------------------------
|
| This URL opens EdgePay checkout.
| Cashfree Checkout then provides the Dynamic QR.
|
*/

$checkout_url =
    $edgepay_base_url .
    "/api/checkout.php?transaction_ref=" .
    urlencode($transaction_ref);


/*
|--------------------------------------------------------------------------
| GENERATE QR
|--------------------------------------------------------------------------
*/

$builder = new Builder(
    writer: new PngWriter(),
    writerOptions: [],
    validateResult: false,
    data: $checkout_url,
    encoding: new Encoding("UTF-8"),
    errorCorrectionLevel: ErrorCorrectionLevel::High,
    size: 300,
    margin: 10,
    roundBlockSizeMode: RoundBlockSizeMode::Margin
);

$qr = $builder->build();


header("Content-Type: image/png");

echo $qr->getString();

?>