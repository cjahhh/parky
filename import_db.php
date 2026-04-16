<?php
require_once 'config/db.php';

$sql = "
CREATE OR REPLACE VIEW view_floor_summary AS
SELECT
    ps.floor,
    COUNT(*) AS total,
    SUM(ps.status = 'available')   AS available,
    SUM(ps.status = 'occupied')    AS occupied,
    SUM(ps.status = 'reserved')    AS reserved,
    SUM(ps.status = 'maintenance') AS maintenance
FROM parking_slots ps
GROUP BY ps.floor
ORDER BY ps.floor ASC
";

try {
    $pdo->exec($sql);
    echo "✅ View 'view_floor_summary' created successfully.";
} catch (PDOException $e) {
    echo "❌ FAILED: " . $e->getMessage();
}
