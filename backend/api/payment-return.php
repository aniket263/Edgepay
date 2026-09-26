<?php

require_once "../config.php";
require_once "../cashfree.php";

$order_id =
    $_GET["order_id"] ?? "";

if (empty($order_id)) {

    http_response_code(400);

    echo "Order ID is required";

    exit;
}


// --------------------------------------------------
// Get payment information from Cashfree
// --------------------------------------------------

$url =
    $cashfree_base_url .
    "/orders/" .
    urlencode($order_id) .
    "/payments";


$ch = curl_init($url);

curl_setopt_array($ch, [

    CURLOPT_RETURNTRANSFER =>
        true,

    CURLOPT_HTTPHEADER => [

        "x-client-id: " .
            $cashfree_app_id,

        "x-client-secret: " .
            $cashfree_secret_key,

        "x-api-version: " .
            $cashfree_api_version,

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

    echo "Payment verification failed";

    exit;
}


// --------------------------------------------------
// Decode Cashfree response
// --------------------------------------------------

$payments =
    json_decode(
        $response,
        true
    );


// --------------------------------------------------
// Determine payment status
// --------------------------------------------------

$payment_status =
    "NOT_ATTEMPTED";


if (
    is_array($payments) &&
    count($payments) > 0
) {

    $latest_payment =
        $payments[0];

    $payment_status =
        strtoupper(
            $latest_payment[
                "payment_status"
            ] ?? "NOT_ATTEMPTED"
        );
}


// --------------------------------------------------
// Update local transaction
// --------------------------------------------------

if ($payment_status === "SUCCESS") {

    $stmt = $conn->prepare(

        "UPDATE transactions
         SET
            status = 'SUCCESS',
            paid_at = COALESCE(
                paid_at,
                CURRENT_TIMESTAMP
            )
         WHERE transaction_ref = ?
           AND status = 'PENDING'"

    );

    $stmt->bind_param(
        "s",
        $order_id
    );

    $stmt->execute();
}


elseif (
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
        $order_id
    );

    $stmt->execute();
}


// --------------------------------------------------
// Public EdgePay URL
// --------------------------------------------------

$public_url = rtrim(

    env_value(
        "EDGEPAY_PUBLIC_URL",
        "http://localhost:8000"
    ),

    "/"
);


// --------------------------------------------------
// Redirect to result page
// --------------------------------------------------

header(

    "Location: " .
    $public_url .
    "/api/payment-result.php?transaction_ref=" .
    urlencode($order_id)

);

exit;

?>