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
| GET TRANSACTION REFERENCE
|--------------------------------------------------------------------------
*/

$transaction_ref = $_GET["transaction_ref"] ?? "";

if (empty($transaction_ref)) {

    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Transaction reference is required"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| GET TRANSACTION FROM DATABASE
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        id,
        transaction_ref,
        cashfree_order_id,
        amount,
        status,
        created_at,
        paid_at,
        dispensed_at
     FROM transactions
     WHERE transaction_ref = ?"
);

$stmt->bind_param(
    "s",
    $transaction_ref
);

$stmt->execute();

$result = $stmt->get_result();

$transaction = $result->fetch_assoc();


/*
|--------------------------------------------------------------------------
| TRANSACTION NOT FOUND
|--------------------------------------------------------------------------
*/

if (!$transaction) {

    http_response_code(404);

    echo json_encode([
        "status" => "error",
        "message" => "Transaction not found"
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| ALREADY DISPENSED
|--------------------------------------------------------------------------
*/

if (
    $transaction["status"] === "SUCCESS" &&
    !empty($transaction["dispensed_at"])
) {

    echo json_encode([

        "status" =>
            "success",

        "transaction_ref" =>
            $transaction_ref,

        "amount" =>
            $transaction["amount"],

        "payment_status" =>
            "SUCCESS",

        "dispense" =>
            false,

        "message" =>
            "Payment already dispensed"

    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| PAYMENT TIMEOUT
|--------------------------------------------------------------------------
|
| Transaction expires after 5 minutes.
|
*/

$created_at =
    strtotime(
        $transaction["created_at"]
    );


if ($created_at > 0) {

    $age =
        time() - $created_at;


    if (
        $age > 300 &&
        $transaction["status"] === "PENDING"
    ) {

        $stmt = $conn->prepare(
            "UPDATE transactions
             SET status = 'EXPIRED'
             WHERE transaction_ref = ?
             AND status = 'PENDING'"
        );

        $stmt->bind_param(
            "s",
            $transaction_ref
        );

        $stmt->execute();


        echo json_encode([

            "status" =>
                "success",

            "transaction_ref" =>
                $transaction_ref,

            "amount" =>
                $transaction["amount"],

            "payment_status" =>
                "EXPIRED",

            "dispense" =>
                false,

            "message" =>
                "Payment session expired"

        ]);

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| CASHFREE ORDER CHECK
|--------------------------------------------------------------------------
*/

$order_id =
    $transaction["cashfree_order_id"];


if (empty($order_id)) {

    http_response_code(400);

    echo json_encode([

        "status" =>
            "error",

        "message" =>
            "Cashfree order not available"

    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| ASK CASHFREE FOR PAYMENT STATUS
|--------------------------------------------------------------------------
*/

$url =
    $cashfree_base_url .
    "/orders/" .
    urlencode($order_id) .
    "/payments";


$ch =
    curl_init($url);


curl_setopt(
    $ch,
    CURLOPT_RETURNTRANSFER,
    true
);


curl_setopt(
    $ch,
    CURLOPT_HTTPHEADER,
    [

        "x-api-version: " .
            $cashfree_api_version,

        "x-client-id: " .
            $cashfree_app_id,

        "x-client-secret: " .
            $cashfree_secret_key,

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


/*
|--------------------------------------------------------------------------
| EXECUTE CASHFREE REQUEST
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


curl_close($ch);


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
| DECODE RESPONSE
|--------------------------------------------------------------------------
*/

$payments =
    json_decode(
        $response,
        true
    );


/*
|--------------------------------------------------------------------------
| CASHFREE API ERROR
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
            "Cashfree payment verification failed",

        "http_code" =>
            $http_code,

        "cashfree_response" =>
            $payments

    ]);

    exit;
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

    /*
    |--------------------------------------------------------------------------
    | Look for successful payment
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | If no SUCCESS, use latest payment status
    |--------------------------------------------------------------------------
    */

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
| SUCCESS PAYMENT
|--------------------------------------------------------------------------
*/

if (
    $payment_status ===
    "SUCCESS"
) {

    /*
    |--------------------------------------------------------------------------
    | Mark transaction as SUCCESS
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare(
        "UPDATE transactions
         SET
            status = 'SUCCESS',
            paid_at = CURRENT_TIMESTAMP
         WHERE transaction_ref = ?
         AND status = 'PENDING'"
    );


    $stmt->bind_param(
        "s",
        $transaction_ref
    );


    $stmt->execute();


    /*
    |--------------------------------------------------------------------------
    | ATOMIC DISPENSE LOCK
    |--------------------------------------------------------------------------
    |
    | Only the first successful request gets dispense=true.
    |
    */

    $stmt = $conn->prepare(
        "UPDATE transactions
         SET dispensed_at = CURRENT_TIMESTAMP
         WHERE transaction_ref = ?
         AND status = 'SUCCESS'
         AND dispensed_at IS NULL"
    );


    $stmt->bind_param(
        "s",
        $transaction_ref
    );


    $stmt->execute();


    $dispense =
        $stmt->affected_rows === 1;


    /*
    |--------------------------------------------------------------------------
    | RESPONSE TO ESP32
    |--------------------------------------------------------------------------
    */

    echo json_encode([

        "status" =>
            "success",

        "transaction_ref" =>
            $transaction_ref,

        "amount" =>
            $transaction["amount"],

        "payment_status" =>
            "SUCCESS",

        "dispense" =>
            $dispense,

        "message" =>
            $dispense
                ? "Payment verified. Dispense item."
                : "Payment already processed."

    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| FAILED PAYMENT
|--------------------------------------------------------------------------
*/

if (
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


/*
|--------------------------------------------------------------------------
| PENDING / NOT ATTEMPTED
|--------------------------------------------------------------------------
*/

echo json_encode([

    "status" =>
        "success",

    "transaction_ref" =>
        $transaction_ref,

    "amount" =>
        $transaction["amount"],

    "payment_status" =>
        $payment_status,

    "dispense" =>
        false

]);

?>