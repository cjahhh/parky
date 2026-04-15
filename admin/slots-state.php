<?php
session_start();
header('Content-Type: application/json; charset=utf-8');


if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}


require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/includes/slot_map_state.php';


$date = trim($_GET['date'] ?? '');
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}


$state = parky_slot_map_state_for_date($pdo, $date);
$byId = [];
foreach ($state['allSlots'] as $s) {
    $byId[(string) $s['id']] = [
        'p' => $s['status'],
        'd' => $s['display_status'],
    ];
}


echo json_encode([
    'ok' => true,
    'date' => $date,
    'counts' => $state['counts'],
    'byId' => $byId,
], JSON_UNESCAPED_UNICODE);

