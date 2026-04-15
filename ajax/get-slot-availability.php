<?php
// ============================================================
//  ajax/get-slot-availability.php
//  Reserved slot IDs on a date + physical statuses + counts.
//  Used by index.php, reserve.php (date change + live polling).
// ============================================================


header('Content-Type: application/json; charset=utf-8');


session_start();


$isUser  = !empty($_SESSION['user_id']);
$isAdmin = !empty($_SESSION['admin_id']);


require_once dirname(__DIR__) . '/config/db.php';


if (!$isUser && !$isAdmin) {
    // ── FIX: unauthenticated visitors still get real live counts
    // so the homepage hero stats and summary stay accurate.
    // We just don't return per-slot detail to prevent scraping.
    $stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM parking_slots GROUP BY status");
    $counts = ['available' => 0, 'reserved' => 0, 'occupied' => 0, 'maintenance' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = (int) $row['cnt'];
        }
    }


    echo json_encode([
        'reservedOnDate'   => [],
        'physicalStatuses' => [],
        'counts'           => $counts,
        'auth'             => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


require_once dirname(__DIR__) . '/config/slot_map_state.php';


$date = trim($_GET['date'] ?? '');


if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode([
        'reservedOnDate'   => [],
        'physicalStatuses' => [],
        'counts'           => ['available' => 0, 'reserved' => 0, 'occupied' => 0, 'maintenance' => 0],
        'auth'             => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


$state = parky_slot_map_state_for_date($pdo, $date);


$reservedOnDate   = [];
$physicalStatuses = [];


foreach ($state['allSlots'] as $s) {
    $raw = $s['status'];
    $physicalStatuses[$s['id']] = ($raw === 'reserved') ? 'available' : $raw;


    if ($s['display_status'] === 'reserved') {
        $reservedOnDate[] = (int) $s['id'];
    }
}


echo json_encode([
    'reservedOnDate'   => $reservedOnDate,
    'physicalStatuses' => $physicalStatuses,
    'counts'           => $state['counts'],
    'auth'             => true,
], JSON_UNESCAPED_UNICODE);

