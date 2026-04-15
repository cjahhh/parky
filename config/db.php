<?php

define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

// ✅ Correct Railway environment variables
$host = $_ENV['MYSQLHOST'];
$db   = $_ENV['MYSQLDATABASE'];
$user = $_ENV['MYSQLUSER'];
$pass = $_ENV['MYSQLPASSWORD'];
$port = $_ENV['MYSQLPORT'];

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    // Sync timezone
    $dbSessionOffset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
    $pdo->exec('SET time_zone = ' . $pdo->quote($dbSessionOffset));

} catch (PDOException $e) {
    http_response_code(500);
    die("Database connection failed: " . $e->getMessage());
}
