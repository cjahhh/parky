<?php
require_once 'config/db.php';

$alterations = [
    "ALTER TABLE sessions_walkin ADD COLUMN IF NOT EXISTS entry_time DATETIME NULL",
    "ALTER TABLE sessions_walkin ADD COLUMN IF NOT EXISTS exit_time DATETIME NULL",
    "ALTER TABLE sessions_walkin ADD COLUMN IF NOT EXISTS payment_status VARCHAR(50) DEFAULT 'unpaid'",
    "ALTER TABLE sessions_walkin ADD COLUMN IF NOT EXISTS paid_until DATETIME NULL",
];

foreach ($alterations as $sql) {
    try {
        $pdo->exec($sql);
        echo "✅ OK: $sql<br>";
    } catch (PDOException $e) {
        echo "❌ FAILED: $sql<br>Reason: " . $e->getMessage() . "<br>";
    }
}

echo "<br><strong>Done. Delete this file now.</strong>";
