<?php

require_once __DIR__ . "/config.php";


/*
|--------------------------------------------------------------------------
| CASHFREE CONFIGURATION
|--------------------------------------------------------------------------
*/

$cashfree_app_id =
    env_value("CASHFREE_APP_ID");

$cashfree_secret_key =
    env_value("CASHFREE_SECRET_KEY");

$cashfree_base_url =
    env_value(
        "CASHFREE_BASE_URL",
        "https://sandbox.cashfree.com/pg"
    );

$cashfree_api_version =
    env_value(
        "CASHFREE_API_VERSION",
        "2025-01-01"
    );


/*
|--------------------------------------------------------------------------
| EDGEPAY DEVICE CONFIGURATION
|--------------------------------------------------------------------------
*/

$edgepay_device_key =
    env_value(
        "EDGEPAY_DEVICE_KEY"
    );

?>