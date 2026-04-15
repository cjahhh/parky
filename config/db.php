<?php

define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

// ✅ Use getenv() instead of $_ENV (more reliable in Railway)
$host = getenv('mysql.railway.internal');
$db   = getenv('railway');
$user = getenv('root');
$pass = getenv('skuiyvrcWsydpCjPKPlXAMWgXOQKfUlr');
$port = getenv('3306');

// 🚨 fallback (prevents crash)
if (!$host || !$db || !$user) {
    die("Environment variables not loaded");
}

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

} catch (PDOException $e) {
    die("DB ERROR: " . $e->getMessage());
}
