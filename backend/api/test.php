<?php

header("Content-Type: application/json");

$response = [
    "status" => "success",
    "message" => "EdgePay API is working"
];

echo json_encode($response);

?>