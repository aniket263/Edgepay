<?php

function env_value($key, $default = null)
{
    static $env = null;

    if ($env === null) {

        $env = [];

        $env_file = __DIR__ . "/../.env";

        if (file_exists($env_file)) {

            $lines = file(
                $env_file,
                FILE_IGNORE_NEW_LINES |
                FILE_SKIP_EMPTY_LINES
            );

            foreach ($lines as $line) {

                $line = trim($line);

                if (
                    $line === "" ||
                    str_starts_with($line, "#")
                ) {
                    continue;
                }

                $parts = explode("=", $line, 2);

                if (count($parts) === 2) {

                    $key_name = trim($parts[0]);
                    $value = trim($parts[1]);

                    $env[$key_name] = $value;
                }
            }
        }
    }

    return $env[$key] ?? $default;
}


/*
|--------------------------------------------------------------------------
| DATABASE CONFIGURATION
|--------------------------------------------------------------------------
*/

$host =
    env_value("DB_HOST", "localhost");

$dbname =
    env_value("DB_NAME", "edgepay");

$username =
    env_value("DB_USER");

$password =
    env_value("DB_PASSWORD");


/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

$conn = new mysqli(
    $host,
    $username,
    $password,
    $dbname
);


if ($conn->connect_error) {

    die(
        "Database connection failed: " .
        $conn->connect_error
    );
}

?>