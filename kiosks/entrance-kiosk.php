<?php
// kiosks/entrance-kiosk.php
require_once __DIR__ . '/includes/kiosk-auth.php';
require_once __DIR__ . '/includes/kiosk-helpers.php';

/** Step 6 (Done): QR image in site /images/ — set only the filename when deployed (e.g. 'parky-qr.png'). Empty = dashed placeholder. */
$done_step_qr_filename = 'parky-qr.png';

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    switch ($_GET['action']) {

        // ── Slot counts for welcome screen ──────────────────────────────────
        case 'counts':
            echo json_encode(['success' => true, 'counts' => countSlotsByStatus($pdo)]);
            exit;

        // ── Slots for a given floor ──────────────────────────────────────────
        case 'slots':
            $floor = (int)($_GET['floor'] ?? 1);
            echo json_encode(['success' => true, 'slots' => getSlotsByFloor($pdo, $floor)]);
            exit;

        // ── Plate check ──────────────────────────────────────────────────────
        // FIX: Checks for an active session FIRST (duplicate guard), then
        //      checks for a valid reservation using the widened time window in
        //      checkReservationByPlate(). Returns full car_type / car_color /
        //      slot details so the JS can pre-populate Step 3 and skip Step 4.
        case 'check_plate':
            $plate = strtoupper(trim($_GET['plate'] ?? ''));
            if (!$plate) {
                echo json_encode(['found' => false, 'message' => 'No plate provided.']);
                exit;
            }

            // Guard: already parked?
            $active = getActiveSessionByPlate($pdo, $plate);
            if ($active) {
                echo json_encode([
                    'found'   => false,
                    'message' => "Plate <strong>{$plate}</strong> already has an active parking session.",
                ]);
                exit;
            }

            // Check for a valid reservation
            $reservation = checkReservationByPlate($pdo, $plate);

            echo json_encode([
                'found'       => true,
                'is_reserved' => (bool)$reservation,
                'reservation' => $reservation,   // full row including car_type, car_color, slot_code, floor
            ]);
            exit;

        // ── TF detector proxy ────────────────────────────────────────────────
        case 'detect':
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            @set_time_limit(180);
            $frameData = $_POST['frame'] ?? '';
            if (!$frameData) {
                echo json_encode(['success' => false, 'message' => 'No frame data.']);
                exit;
            }
            echo json_encode(callTfDetector($frameData));
            exit;

        // ── Confirm entry ────────────────────────────────────────────────────
        // FIX 1: Reserved path now sets slot status to 'occupied' (not 'reserved').
        // FIX 2: Walk-in path uses $userId if provided (links account plate scans).
        // FIX 3: Guard against creating a walk-in session when the plate already
        //        has a pending/confirmed reservation — forces the reserved path.
        case 'confirm_entry':
            $plate      = strtoupper(trim($_POST['plate']        ?? ''));
            $carType    = trim($_POST['car_type']                ?? 'Sedan');
            $carColor   = trim($_POST['car_color']               ?? 'Unknown');
            $slotId     = (int)($_POST['slot_id']                ?? 0);
            $isReserved = ($_POST['is_reserved']                 ?? '0') === '1';
            $resId      = (int)($_POST['reservation_id']         ?? 0);
            $userId     = (int)($_POST['user_id']                ?? 0);

            if (!$plate || !$slotId) {
                echo json_encode(['success' => false, 'message' => 'Missing plate or slot.']);
                exit;
            }

            // Safety guard: if the front-end somehow sent is_reserved=0 but
            // there IS a valid reservation for this plate, enforce reserved path.
            if (!$isReserved) {
                $safetyRes = checkReservationByPlate($pdo, $plate);
                if ($safetyRes) {
                    $isReserved = true;
                    $resId      = (int)$safetyRes['id'];
                    $slotId     = (int)$safetyRes['slot_id'];
                    $userId     = (int)$safetyRes['user_id'];
                    $carType    = $safetyRes['car_type']  ?: $carType;
                    $carColor   = $safetyRes['car_color'] ?: $carColor;
                }
            }

            // Verify the target slot
            $slotStmt = $pdo->prepare("SELECT * FROM parking_slots WHERE id = ?");
            $slotStmt->execute([$slotId]);
            $slot = $slotStmt->fetch(PDO::FETCH_ASSOC);

            if (!$slot) {
                echo json_encode(['success' => false, 'message' => 'Slot not found.']);
                exit;
            }

            // For reserved path the slot should be 'reserved'; for walk-in 'available'.
            if ($isReserved) {
                if (!in_array($slot['status'], ['reserved', 'available'])) {
                    echo json_encode(['success' => false, 'message' => 'Reserved slot is no longer available.']);
                    exit;
                }
            } else {
                if ($slot['status'] !== 'available') {
                    echo json_encode(['success' => false, 'message' => 'Slot is no longer available.']);
                    exit;
                }
            }

            try {
                $pdo->beginTransaction();

                if ($isReserved && $resId) {
                    // ── RESERVED PATH ────────────────────────────────────────
                    // Activate the reservation and mark slot occupied.
                    // arrival_time = NOW() records the actual scan time.
                    $pdo->prepare("
                        UPDATE reservations
                        SET status = 'active',
                            arrival_time = NOW(),
                            car_type  = ?,
                            car_color = ?
                        WHERE id = ?
                    ")->execute([$carType, $carColor, $resId]);

                    // FIX: slot must become 'occupied', not 'reserved'
                    $pdo->prepare("
                        UPDATE parking_slots SET status = 'occupied' WHERE id = ?
                    ")->execute([$slotId]);

                } else {
                    // ── WALK-IN PATH ─────────────────────────────────────────
                    $uid = $userId > 0 ? $userId : null;
                    $pdo->prepare("
                        INSERT INTO sessions_walkin
                            (user_id, plate_number, slot_id, entry_time,
                             car_type, car_color, payment_status, status)
                        VALUES (?, ?, ?, NOW(), ?, ?, 'unpaid', 'active')
                    ")->execute([$uid, $plate, $slotId, $carType, $carColor]);

                    $pdo->prepare("
                        UPDATE parking_slots SET status = 'occupied' WHERE id = ?
                    ")->execute([$slotId]);
                }

                $pdo->commit();

                echo json_encode([
                    'success'    => true,
                    'slot_code'  => $slot['slot_code'],
                    'floor'      => $slot['floor'],
                    'entry_time' => date('h:i A'),
                    'plate'      => $plate,
                    'car_type'   => $carType,
                    'car_color'  => $carColor,
                    'is_reserved' => $isReserved,
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
            }
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parky — Entrance Kiosk</title>
          <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg:#f5f7f5; --bg2:#ffffff; --surface:#f0f4f0; --surface2:#e8efe8;
            --border:rgba(0,0,0,0.07); --border2:rgba(0,0,0,0.11);
            --text:#1a1e1a; --muted:#5a6e5a; --muted2:#8a9e8a;
            --emerald:#10b981; --emerald2:#059669;
            --emeraldbg:rgba(16,185,129,0.08); --emeraldbg2:rgba(16,185,129,0.15);
            --danger:#ef4444; --dangerbg:rgba(239,68,68,0.08);
            --warning:#f59e0b; --warningbg:rgba(245,158,11,0.08);
            --info:#3b82f6; --infobg:rgba(59,130,246,0.08);
            --shadow:0 1px 3px rgba(0,0,0,0.08),0 4px 12px rgba(0,0,0,0.04);
        }
        body { font-family:'Nunito',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }

        /* NAV */
        nav { height:60px; background:var(--bg2); border-bottom:1px solid var(--border2); display:flex; align-items:center; padding:0 2rem; gap:1rem; box-shadow:var(--shadow); position:sticky; top:0; z-index:50; }
        .nav-logo { display:flex; align-items:center; gap:9px; text-decoration:none; margin-right:auto; }
        .nav-logo-icon { width:34px; height:34px; background:var(--emeraldbg2); border:1.5px solid rgba(16,185,129,0.3); border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:0.9rem; font-weight:900; color:var(--emerald); }
        .nav-logo-text { font-size:1.1rem; font-weight:900; color:var(--emerald); letter-spacing:-0.02em; }
        .nav-badge { background:var(--emeraldbg); border:1px solid rgba(16,185,129,0.25); color:var(--emerald); font-size:0.72rem; font-weight:800; padding:4px 12px; border-radius:20px; }
        .nav-live { display:flex; align-items:center; gap:6px; font-size:0.78rem; font-weight:700; color:var(--muted); }
        .live-dot { width:7px; height:7px; background:var(--emerald); border-radius:50%; animation:pulse 2s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.4);} }
        .nav-time { font-size:0.85rem; font-weight:800; color:var(--text); background:var(--surface); border:1px solid var(--border2); border-radius:8px; padding:5px 12px; font-variant-numeric:tabular-nums; }

        /* STEP BAR */
        .stepbar { background:var(--bg2); border-bottom:1px solid var(--border); padding:0 2rem; display:flex; align-items:center; height:52px; box-shadow:0 1px 4px rgba(0,0,0,0.04); }
        .step-item { display:flex; align-items:center; flex:1; }
        .step-node { display:flex; align-items:center; gap:8px; white-space:nowrap; }
        .step-num { width:26px; height:26px; border-radius:50%; border:2px solid var(--border2); display:flex; align-items:center; justify-content:center; font-size:0.72rem; font-weight:800; color:var(--muted); transition:all 0.3s; flex-shrink:0; }
        .step-num.active { border-color:var(--emerald); color:var(--emerald); background:var(--emeraldbg2); }
        .step-num.done { border-color:var(--emerald2); background:var(--emerald2); color:#fff; font-size:0.65rem; }
        .step-lbl { font-size:0.75rem; font-weight:700; color:var(--muted); transition:color 0.3s; }
        .step-lbl.active { color:var(--emerald); }
        .step-lbl.done { color:var(--emerald2); }
        .step-line { flex:1; height:2px; background:var(--border2); margin:0 10px; border-radius:2px; transition:background 0.3s; }
        .step-line.done { background:var(--emerald2); }

        .page { max-width:1000px; margin:0 auto; padding:2rem 1.5rem 4rem; }

        /* VOICE BAR */
        .voice-bar { background:var(--bg2); border:1px solid var(--border2); border-radius:12px; padding:10px 16px; display:flex; align-items:center; gap:12px; margin-bottom:1.5rem; box-shadow:var(--shadow); }
        .voice-indicator { width:32px; height:32px; border-radius:50%; background:var(--surface); border:2px solid var(--border2); display:flex; align-items:center; justify-content:center; transition:all 0.3s; flex-shrink:0; }
        .voice-indicator svg { width:14px; height:14px; stroke:var(--muted); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .voice-indicator.listening { background:var(--emeraldbg2); border-color:var(--emerald); animation:voicePulse 1s ease-in-out infinite; }
        .voice-indicator.listening svg { stroke:var(--emerald); }
        @keyframes voicePulse { 0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,0.35);}50%{box-shadow:0 0 0 8px rgba(16,185,129,0);} }
        .voice-status { font-size:0.8rem; font-weight:700; color:var(--muted); flex:1; }
        .voice-transcript { font-size:0.78rem; font-weight:600; color:var(--emerald); font-style:italic; }
        .voice-chips { display:flex; gap:6px; flex-wrap:wrap; }
        .v-chip { font-size:0.72rem; font-weight:700; color:var(--emerald); background:var(--emeraldbg); border:1px solid rgba(16,185,129,0.2); border-radius:20px; padding:3px 10px; }

        /* CARD */
        .card { background:var(--bg2); border:1px solid var(--border2); border-radius:16px; padding:1.75rem; box-shadow:var(--shadow); }
        .card-title { font-size:0.72rem; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:1.25rem; display:flex; align-items:center; gap:8px; }
        .card-title svg { width:14px; height:14px; stroke:var(--muted); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }

        /* WELCOME */
        .welcome-wrap { text-align:center; padding:3rem 2rem; }
        .welcome-icon { width:72px; height:72px; background:var(--emeraldbg2); border:2px solid rgba(16,185,129,0.3); border-radius:20px; display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; font-size:1.8rem; font-weight:900; color:var(--emerald); }
        .welcome-title { font-size:2.2rem; font-weight:900; letter-spacing:-0.03em; color:var(--text); margin-bottom:6px; }
        .welcome-sub { font-size:0.92rem; color:var(--muted); font-weight:500; margin-bottom:2rem; }
        .counts-row { display:flex; gap:1rem; justify-content:center; flex-wrap:wrap; margin-bottom:2rem; }
        .count-box { background:var(--bg2); border:1px solid var(--border2); border-radius:14px; padding:1.25rem 2rem; box-shadow:var(--shadow); text-align:center; min-width:120px; }
        .count-num { font-size:2rem; font-weight:900; line-height:1; }
        .count-num.green { color:var(--emerald); } .count-num.red { color:var(--danger); } .count-num.yellow { color:var(--warning); }
        .count-lbl { font-size:0.72rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; margin-top:5px; }
        .rate-chips { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-bottom:2rem; }
        .rate-chip { background:var(--surface); border:1px solid var(--border2); border-radius:10px; padding:8px 16px; font-size:0.8rem; font-weight:700; color:var(--muted); }
        .rate-chip span { color:var(--emerald); font-weight:800; }
        .btn-begin { display:inline-flex; align-items:center; gap:10px; background:var(--emerald); color:#fff; font-family:'Nunito',sans-serif; font-size:1rem; font-weight:800; border:none; border-radius:14px; padding:14px 32px; cursor:pointer; transition:background 0.2s,transform 0.1s; box-shadow:0 4px 14px rgba(16,185,129,0.3); }
        .btn-begin:hover { background:var(--emerald2); }
        .btn-begin:active { transform:scale(0.97); }
        .btn-begin svg { width:18px; height:18px; stroke:#fff; fill:none; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; }
        .voice-hint { font-size:0.8rem; color:var(--muted); font-weight:600; margin-top:1rem; }
        .voice-hint span { color:var(--emerald); font-weight:800; }

        /* SCAN */
        .scan-grid { display:grid; grid-template-columns:2fr 1fr; gap:1.25rem; }
        .cam-wrap { background:#000; border-radius:14px; overflow:hidden; position:relative; aspect-ratio:16/10; transition:box-shadow 0.3s, outline 0.3s; }
        .cam-wrap video { width:100%; height:100%; object-fit:cover; display:block; }
        .cam-wrap.scanning { outline:2.5px solid #fbbf24; outline-offset:-2px; box-shadow:0 0 0 4px rgba(251,191,36,0.15); }
        .cam-wrap.found    { outline:2.5px solid var(--emerald); outline-offset:-2px; box-shadow:0 0 0 4px rgba(16,185,129,0.2); }
        .cam-placeholder { display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; gap:8px; color:var(--muted2); }
        .cam-placeholder svg { width:48px; height:48px; stroke:var(--muted2); fill:none; stroke-width:1.5; stroke-linecap:round; stroke-linejoin:round; }
        .cam-placeholder p { font-size:0.85rem; font-weight:600; }
        .scan-status-bar { position:absolute; bottom:0; left:0; right:0; background:linear-gradient(transparent, rgba(0,0,0,0.72)); padding:12px 14px 10px; display:flex; align-items:center; justify-content:space-between; }
        .scan-status-text { font-size:0.72rem; font-weight:800; color:#fff; letter-spacing:0.8px; text-transform:uppercase; }
        .scan-pulse { width:8px; height:8px; border-radius:50%; background:var(--emerald); flex-shrink:0; }
        .scan-pulse.active   { animation:pulse 1.4s infinite; }
        .scan-pulse.scanning { background:#fbbf24; animation:pulse 0.7s infinite; }
        .scan-pulse.idle     { background:#6b7280; animation:none; }
        .analyzing-overlay { position:absolute; inset:0; background:rgba(0,0,0,0.55); display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; }
        .analyzing-text { font-size:0.85rem; font-weight:800; color:var(--emerald); letter-spacing:2px; animation:blink 1s infinite; }
        @keyframes blink { 0%,100%{opacity:1;}50%{opacity:0.3;} }
        .btn-scan-manual { width:100%; background:var(--surface); color:var(--muted); border:1.5px solid var(--border2); border-radius:11px; padding:10px; font-family:'Nunito',sans-serif; font-size:0.85rem; font-weight:800; cursor:pointer; transition:all 0.2s; margin-top:8px; display:flex; align-items:center; justify-content:center; gap:8px; }
        .btn-scan-manual:hover { background:var(--surface2); color:var(--text); }
        .btn-scan-manual:disabled { opacity:0.5; cursor:not-allowed; }
        .btn-scan-manual svg { width:14px; height:14px; stroke:currentColor; fill:none; stroke-width:2.5; stroke-linecap:round; }
        .scan-result-panel { display:flex; flex-direction:column; gap:10px; }
        .detect-row { background:var(--surface); border:1px solid var(--border2); border-radius:10px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between; }
        .dr-lbl { font-size:0.7rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }
        .dr-val { font-size:0.88rem; font-weight:800; color:var(--text); }
        .dr-val.highlight { color:var(--emerald); font-size:1.05rem; letter-spacing:0.04em; }
        .conf-bar-wrap { display:flex; align-items:center; gap:8px; }
        .conf-bar { flex:1; height:5px; background:var(--surface2); border-radius:3px; overflow:hidden; }
        .conf-bar-fill { height:100%; background:var(--emerald); border-radius:3px; transition:width 0.4s; }
        .conf-val { font-size:0.68rem; font-weight:700; color:var(--muted); width:32px; text-align:right; }
        .manual-section { margin-top:10px; }
        .manual-label { font-size:0.72rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:6px; }
        .field-input-wrap { position:relative; margin-bottom:8px; }
        .field-input { background:var(--surface); border:1.5px solid var(--border2); border-radius:10px; padding:10px 40px 10px 14px; font-family:'Nunito',sans-serif; font-size:0.92rem; font-weight:700; color:var(--text); outline:none; transition:border-color 0.2s; width:100%; text-transform:uppercase; }
        .field-input:focus { border-color:var(--emerald); }
        .field-input.normal { text-transform:none; }
        .field-select { background:var(--surface); border:1.5px solid var(--border2); border-radius:10px; padding:10px 14px; font-family:'Nunito',sans-serif; font-size:0.92rem; font-weight:700; color:var(--text); outline:none; width:100%; cursor:pointer; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%235a6e5a' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; transition:border-color 0.2s; }
        .field-select:focus { border-color:var(--emerald); }
        .field-stt-btn { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; padding:4px; }
        .field-stt-btn svg { width:16px; height:16px; stroke:var(--muted); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; transition:stroke 0.2s; }
        .field-stt-btn:hover svg, .field-stt-btn.active svg { stroke:var(--emerald); }
        .field-group { display:flex; flex-direction:column; gap:5px; }
        .field-label { font-size:0.72rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }

        /* CONFIRM */
        .confirm-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.25rem; }
        .vehicle-type-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; }
        @media (max-width:720px) { .vehicle-type-grid { grid-template-columns:repeat(2, 1fr); } }
        .vehicle-type-btn {
            font-family:'Nunito',sans-serif; font-size:0.88rem; font-weight:800;
            background:var(--surface); color:var(--text);
            border:1.5px solid var(--border2); border-radius:11px;
            padding:14px 12px; cursor:pointer; transition:all 0.2s;
            text-align:center;
        }
        .vehicle-type-btn:hover { background:var(--surface2); border-color:rgba(16,185,129,0.35); }
        .vehicle-type-btn.selected {
            background:var(--emeraldbg); border-color:rgba(16,185,129,0.45); color:var(--emerald);
            box-shadow:0 0 0 2px rgba(16,185,129,0.2);
        }
        .vehicle-type-btn:focus { outline:2px solid var(--emerald); outline-offset:2px; }
        .vehicle-type-btn:focus:not(:focus-visible) { outline:none; }
        .reservation-notice { background:var(--emeraldbg); border:1px solid rgba(16,185,129,0.2); border-radius:12px; padding:12px 16px; display:flex; align-items:center; gap:12px; margin-bottom:1rem; }
        .rn-icon { width:36px; height:36px; background:var(--emeraldbg2); border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .rn-icon svg { width:16px; height:16px; stroke:var(--emerald); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .rn-text h4 { font-size:0.85rem; font-weight:800; color:var(--emerald); margin-bottom:2px; }
        .rn-text p { font-size:0.78rem; color:var(--muted); font-weight:500; }

        /* SLOTS */
        .floor-tabs { display:flex; gap:6px; margin-bottom:1rem; }
        .floor-tab { flex:1; text-align:center; font-size:0.8rem; font-weight:700; color:var(--muted); background:var(--surface); border:1.5px solid var(--border2); border-radius:9px; padding:8px; cursor:pointer; transition:all 0.2s; }
        .floor-tab.active { color:var(--emerald); background:var(--emeraldbg); border-color:rgba(16,185,129,0.3); }
        .slot-legend { display:flex; gap:12px; margin-bottom:10px; flex-wrap:wrap; }
        .leg-item { display:flex; align-items:center; gap:5px; font-size:0.72rem; font-weight:600; color:var(--muted); }
        .ldot { width:9px; height:9px; border-radius:2px; }
        .ldot.available { background:var(--emerald); } .ldot.occupied { background:var(--danger); } .ldot.reserved { background:var(--warning); } .ldot.selected { background:#3b82f6; }
        .slot-grid-wrap { overflow-x:auto; }
        .slot-col-labels { display:grid; grid-template-columns:22px repeat(10,1fr); gap:3px; margin-bottom:3px; }
        .slot-col-lbl { font-size:0.58rem; font-weight:700; color:var(--muted2); text-align:center; }
        .slot-row-wrap { display:grid; grid-template-columns:22px repeat(10,1fr); gap:3px; margin-bottom:3px; }
        .slot-row-lbl { font-size:0.62rem; font-weight:700; color:var(--muted2); display:flex; align-items:center; justify-content:center; }
        .slot-cell { aspect-ratio:1; border-radius:4px; display:flex; align-items:center; justify-content:center; font-size:0.5rem; font-weight:700; border:1.5px solid transparent; cursor:default; transition:transform 0.12s; }
        .slot-cell:hover { transform:scale(1.12); position:relative; z-index:2; }
        .slot-cell.available   { background:rgba(16,185,129,0.12); border-color:rgba(16,185,129,0.35); color:var(--emerald); cursor:pointer; }
        .slot-cell.occupied    { background:rgba(239,68,68,0.08);  border-color:rgba(239,68,68,0.25);  color:var(--danger); }
        .slot-cell.reserved    { background:rgba(245,158,11,0.1);  border-color:rgba(245,158,11,0.3);  color:var(--warning); }
        .slot-cell.selected    { background:rgba(59,130,246,0.15); border-color:#3b82f6; color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,0.3); }
        .slot-cell.maintenance { background:var(--surface2); border-color:var(--border2); color:var(--muted2); }
        .slot-selected-info { background:var(--infobg); border:1px solid rgba(59,130,246,0.2); border-radius:10px; padding:10px 14px; margin-top:10px; font-size:0.82rem; font-weight:700; color:var(--info); display:none; }
        .slot-selected-info.show { display:block; }

        /* DIRECTIONS */
        .dir-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .dir-slot { background:var(--emeraldbg); border:2px solid rgba(16,185,129,0.25); border-radius:16px; padding:2rem; text-align:center; }
        .dir-floor-lbl { font-size:0.72rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px; }
        .dir-floor-num { font-size:5rem; font-weight:900; color:var(--emerald); line-height:1; letter-spacing:-0.04em; }
        .dir-slot-lbl { font-size:0.72rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; margin-top:8px; margin-bottom:4px; }
        .dir-slot-num { font-size:2rem; font-weight:900; color:var(--text); letter-spacing:0.02em; }
        .dir-details { display:flex; flex-direction:column; gap:8px; }
        .dir-row { background:var(--surface); border:1px solid var(--border2); border-radius:10px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; }
        .dir-row-lbl { font-size:0.7rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }
        .dir-row-val { font-size:0.9rem; font-weight:800; color:var(--text); }
        .tip-box { background:var(--warningbg); border:1px solid rgba(245,158,11,0.2); border-radius:10px; padding:10px 14px; margin-top:8px; }
        .tip-box p { font-size:0.78rem; font-weight:600; color:var(--warning); line-height:1.6; }
        .tip-box p strong { font-weight:800; }

        /* DONE */
        .success-wrap { padding:2.5rem 2rem; animation:fadeUp 0.4s cubic-bezier(0.22,1,0.36,1); }
        .done-layout { display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:center; gap:2rem 2.5rem; max-width:920px; margin:0 auto; }
        .done-main { flex:1 1 280px; text-align:center; min-width:0; }
        .done-qr-slot {
            width:180px; height:180px; flex:0 0 auto; align-self:center;
            border:2px dashed rgba(0,0,0,0.12); border-radius:14px;
            background:var(--surface); box-sizing:border-box;
            display:flex; align-items:center; justify-content:center; overflow:hidden;
        }
        .done-qr-slot img { width:100%; height:100%; object-fit:contain; display:block; }
        .done-qr-placeholder-label { font-size:0.65rem; font-weight:800; color:var(--muted2); text-transform:uppercase; letter-spacing:0.08em; padding:0 8px; text-align:center; line-height:1.35; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);} }
        .success-icon { width:72px; height:72px; background:var(--emeraldbg2); border:2px solid rgba(16,185,129,0.3); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem; }
        .success-icon svg { width:32px; height:32px; stroke:var(--emerald); fill:none; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; }
        .success-title { font-size:1.75rem; font-weight:900; letter-spacing:-0.03em; color:var(--text); margin-bottom:6px; }
        .success-sub { font-size:0.9rem; color:var(--muted); font-weight:500; margin-bottom:1.5rem; }
        .success-details { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-bottom:1.5rem; }
        .sdet { background:var(--surface); border:1px solid var(--border2); border-radius:10px; padding:10px 16px; text-align:center; }
        .sdet-lbl { font-size:0.65rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }
        .sdet-val { font-size:0.95rem; font-weight:800; color:var(--text); margin-top:3px; }
        .sdet-val.green { color:var(--emerald); }
        .reset-countdown { font-size:0.8rem; font-weight:700; color:var(--muted); }

        /* BUTTONS */
        .btn-row { display:flex; gap:10px; justify-content:flex-end; margin-top:1.5rem; padding-top:1.25rem; border-top:1px solid var(--border); }
        .btn { font-family:'Nunito',sans-serif; font-size:0.88rem; font-weight:800; border-radius:11px; padding:11px 22px; cursor:pointer; transition:all 0.2s; border:none; display:inline-flex; align-items:center; gap:7px; }
        .btn:active { transform:scale(0.97); }
        .btn svg { width:15px; height:15px; fill:none; stroke:currentColor; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; }
        .btn-primary { background:var(--emerald); color:#fff; box-shadow:0 4px 12px rgba(16,185,129,0.25); }
        .btn-primary:hover { background:var(--emerald2); }
        .btn-primary:disabled { background:var(--surface2); color:var(--muted); box-shadow:none; cursor:not-allowed; }
        .btn-ghost { background:var(--surface); color:var(--muted); border:1.5px solid var(--border2); }
        .btn-ghost:hover { background:var(--surface2); color:var(--text); }

        /* ALERTS */
        .alert { border-radius:10px; padding:10px 14px; display:flex; align-items:center; gap:10px; margin-bottom:10px; }
        .alert svg { width:16px; height:16px; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; }
        .alert p { font-size:0.8rem; font-weight:600; line-height:1.5; }
        .alert p strong { font-weight:800; }
        .alert.success { background:var(--emeraldbg); border:1px solid rgba(16,185,129,0.2); }
        .alert.success svg { stroke:var(--emerald); } .alert.success p { color:var(--muted); } .alert.success p strong { color:var(--emerald); }
        .alert.warning { background:var(--warningbg); border:1px solid rgba(245,158,11,0.2); }
        .alert.warning svg { stroke:var(--warning); } .alert.warning p { color:var(--muted); } .alert.warning p strong { color:var(--warning); }
        .alert.danger { background:var(--dangerbg); border:1px solid rgba(239,68,68,0.2); }
        .alert.danger svg { stroke:var(--danger); } .alert.danger p { color:var(--muted); } .alert.danger p strong { color:var(--danger); }

        @media (max-width:720px) { .scan-grid,.confirm-grid,.dir-grid { grid-template-columns:1fr; } .page { padding:1.25rem 1rem 3rem; } }
    </style>
</head>
<body>

<nav>
    <a class="nav-logo" href="#"><div class="nav-logo-icon">P</div><span class="nav-logo-text">Parky</span></a>
    <span class="nav-badge">Entrance Kiosk</span>
    <div class="nav-live" style="margin-left:auto;"><div class="live-dot"></div><span>Gate 1 — Active</span></div>
    <div class="nav-time" id="navTime">--:--:--</div>
</nav>

<div class="stepbar">
    <div class="step-item"><div class="step-node"><div class="step-num active" id="sn1">1</div><div class="step-lbl active" id="sl1">Welcome</div></div><div class="step-line" id="sc1"></div></div>
    <div class="step-item"><div class="step-node"><div class="step-num" id="sn2">2</div><div class="step-lbl" id="sl2">Scan</div></div><div class="step-line" id="sc2"></div></div>
    <div class="step-item"><div class="step-node"><div class="step-num" id="sn3">3</div><div class="step-lbl" id="sl3">Confirm</div></div><div class="step-line" id="sc3"></div></div>
    <div class="step-item"><div class="step-node"><div class="step-num" id="sn4">4</div><div class="step-lbl" id="sl4">Select Slot</div></div><div class="step-line" id="sc4"></div></div>
    <div class="step-item"><div class="step-node"><div class="step-num" id="sn5">5</div><div class="step-lbl" id="sl5">Directions</div></div><div class="step-line" id="sc5"></div></div>
    <div class="step-item" style="flex:0;"><div class="step-node"><div class="step-num" id="sn6">6</div><div class="step-lbl" id="sl6">Done</div></div></div>
</div>

<div class="page">
    <div class="voice-bar">
        <div class="voice-indicator" id="voiceIndicator">
            <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
        </div>
        <div style="flex:1;">
            <div class="voice-status" id="voiceStatus">Listening for voice commands…</div>
            <div class="voice-transcript" id="voiceTranscript"></div>
        </div>
        <div class="voice-chips" id="voiceChips"><span class="v-chip">"Begin"</span><span class="v-chip">"Start"</span></div>
    </div>
    <div id="screenWrap"></div>
</div>

<script>
const DONE_STEP_QR_URL = <?= json_encode(
    $done_step_qr_filename !== '' ? '../images/' . $done_step_qr_filename : '',
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;

// ── State ──────────────────────────────────────────────────────────────────────
// FIX: Added reservedCarType / reservedCarColor to pre-populate from the DB
const S = {
    step: 1,
    plate: '',
    carType: 'Sedan',
    carColor: '',
    isReserved: false,
    reservationId: 0,
    userId: 0,
    slotId: 0,
    slotCode: '',
    floor: 1,
    entryTime: '',
    // These hold the values that came from the reservations table
    reservedCarType: '',
    reservedCarColor: '',
};
const VEHICLE_TYPES = ['Sedan','SUV','Van','Hatchback','Pickup','Motorcycle'];

/** Map speech (word boundaries) to canonical vehicle type; longer phrases first. */
function matchVehicleTypeFromSpeech(t) {
    const low = String(t || '').toLowerCase().replace(/[^\w\s]/g, ' ').replace(/\s+/g, ' ').trim();
    if (!low) return null;
    const pairs = [
        [/\bhatch\s*back\b|\bhatchback\b/, 'Hatchback'],
        [/\bmotor\s*cycle\b|\bmotorcycle\b/, 'Motorcycle'],
        [/\bpickup\b|\bpick\s*up\b/, 'Pickup'],
        [/\bsedan\b/, 'Sedan'],
        [/\bsuv\b/, 'SUV'],
        [/\bvan\b/, 'Van'],
    ];
    for (const [re, canon] of pairs) {
        if (re.test(low)) return canon;
    }
    return VEHICLE_TYPES.find(vt => low.includes(vt.toLowerCase())) || null;
}

function selectVehicleType(type) {
    if (!VEHICLE_TYPES.includes(type)) return;
    S.carType = type;
    document.querySelectorAll('#cfTypeGrid .vehicle-type-btn').forEach(btn => {
        const on = btn.getAttribute('data-type') === type;
        btn.classList.toggle('selected', on);
        btn.setAttribute('aria-checked', on ? 'true' : 'false');
    });
}

const SCAN_INTERVAL_MS  = 2000;
const SCAN_COOLDOWN_MS  = 3000;
const SCAN_IMG_WIDTH    = 720;
const SCAN_JPEG_QUALITY = 0.88;

let camStream = null;
let autoScanTimer = null, autoScanBusy = false, autoScanPaused = false;
let lastDetectedPlate = '';
let recognition = null, recRunning = false, recPaused = false;
let fieldRec = null, resetTimer = null, allSlots = {};
let plateInterimTimer = null;

/** Uppercase plate, no spaces (e.g. ABC1234). */
function normalizePlateStr(s) {
    return String(s || '').toUpperCase().replace(/\s+/g, '').replace(/[^A-Z0-9]/g, '');
}

/** Spoken phrase → letters/digits only (expands digit words). */
function spokenToCompactAlnum(text) {
    let u = String(text).toUpperCase()
        .replace(/\b(ZERO|OH)\b/g, '0')
        .replace(/\bONE\b/g, '1').replace(/\bTWO\b/g, '2').replace(/\bTHREE\b/g, '3')
        .replace(/\bFOUR\b/g, '4').replace(/\bFIVE\b/g, '5').replace(/\bSIX\b/g, '6')
        .replace(/\bSEVEN\b/g, '7').replace(/\bEIGHT\b/g, '8').replace(/\bNINE\b/g, '9');
    return u.replace(/[^A-Z0-9]/g, '');
}

/** Find a valid PH-style plate substring inside compact alnum (handles "my plate is ABC 1234"). */
function extractPlateFromCompact(compact) {
    const patterns = [/^[A-Z]{3}\d{3,5}$/, /^[A-Z]{2}\d{4,5}$/, /^\d{3}[A-Z]{3}$/, /^[A-Z]\d{5,6}$/];
    let best = '';
    for (let i = 0; i < compact.length; i++) {
        for (let len = 12; len >= 5; len--) {
            if (i + len > compact.length) continue;
            const slice = compact.slice(i, i + len);
            if (patterns.some(p => p.test(slice)) && slice.length >= best.length) best = slice;
        }
    }
    return best;
}

// ── Clock ──────────────────────────────────────────────────────────────────────
setInterval(() => {
    document.getElementById('navTime').textContent =
        new Date().toLocaleTimeString('en-PH', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
}, 1000);

// ── TTS ────────────────────────────────────────────────────────────────────────
function speak(text, onEnd) {
    if (!window.speechSynthesis) { resumeRec(); if (onEnd) onEnd(); return; }
    window.speechSynthesis.cancel(); pauseRec();
    const utt = new SpeechSynthesisUtterance(text);
    utt.lang = 'en-PH'; utt.rate = 0.93;
    const voices = window.speechSynthesis.getVoices();
    const v = voices.find(v => v.lang === 'en-PH') || voices.find(v => v.lang.startsWith('en'));
    if (v) utt.voice = v;
    let utterDone = false;
    const done = () => {
        if (utterDone) return;
        utterDone = true;
        resumeRec();
        if (onEnd) onEnd();
    };
    utt.onend = done;
    utt.onerror = done;
    window.speechSynthesis.speak(utt);
}
window.speechSynthesis && (window.speechSynthesis.onvoiceschanged = () => window.speechSynthesis.getVoices());

// ── Step tracker ───────────────────────────────────────────────────────────────
function setStep(n) {
    if (n !== 2 && plateInterimTimer) { clearTimeout(plateInterimTimer); plateInterimTimer = null; }
    S.step = n; window.speechSynthesis && window.speechSynthesis.cancel();
    for (let i = 1; i <= 6; i++) {
        const num  = document.getElementById('sn' + i);
        const lbl  = document.getElementById('sl' + i);
        const line = document.getElementById('sc' + i);
        if (i < n)        { num.className='step-num done';   num.textContent='✓'; lbl.className='step-lbl done';   if (line) line.className='step-line done'; }
        else if (i === n) { num.className='step-num active'; num.textContent=i;   lbl.className='step-lbl active'; }
        else              { num.className='step-num';        num.textContent=i;   lbl.className='step-lbl';        if (line) line.className='step-line'; }
    }
    render();
}

function render() {
    const w     = document.getElementById('screenWrap');
    const chips = document.getElementById('voiceChips');
    switch (S.step) {
        case 1: renderWelcome(w);    chips.innerHTML = `<span class="v-chip">"Begin"</span><span class="v-chip">"Start"</span>`; break;
        case 2: renderScan(w);       chips.innerHTML = `<span class="v-chip">"Scan"</span><span class="v-chip">Plate number</span><span class="v-chip">"Continue"</span>`; break;
        case 3: renderConfirm(w);    chips.innerHTML = `<span class="v-chip">"Confirm"</span><span class="v-chip">"Back"</span>${VEHICLE_TYPES.map(t => `<span class="v-chip">"${t}"</span>`).join('')}<span class="v-chip">Color name</span>`; break;
        case 4: renderSlots(w);      chips.innerHTML = `<span class="v-chip">"2C7"</span><span class="v-chip">"3A1"</span><span class="v-chip">"Auto"</span>`; break;
        case 5: renderDirections(w); chips.innerHTML = `<span class="v-chip">"Done"</span><span class="v-chip">"Repeat"</span>`; break;
        case 6: renderDone(w);       chips.innerHTML = `<span class="v-chip">"Done"</span>`; break;
    }
}

// ── STEP 1: Welcome ────────────────────────────────────────────────────────────
function renderWelcome(w) {
    w.innerHTML = `
    <div class="card"><div class="welcome-wrap">
        <div class="welcome-icon">P</div>
        <h1 class="welcome-title">Welcome to Parky</h1>
        <p class="welcome-sub">Smart Parking System · Entrance Kiosk · Gate 1</p>
        <div class="counts-row">
            <div class="count-box"><div class="count-num green" id="wAvail">—</div><div class="count-lbl">Available</div></div>
            <div class="count-box"><div class="count-num red"   id="wOccup">—</div><div class="count-lbl">Occupied</div></div>
            <div class="count-box"><div class="count-num yellow" id="wRes">—</div><div class="count-lbl">Reserved</div></div>
        </div>
        <div class="rate-chips">
            <div class="rate-chip">First 3 hrs: <span>₱60 flat</span></div>
            <div class="rate-chip">Grace (15 min): <span>+₱10</span></div>
            <div class="rate-chip">Each extra hr: <span>+₱30</span></div>
        </div>
        <button class="btn-begin" onclick="beginSession()">
            <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            Begin Session
        </button>
        <p class="voice-hint">After the welcome message, say <span>"Begin"</span> or <span>"Start"</span> (mic is off while the kiosk speaks)</p>
    </div></div>`;
    loadCounts();
    speak('Welcome to Parky. Please tap Begin or say Begin to start your parking session.');
}

async function loadCounts() {
    try {
        const d = await (await fetch('?action=counts')).json();
        if (d.counts) {
            const a  = document.getElementById('wAvail');
            const o  = document.getElementById('wOccup');
            const rs = document.getElementById('wRes');
            if (a) a.textContent  = d.counts.available;
            if (o) o.textContent  = d.counts.occupied;
            if (rs) rs.textContent = d.counts.reserved;
        }
    } catch(e) {}
}

function beginSession() {
    speak('Starting vehicle scan. Point your plate toward the camera.', () => setStep(2));
}

// ── STEP 2: Scan ───────────────────────────────────────────────────────────────
function renderScan(w) {
    lastDetectedPlate = '';
    w.innerHTML = `
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
            Vehicle Scan — Plate Detection
        </div>
        <div class="scan-grid">
            <div>
                <div class="cam-wrap" id="camWrap">
                    <div class="cam-placeholder" id="camPlaceholder">
                        <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        <p>Camera initializing…</p>
                    </div>
                    <div class="scan-status-bar" id="scanStatusBar" style="display:none;">
                        <span class="scan-status-text" id="scanStatusText">Starting…</span>
                        <span class="scan-pulse active" id="scanPulse"></span>
                    </div>
                </div>
                <button class="btn-scan-manual" id="manualScanBtn" onclick="doManualScan()">
                    <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Scan Now (manual trigger)
                </button>
            </div>
            <div>
                <div class="scan-result-panel">
                    <div class="detect-row">
                        <span class="dr-lbl">Detected Plate</span>
                        <span class="dr-val highlight" id="dPlate">—</span>
                    </div>
                    <div class="detect-row" id="confRow" style="display:none;">
                        <span class="dr-lbl">Confidence</span>
                        <div class="conf-bar-wrap">
                            <div class="conf-bar"><div class="conf-bar-fill" id="confFill" style="width:0%"></div></div>
                            <span class="conf-val" id="confVal">0%</span>
                        </div>
                    </div>
                </div>
                <div class="manual-section">
                    <div class="manual-label">Or enter plate manually</div>
                    <div class="field-input-wrap">
                        <input type="text" class="field-input" id="manPlate"
                               placeholder="e.g. ABC1234" maxlength="12"
                               oninput="this.value=this.value.toUpperCase().replace(/\\s+/g,'')"
                               onkeydown="if(event.key==='Enter')continueToConfirm()">
                        <button class="field-stt-btn" id="sttManPlate"
                                onclick="toggleSTT('manPlate','sttManPlate','plate')" title="Speak plate number">
                            <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/></svg>
                        </button>
                    </div>
                    <p style="font-size:0.7rem;color:var(--muted2);font-weight:600;margin-top:4px;">
                        Point your plate at the camera — auto-scan runs every 2 seconds.
                    </p>
                </div>
                <div id="scanError" style="margin-top:10px;"></div>
            </div>
        </div>
        <div class="btn-row">
            <button class="btn btn-ghost" onclick="goBackStep1()"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Back</button>
            <button class="btn btn-primary" onclick="continueToConfirm()">Continue <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>
        </div>
    </div>`;
    initCamera();
}

function goBackStep1() { stopAutoScan(); stopCamera(); setStep(1); }

async function initCamera() {
    if (!navigator.mediaDevices?.getUserMedia) {
        document.querySelector('.cam-placeholder p').textContent = 'Camera not supported — use manual entry';
        return;
    }
    try {
        camStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
        });
        const vid = document.createElement('video');
        vid.id = 'camVideo'; vid.autoplay = true; vid.playsInline = true; vid.muted = true;
        vid.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
        vid.srcObject = camStream;
        document.getElementById('camPlaceholder').style.display = 'none';
        document.getElementById('camWrap').insertBefore(vid, document.getElementById('camWrap').firstChild);
        document.getElementById('scanStatusBar').style.display = 'flex';
        vid.onloadedmetadata = () => startAutoScan();
    } catch(e) {
        document.querySelector('.cam-placeholder p').textContent = 'Camera unavailable — use manual entry';
    }
}

function captureFrame(vid, quality) {
    quality = quality || SCAN_JPEG_QUALITY;
    const canvas = document.createElement('canvas');
    const scale  = Math.min(1, SCAN_IMG_WIDTH / (vid.videoWidth || 640));
    canvas.width  = Math.round((vid.videoWidth  || 640) * scale);
    canvas.height = Math.round((vid.videoHeight || 480) * scale);
    canvas.getContext('2d').drawImage(vid, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL('image/jpeg', quality);
}

function setScanStatus(state, text) {
    const st = document.getElementById('scanStatusText');
    const sp = document.getElementById('scanPulse');
    const cw = document.getElementById('camWrap');
    if (st) st.textContent = text;
    if (sp) { sp.className = 'scan-pulse'; if (state==='active') sp.classList.add('active'); else if (state==='scanning') sp.classList.add('scanning'); else sp.classList.add('idle'); }
    if (cw) { cw.className = 'cam-wrap'; if (state==='scanning') cw.classList.add('scanning'); else if (state==='found') cw.classList.add('found'); }
}

function startAutoScan() {
    stopAutoScan();
    autoScanBusy = false; autoScanPaused = false;
    setScanStatus('active', 'Auto-detecting…');
    autoScanTimer = setInterval(autoScanTick, SCAN_INTERVAL_MS);
}

function stopAutoScan() {
    if (autoScanTimer) { clearInterval(autoScanTimer); autoScanTimer = null; }
    autoScanBusy = false; autoScanPaused = false;
}

async function autoScanTick() {
    if (autoScanBusy || autoScanPaused || lastDetectedPlate) return;
    const vid = document.getElementById('camVideo');
    if (!vid || vid.readyState < 2) return;
    autoScanBusy = true;
    setScanStatus('scanning', 'Scanning…');
    try {
        const fd = new FormData();
        fd.append('frame', captureFrame(vid));
        const res  = await fetch('?action=detect', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success && data.plate) {
            const plate = normalizePlateStr(data.plate);
            lastDetectedPlate = plate;
            S.plate = plate;
            setScanStatus('found', 'Plate detected — tap Continue');
            updatePlateUI(plate, data.confidence || 0);
            speak(`Plate ${plate} detected. Tap Continue or say Continue.`);
        } else {
            autoScanPaused = true;
            setScanStatus('idle', 'No plate — retrying soon…');
            setTimeout(() => {
                autoScanPaused = false;
                if (S.step === 2 && !lastDetectedPlate) setScanStatus('active', 'Auto-detecting…');
            }, SCAN_COOLDOWN_MS);
        }
    } catch(e) {
        autoScanPaused = true;
        setScanStatus('idle', 'Error — retrying…');
        setTimeout(() => { autoScanPaused = false; }, SCAN_COOLDOWN_MS);
    } finally {
        autoScanBusy = false;
    }
}

function updatePlateUI(plate, confidence) {
    plate = normalizePlateStr(plate);
    const dp = document.getElementById('dPlate');
    if (dp) dp.textContent = plate;
    const mi = document.getElementById('manPlate');
    if (mi) mi.value = plate;
    document.getElementById('scanError').innerHTML = '';
    const pct = Math.round(confidence * 100);
    const cr = document.getElementById('confRow'); if (cr) cr.style.display = 'flex';
    const cf = document.getElementById('confFill'); if (cf) cf.style.width = pct + '%';
    const cv = document.getElementById('confVal'); if (cv) cv.textContent = pct + '%';
}

async function doManualScan() {
    const vid = document.getElementById('camVideo');
    if (!vid) { showScanError('Camera not available. Please enter plate manually.'); return; }
    if (autoScanBusy) return;
    stopAutoScan(); autoScanBusy = true;
    setScanStatus('scanning', 'Scanning…');
    document.getElementById('manualScanBtn').disabled = true;
    const overlay = document.createElement('div');
    overlay.className = 'analyzing-overlay'; overlay.id = 'analyzeOverlay';
    overlay.innerHTML = '<div class="analyzing-text">DETECTING PLATE…</div>';
    document.getElementById('camWrap').appendChild(overlay);
    try {
        const fd = new FormData();
        fd.append('frame', captureFrame(vid, 0.88));
        const data = await (await fetch('?action=detect', { method: 'POST', body: fd })).json();
        document.getElementById('analyzeOverlay')?.remove();
        if (data.success && data.plate) {
            const plate = normalizePlateStr(data.plate);
            lastDetectedPlate = plate; S.plate = plate;
            setScanStatus('found', 'Plate detected');
            updatePlateUI(plate, data.confidence || 0);
            speak(`Plate ${plate} detected.`);
        } else {
            showScanError('Plate not detected. Try again or enter manually.');
            setScanStatus('idle', 'Not detected — try again');
            setTimeout(() => { if (S.step === 2) startAutoScan(); }, 2500);
        }
    } catch(e) {
        document.getElementById('analyzeOverlay')?.remove();
        showScanError('Scan error. Please enter plate manually.');
        setTimeout(() => { if (S.step === 2) startAutoScan(); }, 2500);
    } finally {
        autoScanBusy = false;
        const b = document.getElementById('manualScanBtn');
        if (b) b.disabled = false;
    }
}

function showScanError(msg) {
    const el = document.getElementById('scanError');
    if (el) el.innerHTML = `<div class="alert danger"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><p>${msg}</p></div>`;
}

// ── FIX: continueToConfirm now properly reads the reservation's car details ───
async function continueToConfirm() {
    const manual = normalizePlateStr(document.getElementById('manPlate')?.value);
    if (manual) S.plate = manual;
    if (!S.plate) { showScanError('Please scan or enter a plate number first.'); return; }

    stopAutoScan();

    const d = await (await fetch(`?action=check_plate&plate=${encodeURIComponent(S.plate)}`)).json();
    if (!d.found) {
        showScanError(d.message || 'Could not verify plate.');
        lastDetectedPlate = '';
        S.plate = '';
        const dp = document.getElementById('dPlate'); if (dp) dp.textContent = '—';
        const mi = document.getElementById('manPlate'); if (mi) mi.value = '';
        startAutoScan();
        return;
    }

    S.isReserved = d.is_reserved;

    if (d.is_reserved && d.reservation) {
        // ── RESERVED: pre-populate all fields from the reservations row ──────
        S.reservationId   = d.reservation.id;
        S.userId          = d.reservation.user_id;
        S.slotId          = d.reservation.slot_id;
        S.slotCode        = d.reservation.slot_code;
        S.floor           = parseInt(d.reservation.floor);
        // Store the car details that came from the DB
        S.reservedCarType  = d.reservation.car_type  || '';
        S.reservedCarColor = d.reservation.car_color || '';
        // Pre-fill working state too (can be overridden in confirm step)
        S.carType  = S.reservedCarType  || 'Sedan';
        S.carColor = S.reservedCarColor || '';
    } else {
        // Walk-in: clear any leftover reservation state
        S.reservationId   = 0;
        S.userId          = 0;
        S.slotId          = 0;
        S.slotCode        = '';
        S.reservedCarType  = '';
        S.reservedCarColor = '';
        S.carType          = 'Sedan';
        S.carColor         = '';
    }

    stopCamera();
    setStep(3);
}

function stopCamera() {
    stopAutoScan();
    if (camStream) { camStream.getTracks().forEach(t => t.stop()); camStream = null; }
}

// ── STEP 3: Confirm ────────────────────────────────────────────────────────────
// FIX: For reserved users, car_type and car_color are pre-filled and read-only.
//      They can still be edited (voice/type) in case of a discrepancy.
function renderConfirm(w) {
    const typeBtns = VEHICLE_TYPES.map(t =>
        `<button type="button" role="radio" class="vehicle-type-btn${t === S.carType ? ' selected' : ''}" data-type="${t}" aria-checked="${t === S.carType ? 'true' : 'false'}" onclick="selectVehicleType('${t}')">${t}</button>`
    ).join('');

    const resHTML = S.isReserved ? `
    <div class="reservation-notice">
        <div class="rn-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        <div class="rn-text">
            <h4>Reservation Found — Slot ${S.slotCode}, Floor ${S.floor}</h4>
            <p>Vehicle details are pre-filled from your reservation. Confirm or correct them below. Slot selection is skipped.</p>
        </div>
    </div>` : '';

    w.innerHTML = `
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Confirm Vehicle Details
        </div>
        ${resHTML}
        <p style="font-size:0.82rem;color:var(--muted);margin-bottom:1rem;font-weight:600;">
            ${S.isReserved ? 'Details loaded from your reservation.' : 'Review your details. Go back to re-scan if the plate is wrong.'}
        </p>
        <div class="confirm-grid">
            <div class="field-group">
                <label class="field-label">Plate Number</label>
                <div class="field-input-wrap">
                    <input type="text" class="field-input" id="cfPlate" value="${S.plate}" maxlength="12"
                           oninput="this.value=this.value.toUpperCase().replace(/\\s+/g,'')" style="padding-right:38px;">
                    <button class="field-stt-btn" id="sttCfPlate" onclick="toggleSTT('cfPlate','sttCfPlate','plate')">
                        <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/></svg>
                    </button>
                </div>
            </div>
            <div class="field-group" style="grid-column:1/-1;">
                <label class="field-label">Vehicle Type — tap or say the type</label>
                <div class="vehicle-type-grid" id="cfTypeGrid" role="radiogroup" aria-label="Vehicle type">${typeBtns}</div>
            </div>
            <div class="field-group" style="grid-column:1/-1;">
                <label class="field-label">Vehicle Color</label>
                <div class="field-input-wrap">
                    <input type="text" class="field-input normal" id="cfColor" value="${S.carColor}"
                           placeholder="e.g. Red, White, Silver"
                           style="padding-right:38px;text-transform:capitalize;">
                    <button class="field-stt-btn" id="sttCfColor" onclick="toggleSTT('cfColor','sttCfColor','color')">
                        <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/></svg>
                    </button>
                </div>
            </div>
        </div>
        <div id="confirmError" style="margin-top:10px;"></div>
        <div class="btn-row">
            <button class="btn btn-ghost" onclick="goBackToScan()"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Back & Re-scan</button>
            <button class="btn btn-primary" onclick="confirmDetails()">Confirm <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>
        </div>
    </div>`;

    const spokenSlot = S.isReserved
        ? `Your reservation for slot ${S.slotCode} on floor ${S.floor} has been found. Vehicle details are pre-filled. Tap a vehicle type or say one to change it, then say Confirm when ready.`
        : 'Please review your vehicle details. Tap a vehicle type or say Sedan, S U V, Van, and so on. You can change the type anytime by saying another. Enter the color, then say Confirm when ready.';
    setTimeout(() => speak(spokenSlot), 400);
}

function goBackToScan() {
    S.plate = ''; lastDetectedPlate = '';
    setStep(2);
}

function confirmDetails() {
    const plate = normalizePlateStr(document.getElementById('cfPlate')?.value);
    const color = document.getElementById('cfColor')?.value.trim();
    if (!plate) {
        document.getElementById('confirmError').innerHTML = '<div class="alert danger"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><p>Plate number is required.</p></div>';
        return;
    }
    if (!color) {
        document.getElementById('confirmError').innerHTML = '<div class="alert warning"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><p>Please enter the vehicle color.</p></div>';
        return;
    }
    S.plate    = plate;
    if (!VEHICLE_TYPES.includes(S.carType)) S.carType = 'Sedan';
    S.carColor = color || 'Unknown';

    // FIX: Reserved users skip Step 4 entirely — they already have a slot assigned.
    if (S.isReserved) {
        confirmEntryAndGoDirections();
    } else {
        setStep(4);
    }
}

// ── STEP 4: Select Slot (walk-in only) ────────────────────────────────────────
function renderSlots(w) {
    w.innerHTML = `
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            Select Parking Slot
        </div>
        <div class="floor-tabs">
            <div class="floor-tab active" id="ftab1" onclick="switchFloor(1)">Floor 1</div>
            <div class="floor-tab"        id="ftab2" onclick="switchFloor(2)">Floor 2</div>
            <div class="floor-tab"        id="ftab3" onclick="switchFloor(3)">Floor 3</div>
        </div>
        <div class="slot-legend">
            <div class="leg-item"><div class="ldot available"></div>Available</div>
            <div class="leg-item"><div class="ldot occupied"></div>Occupied</div>
            <div class="leg-item"><div class="ldot reserved"></div>Reserved</div>
            <div class="leg-item"><div class="ldot selected"></div>Selected</div>
        </div>
        <div id="slotGrid">Loading slots…</div>
        <div class="slot-selected-info" id="slotInfo">No slot selected</div>
        <div id="slotError" style="margin-top:8px;"></div>
        <div class="btn-row">
            <button class="btn btn-ghost" onclick="setStep(3)"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Back</button>
            <button class="btn btn-ghost" onclick="autoAssign()">Auto Assign</button>
            <button class="btn btn-primary" id="confSlotBtn" disabled onclick="confirmSlot()">Confirm Slot <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>
        </div>
    </div>`;
    loadSlots(1);
    speak('Please select a parking slot. Tap a green slot, or say a code like 2 C 7 or 3 A 1. Say Auto to auto-assign.');
}

async function loadSlots(floor) {
    S.floor = floor;
    if (!allSlots[floor]) {
        const d = await (await fetch(`?action=slots&floor=${floor}`)).json();
        allSlots[floor] = d.slots || [];
    }
    renderSlotGrid(floor);
}

function renderSlotGrid(floor) {
    const slots = allSlots[floor] || [];
    const grid  = document.getElementById('slotGrid');
    if (!grid) return;
    const rowMap = {};
    slots.forEach(s => {
        const m = s.slot_code.match(/^\d([A-C])(\d+)$/);
        if (!m) return;
        const rowLetter = m[1], colNum = parseInt(m[2]);
        if (!rowMap[rowLetter]) rowMap[rowLetter] = [];
        rowMap[rowLetter].push({ ...s, colNum });
    });
    const rowLetters = Object.keys(rowMap).sort();
    rowLetters.forEach(r => rowMap[r].sort((a, b) => a.colNum - b.colNum));
    const maxCols = Math.max(...rowLetters.map(r => rowMap[r].length), 0);
    let html = `<div class="slot-grid-wrap"><div class="slot-col-labels"><div></div>`;
    for (let c = 1; c <= maxCols; c++) html += `<div class="slot-col-lbl">${c}</div>`;
    html += `</div>`;
    rowLetters.forEach(rl => {
        html += `<div class="slot-row-wrap"><div class="slot-row-lbl">${rl}</div>`;
        rowMap[rl].forEach(s => {
            let cls = s.status;
            if (s.id == S.slotId) cls = 'selected';
            const click = s.status === 'available' ? `onclick="pickSlot(${s.id},'${s.slot_code}',${s.floor})"` : '';
            html += `<div class="slot-cell ${cls}" ${click} title="${s.slot_code}">${s.slot_code}</div>`;
        });
        html += `</div>`;
    });
    html += `</div>`;
    grid.innerHTML = html;
}

function switchFloor(f) {
    S.floor = f;
    [1, 2, 3].forEach(i => { const t = document.getElementById('ftab' + i); if (t) t.className = 'floor-tab' + (i === f ? ' active' : ''); });
    if (allSlots[f]) renderSlotGrid(f); else loadSlots(f);
}

function refreshAllSlotGrids() {
    for (let f = 1; f <= 3; f++) {
        if (allSlots[f]) renderSlotGrid(f);
    }
}

function pickSlot(id, code, floor) {
    S.slotId = id; S.slotCode = code; S.floor = floor;
    refreshAllSlotGrids();
    const info = document.getElementById('slotInfo');
    if (info) { info.textContent = `✓ Slot ${code} on Floor ${floor} selected`; info.classList.add('show'); }
    const btn = document.getElementById('confSlotBtn'); if (btn) btn.disabled = false;
    speak(`Slot ${code} on floor ${floor} selected.`);
}

function autoAssign() {
    for (let f = 1; f <= 3; f++) {
        const sorted = (allSlots[f] || []).slice().sort((a, b) => {
            const ma = a.slot_code.match(/^(\d)([A-C])(\d+)$/);
            const mb = b.slot_code.match(/^(\d)([A-C])(\d+)$/);
            if (!ma || !mb) return 0;
            if (ma[2] !== mb[2]) return ma[2].localeCompare(mb[2]);
            return parseInt(ma[3]) - parseInt(mb[3]);
        });
        const avail = sorted.find(s => s.status === 'available');
        if (avail) { switchFloor(f); setTimeout(() => pickSlot(avail.id, avail.slot_code, avail.floor), 150); return; }
    }
    speak('No available slots found.');
}

function confirmSlot() {
    if (!S.slotId) {
        document.getElementById('slotError').innerHTML = '<div class="alert warning"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><p>Please select a slot first.</p></div>';
        return;
    }
    confirmEntryAndGoDirections();
}

// ── Core entry confirmation (shared by reserved + walk-in) ────────────────────
async function confirmEntryAndGoDirections() {
    const fd = new FormData();
    fd.append('plate',          S.plate);
    fd.append('car_type',       S.carType);
    fd.append('car_color',      S.carColor);
    fd.append('slot_id',        S.slotId);
    fd.append('is_reserved',    S.isReserved ? '1' : '0');
    fd.append('reservation_id', S.reservationId);
    fd.append('user_id',        S.userId);
    const d = await (await fetch('?action=confirm_entry', { method: 'POST', body: fd })).json();
    if (!d.success) {
        const errEl = document.getElementById('slotError') || document.getElementById('confirmError');
        if (errEl) errEl.innerHTML = `<div class="alert danger"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><p>${d.message}</p></div>`;
        return;
    }
    S.slotCode = d.slot_code; S.floor = d.floor; S.entryTime = d.entry_time;
    setStep(5);
}

// ── STEP 5: Directions ─────────────────────────────────────────────────────────
function renderDirections(w) {
    const modeLabel = S.isReserved ? 'Reservation confirmed' : 'Walk-in session started';
    w.innerHTML = `
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>Proceed to Your Slot</div>
        <div class="dir-grid">
            <div class="dir-slot">
                <div class="dir-floor-lbl">Floor</div><div class="dir-floor-num">${S.floor}</div>
                <div class="dir-slot-lbl">Slot</div><div class="dir-slot-num">${S.slotCode}</div>
            </div>
            <div class="dir-details">
                <div class="dir-row"><span class="dir-row-lbl">Plate</span><span class="dir-row-val">${S.plate}</span></div>
                <div class="dir-row"><span class="dir-row-lbl">Vehicle</span><span class="dir-row-val">${S.carType} · ${S.carColor}</span></div>
                <div class="dir-row"><span class="dir-row-lbl">Entry Time</span><span class="dir-row-val">${S.entryTime}</span></div>
                <div class="dir-row"><span class="dir-row-lbl">Session</span><span class="dir-row-val" style="color:var(--emerald);font-size:0.82rem;">${modeLabel}</span></div>
                <div class="dir-row"><span class="dir-row-lbl">First 3 hrs</span><span class="dir-row-val" style="color:var(--emerald);">₱60 flat</span></div>
                <div class="dir-row"><span class="dir-row-lbl">After 3 hrs</span><span class="dir-row-val">₱10 grace, then ₱30/hr</span></div>
                <div class="tip-box"><p><strong> Tips:</strong><br>Park within the designated slot lines.<br>Pay at the <strong>Payment Kiosk</strong> before heading to the exit.<br>The exit kiosk will block unpaid vehicles.</p></div>
            </div>
        </div>
        <div class="btn-row">
            <button class="btn btn-ghost" onclick="speakDirections()"><svg viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>Repeat</button>
            <button class="btn btn-primary" onclick="setStep(6)">Done — Proceed to Slot <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>
        </div>
    </div>`;
    setTimeout(() => speakDirections(), 400);
}

function speakDirections() {
    speak(`Please proceed to Floor ${S.floor}, Slot ${S.slotCode}. Your plate is ${S.plate.split('').join(' ')}. Entry time is ${S.entryTime}. First 3 hours are 60 pesos flat. Pay at the payment kiosk before exiting. Say Done when ready.`);
}

// ── STEP 6: Done ───────────────────────────────────────────────────────────────
function renderDone(w) {
    const qrInner = DONE_STEP_QR_URL
        ? `<img src="${DONE_STEP_QR_URL}" alt="Scan QR code" width="180" height="180" />`
        : `<span class="done-qr-placeholder-label">QR code<br>(image)</span>`;
    w.innerHTML = `
    <div class="card"><div class="success-wrap">
        <div class="done-layout">
            <div class="done-main">
                <div class="success-icon"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
                <h2 class="success-title">Session Active!</h2>
                <p class="success-sub">Your parking session has started. Have a safe visit!</p>
                <div class="success-details">
                    <div class="sdet"><div class="sdet-lbl">Plate</div><div class="sdet-val green">${S.plate}</div></div>
                    <div class="sdet"><div class="sdet-lbl">Slot</div><div class="sdet-val">${S.slotCode}</div></div>
                    <div class="sdet"><div class="sdet-lbl">Floor</div><div class="sdet-val">${S.floor}</div></div>
                    <div class="sdet"><div class="sdet-lbl">Entry</div><div class="sdet-val">${S.entryTime}</div></div>
                </div>
                <div class="alert success" style="max-width:420px;margin:0 auto 1.5rem;">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <p>Remember to <strong>pay at the Payment Kiosk</strong> before proceeding to the exit.</p>
                </div>
                <p class="reset-countdown">This kiosk resets in <strong id="cdNum">10</strong> seconds…</p>
            </div>
            <div class="done-qr-slot" id="doneQrSlot">${qrInner}</div>
        </div>
    </div></div>`;
    speak(`Session active. Proceed to Floor ${S.floor}, Slot ${S.slotCode}. Remember to pay at the payment kiosk. Have a great day!`);
    startResetCountdown(10);
}

function startResetCountdown(secs) {
    if (resetTimer) clearInterval(resetTimer);
    let r = secs;
    resetTimer = setInterval(() => {
        r--;
        const el = document.getElementById('cdNum'); if (el) el.textContent = r;
        if (r <= 0) { clearInterval(resetTimer); resetKiosk(); }
    }, 1000);
}

function resetKiosk() {
    if (resetTimer) { clearInterval(resetTimer); resetTimer = null; }
    stopCamera(); window.speechSynthesis && window.speechSynthesis.cancel();
    Object.assign(S, {
        step: 1, plate: '', carType: 'Sedan', carColor: '',
        isReserved: false, reservationId: 0, userId: 0,
        slotId: 0, slotCode: '', floor: 1, entryTime: '',
        reservedCarType: '', reservedCarColor: '',
    });
    allSlots = {}; lastDetectedPlate = '';
    setStep(1);
}

// ── STT for individual fields (pause main mic — only one Web Speech session at a time) ──
function toggleSTT(inputId, btnId, sttMode) {
    if (fieldRec) {
        try {
            fieldRec.stop();
        } catch(e) {
            fieldRec = null;
            document.querySelectorAll('.field-stt-btn.active').forEach(b => b.classList.remove('active'));
            resumeRec();
        }
        return;
    }
    if (!('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) { alert('Speech recognition not supported.'); return; }
    pauseRec();
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    fieldRec = new SR();
    fieldRec.lang = 'en-US';
    fieldRec.continuous = false;
    fieldRec.interimResults = false;
    fieldRec.onresult = e => {
        let val = e.results[0][0].transcript.trim();
        if (sttMode === 'plate') val = parsePlateSpeech(val) || normalizePlateStr(val);
        else if (sttMode === 'color') val = formatColorFromSpeech(val);
        const el = document.getElementById(inputId);
        if (el) el.value = val;
    };
    let fieldCleaned = false;
    const cleanup = () => {
        if (fieldCleaned) return;
        fieldCleaned = true;
        fieldRec = null;
        document.getElementById(btnId)?.classList.remove('active');
        resumeRec();
    };
    fieldRec.onend = cleanup;
    fieldRec.onerror = cleanup;
    document.getElementById(btnId)?.classList.add('active');
    try { fieldRec.start(); } catch(e) { cleanup(); }
}

// ── Continuous voice recognition ───────────────────────────────────────────────
function initVoice() {
    const st = document.getElementById('voiceStatus');
    if (!('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) {
        if (st) st.textContent = 'Voice not supported in this browser'; return;
    }
    if (window.isSecureContext === false) {
        if (st) st.textContent = 'Voice needs HTTPS or localhost (not an IP URL)'; return;
    }
    startRec();
}
function startRec() {
    if (recRunning || recPaused) return;
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    recognition = new SR();
    recognition.lang = 'en-US';
    recognition.continuous = false;
    recognition.interimResults = true;
    recognition.maxAlternatives = 5;
    recognition.onstart  = () => { recRunning = true; document.getElementById('voiceIndicator').classList.add('listening'); document.getElementById('voiceStatus').textContent = 'Listening…'; };
    recognition.onresult = e => {
        let interimBuf = '';
        for (let i = 0; i < e.results.length; i++) {
            if (!e.results[i].isFinal) interimBuf += e.results[i][0].transcript;
        }
        if (S.step === 2 && interimBuf.trim()) {
            clearTimeout(plateInterimTimer);
            const snap = interimBuf;
            plateInterimTimer = setTimeout(() => {
                plateInterimTimer = null;
                if (S.step !== 2) return;
                const p = parsePlateSpeech(snap);
                if (p) {
                    const el = document.getElementById('manPlate');
                    if (el) { el.value = p; S.plate = p; }
                }
            }, 420);
        }
        for (let i = e.resultIndex; i < e.results.length; i++) {
            const res = e.results[i];
            if (!res.isFinal) continue;
            if (plateInterimTimer) { clearTimeout(plateInterimTimer); plateInterimTimer = null; }
            const t = pickBestTranscriptForResult(res);
            document.getElementById('voiceTranscript').textContent = `"${t}"`;
            setTimeout(() => { const el = document.getElementById('voiceTranscript'); if (el) el.textContent = ''; }, 2500);
            handleVoice(t);
        }
    };
    recognition.onend   = () => { recRunning = false; document.getElementById('voiceIndicator').classList.remove('listening'); if (!recPaused) setTimeout(startRec, 60); };
    recognition.onerror = e => {
        const st = document.getElementById('voiceStatus');
        if (e.error === 'not-allowed' && st) st.textContent = 'Microphone blocked — allow access in the site settings';
        else if (e.error === 'network' && st) st.textContent = 'Speech service unavailable — check internet; use Chrome/Edge on localhost or HTTPS';
        else if (e.error === 'aborted' || e.error === 'no-speech') { /* normal */ }
        else if (st && e.error) st.textContent = 'Voice error: ' + e.error;
        if (e.error !== 'not-allowed') setTimeout(startRec, 120);
    };
    try { recognition.start(); } catch(e) {}
}
function pauseRec()  {
    recPaused = true;
    if (plateInterimTimer) { clearTimeout(plateInterimTimer); plateInterimTimer = null; }
    try { recognition?.stop(); } catch(e) {}
}
function resumeRec() { recPaused = false; recRunning = false; setTimeout(startRec, 120); }

function handleVoice(t) {
    switch (S.step) {
        case 1:
            if (/\b(begin(ning)?|start(ing)?|park(ing)?|enter(ing)?|go(ing)?|commence|proceed)\b/.test(t)) beginSession();
            break;
        case 2:
            if (/\b(scan|detect|read)\b/.test(t)) doManualScan();
            else if (/\b(continue|next|proceed)\b/.test(t)) continueToConfirm();
            else {
                const p = parsePlateSpeech(t);
                if (p) {
                    const el = document.getElementById('manPlate');
                    if (el) { el.value = p; S.plate = p; }
                }
            }
            break;
        case 3:
            if (/\b(confirm|yes|proceed)\b/.test(t)) confirmDetails();
            else if (/\b(back|no|rescan)\b/.test(t)) goBackToScan();
            else {
                const found = matchVehicleTypeFromSpeech(t);
                if (found) {
                    selectVehicleType(found);
                    speak(`Vehicle type set to ${found}.`);
                } else {
                    const col = extractColorFromTranscript(t);
                    if (col) {
                        const el = document.getElementById('cfColor');
                        if (el) { el.value = col; S.carColor = col; }
                        speak(`Color set to ${col}.`);
                    }
                }
            }
            break;
        case 4:
            if (/\b(confirm|proceed|yes)\b/.test(t) && S.slotId) confirmSlot();
            else if (/\bauto\b/.test(t)) autoAssign();
            else if (/\bback\b/.test(t)) setStep(3);
            else {
                const sc = parseSlotCodeFromSpeech(t);
                if (sc) {
                    const slot = findSlotByCode(sc);
                    if (slot) {
                        if (slot.status === 'available') {
                            switchFloor(slot.floor);
                            setTimeout(() => pickSlot(slot.id, slot.slot_code, slot.floor), 100);
                        } else if (slot.status === 'occupied') {
                            speak(`Slot ${slot.slot_code} is occupied. Try another code.`);
                        } else {
                            speak(`Slot ${slot.slot_code} is reserved. Try another code.`);
                        }
                    } else {
                        speak('That slot code was not found.');
                    }
                } else {
                    const fm = t.match(/\bfloor\s*([123]|one|two|three)\b/);
                    if (fm) { const f = {one:1,two:2,three:3}[fm[1]] || parseInt(fm[1]); if (f) switchFloor(f); }
                    else {
                        const sp = t.toUpperCase().replace(/[^A-Z0-9]/g, '');
                        for (let f = 1; f <= 3; f++) {
                            const found = (allSlots[f] || []).find(s => s.slot_code.replace(/[^A-Z0-9]/g,'') === sp && s.status === 'available');
                            if (found) { switchFloor(f); setTimeout(() => pickSlot(found.id, found.slot_code, found.floor), 200); break; }
                        }
                    }
                }
            }
            break;
        case 5:
            if (/\b(done|proceed|go|next)\b/.test(t)) setStep(6);
            else if (/\brepeat\b/.test(t)) speakDirections();
            break;
        case 6:
            if (/\b(done|new|session|again|reset)\b/.test(t)) { clearInterval(resetTimer); resetKiosk(); }
            break;
    }
}

function pickBestTranscriptForResult(res) {
    if (S.step === 2) {
        for (let k = 0; k < res.length; k++) {
            if (parsePlateSpeech(res[k].transcript)) return res[k].transcript.toLowerCase().trim();
        }
    }
    if (S.step === 4) {
        for (let k = 0; k < res.length; k++) {
            if (parseSlotCodeFromSpeech(res[k].transcript)) return res[k].transcript.toLowerCase().trim();
        }
    }
    return res[0].transcript.toLowerCase().trim();
}

function parsePlateSpeech(text) {
    return extractPlateFromCompact(spokenToCompactAlnum(text));
}

/** Normalize spoken color (mic button + continuous voice). */
function formatColorFromSpeech(raw) {
    let s = String(raw || '').trim();
    if (!s) return '';
    s = s.replace(/^(the |my |it's |its |a |an |color is |colour is |paint is |vehicle is |car is )+/i, '');
    s = s.replace(/[.!?]+$/g, '').trim();
    s = s.replace(/\b(please|thanks|thank you)\b/gi, '').trim();
    if (!s) return '';
    return s.split(/\s+/).filter(Boolean).map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join(' ');
}

/** Step 3: extract a color phrase; avoids commands, plate-like strings, lone vehicle types. */
function extractColorFromTranscript(t) {
    if (!t || t.length < 2) return '';
    const low = t.toLowerCase().trim();
    if (/\b(confirm|yes|proceed|back|no|rescan)\b/.test(low)) return '';
    if (parsePlateSpeech(t)) return '';
    if (matchVehicleTypeFromSpeech(t)) {
        const remainder = low.replace(/[^\w\s]+/g, ' ')
            .replace(/\b(hatchback|hatch\s*back|motorcycle|motor\s*cycle|pickup|pick\s*up|sedan|suv|van)\b/gi, ' ')
            .replace(/\s+/g, ' ').trim();
        if (remainder.length < 2) return '';
    }
    let s = low.replace(/^(the |my |it's |its |color is |colour is |paint is |vehicle |car )\s*/i, '').trim();
    s = s.replace(/\b(color|colour|vehicle|car)\b$/i, '').trim();
    if (s.length < 2) return '';
    if (parsePlateSpeech(s)) return '';
    return formatColorFromSpeech(s);
}

/** Step 4: slot code like 1A3, 2C7 from speech (handles "2 c 7", "two c seven"). */
function parseSlotCodeFromSpeech(t) {
    const c = spokenToCompactAlnum(t);
    const m = c.match(/([123])([ABC])(\d{1,2})/);
    if (!m) return null;
    return m[1] + m[2] + m[3];
}

function findSlotByCode(code) {
    const want = String(code || '').replace(/[^0-9A-Z]/gi, '').toUpperCase();
    const re = /^([123])([ABC])(\d{1,2})$/;
    if (!re.test(want)) return null;
    for (let f = 1; f <= 3; f++) {
        const s = (allSlots[f] || []).find(x => x.slot_code.replace(/[^0-9A-Z]/g, '').toUpperCase() === want);
        if (s) return s;
    }
    return null;
}

window.addEventListener('load', () => { initVoice(); setStep(1); });
</script>
</body>
</html>

