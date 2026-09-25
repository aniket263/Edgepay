<?php

require_once "../config.php";
require_once "../cashfree.php";


/*
|--------------------------------------------------------------------------
| GET TRANSACTION
|--------------------------------------------------------------------------
*/

$transaction_ref = $_GET["transaction_ref"] ?? "";

if (empty($transaction_ref)) {
    die("Transaction reference is required");
}


$stmt = $conn->prepare(
    "SELECT
        transaction_ref,
        amount,
        status,
        cashfree_order_id
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


if (!$transaction) {
    die("Transaction not found");
}


if ($transaction["status"] !== "PENDING") {
    die(
        "Transaction is not pending. Current status: "
        . htmlspecialchars($transaction["status"])
    );
}


$order_id =
    $transaction["cashfree_order_id"];

$amount =
    $transaction["amount"];


if (empty($order_id)) {
    die("Cashfree order ID not found");
}


/*
|--------------------------------------------------------------------------
| GET CURRENT ORDER DETAILS FROM CASHFREE
|--------------------------------------------------------------------------
*/

$url =
    $cashfree_base_url .
    "/orders/" .
    urlencode($order_id);


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
        "Cashfree request failed: "
        . htmlspecialchars($curl_error)
    );
}


$cashfree_order =
    json_decode(
        $response,
        true
    );


if (
    $http_code < 200 ||
    $http_code >= 300
) {

    die(
        "Cashfree order request failed. HTTP code: "
        . htmlspecialchars($http_code)
    );
}


/*
|--------------------------------------------------------------------------
| GET CURRENT PAYMENT SESSION
|--------------------------------------------------------------------------
*/

$payment_session_id =
    $cashfree_order["payment_session_id"]
    ?? "";


if (empty($payment_session_id)) {

    die(
        "Cashfree did not return a payment session ID."
    );
}


/*
|--------------------------------------------------------------------------
| UPDATE DATABASE WITH CURRENT SESSION
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "UPDATE transactions
     SET payment_session_id = ?
     WHERE transaction_ref = ?"
);

$stmt->bind_param(
    "ss",
    $payment_session_id,
    $transaction_ref
);

$stmt->execute();

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>EdgePay Checkout</title>

    <script
        src="https://sdk.cashfree.com/js/v3/cashfree.js">
    </script>

</head>


<body>

    <h2>EdgePay Payment</h2>

    <p>
        Amount:
        ₹<?php echo htmlspecialchars($amount); ?>
    </p>

    <p>
        Transaction:
        <?php echo htmlspecialchars($transaction_ref); ?>
    </p>


    <button
        type="button"
        id="payButton"
    >
        Proceed to Pay
    </button>


    <script>

        const cashfree =
            Cashfree({
                mode: "sandbox"
            });


        const paymentSessionId =
            <?php
            echo json_encode($payment_session_id);
            ?>;


        document
            .getElementById("payButton")
            .addEventListener(
                "click",
                function () {

                    cashfree.checkout({

                        paymentSessionId:
                            paymentSessionId

                    });

                }
            );

    </script>


</body>

</html>