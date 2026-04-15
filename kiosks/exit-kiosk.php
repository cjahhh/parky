<?php
// kiosks/exit-kiosk.php
require_once __DIR__ . '/includes/kiosk-auth.php';
require_once __DIR__ . '/includes/kiosk-helpers.php';


if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    switch ($_GET['action']) {


        case 'detect':
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            @set_time_limit(180);
            $frameData = $_POST['frame'] ?? '';
            if (!$frameData) { echo json_encode(['success' => false, 'message' => 'No frame data.']); exit; }
            echo json_encode(callTfDetector($frameData));
            exit;


        case 'lookup':
            $plate = strtoupper(trim($_GET['plate'] ?? ''));
            if (!$plate) { echo json_encode(['found' => false, 'message' => 'Please enter a plate number.']); exit; }
            $session = getActiveSessionByPlate($pdo, $plate);
            if (!$session) {
                $pendingStmt = $pdo->prepare(
                    "SELECT id FROM reservations WHERE UPPER(plate_number)=? AND status IN ('pending','confirmed') LIMIT 1"
                );
                $pendingStmt->execute([$plate]);
                if ($pendingStmt->fetch()) {
                    echo json_encode(['found' => false, 'message' => 'This vehicle has not been scanned at the entrance kiosk yet.']);
                    exit;
                }
                echo json_encode(['found' => false, 'message' => "No active session found for plate <strong>{$plate}</strong>."]);
                exit;
            }
            $entryTime = $session['entry_time'];
            $elapsed   = time() - strtotime($entryTime);
            $totalFee  = calculateFee($elapsed);
            $amountDue = getAmountDue($elapsed, $session['paid_until'], $entryTime);
            $isPaid    = $amountDue <= 0;
            $ph = $pdo->prepare(
                "SELECT receipt_number, total_amount, method, paid_at FROM payments WHERE session_id=? AND session_type=? ORDER BY paid_at ASC"
            );
            $ph->execute([$session['id'], $session['session_type']]);
            $history = $ph->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                'found'        => true,
                'is_paid'      => $isPaid,
                'session_id'   => $session['id'],
                'session_type' => $session['session_type'],
                'plate'        => $session['plate_number'],
                'slot_code'    => $session['slot_code'],
                'floor'        => $session['floor'],
                'car_type'     => $session['car_type']  ?? '—',
                'car_color'    => $session['car_color'] ?? '—',
                'entry_time'   => date('h:i A', strtotime($entryTime)),
                'elapsed_sec'  => $elapsed,
                'total_fee'    => $totalFee,
                'amount_due'   => $amountDue,
                'paid_until'   => $session['paid_until'],
                'history'      => $history,
            ]);
            exit;


        case 'pay':
            $sessionId   = (int)($_POST['session_id']   ?? 0);
            $sessionType =       $_POST['session_type'] ?? '';
            $method      =       $_POST['method']       ?? '';
            if (!$sessionId || !in_array($sessionType, ['walkin','reservation']) || !in_array($method, ['cash','card','gcash','maya'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid payment data.']); exit;
            }
            echo json_encode(processKioskPayment($pdo, $sessionId, $sessionType, $method, 'kiosk'));
            exit;


        case 'process_exit':
            $plate = strtoupper(trim($_POST['plate'] ?? ''));
            if (!$plate) { echo json_encode(['success' => false, 'message' => 'Plate required.']); exit; }
            $session = getActiveSessionByPlate($pdo, $plate);
            if (!$session) { echo json_encode(['success' => false, 'message' => 'Session not found.']); exit; }
            $elapsed   = time() - strtotime($session['entry_time']);
            $amountDue = getAmountDue($elapsed, $session['paid_until'], $session['entry_time']);
            if ($amountDue > 0) { echo json_encode(['success' => false, 'message' => 'Outstanding balance. Please pay first.']); exit; }
            try {
                $pdo->beginTransaction();
                $exitTime = date('Y-m-d H:i:s');
                if ($session['session_type'] === 'walkin') {
                    $pdo->prepare("UPDATE sessions_walkin SET status='completed', exit_time=? WHERE id=?")->execute([$exitTime, $session['id']]);
                } else {
                    $pdo->prepare("UPDATE reservations SET status='completed', expires_at=? WHERE id=?")->execute([$exitTime, $session['id']]);
                }
                $pdo->prepare("UPDATE parking_slots SET status='available' WHERE id=?")->execute([$session['slot_id']]);
                $pdo->commit();
                echo json_encode(['success' => true, 'slot_code' => $session['slot_code'], 'floor' => $session['floor']]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Exit processing failed. Please try again.']);
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
    <title>Parky — Exit Kiosk</title>
          <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
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
        .nav-badge { background:rgba(245,158,11,0.1); border:1px solid rgba(245,158,11,0.25); color:var(--warning); font-size:0.72rem; font-weight:800; padding:4px 12px; border-radius:20px; }
        .nav-live { display:flex; align-items:center; gap:6px; font-size:0.78rem; font-weight:700; color:var(--muted); margin-left:auto; }
        .live-dot { width:7px; height:7px; background:var(--emerald); border-radius:50%; animation:pulse 2s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.4);} }
        .nav-time { font-size:0.85rem; font-weight:800; color:var(--text); background:var(--surface); border:1px solid var(--border2); border-radius:8px; padding:5px 12px; }


        /* STEP BAR */
        .stepbar { background:var(--bg2); border-bottom:1px solid var(--border); padding:0 2rem; display:flex; align-items:center; height:52px; box-shadow:0 1px 4px rgba(0,0,0,0.04); }
        .step-item { display:flex; align-items:center; flex:1; }
        .step-node { display:flex; align-items:center; gap:8px; white-space:nowrap; }
        .step-num { width:26px; height:26px; border-radius:50%; border:2px solid var(--border2); display:flex; align-items:center; justify-content:center; font-size:0.72rem; font-weight:800; color:var(--muted); transition:all 0.3s; }
        .step-num.active { border-color:var(--emerald); color:var(--emerald); background:var(--emeraldbg2); }
        .step-num.done   { border-color:var(--emerald2); background:var(--emerald2); color:#fff; font-size:0.65rem; }
        .step-lbl { font-size:0.75rem; font-weight:700; color:var(--muted); transition:color 0.3s; }
        .step-lbl.active { color:var(--emerald); }
        .step-lbl.done   { color:var(--emerald2); }
        .step-line { flex:1; height:2px; background:var(--border2); margin:0 10px; border-radius:2px; transition:background 0.3s; }
        .step-line.done { background:var(--emerald2); }


        .page { max-width:1000px; margin:0 auto; padding:2rem 1.5rem 4rem; }


        /* VOICE BAR */
        .voice-bar { background:var(--bg2); border:1px solid var(--border2); border-radius:12px; padding:10px 16px; display:flex; align-items:center; gap:12px; margin-bottom:1.5rem; box-shadow:var(--shadow); }
        .voice-indicator { width:32px; height:32px; border-radius:50%; background:var(--surface); border:2px solid var(--border2); display:flex; align-items:center; justify-content:center; transition:all 0.3s; flex-shrink:0; }
        .voice-indicator svg { width:14px; height:14px; stroke:var(--muted); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .voice-indicator.listening { background:var(--emeraldbg2); border-color:var(--emerald); animation:vPulse 1s ease-in-out infinite; }
        .voice-indicator.listening svg { stroke:var(--emerald); }
        @keyframes vPulse { 0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,0.35);}50%{box-shadow:0 0 0 8px rgba(16,185,129,0);} }
        .voice-status { font-size:0.8rem; font-weight:700; color:var(--muted); flex:1; }
        .voice-transcript { font-size:0.78rem; font-weight:600; color:var(--emerald); font-style:italic; }
        .voice-chips { display:flex; gap:6px; flex-wrap:wrap; }
        .v-chip { font-size:0.72rem; font-weight:700; color:var(--emerald); background:var(--emeraldbg); border:1px solid rgba(16,185,129,0.2); border-radius:20px; padding:3px 10px; }


        /* CARD */
        .card { background:var(--bg2); border:1px solid var(--border2); border-radius:16px; padding:1.75rem; box-shadow:var(--shadow); animation:fadeUp 0.3s cubic-bezier(0.22,1,0.36,1) both; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);} }
        .card-title { font-size:0.72rem; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:1.25rem; display:flex; align-items:center; gap:8px; }
        .card-title svg { width:14px; height:14px; stroke:var(--muted); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }


        /* SCAN STEP */
        .scan-layout { display:grid; grid-template-columns:2fr 1fr; gap:1.25rem; }


        /* Camera — full frame scan area, no rectangle */
        .cam-wrap { background:#000; border-radius:14px; overflow:hidden; position:relative; aspect-ratio:16/10; transition:box-shadow 0.3s, outline 0.3s; }
        .cam-wrap video { width:100%; height:100%; object-fit:cover; display:block; }
        .cam-wrap.scanning { outline:2.5px solid #fbbf24; outline-offset:-2px; box-shadow:0 0 0 4px rgba(251,191,36,0.15); }
        .cam-wrap.found    { outline:2.5px solid var(--emerald); outline-offset:-2px; box-shadow:0 0 0 4px rgba(16,185,129,0.2); }
        .cam-placeholder { display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; gap:8px; color:var(--muted2); }
        .cam-placeholder svg { width:48px; height:48px; stroke:var(--muted2); fill:none; stroke-width:1.5; stroke-linecap:round; stroke-linejoin:round; }
        .cam-placeholder p { font-size:0.85rem; font-weight:600; }


        /* Status bar */
        .scan-status-bar { position:absolute; bottom:0; left:0; right:0; background:linear-gradient(transparent, rgba(0,0,0,0.72)); padding:12px 14px 10px; display:flex; align-items:center; justify-content:space-between; }
        .scan-status-text { font-size:0.72rem; font-weight:800; color:#fff; letter-spacing:0.8px; text-transform:uppercase; }
        .scan-pulse { width:8px; height:8px; border-radius:50%; background:var(--emerald); flex-shrink:0; }
        .scan-pulse.active   { animation:pulse 1.4s infinite; }
        .scan-pulse.scanning { background:#fbbf24; animation:pulse 0.7s infinite; }
        .scan-pulse.idle     { background:#6b7280; animation:none; }


        .analyzing-overlay { position:absolute; inset:0; background:rgba(0,0,0,0.55); display:flex; align-items:center; justify-content:center; }
        .analyzing-text { font-size:0.85rem; font-weight:800; color:var(--emerald); letter-spacing:2px; animation:blink 1s infinite; }
        @keyframes blink { 0%,100%{opacity:1;}50%{opacity:0.3;} }


        .btn-scan-manual { width:100%; background:var(--surface); color:var(--muted); border:1.5px solid var(--border2); border-radius:11px; padding:10px; font-family:'Nunito',sans-serif; font-size:0.85rem; font-weight:800; cursor:pointer; transition:all 0.2s; margin-top:8px; display:flex; align-items:center; justify-content:center; gap:8px; }
        .btn-scan-manual:hover { background:var(--surface2); color:var(--text); }
        .btn-scan-manual:disabled { opacity:0.5; cursor:not-allowed; }
        .btn-scan-manual svg { width:14px; height:14px; stroke:currentColor; fill:none; stroke-width:2.5; stroke-linecap:round; }


        .detect-row { background:var(--surface); border:1px solid var(--border2); border-radius:10px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
        .dr-lbl { font-size:0.7rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }
        .dr-val.highlight { color:var(--emerald); font-size:1.05rem; font-weight:800; letter-spacing:0.04em; }


        .field-input-wrap { position:relative; margin-bottom:8px; }
        .field-input { background:var(--surface); border:1.5px solid var(--border2); border-radius:10px; padding:10px 44px 10px 14px; font-family:'Nunito',sans-serif; font-size:0.95rem; font-weight:700; color:var(--text); outline:none; width:100%; transition:border-color 0.2s; text-transform:uppercase; }
        .field-input:focus { border-color:var(--emerald); }
        .field-stt-btn { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; padding:4px; }
        .field-stt-btn svg { width:16px; height:16px; stroke:var(--muted); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .field-stt-btn.active svg { stroke:var(--emerald); }


        .btn-search { width:100%; background:var(--emerald); color:#fff; border:none; border-radius:11px; padding:10px; font-family:'Nunito',sans-serif; font-size:0.85rem; font-weight:800; cursor:pointer; transition:background 0.2s; margin-top:6px; display:flex; align-items:center; justify-content:center; gap:8px; }
        .btn-search:hover { background:var(--emerald2); }
        .btn-search svg { width:14px; height:14px; stroke:#fff; fill:none; stroke-width:2.5; stroke-linecap:round; }


        /* PAYMENT */
        .session-grid { display:grid; grid-template-columns:1fr 300px; gap:1rem; }
        .detail-rows { display:flex; flex-direction:column; gap:8px; margin-bottom:1rem; }
        .detail-row { background:var(--surface); border:1px solid var(--border2); border-radius:10px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; }
        .dr-l { font-size:0.7rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }
        .dr-r { font-size:0.9rem; font-weight:800; color:var(--text); }
        .dr-r.green { color:var(--emerald); } .dr-r.yellow { color:var(--warning); } .dr-r.red { color:var(--danger); }


        .fee-card { background:var(--bg2); border:1px solid var(--border2); border-radius:16px; padding:1.25rem; box-shadow:var(--shadow); }
        .fee-card-title { font-size:0.7rem; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:1rem; }
        .elapsed-display { text-align:center; margin-bottom:1rem; }
        .elapsed-time { font-size:2rem; font-weight:900; color:var(--text); font-variant-numeric:tabular-nums; line-height:1; }
        .elapsed-sub { font-size:0.72rem; font-weight:600; color:var(--muted); margin-top:4px; }
        .fee-row { border-radius:10px; padding:10px 13px; display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
        .fee-row.f-total { background:var(--emeraldbg); border:1px solid rgba(16,185,129,0.18); }
        .fee-row.f-due   { background:var(--warningbg); border:1px solid rgba(245,158,11,0.25); }
        .fee-row.f-clear { background:var(--emeraldbg); border:1px solid rgba(16,185,129,0.2); }
        .fr-l { font-size:0.75rem; font-weight:700; color:var(--muted); }
        .fr-l small { display:block; font-size:0.65rem; color:var(--muted2); margin-top:2px; }
        .fr-r { font-size:1.3rem; font-weight:900; font-variant-numeric:tabular-nums; }
        .fr-r.green { color:var(--emerald); } .fr-r.yellow { color:var(--warning); }


        .pay-section { margin-top:1rem; padding-top:1rem; border-top:1px solid var(--border); }
        .pay-section-title { font-size:0.72rem; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:10px; }
        .method-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:10px; }
        .method-opt { border:1.5px solid var(--border2); border-radius:10px; padding:10px 12px; cursor:pointer; text-align:center; transition:all 0.2s; }
        .method-opt input { display:none; }
        .method-opt-lbl { font-size:0.82rem; font-weight:700; color:var(--muted); }
        .method-opt:has(input:checked) { border-color:rgba(16,185,129,0.4); background:var(--emeraldbg); }
        .method-opt:has(input:checked) .method-opt-lbl { color:var(--emerald); }
        .pay-status { font-size:0.78rem; font-weight:700; text-align:center; min-height:20px; margin-top:6px; }


        .pay-history { margin-top:1rem; padding-top:1rem; border-top:1px solid var(--border); }
        .ph-title { font-size:0.68rem; font-weight:700; color:var(--muted2); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:8px; }
        .ph-row { display:flex; justify-content:space-between; align-items:center; padding:7px 10px; border-radius:8px; background:var(--surface); margin-bottom:5px; }
        .ph-l { font-size:0.77rem; font-weight:600; color:var(--muted); }
        .ph-r { font-size:0.82rem; font-weight:800; color:var(--emerald); }
        .ph-rec { font-size:0.65rem; color:var(--muted2); margin-top:1px; }


        /* EXIT DONE */
        .exit-success { text-align:center; padding:2.5rem 2rem; }
        .exit-icon { width:72px; height:72px; background:var(--emeraldbg2); border:2px solid rgba(16,185,129,0.3); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem; }
        .exit-icon svg { width:32px; height:32px; stroke:var(--emerald); fill:none; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; }
        .exit-title { font-size:1.75rem; font-weight:900; letter-spacing:-0.03em; color:var(--text); margin-bottom:6px; }
        .exit-sub { font-size:0.9rem; color:var(--muted); font-weight:500; margin-bottom:1.5rem; }
        .exit-details { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-bottom:1.5rem; }
        .edet { background:var(--surface); border:1px solid var(--border2); border-radius:10px; padding:10px 16px; text-align:center; }
        .edet-lbl { font-size:0.65rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; }
        .edet-val { font-size:0.95rem; font-weight:800; color:var(--text); margin-top:3px; }
        .edet-val.green { color:var(--emerald); }


        /* ALERTS */
        .alert { border-radius:10px; padding:10px 14px; display:flex; align-items:center; gap:10px; margin-bottom:10px; }
        .alert svg { width:16px; height:16px; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; }
        .alert p { font-size:0.8rem; font-weight:600; line-height:1.5; }
        .alert p strong { font-weight:800; }
        .alert.success { background:var(--emeraldbg); border:1px solid rgba(16,185,129,0.2); }
        .alert.success svg { stroke:var(--emerald); } .alert.success p { color:var(--muted); } .alert.success p strong { color:var(--emerald); }
        .alert.danger { background:var(--dangerbg); border:1px solid rgba(239,68,68,0.2); }
        .alert.danger svg { stroke:var(--danger); } .alert.danger p { color:var(--muted); } .alert.danger p strong { color:var(--danger); }
        .alert.warning { background:var(--warningbg); border:1px solid rgba(245,158,11,0.2); }
        .alert.warning svg { stroke:var(--warning); } .alert.warning p { color:var(--muted); } .alert.warning p strong { color:var(--warning); }


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
        .btn-pay { background:var(--warning); color:#fff; box-shadow:0 4px 12px rgba(245,158,11,0.25); border:none; }
        .btn-pay:hover { background:#d97706; }


        .reset-countdown { font-size:0.8rem; font-weight:700; color:var(--muted); }


        @media(max-width:720px) { .scan-layout,.session-grid { grid-template-columns:1fr; } .page { padding:1.25rem 1rem 3rem; } }
    </style>
</head>
<body>


<nav>
    <a class="nav-logo" href="#"><div class="nav-logo-icon">P</div><span class="nav-logo-text">Parky</span></a>
    <span class="nav-badge">Exit Kiosk</span>
    <div class="nav-live" style="margin-left:auto;"><div class="live-dot"></div><span>Gate 2 — Active</span></div>
    <div class="nav-time" id="navTime">--:--:--</div>
</nav>


<div class="stepbar">
    <div class="step-item"><div class="step-node"><div class="step-num active" id="sn1">1</div><div class="step-lbl active" id="sl1">Scan</div></div><div class="step-line" id="sc1"></div></div>
    <div class="step-item"><div class="step-node"><div class="step-num" id="sn2">2</div><div class="step-lbl" id="sl2">Payment Check</div></div><div class="step-line" id="sc2"></div></div>
    <div class="step-item" style="flex:0;"><div class="step-node"><div class="step-num" id="sn3">3</div><div class="step-lbl" id="sl3">Exit</div></div></div>
</div>


<div class="page">
    <div class="voice-bar">
        <div class="voice-indicator" id="voiceInd">
            <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
        </div>
        <div style="flex:1;">
            <div class="voice-status" id="voiceStatus">Listening for voice commands…</div>
            <div class="voice-transcript" id="voiceTrans"></div>
        </div>
        <div class="voice-chips" id="voiceChips"><span class="v-chip">"Scan"</span><span class="v-chip">Plate number</span></div>
    </div>
    <div id="screenWrap"></div>
</div>


<script>
// ── State ──────────────────────────────────────────────────────────────────────
const S = {
    step: 1, plate: '',
    sessionId: 0, sessionType: '',
    slotCode: '', floor: 0,
    carType: '', carColor: '',
    entryTime: '', elapsedSec: 0,
    totalFee: 0, amountDue: 0,
    isPaid: false, history: [],
    paidFee: 0,
};


// ── Auto-scan config (same as entrance) ────────────────────────────────────────
const SCAN_INTERVAL_MS  = 2000;
const SCAN_COOLDOWN_MS  = 3000;
const SCAN_IMG_WIDTH    = 720;
const SCAN_JPEG_QUALITY = 0.88;


let camStream = null;
let autoScanTimer = null, autoScanBusy = false, autoScanPaused = false;
// FIX: lastDetectedPlate tracks camera detection only.
// It is reset on lookup failure so scanning can retry a different plate.
let lastDetectedPlate = '';
let recognition = null, recRunning = false, recPaused = false;
let fieldRec = null, elapsedTimer = null, resetTimer = null;
let plateInterimTimer = null;


function normalizePlateStr(s) {
    return String(s || '').toUpperCase().replace(/\s+/g, '').replace(/[^A-Z0-9]/g, '');
}
function spokenToCompactAlnum(text) {
    let u = String(text).toUpperCase()
        .replace(/\b(ZERO|OH)\b/g, '0')
        .replace(/\bONE\b/g, '1').replace(/\bTWO\b/g, '2').replace(/\bTHREE\b/g, '3')
        .replace(/\bFOUR\b/g, '4').replace(/\bFIVE\b/g, '5').replace(/\bSIX\b/g, '6')
        .replace(/\bSEVEN\b/g, '7').replace(/\bEIGHT\b/g, '8').replace(/\bNINE\b/g, '9');
    return u.replace(/[^A-Z0-9]/g, '');
}
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
function parsePlateSpeech(text) {
    return extractPlateFromCompact(spokenToCompactAlnum(text));
}


// ── Clock ──────────────────────────────────────────────────────────────────────
setInterval(() => {
    document.getElementById('navTime').textContent =
        new Date().toLocaleTimeString('en-PH', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
}, 1000);


// ── TTS ────────────────────────────────────────────────────────────────────────
/** Prefer a voice that actually outputs audio on Windows/Chrome (en-PH alone can be silent/remote). */
function pickExitTtsVoice() {
    const voices = window.speechSynthesis.getVoices();
    if (!voices || !voices.length) return null;
    const en = voices.filter(v => v.lang && /^en/i.test(v.lang));
    const pool = en.length ? en : voices;
    const pick = (arr, pred) => arr.find(pred) || null;
    const locals = pool.filter(v => v.localService);
    return pick(locals, v => /^en-us/i.test(v.lang))
        || pick(pool, v => /^en-us/i.test(v.lang))
        || pick(locals, v => /en-ph/i.test(v.lang))
        || pick(pool, v => /en-ph/i.test(v.lang))
        || pick(pool, v => /^en/i.test(v.lang))
        || pool[0];
}


function speak(text, onEnd, opts) {
    opts = opts || {};
    const deferMs = typeof opts.deferMs === 'number' ? opts.deferMs : 60;
    if (!window.speechSynthesis) { resumeRec(); if (onEnd) onEnd(); return; }
    pauseRec();
    window.speechSynthesis.cancel();
    try {
        if (window.speechSynthesis.paused) window.speechSynthesis.resume();
    } catch (e) { /* ignore */ }
    const run = () => {
        if (!window.speechSynthesis) { resumeRec(); if (onEnd) onEnd(); return; }
        const utt = new SpeechSynthesisUtterance(text);
        utt.rate = 0.93;
        const v = pickExitTtsVoice();
        if (v) {
            utt.voice = v;
            utt.lang = v.lang || 'en-PH';
        } else {
            utt.lang = 'en-PH';
        }
        let utterDone = false;
        const done = () => {
            if (utterDone) return;
            utterDone = true;
            resumeRec();
            if (onEnd) onEnd();
        };
        utt.onend = done;
        utt.onerror = done;
        try {
            window.speechSynthesis.speak(utt);
        } catch (e) {
            done();
        }
    };
    // Chrome/Edge: speak() right after cancel() (e.g. setStep) often stays silent; defer helps.
    setTimeout(run, deferMs);
}
window.speechSynthesis && (window.speechSynthesis.onvoiceschanged = () => { window.speechSynthesis.getVoices(); });


// ── Step tracker ───────────────────────────────────────────────────────────────
function setStep(n) {
    if (n !== 1 && plateInterimTimer) { clearTimeout(plateInterimTimer); plateInterimTimer = null; }
    S.step = n; window.speechSynthesis && window.speechSynthesis.cancel();
    for (let i = 1; i <= 3; i++) {
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
        case 1:
            renderScan(w);
            chips.innerHTML = `<span class="v-chip">"Scan"</span><span class="v-chip">Plate number</span>`;
            break;
        case 2:
            renderPaymentCheck(w);
            chips.innerHTML = S.isPaid
                ? `<span class="v-chip">"Exit"</span><span class="v-chip">"Confirm"</span>`
                : `<span class="v-chip">"Cash"</span><span class="v-chip">"Card"</span><span class="v-chip">"GCash"</span><span class="v-chip">"Maya"</span><span class="v-chip">"Pay now"</span>`;
            break;
        case 3:
            renderExitDone(w);
            chips.innerHTML = `<span class="v-chip">"Done"</span>`;
            break;
    }
}


// ── STEP 1: Scan ───────────────────────────────────────────────────────────────
function renderScan(w) {
    lastDetectedPlate = '';
    w.innerHTML = `
    <div class="card">
        <div class="card-title">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
            Vehicle Scan — Exit Gate
        </div>
        <div class="scan-layout">
            <div>
                <div class="cam-wrap" id="camWrap">
                    <div class="cam-placeholder" id="camPH">
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
                <div class="detect-row">
                    <span class="dr-lbl">Detected Plate</span>
                    <span class="dr-val highlight" id="dPlate">—</span>
                </div>
                <p style="font-size:0.82rem;font-weight:600;color:var(--muted);margin-bottom:10px;margin-top:10px;">
                    Or enter your plate number manually:
                </p>
                <div class="field-input-wrap">
                    <input type="text" class="field-input" id="manPlate"
                           placeholder="e.g. ABC1234" maxlength="12"
                           oninput="this.value=this.value.toUpperCase().replace(/\\s+/g,'')"
                           onkeydown="if(event.key==='Enter')manualLookup()">
                    <button class="field-stt-btn" id="sttManPlate" onclick="toggleSTT('manPlate','sttManPlate','plate')">
                        <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/></svg>
                    </button>
                </div>
                <button class="btn-search" onclick="manualLookup()">
                    <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Search Plate
                </button>
                <div id="scanError" style="margin-top:10px;"></div>
                <p style="font-size:0.7rem;color:var(--muted2);font-weight:600;margin-top:8px;">
                    Point your plate at the camera — auto-scan runs every 2 seconds.
                </p>
            </div>
        </div>
    </div>`;
    initCamera();
    setTimeout(() => speak('Welcome to the Parky exit kiosk. Scanning your plate automatically. You may also enter it manually.'), 400);
}


async function initCamera() {
    if (!navigator.mediaDevices?.getUserMedia) {
        const ph = document.querySelector('.cam-placeholder p');
        if (ph) ph.textContent = 'Camera not supported — use manual entry';
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
        const ph = document.getElementById('camPH'); if (ph) ph.style.display = 'none';
        document.getElementById('camWrap').insertBefore(vid, document.getElementById('camWrap').firstChild);
        document.getElementById('scanStatusBar').style.display = 'flex';
        vid.onloadedmetadata = () => startAutoScan();
    } catch(e) {
        const ph = document.querySelector('.cam-placeholder p');
        if (ph) ph.textContent = 'Camera unavailable — use manual entry';
    }
}


// ── Capture helper ─────────────────────────────────────────────────────────────
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


// ── Auto-scan loop ─────────────────────────────────────────────────────────────
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
    // FIX: Only skip if busy or in cooldown.
    // lastDetectedPlate being set does NOT stop the loop anymore — the lookup
    // may have failed (plate not in DB), so we must allow retry.
    if (autoScanBusy || autoScanPaused) return;


    // If a plate was detected and we're still on step 1, it means lookup
    // failed previously — clear it and try scanning again.
    if (lastDetectedPlate) {
        lastDetectedPlate = '';
        S.plate = '';
        const dp = document.getElementById('dPlate'); if (dp) dp.textContent = '—';
        const mi = document.getElementById('manPlate'); if (mi) mi.value = '';
    }


    const vid = document.getElementById('camVideo');
    if (!vid || vid.readyState < 2) return;


    autoScanBusy = true;
    setScanStatus('scanning', 'Scanning…');


    try {
        const fd = new FormData();
        fd.append('frame', captureFrame(vid));
        const data = await (await fetch('?action=detect', { method: 'POST', body: fd })).json();


        if (data.success && data.plate) {
            const plate = normalizePlateStr(data.plate);
            lastDetectedPlate = plate;
            S.plate = plate;


            // Show on UI
            const dp = document.getElementById('dPlate'); if (dp) dp.textContent = plate;
            const mi = document.getElementById('manPlate'); if (mi) mi.value = plate;
            document.getElementById('scanError').innerHTML = '';
            setScanStatus('found', 'Plate detected — looking up…');
            speak(`Plate ${plate} detected. Checking session.`);


            // FIX: Stop camera BEFORE lookup so the lookup result decides next step.
            // Do NOT set lastDetectedPlate permanently here — lookupPlateValue will
            // reset it if the plate is not found in the database.
            stopCamera();
            await lookupPlateValue(plate);
        } else {
            // No plate visible — enter cooldown before retrying
            autoScanPaused = true;
            setScanStatus('idle', 'No plate — retrying soon…');
            setTimeout(() => {
                autoScanPaused = false;
                if (S.step === 1) setScanStatus('active', 'Auto-detecting…');
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


// ── Manual scan trigger ────────────────────────────────────────────────────────
async function doManualScan() {
    const vid = document.getElementById('camVideo');
    if (!vid) { showScanErr('Camera not available. Enter plate manually.'); return; }
    if (autoScanBusy) return;


    stopAutoScan(); autoScanBusy = true;
    setScanStatus('scanning', 'Scanning…');
    const btn = document.getElementById('manualScanBtn'); if (btn) btn.disabled = true;


    const overlay = document.createElement('div');
    overlay.className = 'analyzing-overlay'; overlay.id = 'anaOv';
    overlay.innerHTML = '<div class="analyzing-text">SCANNING PLATE…</div>';
    document.getElementById('camWrap').appendChild(overlay);


    try {
        const fd = new FormData();
        fd.append('frame', captureFrame(vid, 0.88));
        const data = await (await fetch('?action=detect', { method: 'POST', body: fd })).json();
        document.getElementById('anaOv')?.remove();


        if (data.success && data.plate) {
            const plate = normalizePlateStr(data.plate);
            lastDetectedPlate = plate; S.plate = plate;
            const dp = document.getElementById('dPlate'); if (dp) dp.textContent = plate;
            const mi = document.getElementById('manPlate'); if (mi) mi.value = plate;
            document.getElementById('scanError').innerHTML = '';
            setScanStatus('found', 'Plate detected — looking up…');
            speak(`Plate ${plate} detected. Checking session.`);
            stopCamera();
            await lookupPlateValue(plate);
        } else {
            showScanErr('Plate not detected. Try again or enter manually.');
            setScanStatus('idle', 'Not detected — try again');
            setTimeout(() => { if (S.step === 1) startAutoScan(); }, 2500);
        }
    } catch(e) {
        document.getElementById('anaOv')?.remove();
        showScanErr('Scan error. Please enter plate manually.');
        setTimeout(() => { if (S.step === 1) startAutoScan(); }, 2500);
    } finally {
        autoScanBusy = false;
        if (btn) btn.disabled = false;
    }
}


// Manual Search Plate button
async function manualLookup() {
    const val = normalizePlateStr(document.getElementById('manPlate')?.value);
    if (!val) { showScanErr('Please enter a plate number.'); return; }
    S.plate = val;
    stopAutoScan();
    stopCamera();
    await lookupPlateValue(val);
}


function showScanErr(msg) {
    const el = document.getElementById('scanError');
    if (el) el.innerHTML = `<div class="alert danger"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><p>${msg}</p></div>`;
}


// ── Core lookup — shared by auto-scan, manual scan, and manual entry ───────────
// FIX: On lookup failure, resets lastDetectedPlate and restarts camera+scan
// so the user can try a different plate without refreshing the page.
async function lookupPlateValue(plate) {
    plate = normalizePlateStr(plate);
    if (!plate) {
        showScanErr('Please enter a plate number.');
        return;
    }
    const r = await fetch(`?action=lookup&plate=${encodeURIComponent(plate)}`);
    const d = await r.json();


    if (!d.found) {
        // Show error
        showScanErr(d.message || 'Session not found. Please check your plate number.');
        speak('No active session found for that plate. Please try again.');


        // FIX: Reset detection state so scanning can restart cleanly
        lastDetectedPlate = '';
        S.plate = '';
        const dp = document.getElementById('dPlate'); if (dp) dp.textContent = '—';
        const mi = document.getElementById('manPlate'); if (mi) mi.value = '';
        setScanStatus('idle', 'Not found — restarting camera…');


        // Restart camera and auto-scan so user can try again
        setTimeout(() => {
            if (S.step === 1) initCamera();
        }, 1500);
        return;
    }


    // Found — populate state and go to payment step
    Object.assign(S, {
        sessionId:   d.session_id,
        sessionType: d.session_type,
        slotCode:    d.slot_code,
        floor:       d.floor,
        carType:     d.car_type,
        carColor:    d.car_color,
        entryTime:   d.entry_time,
        elapsedSec:  d.elapsed_sec,
        totalFee:    d.total_fee,
        amountDue:   d.amount_due,
        isPaid:      d.is_paid,
        history:     d.history || [],
        paidFee:     d.total_fee - d.amount_due,
    });
    setStep(2);
}


function stopCamera() {
    stopAutoScan();
    if (camStream) { camStream.getTracks().forEach(t => t.stop()); camStream = null; }
}


// ── STEP 2: Payment Check ──────────────────────────────────────────────────────
function calcFee(sec) {
    if (sec <= 0) return 60;
    const mins = sec / 60, base = 180;
    if (mins <= base) return 60;
    const extra = mins - base, fullHrs = Math.floor(extra / 60), rem = extra - fullHrs * 60;
    let fee = 60 + fullHrs * 30;
    if (rem > 0 && rem <= 15) fee += 10;
    else if (rem > 15) fee += 30;
    return fee;
}


function renderPaymentCheck(w) {
    const histHTML = S.history.length > 0
        ? `<div class="pay-history"><div class="ph-title">Payment history</div>${S.history.map(p => `
            <div class="ph-row">
                <div>
                    <div class="ph-l">${new Date(p.paid_at).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})} · ${p.method.toUpperCase()}</div>
                    <div class="ph-rec">${p.receipt_number}</div>
                </div>
                <div class="ph-r">₱${parseFloat(p.total_amount).toFixed(2)}</div>
            </div>`).join('')}</div>` : '';


    const paySection = !S.isPaid ? `
    <div class="pay-section">
        <div class="pay-section-title">Select Payment Method</div>
        <div class="method-grid">
            <label class="method-opt"><input type="radio" name="exitMethod" value="cash" checked><span class="method-opt-lbl">Cash</span></label>
            <label class="method-opt"><input type="radio" name="exitMethod" value="card"><span class="method-opt-lbl">Card</span></label>
            <label class="method-opt"><input type="radio" name="exitMethod" value="gcash"><span class="method-opt-lbl">GCash</span></label>
            <label class="method-opt"><input type="radio" name="exitMethod" value="maya"><span class="method-opt-lbl">Maya</span></label>
        </div>
        <div class="pay-status" id="payStatus"></div>
    </div>` : '';


    const statusBadge = S.isPaid
        ? `<span style="background:var(--emeraldbg);border:1px solid rgba(16,185,129,0.2);color:var(--emerald);font-size:0.72rem;font-weight:800;padding:4px 12px;border-radius:20px;">Paid ✓</span>`
        : `<span style="background:var(--dangerbg);border:1px solid rgba(239,68,68,0.2);color:var(--danger);font-size:0.72rem;font-weight:800;padding:4px 12px;border-radius:20px;">Payment Required</span>`;


    const actionBtns = S.isPaid
        ? `<button class="btn btn-ghost" onclick="goBackToScan()"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Back</button>
           <button class="btn btn-primary" onclick="processExit()">Allow Exit <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>`
        : `<button class="btn btn-ghost" onclick="goBackToScan()"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg> Back</button>
           <button class="btn btn-pay" onclick="payAndExit()">Pay ₱<span id="payAmt">${S.amountDue.toFixed(2)}</span> &amp; Exit</button>`;


    w.innerHTML = `
    <div class="session-grid">
        <div>
            <div class="card">
                <div class="card-title" style="justify-content:space-between;">
                    <span style="display:flex;align-items:center;gap:8px;">
                        <svg viewBox="0 0 24 24"><path d="M1 3h22v13a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2z"/><line x1="1" y1="20" x2="23" y2="20"/></svg>
                        Payment Check
                    </span>
                    ${statusBadge}
                </div>
                <div class="detail-rows">
                    <div class="detail-row"><span class="dr-l">Plate</span><span class="dr-r green">${S.plate}</span></div>
                    <div class="detail-row"><span class="dr-l">Slot</span><span class="dr-r">${S.slotCode} — Floor ${S.floor}</span></div>
                    <div class="detail-row"><span class="dr-l">Vehicle</span><span class="dr-r">${S.carType} · ${S.carColor}</span></div>
                    <div class="detail-row"><span class="dr-l">Entry Time</span><span class="dr-r">${S.entryTime}</span></div>
                </div>
                ${S.isPaid
                    ? `<div class="alert success"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg><p><strong>Payment confirmed.</strong> This vehicle is cleared to exit.</p></div>`
                    : `<div class="alert warning"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><p><strong>Outstanding balance.</strong> Select a payment method below.</p></div>`}
                ${paySection}
                ${histHTML}
                <div id="payError" style="margin-top:8px;"></div>
                <div class="btn-row">${actionBtns}</div>
            </div>
        </div>
        <div>
            <div class="fee-card">
                <div class="fee-card-title">Live Fee</div>
                <div class="elapsed-display">
                    <div class="elapsed-time" id="elapsedDisp">00:00:00</div>
                    <div class="elapsed-sub">hours : minutes : seconds</div>
                </div>
                <div class="fee-row f-total">
                    <div class="fr-l">Total fee<small>Updates every second</small></div>
                    <div class="fr-r green" id="feeTotal">₱${S.totalFee.toFixed(2)}</div>
                </div>
                <div class="fee-row ${S.amountDue > 0 ? 'f-due' : 'f-clear'}">
                    <div class="fr-l">Amount due</div>
                    <div class="fr-r ${S.amountDue > 0 ? 'yellow' : 'green'}" id="feeDue">₱${S.amountDue.toFixed(2)}</div>
                </div>
            </div>
        </div>
    </div>`;


    startElapsedTimer();
    // setStep(2) calls cancel() right before this render; Chrome often drops TTS if speak runs in the same turn.
    const paymentStepMsg = S.isPaid
        ? `Payment verified. Plate ${S.plate} is cleared to exit. Tap Allow Exit to open the gate.`
        : `Outstanding balance of ${S.amountDue.toFixed(0)} pesos. Say Cash, Card, GCash, or Maya to choose a method, then say Pay now to confirm, or tap Pay to exit.`;
    setTimeout(() => {
        if (S.step !== 2) return;
        speak(paymentStepMsg, null, { deferMs: 40 });
    }, 220);
}


function startElapsedTimer() {
    if (elapsedTimer) clearInterval(elapsedTimer);
    let base = S.elapsedSec;
    elapsedTimer = setInterval(() => {
        base++;
        const hh = String(Math.floor(base / 3600)).padStart(2, '0');
        const mm = String(Math.floor((base % 3600) / 60)).padStart(2, '0');
        const ss = String(base % 60).padStart(2, '0');
        const el = document.getElementById('elapsedDisp'); if (el) el.textContent = `${hh}:${mm}:${ss}`;
        const fee = calcFee(base);
        const due = Math.max(0, fee - S.paidFee);
        const ft = document.getElementById('feeTotal'); if (ft) ft.textContent = `₱${fee.toFixed(2)}`;
        const fd = document.getElementById('feeDue'); if (fd) { fd.textContent = `₱${due.toFixed(2)}`; fd.className = 'fr-r ' + (due > 0 ? 'yellow' : 'green'); }
        const pa = document.getElementById('payAmt'); if (pa) pa.textContent = due.toFixed(2);
    }, 1000);
}


function goBackToScan() {
    if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
    lastDetectedPlate = ''; S.plate = '';
    setStep(1);
}


async function payAndExit() {
    const method = document.querySelector('input[name="exitMethod"]:checked')?.value || 'cash';
    const statusEl = document.getElementById('payStatus');
    if (statusEl) statusEl.innerHTML = '<span style="color:var(--emerald);">Processing payment…</span>';


    const fd = new FormData();
    fd.append('session_id',   S.sessionId);
    fd.append('session_type', S.sessionType);
    fd.append('method',       method);
    const d = await (await fetch('?action=pay', { method: 'POST', body: fd })).json();


    if (!d.success) {
        if (statusEl) statusEl.innerHTML = `<span style="color:var(--danger);">✗ ${d.message}</span>`;
        const errEl = document.getElementById('payError');
        if (errEl) errEl.innerHTML = `<div class="alert danger"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><p>${d.message}</p></div>`;
        return;
    }


    S.isPaid  = true;
    S.paidFee = S.totalFee;
    if (statusEl) statusEl.innerHTML = '<span style="color:var(--emerald);">✓ Payment confirmed!</span>';
    speak(`Payment of ${d.amount_paid.toFixed(0)} pesos confirmed via ${method}. Receipt ${d.receipt_number}. Processing exit.`);
    if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
    setTimeout(() => processExit(), 900);
}


async function processExit() {
    if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
    const fd = new FormData(); fd.append('plate', S.plate);
    const d = await (await fetch('?action=process_exit', { method: 'POST', body: fd })).json();
    if (!d.success) {
        const errEl = document.getElementById('payError');
        if (errEl) errEl.innerHTML = `<div class="alert danger"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><p>${d.message}</p></div>`;
        return;
    }
    setStep(3);
}


// ── STEP 3: Exit Done ──────────────────────────────────────────────────────────
function renderExitDone(w) {
    w.innerHTML = `
    <div class="card"><div class="exit-success">
        <div class="exit-icon"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
        <h2 class="exit-title">Exit Complete!</h2>
        <p class="exit-sub">Slot ${S.slotCode} on Floor ${S.floor} has been released. Drive safely!</p>
        <div class="exit-details">
            <div class="edet"><div class="edet-lbl">Plate</div><div class="edet-val green">${S.plate}</div></div>
            <div class="edet"><div class="edet-lbl">Slot Released</div><div class="edet-val">${S.slotCode}</div></div>
            <div class="edet"><div class="edet-lbl">Floor</div><div class="edet-val">${S.floor}</div></div>
        </div>
        <div class="alert success" style="max-width:420px;margin:0 auto 1.5rem;">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <p>Thank you for parking at <strong>Parky</strong>! Have a safe journey home.</p>
        </div>
        <p class="reset-countdown">Kiosk resets in <strong id="cdNum">10</strong> seconds…</p>
    </div></div>`;
    speak(`Exit complete. Slot ${S.slotCode} has been released. Thank you for parking at Parky. Have a safe journey!`);
    startResetCountdown(10);
}


function startResetCountdown(secs) {
    if (resetTimer) clearInterval(resetTimer);
    let rem = secs;
    resetTimer = setInterval(() => {
        rem--;
        const el = document.getElementById('cdNum'); if (el) el.textContent = rem;
        if (rem <= 0) { clearInterval(resetTimer); resetKiosk(); }
    }, 1000);
}


function resetKiosk() {
    if (resetTimer)   { clearInterval(resetTimer);   resetTimer   = null; }
    if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
    stopCamera(); window.speechSynthesis && window.speechSynthesis.cancel();
    Object.assign(S, { step:1, plate:'', sessionId:0, sessionType:'', slotCode:'', floor:0, carType:'', carColor:'', entryTime:'', elapsedSec:0, totalFee:0, amountDue:0, isPaid:false, history:[], paidFee:0 });
    lastDetectedPlate = '';
    setStep(1);
}


// ── STT for fields (pause main mic — one Web Speech session at a time) ─────────
function toggleSTT(inputId, btnId, sttMode) {
    if (fieldRec) {
        try { fieldRec.stop(); } catch(e) {
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


// ── Continuous voice ───────────────────────────────────────────────────────────
function initVoice() {
    const st = document.getElementById('voiceStatus');
    if (!('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) {
        if (st) st.textContent = 'Voice not supported'; return;
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
    recognition.onstart  = () => { recRunning = true; document.getElementById('voiceInd').classList.add('listening'); document.getElementById('voiceStatus').textContent = 'Listening…'; };
    recognition.onresult = e => {
        let interimBuf = '';
        for (let i = 0; i < e.results.length; i++) {
            if (!e.results[i].isFinal) interimBuf += e.results[i][0].transcript;
        }
        if (S.step === 1 && interimBuf.trim()) {
            clearTimeout(plateInterimTimer);
            const snap = interimBuf;
            plateInterimTimer = setTimeout(() => {
                plateInterimTimer = null;
                if (S.step !== 1) return;
                const p = parsePlateSpeech(snap);
                if (p) {
                    const el = document.getElementById('manPlate');
                    if (el) el.value = p;
                    S.plate = p;
                }
            }, 420);
        }
        for (let i = e.resultIndex; i < e.results.length; i++) {
            const res = e.results[i];
            if (!res.isFinal) continue;
            if (plateInterimTimer) { clearTimeout(plateInterimTimer); plateInterimTimer = null; }
            const t = pickBestTranscriptForResult(res);
            document.getElementById('voiceTrans').textContent = `"${t}"`;
            setTimeout(() => { const el = document.getElementById('voiceTrans'); if (el) el.textContent = ''; }, 2500);
            handleVoice(t);
        }
    };
    recognition.onend   = () => { recRunning = false; document.getElementById('voiceInd').classList.remove('listening'); if (!recPaused) setTimeout(startRec, 60); };
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
            if (/\b(scan|detect|read)\b/.test(t)) void doManualScan();
            else if (/\b(search|check|lookup)\b/.test(t)) void manualLookup();
            else {
                const plate = parsePlateSpeech(t);
                if (plate) {
                    const el = document.getElementById('manPlate');
                    if (el) el.value = plate;
                    S.plate = plate;
                    stopAutoScan();
                    stopCamera();
                    void lookupPlateValue(plate);
                }
            }
            break;
        case 2:
            if (/\bback\b/.test(t)) { goBackToScan(); break; }
            if (S.isPaid) {
                if (/\b(exit|confirm|proceed|go)\b/.test(t)) processExit();
            } else {
                if (/\b(pay\s+now|confirm\s+pay|yes\s+pay|submit\s+payment|process\s+payment|complete\s+payment|pay\s+and\s+exit)\b/i.test(t)) {
                    void payAndExit();
                } else {
                    let m = null;
                    if (/\bgcash\b/i.test(t)) m = 'gcash';
                    else if (/\bmaya\b/i.test(t)) m = 'maya';
                    else if (/\bcash\b/i.test(t)) m = 'cash';
                    else if (/\bcard\b/i.test(t)) m = 'card';
                    if (m) {
                        const opt = document.querySelector(`input[name="exitMethod"][value="${m}"]`);
                        if (opt) opt.checked = true;
                        const label = m === 'gcash' ? 'GCash' : m === 'maya' ? 'Maya' : m.charAt(0).toUpperCase() + m.slice(1);
                        speak(`${label} selected. Say Pay now to confirm, or tap the Pay button.`, null, { deferMs: 80 });
                    }
                }
            }
            break;
        case 3:
            if (/\b(done|new|session|again|reset)\b/.test(t)) { clearInterval(resetTimer); resetKiosk(); }
            break;
    }
}


function pickBestTranscriptForResult(res) {
    if (S.step === 1) {
        for (let k = 0; k < res.length; k++) {
            if (parsePlateSpeech(res[k].transcript)) return res[k].transcript.toLowerCase().trim();
        }
    }
    return res[0].transcript.toLowerCase().trim();
}


window.addEventListener('load', () => { initVoice(); setStep(1); });
</script>
</body>
</html>

