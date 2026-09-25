<?php

require_once "../config.php";

$transaction_ref = $_GET["transaction_ref"] ?? "";

if (empty($transaction_ref)) {
    die("Transaction reference is missing");
}

$stmt = $conn->prepare(
    "SELECT transaction_ref, amount, status, paid_at
     FROM transactions
     WHERE transaction_ref = ?"
);

$stmt->bind_param("s", $transaction_ref);
$stmt->execute();

$result = $stmt->get_result();
$transaction = $result->fetch_assoc();

if (!$transaction) {
    die("Transaction not found");
}

$amount = $transaction["amount"];
$status = $transaction["status"];

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>EdgePay Payment Result</title>

    <style>

        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }

        .card {
            background: white;
            width: 90%;
            max-width: 450px;
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .logo {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 20px;
        }

        .success {
            color: #16a34a;
            font-size: 60px;
        }

        .failed {
            color: #dc2626;
            font-size: 60px;
        }

        .amount {
            font-size: 36px;
            font-weight: bold;
            margin: 20px 0 10px;
        }

        .status {
            font-size: 22px;
            margin-bottom: 25px;
        }

        .details {
            text-align: left;
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            font-size: 14px;
        }

        .details p {
            margin: 10px 0;
        }

    </style>

</head>

<body>

<div class="card">

    <div class="logo">
        EdgePay
    </div>

    <?php if ($status === "SUCCESS"): ?>

        <div class="success">
            ✓
        </div>

        <div class="amount">
            ₹<?php echo htmlspecialchars($amount); ?>
        </div>

        <div class="status">
            Payment Successful
        </div>

        <div class="details">

            <p>
                <strong>Transaction:</strong><br>
                <?php echo htmlspecialchars($transaction_ref); ?>
            </p>

            <p>
                <strong>Status:</strong>
                Payment Verified
            </p>

            <?php if (!empty($transaction["paid_at"])): ?>

                <p>
                    <strong>Paid At:</strong>
                    <?php echo htmlspecialchars($transaction["paid_at"]); ?>
                </p>

            <?php endif; ?>

        </div>

    <?php else: ?>

        <div class="failed">
            !
        </div>

        <div class="status">
            Payment <?php echo htmlspecialchars($status); ?>
        </div>

        <div class="details">

            <p>
                <strong>Transaction:</strong><br>
                <?php echo htmlspecialchars($transaction_ref); ?>
            </p>

        </div>

    <?php endif; ?>

</div>

</body>

</html>