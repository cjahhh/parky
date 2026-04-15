<?php
session_start();
require_once 'config/db.php';
require_once 'config/slot_map_state.php';
require_once 'config/slot_sort.php';


// ── Auth guard ───────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}


$userId = $_SESSION['user_id'];


// ── Fetch user info ──────────────────────────────────────────
// plate_number added so we can auto-fill the reservation form (Step 5)
$stmt = $pdo->prepare("SELECT id, first_name, last_name, username, email, plate_number FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();


if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}


$navFullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$userDisplayName = $navFullName !== '' ? $navFullName : ($user['username'] ?? '');
$navInitial = strtoupper(substr($user['first_name'] ?? '', 0, 1));
if ($navInitial === '') {
    $navInitial = strtoupper(substr($user['username'] ?? '?', 0, 1));
}


$registeredPlate = strtoupper(trim($user['plate_number'] ?? ''));


// ════════════════════════════════════════════════════════════
// AUTO-EXPIRY CHECK
// Must run BEFORE the $existingReservation check below.
// Without this, a user whose reservation just expired would
// still be blocked from making a new one because their old
// 'pending' row was never updated to 'expired' in the DB.
// ════════════════════════════════════════════════════════════
$stmtExpired = $pdo->prepare("
    SELECT r.id, r.slot_id
    FROM reservations r
    WHERE r.user_id = ?
      AND r.status IN ('pending', 'confirmed')
      AND r.expires_at < NOW()
");
$stmtExpired->execute([$userId]);
$toExpire = $stmtExpired->fetchAll(PDO::FETCH_ASSOC);


foreach ($toExpire as $ex) {
    $pdo->prepare("UPDATE reservations SET status = 'expired' WHERE id = ?")
        ->execute([$ex['id']]);
    $pdo->prepare("UPDATE parking_slots SET status = 'available' WHERE id = ? AND status = 'reserved'")
        ->execute([$ex['slot_id']]);
}
// ════════════════════════════════════════════════════════════


// ── Check if user already has an active reservation ─────────
$stmtCheck = $pdo->prepare("
    SELECT r.id, r.status, r.arrival_time, ps.slot_code, ps.floor
    FROM reservations r
    JOIN parking_slots ps ON r.slot_id = ps.id
    WHERE r.user_id = ?
    AND r.status IN ('pending', 'confirmed', 'active')
    LIMIT 1
");
$stmtCheck->execute([$userId]);
$existingReservation = $stmtCheck->fetch();


$error   = '';
$success = '';


// ── Handle reservation form submission ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existingReservation) {
    $slotId      = intval($_POST['slot_id']      ?? 0);
    $arrivalDate = trim($_POST['arrival_date']   ?? '');
    $arrivalTime = trim($_POST['arrival_time']   ?? '');
    $plateNumber = strtoupper(trim($_POST['plate_number'] ?? ''));
    $carType     = trim($_POST['car_type']        ?? '');
    $carColor    = trim($_POST['car_color']       ?? '');


    // ── Validation ───────────────────────────────────────────
    if (!$slotId || !$arrivalDate || !$arrivalTime || !$plateNumber || !$carType || !$carColor) {
        $error = 'Please fill in all fields and select a parking slot.';
    } else {
        $arrivalDatetime = $arrivalDate . ' ' . $arrivalTime . ':00';
        $arrivalTs       = strtotime($arrivalDatetime);
        $nowTs           = time();


        if ($arrivalTs === false || $arrivalTs < $nowTs) {
            $error = 'Arrival date and time must be today or in the future.';
        } else {
            $stmtSlot = $pdo->prepare("
                SELECT id, slot_code, floor, status
                FROM parking_slots
                WHERE id = ?
                LIMIT 1
            ");
            $stmtSlot->execute([$slotId]);
            $slot = $stmtSlot->fetch();


            if (!$slot) {
                $error = 'Invalid parking slot selected.';
            } elseif (in_array($slot['status'], ['occupied', 'maintenance'])) {
                $error = 'This slot is not available for reservation.';
            } else {
                $stmtConflict = $pdo->prepare("
                    SELECT id FROM reservations
                    WHERE slot_id = ?
                    AND DATE(arrival_time) = ?
                    AND status IN ('pending', 'confirmed')
                    LIMIT 1
                ");
                $stmtConflict->execute([$slotId, $arrivalDate]);
                $conflict = $stmtConflict->fetch();


                if ($conflict) {
                    $error = 'This slot is already reserved on that date. Please choose another slot or date.';
                } else {
                    $expiresAt  = date('Y-m-d H:i:s', $arrivalTs + 3600);
                    $reservedAt = date('Y-m-d H:i:s');


                    $stmtInsert = $pdo->prepare("
                        INSERT INTO reservations
                            (user_id, slot_id, plate_number, car_type, car_color,
                             reserved_at, arrival_time, expires_at, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmtInsert->execute([
                        $userId,
                        $slotId,
                        $plateNumber,
                        $carType,
                        $carColor,
                        $reservedAt,
                        $arrivalDatetime,
                        $expiresAt
                    ]);


                    $pdo->prepare("UPDATE parking_slots SET status = 'reserved' WHERE id = ?")
                        ->execute([$slotId]);


                    $success = 'Reservation confirmed! Your spot has been reserved.';


                    $stmtCheck->execute([$userId]);
                    $existingReservation = $stmtCheck->fetch();
                }
            }
        }
    }
}


// ── Today's date for min date picker + default map date ──────
$todayDate      = date('Y-m-d');
$initialMapDate = $todayDate;
$postArrival    = trim((string) ($_POST['arrival_date'] ?? ''));
if ($postArrival !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $postArrival)) {
    $initialMapDate = $postArrival;
}


$mapState    = parky_slot_map_state_for_date($pdo, $initialMapDate);
$allSlots    = $mapState['allSlots'];
$mapCounts   = $mapState['counts'];


$slotsByFloor = [1 => [], 2 => [], 3 => []];
foreach ($allSlots as $s) {
    $slotsByFloor[(int) $s['floor']][] = $s;
}


// ── Plate value priority: POST resubmit → registered plate → empty ──
// This means the field is pre-filled on first load if the user
// has a plate on their account, but they can still type over it.
$plateFieldValue = strtoupper(trim($_POST['plate_number'] ?? $registeredPlate));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky — Reserve a Parking</title>
          <link rel="icon" href="favicon.ico" type="image/x-icon">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }


    :root {
      --bg:           #131613;
      --bg2:          #1a1f1a;
      --bg3:          #1f251f;
      --surface:      #252b25;
      --surface2:     #2c332c;
      --surface3:     #333b33;
      --border:       rgba(255,255,255,0.06);
      --border2:      rgba(255,255,255,0.10);
      --border3:      rgba(255,255,255,0.15);
      --text:         #eaf2ea;
      --text2:        #c5d9c5;
      --muted:        #7a907a;
      --muted2:       #556655;
      --emerald:      #34d399;
      --emerald2:     #10b981;
      --emerald3:     #059669;
      --emeraldbg:    rgba(52,211,153,0.08);
      --emeraldbg2:   rgba(52,211,153,0.15);
      --emeraldborder:rgba(52,211,153,0.25);
      --danger:       #f87171;
      --dangerbg:     rgba(248,113,113,0.08);
      --dangerborder: rgba(248,113,113,0.25);
      --warning:      #fbbf24;
      --warningbg:    rgba(251,191,36,0.08);
      --warningborder:rgba(251,191,36,0.25);
      --successbg:    rgba(52,211,153,0.08);
      --nav-h:        64px;


      --slot-available:      #34d399;
      --slot-available-bg:   rgba(52,211,153,0.10);
      --slot-available-bdr:  rgba(52,211,153,0.30);
      --slot-reserved:       #fbbf24;
      --slot-reserved-bg:    rgba(251,191,36,0.10);
      --slot-reserved-bdr:   rgba(251,191,36,0.35);
      --slot-occupied:       #f87171;
      --slot-occupied-bg:    rgba(248,113,113,0.10);
      --slot-occupied-bdr:   rgba(248,113,113,0.30);
      --slot-maintenance:    #6b7280;
      --slot-maintenance-bg: rgba(107,114,128,0.12);
      --slot-maintenance-bdr:rgba(107,114,128,0.30);
      --slot-selected:       #10b981;
      --slot-selected-bg:    rgba(16,185,129,0.20);
      --slot-selected-bdr:   rgba(16,185,129,0.60);
    }


    html { scroll-behavior: smooth; }
    body { font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden; }
    body::before { content:'';position:fixed;top:-200px;right:-200px;width:600px;height:600px;background:radial-gradient(circle,rgba(52,211,153,0.05) 0%,transparent 65%);pointer-events:none;z-index:0; }


    /* ════════ NAVBAR ════════ */
    .navbar { position:sticky;top:0;z-index:100;height:var(--nav-h);background:rgba(19,22,19,0.85);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border-bottom:1px solid var(--border2);display:flex;align-items:center;padding:0 2rem;gap:2rem; }
    .nav-logo { display:flex;align-items:center;gap:9px;text-decoration:none;flex-shrink:0; }
    .nav-logo-icon { width:34px;height:34px;background:var(--emeraldbg2);border:1.5px solid var(--emeraldborder);border-radius:9px;display:flex;align-items:center;justify-content:center; }
    .nav-logo-icon span { font-size:1rem;font-weight:900;color:var(--emerald);line-height:1; }
    .nav-logo-text { font-size:1.15rem;font-weight:800;color:var(--emerald);letter-spacing:-0.02em; }
    .nav-links { display:flex;align-items:center;gap:0.25rem;flex:1; }
    .nav-links a { text-decoration:none;font-size:0.875rem;font-weight:600;color:var(--muted);padding:6px 14px;border-radius:8px;transition:color 0.2s,background 0.2s; }
    .nav-links a:hover { color:var(--text2);background:var(--surface); }
    .nav-links a.active { color:var(--emerald);background:var(--emeraldbg); }
    .nav-right { display:flex;align-items:center;gap:0.75rem;margin-left:auto; }
    .nav-user { display:flex;align-items:center;gap:8px;font-size:0.82rem;font-weight:700;color:var(--muted); }
    .nav-user-dot { width:28px;height:28px;border-radius:50%;background:var(--emeraldbg2);border:1.5px solid var(--emeraldborder);display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;color:var(--emerald); }
    .btn-nav-logout { background:var(--surface);border:1px solid var(--border2);border-radius:8px;padding:7px 14px;font-family:'Nunito',sans-serif;font-size:0.8rem;font-weight:700;color:var(--muted);cursor:pointer;transition:all 0.2s;text-decoration:none;display:flex;align-items:center;gap:6px; }
    .btn-nav-logout:hover { color:var(--danger);border-color:var(--dangerborder);background:var(--dangerbg); }


    /* ════════ MAIN ════════ */
    .main { max-width:1280px;margin:0 auto;padding:2rem 2rem 4rem;position:relative;z-index:1; }
    .page-header { margin-bottom:2rem; }
    .page-header-top { display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap; }
    .page-badge { display:inline-flex;align-items:center;gap:6px;background:var(--emeraldbg);border:1px solid var(--emeraldborder);border-radius:20px;padding:4px 12px;font-size:0.72rem;font-weight:700;color:var(--emerald);letter-spacing:0.05em;text-transform:uppercase;margin-bottom:0.6rem; }
    .page-badge-dot { width:5px;height:5px;border-radius:50%;background:var(--emerald);animation:pulse 2s infinite; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1);}50%{opacity:0.5;transform:scale(0.8);} }
    .page-title { font-size:1.75rem;font-weight:900;color:var(--text);letter-spacing:-0.03em;line-height:1.15; }
    .page-subtitle { font-size:0.88rem;color:var(--muted);font-weight:500;margin-top:5px; }


    /* Existing reservation block */
    .existing-block { background:var(--warningbg);border:1px solid var(--warningborder);border-radius:16px;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:2rem;animation:fadeUp 0.4s ease both; }
    .existing-icon { width:42px;height:42px;flex-shrink:0;background:rgba(251,191,36,0.12);border:1.5px solid rgba(251,191,36,0.3);border-radius:11px;display:flex;align-items:center;justify-content:center; }
    .existing-icon svg { width:20px;height:20px;stroke:var(--warning);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .existing-info { flex:1;min-width:200px; }
    .existing-info h3 { font-size:0.92rem;font-weight:800;color:var(--warning);margin-bottom:3px; }
    .existing-info p  { font-size:0.82rem;color:var(--muted);font-weight:500; }
    .existing-info strong { color:var(--text2); }
    .btn-dashboard { background:rgba(251,191,36,0.12);border:1px solid rgba(251,191,36,0.3);border-radius:10px;padding:9px 18px;font-family:'Nunito',sans-serif;font-size:0.82rem;font-weight:800;color:var(--warning);cursor:pointer;text-decoration:none;transition:all 0.2s;white-space:nowrap; }
    .btn-dashboard:hover { background:rgba(251,191,36,0.2); }


    /* Messages */
    .msg-box { border-radius:14px;padding:12px 16px;font-size:0.85rem;font-weight:600;margin-bottom:1.5rem;display:flex;align-items:center;gap:10px;animation:fadeUp 0.35s ease both; }
    .msg-box::before { content:'';width:7px;height:7px;border-radius:50%;flex-shrink:0; }
    .msg-error   { background:var(--dangerbg); border:1px solid var(--dangerborder);color:var(--danger); }
    .msg-error::before   { background:var(--danger); }
    .msg-success { background:var(--successbg);border:1px solid var(--emeraldborder);color:var(--emerald); }
    .msg-success::before { background:var(--emerald); }


    /* ════════ GRID ════════ */
    .content-grid { display:grid;grid-template-columns:1fr 360px;gap:1.5rem;align-items:start; }
    @media (max-width:900px) { .content-grid { grid-template-columns:1fr; } }


    /* ════════ SLOT MAP PANEL ════════ */
    .map-panel { background:var(--bg2);border:1px solid var(--border2);border-radius:20px;overflow:hidden; }
    .map-panel-header { padding:1.25rem 1.5rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap; }
    .map-panel-title { font-size:1rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:8px; }
    .map-panel-title svg { width:16px;height:16px;stroke:var(--emerald);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .map-date-badge { font-size:0.75rem;font-weight:700;color:var(--emerald);background:var(--emeraldbg);border:1px solid var(--emeraldborder);border-radius:20px;padding:4px 12px; }


    .legend { display:flex;align-items:center;gap:1rem;flex-wrap:wrap;padding:0.75rem 1.5rem;border-bottom:1px solid var(--border);background:var(--bg3); }
    .legend-item { display:flex;align-items:center;gap:6px;font-size:0.72rem;font-weight:700;color:var(--muted); }
    .legend-dot { width:10px;height:10px;border-radius:3px; }
    .legend-dot.available   { background:var(--slot-available); }
    .legend-dot.reserved    { background:var(--slot-reserved); }
    .legend-dot.occupied    { background:var(--slot-occupied); }
    .legend-dot.maintenance { background:var(--slot-maintenance); }


    .floor-tabs { display:flex;gap:0.5rem;padding:1rem 1.5rem 0; }
    .floor-tab { background:var(--surface);border:1px solid var(--border2);border-radius:10px;padding:7px 18px;font-family:'Nunito',sans-serif;font-size:0.82rem;font-weight:700;color:var(--muted);cursor:pointer;transition:all 0.2s; }
    .floor-tab:hover { color:var(--text2);border-color:var(--border3); }
    .floor-tab.active { background:var(--emeraldbg2);border-color:var(--emeraldborder);color:var(--emerald); }


    .floor-map { display:none;padding:1rem 1.5rem 1.5rem; }
    .floor-map.active { display:block; }
    .row-label { font-size:0.65rem;font-weight:800;color:var(--muted2);letter-spacing:0.06em;text-transform:uppercase;display:flex;align-items:center;margin-bottom:0.4rem;gap:8px; }
    .row-label::after { content:'';flex:1;height:1px;background:var(--border); }
    .slots-row { display:grid;grid-template-columns:repeat(10,1fr);gap:6px;margin-bottom:0.85rem; }


    .slot-btn { aspect-ratio:1;border-radius:9px;border:1.5px solid;display:flex;flex-direction:column;align-items:center;justify-content:center;font-family:'Nunito',sans-serif;font-size:0.6rem;font-weight:800;cursor:pointer;transition:all 0.18s;position:relative;line-height:1.2;padding:2px; }
    .slot-btn .slot-code { font-size:0.65rem;font-weight:800; }
    .slot-btn.available { background:var(--slot-available-bg);border-color:var(--slot-available-bdr);color:var(--slot-available); }
    .slot-btn.available:hover { background:rgba(52,211,153,0.20);border-color:var(--slot-available);transform:translateY(-2px);box-shadow:0 4px 12px rgba(52,211,153,0.2); }
    .slot-btn.reserved    { background:var(--slot-reserved-bg);   border-color:var(--slot-reserved-bdr);    color:var(--slot-reserved);    cursor:not-allowed; }
    .slot-btn.occupied    { background:var(--slot-occupied-bg);    border-color:var(--slot-occupied-bdr);    color:var(--slot-occupied);    cursor:not-allowed; }
    .slot-btn.maintenance { background:var(--slot-maintenance-bg); border-color:var(--slot-maintenance-bdr); color:var(--slot-maintenance); cursor:not-allowed; }
    .slot-btn.selected { background:var(--slot-selected-bg) !important;border-color:var(--slot-selected-bdr) !important;color:var(--slot-selected) !important;transform:translateY(-2px) scale(1.05);box-shadow:0 4px 16px rgba(16,185,129,0.3);cursor:pointer; }


    .map-loading { display:none;position:absolute;inset:0;background:rgba(19,22,19,0.7);border-radius:12px;align-items:center;justify-content:center;z-index:10; }
    .map-loading.show { display:flex; }
    .map-loading-spin { width:28px;height:28px;border:3px solid var(--border2);border-top-color:var(--emerald);border-radius:50%;animation:spin 0.7s linear infinite; }
    @keyframes spin { to{transform:rotate(360deg);} }
    .floor-map-wrap { position:relative; }


    .slot-summary { display:flex;gap:1rem;padding:0.75rem 1.5rem;border-top:1px solid var(--border);background:var(--bg3);flex-wrap:wrap; }
    .slot-count { font-size:0.75rem;font-weight:700;color:var(--muted); }
    .slot-count span { font-weight:800; }
    .slot-count.green  span { color:var(--slot-available); }
    .slot-count.yellow span { color:var(--slot-reserved); }
    .slot-count.red    span { color:var(--slot-occupied); }
    .slot-count.gray   span { color:var(--slot-maintenance); }


    /* ════════ FORM PANEL ════════ */
    .form-panel { background:var(--bg2);border:1px solid var(--border2);border-radius:20px;overflow:hidden;position:sticky;top:calc(var(--nav-h) + 1.5rem); }
    .form-panel-header { padding:1.25rem 1.5rem 1rem;border-bottom:1px solid var(--border); }
    .form-panel-title { font-size:1rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:8px;margin-bottom:3px; }
    .form-panel-title svg { width:16px;height:16px;stroke:var(--emerald);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .form-panel-sub { font-size:0.78rem;color:var(--muted);font-weight:500; }


    .selected-slot-display { margin:1rem 1.5rem 0;background:var(--surface);border:1.5px solid var(--border2);border-radius:14px;padding:1rem;text-align:center;transition:all 0.3s;min-height:72px;display:flex;flex-direction:column;align-items:center;justify-content:center; }
    .selected-slot-display.has-slot { background:var(--slot-selected-bg);border-color:var(--slot-selected-bdr); }
    .selected-slot-code { font-size:1.6rem;font-weight:900;color:var(--emerald);letter-spacing:-0.02em;line-height:1; }
    .selected-slot-sub  { font-size:0.75rem;color:var(--muted);font-weight:600;margin-top:4px; }
    .selected-slot-placeholder { font-size:0.82rem;color:var(--muted2);font-weight:600; }


    .form-body { padding:1rem 1.5rem 1.5rem; }
    .form-group { margin-bottom:0.9rem; }
    .form-row { display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.9rem; }


    label.field-label { display:block;font-size:0.7rem;font-weight:700;color:var(--muted);margin-bottom:5px;letter-spacing:0.05em;text-transform:uppercase; }
    .input-wrap { position:relative; }
    .input-wrap svg.ico { position:absolute;left:12px;top:50%;transform:translateY(-50%);width:14px;height:14px;stroke:var(--muted2);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;pointer-events:none;transition:stroke 0.2s; }
    .input-wrap:focus-within svg.ico { stroke:var(--emerald2); }


    input[type="date"],input[type="time"],input[type="text"],select { width:100%;background:var(--surface);border:1.5px solid var(--border2);border-radius:11px;padding:10px 12px 10px 36px;font-family:'Nunito',sans-serif;font-size:0.85rem;font-weight:600;color:var(--text);outline:none;transition:border-color 0.2s,background 0.2s;appearance:none;-webkit-appearance:none; }
    input::placeholder { color:var(--muted2);font-weight:500; }
    input:focus,select:focus { border-color:var(--emerald2);background:var(--surface2); }


    /* Auto-filled plate gets a subtle emerald tint so users know it came from their profile */
    input.plate-autofilled { border-color:var(--emeraldborder);background:var(--emeraldbg); }


    .color-preview { position:absolute;right:12px;top:50%;transform:translateY(-50%);width:16px;height:16px;border-radius:50%;border:1.5px solid var(--border2);background:var(--surface3);transition:background 0.3s; }
    .select-wrap { position:relative; }
    .select-wrap::after { content:'';position:absolute;right:12px;top:50%;transform:translateY(-50%);width:0;height:0;border-left:5px solid transparent;border-right:5px solid transparent;border-top:5px solid var(--muted2);pointer-events:none; }
    .select-wrap select { padding-right:32px; }
    #slot_id { display:none; }
    .divider { height:1px;background:var(--border);margin:1rem 0; }


    /* Plate auto-fill hint badge */
    .plate-hint { display:flex;align-items:center;gap:5px;font-size:0.7rem;font-weight:700;color:var(--emerald);margin-top:5px; }
    .plate-hint svg { width:11px;height:11px;stroke:var(--emerald);fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round; }


    .form-note { display:flex;align-items:flex-start;gap:8px;background:var(--emeraldbg);border:1px solid var(--emeraldborder);border-radius:10px;padding:10px 12px;margin-bottom:0.9rem; }
    .form-note svg { width:14px;height:14px;flex-shrink:0;margin-top:1px;stroke:var(--emerald);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .form-note p { font-size:0.75rem;font-weight:600;color:var(--muted);line-height:1.5; }
    .form-note p strong { color:var(--emerald); }


    .btn-submit { width:100%;background:var(--emerald2);color:#0a1a12;border:none;border-radius:12px;padding:13px;font-family:'Nunito',sans-serif;font-size:0.92rem;font-weight:900;cursor:pointer;transition:background 0.2s,transform 0.1s,box-shadow 0.2s;letter-spacing:-0.01em; }
    .btn-submit:hover { background:var(--emerald);box-shadow:0 4px 20px rgba(52,211,153,0.25); }
    .btn-submit:active { transform:scale(0.98); }
    .btn-submit:disabled { background:var(--surface3);color:var(--muted2);cursor:not-allowed;box-shadow:none; }


    @keyframes fadeUp { from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);} }
    .map-panel  { animation:fadeUp 0.4s ease 0.05s both; }
    .form-panel { animation:fadeUp 0.4s ease 0.15s both; }


    .slot-btn[data-tip] { position:relative; }
    .slot-btn[data-tip]:hover::after { content:attr(data-tip);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:#0a1a12;border:1px solid var(--border3);color:var(--text2);font-size:0.65rem;font-weight:700;padding:4px 8px;border-radius:6px;white-space:nowrap;z-index:50;pointer-events:none; }


    @media (max-width:600px) { .main{padding:1rem 1rem 3rem;} .navbar{padding:0 1rem;} .slots-row{grid-template-columns:repeat(5,1fr);} .page-title{font-size:1.4rem;} }
  </style>
</head>
<body>


<nav class="navbar">
  <a href="index.php" class="nav-logo">
    <div class="nav-logo-icon"><span>P</span></div>
    <span class="nav-logo-text">Parky</span>
  </a>
  <div class="nav-links">
    <a href="index.php">Home</a>
    <a href="reserve.php" class="active">Reserve a parking</a>
    <a href="find-my-car.php">Find my car</a>
    <a href="about.php">About</a>
  </div>
  <div class="nav-right">
    <div class="nav-user">
      <div class="nav-user-dot">
        <?= htmlspecialchars($navInitial) ?>
      </div>
      <?= htmlspecialchars($userDisplayName) ?>
    </div>
    <a href="logout.php" class="btn-nav-logout">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Log out
    </a>
  </div>
</nav>


<main class="main">


  <div class="page-header">
    <div class="page-header-top">
      <div>
        <div class="page-badge"><span class="page-badge-dot"></span>Live availability</div>
        <h1 class="page-title">Reserve a parking slot</h1>
        <p class="page-subtitle">Pick your floor, choose a date, and select an available spot.</p>
      </div>
    </div>
  </div>


  <?php if ($existingReservation): ?>
  <div class="existing-block">
    <div class="existing-icon">
      <svg viewBox="0 0 24 24">
        <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
      </svg>
    </div>
    <div class="existing-info">
      <h3>You already have an active reservation</h3>
      <p>
        Slot <strong><?= htmlspecialchars($existingReservation['slot_code']) ?></strong>
        (Floor <?= htmlspecialchars($existingReservation['floor']) ?>) —
        Arriving <strong><?= date('M d, Y g:i A', strtotime($existingReservation['arrival_time'])) ?></strong>
        &nbsp;·&nbsp; Status: <strong><?= ucfirst($existingReservation['status']) ?></strong>
      </p>
      <p style="margin-top:4px;font-size:0.78rem;">
        You must complete or cancel your current reservation before making a new one.
      </p>
    </div>
    <a href="dashboard.php" class="btn-dashboard">View dashboard →</a>
  </div>
  <?php endif; ?>


  <?php if ($error): ?>
    <div class="msg-box msg-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="msg-box msg-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>


  <div class="content-grid">


    <!-- ══ SLOT MAP ══ -->
    <div class="map-panel">
      <div class="map-panel-header">
        <div class="map-panel-title">
          <svg viewBox="0 0 24 24">
            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
            <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
          </svg>
          Slot map
        </div>
        <div class="map-date-badge" id="mapDateBadge">
          <?= $initialMapDate === $todayDate
              ? 'Showing: Today'
              : 'Showing: ' . htmlspecialchars(date('M j, Y', strtotime($initialMapDate . ' 12:00:00'))) ?>
        </div>
      </div>


      <div class="legend">
        <div class="legend-item"><div class="legend-dot available"></div> Available</div>
        <div class="legend-item"><div class="legend-dot reserved"></div> Reserved</div>
        <div class="legend-item"><div class="legend-dot occupied"></div> Occupied</div>
        <div class="legend-item"><div class="legend-dot maintenance"></div> Maintenance</div>
      </div>


      <div class="floor-tabs">
        <button class="floor-tab active" onclick="switchFloor(1, this)">Floor 1</button>
        <button class="floor-tab" onclick="switchFloor(2, this)">Floor 2</button>
        <button class="floor-tab" onclick="switchFloor(3, this)">Floor 3</button>
      </div>


      <?php foreach ([1, 2, 3] as $floor): ?>
      <div class="floor-map <?= $floor === 1 ? 'active' : '' ?>" id="floor-map-<?= $floor ?>">
        <div class="floor-map-wrap" id="floor-wrap-<?= $floor ?>">
          <div class="map-loading" id="map-loading-<?= $floor ?>">
            <div class="map-loading-spin"></div>
          </div>


          <?php
            $rows = [];
            foreach ($slotsByFloor[$floor] as $s) {
              preg_match('/^\d+([A-Z]+)/', $s['slot_code'], $m);
              $row = $m[1] ?? 'A';
              $rows[$row][] = $s;
            }
            ksort($rows);
            foreach ($rows as &$rowSlots) {
              parky_usort_row_slots($rowSlots);
            }
            unset($rowSlots);
          ?>


          <?php foreach ($rows as $rowLetter => $rowSlots): ?>
          <div class="row-label">Row <?= $rowLetter ?></div>
          <div class="slots-row">
            <?php foreach ($rowSlots as $s):
              $displayStatus = $s['display_status'];
              $isClickable   = ($displayStatus === 'available') && !$existingReservation;
              $tipMap = [
                'available'   => $s['slot_code'] . ' — Click to select',
                'reserved'    => $s['slot_code'] . ' — Reserved on this date',
                'occupied'    => $s['slot_code'] . ' — Occupied',
                'maintenance' => $s['slot_code'] . ' — Under maintenance',
              ];
            ?>
            <button
              type="button"
              class="slot-btn <?= $displayStatus ?>"
              id="slot-btn-<?= $s['id'] ?>"
              data-id="<?= $s['id'] ?>"
              data-code="<?= htmlspecialchars($s['slot_code']) ?>"
              data-floor="<?= $s['floor'] ?>"
              data-status="<?= $displayStatus ?>"
              data-tip="<?= htmlspecialchars($tipMap[$displayStatus] ?? $s['slot_code']) ?>"
              <?= !$isClickable ? 'disabled' : "onclick=\"selectSlot(this)\"" ?>
            >
              <span class="slot-code"><?= $s['slot_code'] ?></span>
            </button>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>


        </div>
      </div>
      <?php endforeach; ?>


      <div class="slot-summary">
        <div class="slot-count green">Available: <span id="cnt-available"><?= (int) $mapCounts['available'] ?></span></div>
        <div class="slot-count yellow">Reserved: <span id="cnt-reserved"><?= (int) $mapCounts['reserved'] ?></span></div>
        <div class="slot-count red">Occupied: <span id="cnt-occupied"><?= (int) $mapCounts['occupied'] ?></span></div>
        <div class="slot-count gray">Maintenance: <span id="cnt-maintenance"><?= (int) $mapCounts['maintenance'] ?></span></div>
      </div>
    </div>


    <!-- ══ FORM PANEL ══ -->
    <div class="form-panel">
      <div class="form-panel-header">
        <div class="form-panel-title">
          <svg viewBox="0 0 24 24">
            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
          </svg>
          Reservation details
        </div>
        <p class="form-panel-sub">Fill in your details to confirm your spot.</p>
      </div>


      <div class="selected-slot-display" id="selectedSlotDisplay">
        <p class="selected-slot-placeholder">No slot selected yet</p>
      </div>


      <div class="form-body">
        <?php if (!$existingReservation): ?>
        <form method="POST" action="reserve.php" id="reserveForm">
          <input type="hidden" name="slot_id" id="slot_id" value="">


          <div class="form-group">
            <label class="field-label">Arrival date</label>
            <div class="input-wrap">
              <input type="date" name="arrival_date" id="arrival_date"
                min="<?= $todayDate ?>"
                value="<?= htmlspecialchars($_POST['arrival_date'] ?? $todayDate) ?>"
                required onchange="onDateChange(this.value)"/>
              <svg class="ico" viewBox="0 0 24 24">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
              </svg>
            </div>
          </div>


          <div class="form-group">
            <label class="field-label">Arrival time</label>
            <div class="input-wrap">
              <input type="time" name="arrival_time" id="arrival_time"
                value="<?= htmlspecialchars($_POST['arrival_time'] ?? '09:00') ?>" required/>
              <svg class="ico" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
              </svg>
            </div>
          </div>


          <div class="divider"></div>


          <!-- ── Plate number — auto-filled from profile if available ── -->
          <div class="form-group">
            <label class="field-label">Plate number</label>
            <div class="input-wrap">
              <input type="text" name="plate_number" id="plate_number"
                placeholder="e.g. ABC 123"
                value="<?= htmlspecialchars($plateFieldValue) ?>"
                maxlength="15"
                style="text-transform:uppercase;"
                class="<?= $plateFieldValue !== '' && !isset($_POST['plate_number']) ? 'plate-autofilled' : '' ?>"
                required/>
              <svg class="ico" viewBox="0 0 24 24">
                <rect x="2" y="7" width="20" height="10" rx="2"/>
                <path d="M7 12h.01M17 12h.01"/>
              </svg>
            </div>
            <?php if ($registeredPlate && !isset($_POST['plate_number'])): ?>
            <div class="plate-hint">
              <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
              Auto-filled from your profile — you can edit this if needed.
            </div>
            <?php endif; ?>
          </div>


          <div class="form-row">
            <div class="form-group" style="margin-bottom:0;">
              <label class="field-label">Car type</label>
              <div class="input-wrap select-wrap">
                <select name="car_type" id="car_type" required>
                  <option value="" disabled <?= empty($_POST['car_type']) ? 'selected' : '' ?>>Select type</option>
                  <?php foreach (['Sedan','SUV','Van','Hatchback','Pickup','Motorcycle'] as $ct): ?>
                  <option value="<?= $ct ?>" <?= (($_POST['car_type'] ?? '') === $ct) ? 'selected' : '' ?>><?= $ct ?></option>
                  <?php endforeach; ?>
                </select>
                <svg class="ico" viewBox="0 0 24 24">
                  <path d="M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v5"/>
                  <circle cx="18" cy="19" r="2"/><circle cx="8" cy="19" r="2"/>
                </svg>
              </div>
            </div>


            <div class="form-group" style="margin-bottom:0;">
              <label class="field-label">Car color</label>
              <div class="input-wrap">
                <input type="text" name="car_color" id="car_color"
                  placeholder="e.g. Red"
                  value="<?= htmlspecialchars($_POST['car_color'] ?? '') ?>"
                  maxlength="30" required oninput="updateColorSwatch(this.value)"/>
                <svg class="ico" viewBox="0 0 24 24">
                  <circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/>
                  <circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/>
                  <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 011.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>
                </svg>
                <div class="color-preview" id="colorSwatch"></div>
              </div>
            </div>
          </div>


          <div class="divider"></div>


          <div class="form-note">
            <svg viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="10"/>
              <line x1="12" y1="8" x2="12" y2="12"/>
              <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>You have a <strong>1-hour grace period</strong> from your arrival time.
               If you don't check in, your reservation will be automatically cancelled.</p>
          </div>


          <button type="submit" class="btn-submit" id="submitBtn" disabled>
            Confirm reservation
          </button>
        </form>


        <?php else: ?>
        <div style="padding:1rem 0;text-align:center;">
          <p style="font-size:0.82rem;color:var(--muted);font-weight:600;line-height:1.6;">
            You already have an active reservation. Complete or cancel it from your dashboard before making a new one.
          </p>
          <a href="dashboard.php" class="btn-submit"
             style="display:block;margin-top:1rem;text-decoration:none;text-align:center;padding:13px;">
            Go to dashboard
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>


  </div>
</main>


<script>
const allSlotsData   = <?= json_encode(array_column($allSlots, null, 'id'), JSON_HEX_TAG) ?>;
const TODAY          = '<?= $todayDate ?>';
const POLL_MS        = 4000;
const canSelectSlots = <?= $existingReservation ? 'false' : 'true' ?>;


let selectedSlotId   = null;
let selectedSlotCode = null;
let selectedFloor    = null;
let currentDate      = document.getElementById('arrival_date') ? document.getElementById('arrival_date').value : TODAY;
let pollTimer        = null;


window.addEventListener('DOMContentLoaded', () => {
  updateCounts();
  updateSubmitBtn();


  // Apply the emerald tint on load if the plate was auto-filled from profile
  const plateInput = document.getElementById('plate_number');
  if (plateInput) {
    plateInput.addEventListener('input', function () {
      this.value = this.value.toUpperCase();
      // Remove auto-fill styling once the user starts editing
      this.classList.remove('plate-autofilled');
    });
  }


  <?php if (!empty($_POST['slot_id'])): ?>
  const prevSlot = allSlotsData[<?= intval($_POST['slot_id'] ?? 0) ?>];
  if (prevSlot) {
    const btn = document.getElementById('slot-btn-' + prevSlot.id);
    if (btn && btn.dataset.status === 'available') selectSlot(btn);
  }
  <?php endif; ?>


  startPolling();
});


function switchFloor(floor, tabEl) {
  document.querySelectorAll('.floor-map').forEach(m => m.classList.remove('active'));
  document.querySelectorAll('.floor-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('floor-map-' + floor).classList.add('active');
  tabEl.classList.add('active');
}


function selectSlot(btn) {
  if (btn.dataset.status !== 'available') return;
  if (selectedSlotId) {
    const prev = document.getElementById('slot-btn-' + selectedSlotId);
    if (prev) { prev.classList.remove('selected'); prev.classList.add('available'); }
  }
  btn.classList.remove('available');
  btn.classList.add('selected');
  selectedSlotId   = btn.dataset.id;
  selectedSlotCode = btn.dataset.code;
  selectedFloor    = btn.dataset.floor;
  document.getElementById('slot_id').value = selectedSlotId;
  const display = document.getElementById('selectedSlotDisplay');
  display.classList.add('has-slot');
  display.innerHTML = `<div class="selected-slot-code">${selectedSlotCode}</div><div class="selected-slot-sub">Floor ${selectedFloor} — selected</div>`;
  updateSubmitBtn();
}


function setMapLoading(show) {
  [1,2,3].forEach(function(f) {
    var ld = document.getElementById('map-loading-' + f);
    if (ld) ld.classList.toggle('show', show);
  });
}


function applyCountsFromPayload(data) {
  if (data.counts) {
    document.getElementById('cnt-available').textContent  = data.counts.available;
    document.getElementById('cnt-reserved').textContent   = data.counts.reserved;
    document.getElementById('cnt-occupied').textContent   = data.counts.occupied;
    document.getElementById('cnt-maintenance').textContent = data.counts.maintenance;
  } else {
    updateCounts();
  }
}


function fetchSlotMap(dateVal, showLoading) {
  return fetch('ajax/get-slot-availability.php?date=' + encodeURIComponent(dateVal), { cache: 'no-store' })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      applyAvailability(data.reservedOnDate || [], data.physicalStatuses || {});
      applyCountsFromPayload(data);
    })
    .catch(function() { /* keep map as-is on network error */ })
    .finally(function() {
      if (showLoading) setMapLoading(false);
    });
}


function onDateChange(dateVal) {
  currentDate = dateVal;
  var badge = document.getElementById('mapDateBadge');
  if (dateVal === TODAY) {
    badge.textContent = 'Showing: Today';
  } else {
    var d = new Date(dateVal + 'T00:00:00');
    badge.textContent = 'Showing: ' + d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  }


  if (selectedSlotId) {
    var prev = document.getElementById('slot-btn-' + selectedSlotId);
    if (prev) { prev.classList.remove('selected'); prev.classList.add('available'); }
    selectedSlotId = null; selectedSlotCode = null; selectedFloor = null;
    document.getElementById('slot_id').value = '';
    var display = document.getElementById('selectedSlotDisplay');
    display.classList.remove('has-slot');
    display.innerHTML = '<p class="selected-slot-placeholder">No slot selected yet</p>';
    updateSubmitBtn();
  }


  setMapLoading(true);
  fetchSlotMap(dateVal, true);
}


function restoreSlotSelection() {
  if (!canSelectSlots || !selectedSlotId) return;
  var btn = document.getElementById('slot-btn-' + selectedSlotId);
  if (btn && btn.dataset.status === 'available') {
    btn.classList.remove('available');
    btn.classList.add('selected');
    btn.disabled = false;
    btn.onclick = function() { selectSlot(this); };
  } else {
    selectedSlotId = null; selectedSlotCode = null; selectedFloor = null;
    var hid = document.getElementById('slot_id');
    if (hid) hid.value = '';
    var display = document.getElementById('selectedSlotDisplay');
    if (display) {
      display.classList.remove('has-slot');
      display.innerHTML = '<p class="selected-slot-placeholder">No slot selected yet</p>';
    }
    updateSubmitBtn();
  }
}


function applyAvailability(reservedSlotIds, physicalStatuses) {
  reservedSlotIds  = reservedSlotIds  || [];
  physicalStatuses = physicalStatuses || {};
  document.querySelectorAll('.slot-btn').forEach(function(btn) {
    var id       = btn.dataset.id;
    var physical = physicalStatuses[id] || physicalStatuses[String(id)] || 'available';
    var newStatus = physical;
    if (physical === 'available' && reservedSlotIds.indexOf(parseInt(id, 10)) !== -1) {
      newStatus = 'reserved';
    }
    btn.classList.remove('available','reserved','occupied','maintenance','selected');
    btn.classList.add(newStatus);
    btn.dataset.status = newStatus;


    var tipMap = {
      available:   btn.dataset.code + ' — Click to select',
      reserved:    btn.dataset.code + ' — Reserved on this date',
      occupied:    btn.dataset.code + ' — Occupied',
      maintenance: btn.dataset.code + ' — Under maintenance',
    };
    btn.setAttribute('data-tip', tipMap[newStatus] || btn.dataset.code);


    var canClick = (newStatus === 'available') && canSelectSlots;
    if (canClick) {
      btn.disabled = false;
      btn.onclick  = function() { selectSlot(this); };
    } else {
      btn.disabled = true;
      btn.onclick  = null;
    }
  });
  restoreSlotSelection();
}


function updateCounts() {
  const counts = { available:0, reserved:0, occupied:0, maintenance:0 };
  document.querySelectorAll('.slot-btn').forEach(btn => {
    const s = btn.dataset.status;
    if (counts.hasOwnProperty(s)) counts[s]++;
  });
  document.getElementById('cnt-available').textContent   = counts.available;
  document.getElementById('cnt-reserved').textContent    = counts.reserved;
  document.getElementById('cnt-occupied').textContent    = counts.occupied;
  document.getElementById('cnt-maintenance').textContent = counts.maintenance;
}


function updateSubmitBtn() {
  const btn = document.getElementById('submitBtn');
  if (!btn) return;
  btn.disabled = !selectedSlotId;
}


function stopPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
}


function startPolling() {
  stopPolling();
  if (document.hidden) return;
  var dateInput = document.getElementById('arrival_date');
  if (!dateInput) return;
  pollTimer = setInterval(function() {
    currentDate = dateInput.value;
    fetchSlotMap(currentDate, false);
  }, POLL_MS);
}


document.addEventListener('visibilitychange', function() {
  if (document.hidden) {
    stopPolling();
  } else {
    var dateInput = document.getElementById('arrival_date');
    if (dateInput) {
      currentDate = dateInput.value;
      fetchSlotMap(currentDate, false);
    }
    startPolling();
  }
});


const colorMap = {
  red:'#ef4444',blue:'#3b82f6',green:'#22c55e',yellow:'#eab308',
  white:'#f1f5f9',black:'#1f2937',silver:'#94a3b8',gray:'#6b7280',
  grey:'#6b7280',orange:'#f97316',purple:'#a855f7',brown:'#92400e',
  pink:'#ec4899',beige:'#d4b896',gold:'#d97706',maroon:'#991b1b',
  navy:'#1e3a5f',cyan:'#06b6d4',teal:'#14b8a6',violet:'#7c3aed',
};
function updateColorSwatch(val) {
  const swatch = document.getElementById('colorSwatch');
  const key    = val.trim().toLowerCase();
  swatch.style.background  = colorMap[key] || 'var(--surface3)';
  swatch.style.borderColor = colorMap[key] ? colorMap[key] : 'var(--border2)';
}
</script>


</body>
</html>

