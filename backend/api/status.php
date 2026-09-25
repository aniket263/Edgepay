<?php

require_once "../config.php";

header("Content-Type: application/json");

$transaction_ref = $_GET["transaction_ref"] ?? "";

if (empty($transaction_ref)) {
    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Transaction reference is required"
    ]);

    exit;
}

$stmt = $conn->prepare(
    "SELECT transaction_ref, amount, status, created_at, paid_at
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

echo json_encode([
    "status" => "success",
    "transaction" => $transaction
]);

?>