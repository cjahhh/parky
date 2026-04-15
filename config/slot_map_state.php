<?php
/**
 * Slot map display state for a calendar day (reservations + physical DB status).
 *
 * @return array{
 *   allSlots: list<array{id:int,slot_code:string,floor:int,status:string,display_status:string}>,
 *   counts: array{available:int,reserved:int,occupied:int,maintenance:int}
 * }
 */
function parky_slot_map_state_for_date(PDO $pdo, string $dateYmd): array
{
    $stmtRes = $pdo->prepare("
        SELECT slot_id FROM reservations
        WHERE DATE(arrival_time) = ?
        AND status IN ('pending','confirmed')
    ");
    $stmtRes->execute([$dateYmd]);
    $reservedOnDateIds = array_map(
        'intval',
        array_column($stmtRes->fetchAll(PDO::FETCH_ASSOC), 'slot_id')
    );


    $stmtAll = $pdo->query("SELECT id, slot_code, floor, status FROM parking_slots ORDER BY floor ASC, slot_code ASC");
    $allSlots = $stmtAll->fetchAll(PDO::FETCH_ASSOC);


    foreach ($allSlots as &$s) {
        $phys = $s['status'];
        if (in_array($phys, ['occupied', 'maintenance'], true)) {
            $s['display_status'] = $phys;
        } elseif (in_array((int) $s['id'], $reservedOnDateIds, true)) {
            $s['display_status'] = 'reserved';
        } else {
            $s['display_status'] = 'available';
        }
    }
    unset($s);


    $counts = ['available' => 0, 'reserved' => 0, 'occupied' => 0, 'maintenance' => 0];
    foreach ($allSlots as $s) {
        match ($s['display_status']) {
            'available' => $counts['available']++,
            'reserved' => $counts['reserved']++,
            'occupied' => $counts['occupied']++,
            'maintenance' => $counts['maintenance']++,
            default => null,
        };
    }


    return ['allSlots' => $allSlots, 'counts' => $counts];
}

