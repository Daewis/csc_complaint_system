<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST .
                   ";port=" . DB_PORT .
                   ";dbname=" . DB_NAME .
                   ";charset=utf8mb4";

            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

        } catch (PDOException $e) {
            error_log("DB ERROR: " . $e->getMessage());
            // Temporary debug — remove before go-live
            die("DB Error: " . $e->getMessage() .
                "<br>Host: " . DB_HOST .
                "<br>DB: " . DB_NAME .
                "<br>User: " . DB_USER);
        }
    }

    return $pdo;
}
