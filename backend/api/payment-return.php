<?php

require_once "../config.php";
require_once "../cashfree.php";

$order_id = $_GET["order_id"] ?? "";

if (empty($order_id)) {
    die("Order ID is missing");
}


/*
|--------------------------------------------------------------------------
| VERIFY PAYMENT WITH CASHFREE
|--------------------------------------------------------------------------
*/

$url =
    $cashfree_base_url .
    "/orders/" .
    urlencode($order_id) .
    "/payments";


$ch = curl_init($url);


curl_setopt(
    $ch,
    CURLOPT_RETURNTRANSFER,
    true
);


curl_setopt(
    $ch,
    CURLOPT_HTTPHEADER,
    [
        "x-api-version: " . $cashfree_api_version,
        "x-client-id: " . $cashfree_app_id,
        "x-client-secret: " . $cashfree_secret_key,
        "Accept: application/json"
    ]
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


$response =
    curl_exec($ch);


$curl_error =
    curl_error($ch);


$http_code =
    curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );





if ($response === false) {

    die(
        "Cashfree verification failed: " .
        htmlspecialchars($curl_error)
    );
}


$payments =
    json_decode(
        $response,
        true
    );


if (
    $http_code < 200 ||
    $http_code >= 300
) {

    die(
        "Cashfree verification failed. HTTP: " .
        htmlspecialchars($http_code)
    );
}


/*
|--------------------------------------------------------------------------
| DETERMINE PAYMENT STATUS
|--------------------------------------------------------------------------
*/

$payment_status =
    "NOT_ATTEMPTED";


if (
    is_array($payments) &&
    count($payments) > 0
) {

    foreach ($payments as $payment) {

        if (
            ($payment["payment_status"] ?? "")
            === "SUCCESS"
        ) {

            $payment_status =
                "SUCCESS";

            break;
        }
    }


    if (
        $payment_status !==
        "SUCCESS"
    ) {

        $latest_payment =
            $payments[
                count($payments) - 1
            ];


        $payment_status =
            $latest_payment[
                "payment_status"
            ]
            ?? "NOT_ATTEMPTED";
    }
}


/*
|--------------------------------------------------------------------------
| UPDATE EDGEPAY DATABASE
|--------------------------------------------------------------------------
*/

if (
    $payment_status ===
    "SUCCESS"
) {

    $stmt = $conn->prepare(
        "UPDATE transactions
         SET
            status = 'SUCCESS',
            paid_at = CURRENT_TIMESTAMP
         WHERE cashfree_order_id = ?
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
    $payment_status === "USER_DROPPED" ||
    $payment_status === "CANCELLED" ||
    $payment_status === "VOID"
) {

    $stmt = $conn->prepare(
        "UPDATE transactions
         SET status = 'FAILED'
         WHERE cashfree_order_id = ?
         AND status = 'PENDING'"
    );


    $stmt->bind_param(
        "s",
        $order_id
    );


    $stmt->execute();
}


/*
|--------------------------------------------------------------------------
| SHOW RESULT PAGE
|--------------------------------------------------------------------------
*/

header(
    "Location: http://localhost:8000/api/payment-result.php?transaction_ref="
    . urlencode($order_id)
);

exit;

?>