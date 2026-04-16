<?php
require_once 'config/db.php';

$columns = [
    'entry_time'     => "ALTER TABLE sessions_walkin ADD COLUMN entry_time DATETIME DEFAULT CURRENT_TIMESTAMP",
    'exit_time'      => "ALTER TABLE sessions_walkin ADD COLUMN exit_time DATETIME DEFAULT NULL",
    'payment_status' => "ALTER TABLE sessions_walkin ADD COLUMN payment_status ENUM('unpaid','paid') DEFAULT 'unpaid'",
    'paid_until'     => "ALTER TABLE sessions_walkin ADD COLUMN paid_until DATETIME DEFAULT NULL",
];

foreach ($columns as $col => $sql) {
    // Check if column already exists
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'sessions_walkin' 
        AND COLUMN_NAME = ?
    ");
    $check->execute([$col]);
    
    if ($check->fetchColumn() > 0) {
        echo "⏭️ SKIPPED (already exists): $col<br>";
    } else {
        try {
            $pdo->exec($sql);
            echo "✅ ADDED: $col<br>";
        } catch (PDOException $e) {
            echo "❌ FAILED: $col — " . $e->getMessage() . "<br>";
        }
    }
}

echo "<br><strong>Done! Delete this file now.</strong>";
