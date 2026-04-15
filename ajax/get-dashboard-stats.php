<?php
// ============================================================
//  ajax/get-dashboard-stats.php
//  Polled every 5 s by admin/dashboard.php
//  All live fees computed via calculateFee() — never flat rate.
// ============================================================
header('Content-Type: application/json');


require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/rates.php';


// Lightweight auth guard: must be a logged-in admin
session_start();
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}


try {


// ── 1. SLOT SUMMARY ──────────────────────────────────────────
$slots = $pdo->query("
    SELECT
        COUNT(*)                        AS total,
        SUM(status = 'available')       AS available,
        SUM(status = 'occupied')        AS occupied,
        SUM(status = 'reserved')        AS reserved,
        SUM(status = 'maintenance')     AS maintenance
    FROM parking_slots
")->fetch();


// ── 2. VEHICLES CURRENTLY INSIDE ─────────────────────────────
$vehicles_inside = (int)$pdo->query("
    SELECT
        (SELECT COUNT(*) FROM sessions_walkin WHERE status = 'active') +
        (SELECT COUNT(*) FROM reservations    WHERE status = 'active')
")->fetchColumn();


// ── 3. TODAY'S CONFIRMED REVENUE (settled payments only) ─────
$paid_revenue = (float)$pdo->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM payments
    WHERE DATE(paid_at) = CURDATE()
")->fetchColumn();


// ── 4. LIVE FEE FOR ALL ACTIVE SESSIONS ──────────────────────
//  For each active walk-in / reservation we recompute the
//  outstanding fee using calculateFee() from rates.php.
//  If the session has already been partially paid (paid_until is
//  set), we only bill from that point forward.
$active_walkin = $pdo->query("
    SELECT id, entry_time, payment_status, paid_until
    FROM sessions_walkin
    WHERE status = 'active'
")->fetchAll();


$active_res = $pdo->query("
    SELECT id, arrival_time, payment_status, paid_until
    FROM reservations
    WHERE status = 'active'
")->fetchAll();


$now          = time();
$live_fee_sum = 0.0;


foreach ($active_walkin as $s) {
    $start = strtotime($s['entry_time']);
    if ($s['payment_status'] === 'paid' && $s['paid_until']) {
        $start = strtotime($s['paid_until']);
    }
    $live_fee_sum += calculateFee(max(0, $now - $start));
}


foreach ($active_res as $r) {
    $start = strtotime($r['arrival_time']);
    if ($start > $now) continue; // not yet arrived
    if ($r['payment_status'] === 'paid' && $r['paid_until']) {
        $start = strtotime($r['paid_until']);
    }
    $live_fee_sum += calculateFee(max(0, $now - $start));
}


// Revenue shown on dashboard = settled + outstanding live fees
$today_revenue = $paid_revenue + $live_fee_sum;


// ── 5. UNPAID ACTIVE SESSIONS ────────────────────────────────
$unpaid_active = (int)$pdo->query("
    SELECT
        (SELECT COUNT(*) FROM sessions_walkin WHERE payment_status = 'unpaid' AND status = 'active') +
        (SELECT COUNT(*) FROM reservations    WHERE payment_status = 'unpaid' AND status = 'active')
")->fetchColumn();


// ── 6. TOTAL REGISTERED USERS ────────────────────────────────
$total_users      = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$verified_users   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(is_verified, 0) = 1")->fetchColumn();
$unverified_users = max(0, $total_users - $verified_users);


// ── 7. FLOOR SUMMARY ─────────────────────────────────────────
$floor_summary = $pdo->query("
    SELECT floor, total, available, occupied, reserved, maintenance
    FROM view_floor_summary
    ORDER BY floor ASC
")->fetchAll();


foreach ($floor_summary as &$f) {
    $f['floor']       = (int)$f['floor'];
    $f['total']       = (int)$f['total'];
    $f['available']   = (int)$f['available'];
    $f['occupied']    = (int)$f['occupied'];
    $f['reserved']    = (int)$f['reserved'];
    $f['maintenance'] = (int)$f['maintenance'];
}
unset($f);


// ── 8. KIOSK LAST-ACTIVITY TIMESTAMPS ────────────────────────
$last_entry   = $pdo->query("SELECT MAX(entry_time) FROM sessions_walkin")->fetchColumn();
$last_exit    = $pdo->query("SELECT MAX(exit_time)  FROM sessions_walkin WHERE exit_time IS NOT NULL")->fetchColumn();
$last_payment = $pdo->query("SELECT MAX(paid_at)    FROM payments")->fetchColumn();


// ── 9. TODAY'S COUNTS ────────────────────────────────────────
$today_entries        = (int)$pdo->query("SELECT COUNT(*) FROM sessions_walkin WHERE DATE(entry_time)  = CURDATE()")->fetchColumn();
$today_reservations   = (int)$pdo->query("SELECT COUNT(*) FROM reservations    WHERE DATE(reserved_at) = CURDATE()")->fetchColumn();
$payments_today_count = (int)$pdo->query("SELECT COUNT(*) FROM payments        WHERE DATE(paid_at)     = CURDATE()")->fetchColumn();


// ── 10. HOURLY WALK-IN ENTRIES TODAY ─────────────────────────
$hourMap = [];
$hStmt   = $pdo->query("
    SELECT HOUR(entry_time) AS h, COUNT(*) AS c
    FROM sessions_walkin
    WHERE DATE(entry_time) = CURDATE()
    GROUP BY HOUR(entry_time)
");
while ($row = $hStmt->fetch()) {
    $hourMap[(int)$row['h']] = (int)$row['c'];
}


$hourly_entries = [];
$hourly_max     = 1;
for ($h = 6; $h <= 22; $h++) {
    $cnt = $hourMap[$h] ?? 0;
    if ($cnt > $hourly_max) $hourly_max = $cnt;
    $hourly_entries[] = ['hour' => $h, 'count' => $cnt];
}


// ── 11. RECENT ACTIVITY FEED ─────────────────────────────────
$recent_activity = $pdo->query("
    SELECT * FROM (


        SELECT
            'entry'          AS type,
            sw.plate_number  AS plate,
            ps.slot_code     AS slot,
            ps.floor         AS floor,
            'Walk-in entry'  AS action,
            sw.car_type,
            sw.car_color,
            sw.entry_time    AS event_time
        FROM sessions_walkin sw
        JOIN parking_slots ps ON sw.slot_id = ps.id


        UNION ALL


        SELECT
            'exit',
            sw.plate_number, ps.slot_code, ps.floor,
            'Walk-in exit',
            sw.car_type, sw.car_color,
            sw.exit_time
        FROM sessions_walkin sw
        JOIN parking_slots ps ON sw.slot_id = ps.id
        WHERE sw.exit_time IS NOT NULL


        UNION ALL


        SELECT
            'payment',
            CASE WHEN p.session_type = 'walkin' THEN sw2.plate_number ELSE r.plate_number END,
            CASE WHEN p.session_type = 'walkin' THEN ps2.slot_code    ELSE ps3.slot_code  END,
            CASE WHEN p.session_type = 'walkin' THEN ps2.floor        ELSE ps3.floor      END,
            CONCAT('Paid ₱', FORMAT(p.total_amount, 2), ' (', p.method, ')'),
            NULL, NULL,
            p.paid_at
        FROM payments p
        LEFT JOIN sessions_walkin sw2 ON p.session_id = sw2.id AND p.session_type = 'walkin'
        LEFT JOIN reservations r      ON p.session_id = r.id   AND p.session_type = 'reservation'
        LEFT JOIN parking_slots ps2   ON sw2.slot_id  = ps2.id
        LEFT JOIN parking_slots ps3   ON r.slot_id    = ps3.id


        UNION ALL


        SELECT
            'reservation',
            r2.plate_number, ps4.slot_code, ps4.floor,
            CONCAT('Reserved slot ', ps4.slot_code),
            r2.car_type, r2.car_color,
            r2.reserved_at
        FROM reservations r2
        JOIN parking_slots ps4 ON r2.slot_id = ps4.id


    ) AS combined
    ORDER BY event_time DESC
    LIMIT 10
")->fetchAll();


// ── OUTPUT ───────────────────────────────────────────────────
echo json_encode([
    'slots' => [
        'total'       => (int)$slots['total'],
        'available'   => (int)$slots['available'],
        'occupied'    => (int)$slots['occupied'],
        'reserved'    => (int)$slots['reserved'],
        'maintenance' => (int)$slots['maintenance'],
    ],
    'vehicles_inside'      => $vehicles_inside,
    'today_revenue'        => round($today_revenue, 2),
    'paid_revenue'         => round($paid_revenue, 2),
    'live_fee_sum'         => round($live_fee_sum, 2),
    'unpaid_active'        => $unpaid_active,
    'total_users'          => $total_users,
    'verified_users'       => $verified_users,
    'unverified_users'     => $unverified_users,
    'today_entries'        => $today_entries,
    'today_reservations'   => $today_reservations,
    'payments_today_count' => $payments_today_count,
    'hourly_entries'       => $hourly_entries,
    'hourly_max'           => $hourly_max,
    'floor_summary'        => $floor_summary,
    'kiosk_status' => [
        'last_entry'   => $last_entry   ?: null,
        'last_exit'    => $last_exit    ?: null,
        'last_payment' => $last_payment ?: null,
    ],
    'recent_activity' => $recent_activity,
]);


} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

