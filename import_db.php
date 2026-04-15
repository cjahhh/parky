<?php
$host = getenv('MYSQLHOST') ?: 'mysql.railway.internal';
$db   = getenv('MYSQLDATABASE') ?: 'railway';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: 'skuiyvrcWsydpCjPKPlXAMWgXOQKfUlr';
$port = getenv('MYSQLPORT') ?: '3306';

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);

$fixes = [
    // Add missing columns to sessions_walkin if they don't exist
    "ALTER TABLE sessions_walkin ADD COLUMN IF NOT EXISTS entry_time datetime DEFAULT current_timestamp()",
    "ALTER TABLE sessions_walkin ADD COLUMN IF NOT EXISTS exit_time datetime DEFAULT NULL",
    "ALTER TABLE sessions_walkin ADD COLUMN IF NOT EXISTS payment_status enum('unpaid','paid') DEFAULT 'unpaid'",
    "ALTER TABLE sessions_walkin ADD COLUMN IF NOT EXISTS paid_until datetime DEFAULT NULL",
];

foreach ($fixes as $sql) {
    try {
        $pdo->exec($sql);
        echo "✅ OK: $sql <br>";
    } catch (PDOException $e) {
        echo "⚠️ " . $e->getMessage() . "<br>";
    }
}
echo "<br><strong>Done! Delete this file now.</strong>";
?>
