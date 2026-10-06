<?php
require_once __DIR__ . "/config.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$transaction_ref = "";
$amount = "";
$error = "";
$payment_created = false;
$qr_data = "";
$payment_status = "";

function call_edgepay_api($url, $device_key, $method = "GET", $body = null)
{
    $ch = curl_init($url);

    $headers = [
        "Accept: application/json",
        "X-Device-Key: " . $device_key
    ];

    if ($method === "POST") {
        $headers[] = "Content-Type: application/x-www-form-urlencoded";
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body ?? []));
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    if ($response === false) {
        return [
            "ok" => false,
            "http_code" => $http_code,
            "error" => $curl_error
        ];
    }

    $json = json_decode($response, true);

    return [
        "ok" => $http_code >= 200 && $http_code < 300,
        "http_code" => $http_code,
        "data" => $json,
        "raw" => $response
    ];
}

$device_key = env_value("EDGEPAY_DEVICE_KEY");
$public_url = rtrim(
    env_value("EDGEPAY_PUBLIC_URL", "http://localhost:8000"),
    "/"
);
$api_base_url = "http://127.0.0.1:8000";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $amount = trim($_POST["amount"] ?? "10");

    if (!is_numeric($amount) || (float)$amount <= 0) {
        $error = "Enter a valid positive amount.";
    } else {
        $create_url = $api_base_url . "/api/device-create-payment.php";

        $result = call_edgepay_api(
            $create_url,
            $device_key,
            "POST",
            ["amount" => $amount]
        );

        if (!$result["ok"] || empty($result["data"]["transaction_ref"])) {
            $error = $result["data"]["message"]
                ?? $result["error"]
                ?? "Unable to create payment.";
        } else {
            $payment_created = true;
            $transaction_ref = $result["data"]["transaction_ref"];
            $amount = $result["data"]["amount"] ?? $amount;

            $qr_url = $api_base_url .
                "/api/device-qr.php?transaction_ref=" .
                urlencode($transaction_ref);

            $qr_result = call_edgepay_api(
                $qr_url,
                $device_key,
                "GET"
            );

            if (
                $qr_result["ok"] &&
                !empty($qr_result["raw"])
            ) {
                $qr_data = "data:image/png;base64," .
                    base64_encode($qr_result["raw"]);
            } else {
                $error = "Payment created, but QR generation failed.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EdgePay Demo</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #17202a;
        }

        .card {
            width: min(92%, 520px);
            background: white;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 10px 35px rgba(0,0,0,.10);
            text-align: center;
        }

        h1 {
            margin: 0 0 6px;
            font-size: 30px;
        }

        .subtitle {
            margin: 0 0 24px;
            color: #667085;
        }

        label {
            display: block;
            text-align: left;
            margin-bottom: 8px;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #ccd3da;
            border-radius: 10px;
            font-size: 17px;
            margin-bottom: 14px;
        }

        button {
            width: 100%;
            padding: 14px;
            border: 0;
            border-radius: 10px;
            background: #111827;
            color: white;
            font-size: 17px;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #263244;
        }

        .qr {
            width: 320px;
            max-width: 100%;
            margin: 18px auto;
            display: block;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
        }

        .success {
            color: #087443;
            font-weight: 700;
            margin-top: 16px;
        }

        .error {
            color: #b42318;
            background: #fef3f2;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .ref {
            font-family: monospace;
            font-size: 13px;
            word-break: break-all;
            color: #667085;
        }

        .new-payment {
            margin-top: 14px;
            background: #eef2f6;
            color: #17202a;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>EdgePay</h1>
    <p class="subtitle">Payment Demo Terminal</p>

    <?php if ($error): ?>
        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($payment_created && $qr_data): ?>
        <div class="success">Payment created successfully</div>

        <p>
            Amount:
            <strong>₹<?= htmlspecialchars($amount) ?></strong>
        </p>

        <img
            class="qr"
            src="<?= htmlspecialchars($qr_data) ?>"
            alt="EdgePay payment QR"
        >

        <p>Scan this QR with your UPI app.</p>

        <p class="ref">
            <?= htmlspecialchars($transaction_ref) ?>
        </p>

        <form method="get">
            <button class="new-payment" type="submit">
                Create New Payment
            </button>
        </form>
    <?php else: ?>
        <form method="post">
            <label for="amount">Amount (₹)</label>
            <input
                id="amount"
                name="amount"
                type="number"
                min="1"
                step="0.01"
                value="10"
                required
            >

            <button type="submit">
                Create Payment
            </button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
