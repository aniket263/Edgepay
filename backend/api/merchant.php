<?php

require_once "../config.php";

header("Content-Type: application/json");

$merchant_name = $_POST["merchant_name"];
$upi_id = $_POST["upi_id"];
$wifi_ssid = $_POST["wifi_ssid"];

$stmt = $conn->prepare(
    "INSERT INTO merchants (merchant_name, upi_id, wifi_ssid)
     VALUES (?, ?, ?)"
);

$stmt->bind_param("sss", $merchant_name, $upi_id, $wifi_ssid);

$stmt->execute();

echo json_encode([
    "status" => "success",
    "message" => "Merchant saved successfully"
]);

?>