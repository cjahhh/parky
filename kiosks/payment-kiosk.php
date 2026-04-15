<?php
// kiosks/payment-kiosk.php


require_once __DIR__ . '/includes/kiosk-auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/rates.php';


// ── Helpers ────────────────────────────────────────────────
function generateReceiptNumber(): string {
    $date = date('Ymd');
    $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    return "RCP-{$date}-{$rand}";
}


function formatElapsed(int $seconds): string {
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}



// ── AJAX: Plate Lookup ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'lookup') {
    header('Content-Type: application/json');
    $plate = strtoupper(trim($_GET['plate'] ?? ''));
    if (!$plate) { echo json_encode(['found' => false, 'message' => 'Please enter a plate number.']); exit; }


    $now = time();
    $session = null;


    // Check walk-in sessions
    $stmt = $pdo->prepare("
        SELECT sw.*, ps.slot_code, ps.floor
        FROM sessions_walkin sw
        JOIN parking_slots ps ON sw.slot_id = ps.id
        WHERE UPPER(sw.plate_number) = ? AND sw.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$plate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);


    if ($row) {
        $elapsed  = $now - strtotime($row['entry_time']);
        $totalFee = calculateFee($elapsed);
        $amountDue = getAmountDue($elapsed, $row['paid_until'], $row['entry_time']);


        // Fetch payment history for this session
        $ph = $pdo->prepare("SELECT receipt_number, total_amount, method, paid_at FROM payments WHERE session_id = ? AND session_type = 'walkin' ORDER BY paid_at ASC");
        $ph->execute([$row['id']]);
        $history = $ph->fetchAll(PDO::FETCH_ASSOC);


        $session = [
            'type'          => 'walkin',
            'session_id'    => $row['id'],
            'plate_number'  => $row['plate_number'],
            'slot_code'     => $row['slot_code'],
            'floor'         => $row['floor'],
            'car_type'      => $row['car_type'] ?? '—',
            'car_color'     => $row['car_color'] ?? '—',
            'entry_time'    => $row['entry_time'],
            'elapsed'       => $elapsed,
            'payment_status'=> $row['payment_status'],
            'paid_until'    => $row['paid_until'],
            'total_fee'     => $totalFee,
            'amount_due'    => $amountDue,
            'user_id'       => $row['user_id'],
            'history'       => $history,
        ];
    }


    // Check reservation sessions (must be 'active' — scanned at entrance)
    if (!$session) {
        $stmt = $pdo->prepare("
            SELECT r.*, ps.slot_code, ps.floor
            FROM reservations r
            JOIN parking_slots ps ON r.slot_id = ps.id
            WHERE UPPER(r.plate_number) = ? AND r.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$plate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);


        if ($row) {
            $elapsed  = $now - strtotime($row['arrival_time']);
            $totalFee = calculateFee($elapsed);
            $amountDue = getAmountDue($elapsed, $row['paid_until'], $row['arrival_time']);


            $ph = $pdo->prepare("SELECT receipt_number, total_amount, method, paid_at FROM payments WHERE session_id = ? AND session_type = 'reservation' ORDER BY paid_at ASC");
            $ph->execute([$row['id']]);
            $history = $ph->fetchAll(PDO::FETCH_ASSOC);


            $session = [
                'type'          => 'reservation',
                'session_id'    => $row['id'],
                'plate_number'  => $row['plate_number'],
                'slot_code'     => $row['slot_code'],
                'floor'         => $row['floor'],
                'car_type'      => $row['car_type'],
                'car_color'     => $row['car_color'],
                'entry_time'    => $row['arrival_time'],
                'elapsed'       => $elapsed,
                'payment_status'=> $row['payment_status'],
                'paid_until'    => $row['paid_until'],
                'total_fee'     => $totalFee,
                'amount_due'    => $amountDue,
                'user_id'       => $row['user_id'],
                'extension_fee' => (float)$row['extension_fee'],
                'history'       => $history,
            ];
        }


        // Not active — check if it exists but not yet scanned
        if (!$session) {
            $stmt = $pdo->prepare("SELECT id FROM reservations WHERE UPPER(plate_number) = ? AND status IN ('pending','confirmed') LIMIT 1");
            $stmt->execute([$plate]);
            if ($stmt->fetch()) {
                echo json_encode(['found' => false, 'message' => 'This reservation has not been scanned at the entrance kiosk yet. Please proceed to the entrance first.']);
                exit;
            }
        }
    }


    if (!$session) {
        echo json_encode(['found' => false, 'message' => 'No active parking session found for plate <strong>' . htmlspecialchars($plate) . '</strong>. Make sure you have been scanned at the entrance kiosk.']);
        exit;
    }


    // Fetch registered user email if linked
    if ($session['user_id']) {
        $us = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $us->execute([$session['user_id']]);
        $usr = $us->fetch(PDO::FETCH_ASSOC);
        $session['user_email'] = $usr['email'] ?? null;
    } else {
        $session['user_email'] = null;
    }


    echo json_encode(['found' => true, 'session' => $session]);
    exit;
}


// ── AJAX: Process Payment ───────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'pay') {
    header('Content-Type: application/json');


    $sessionId   = (int)($_POST['session_id'] ?? 0);
    $sessionType = $_POST['session_type'] ?? '';
    $method      = $_POST['method'] ?? '';


    $allowed_methods = ['cash','card','gcash','maya'];
    $allowed_types   = ['walkin','reservation'];


    if (!$sessionId || !in_array($sessionType, $allowed_types) || !in_array($method, $allowed_methods)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment data.']); exit;
    }


    // Re-fetch session to get fresh elapsed + amounts
    $now = time();
    if ($sessionType === 'walkin') {
        $stmt = $pdo->prepare("SELECT * FROM sessions_walkin WHERE id = ? AND status = 'active'");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ? AND status = 'active'");
    }
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$row) { echo json_encode(['success' => false, 'message' => 'Session no longer active.']); exit; }


    $entryTime   = $sessionType === 'walkin' ? $row['entry_time'] : $row['arrival_time'];
    $elapsed     = $now - strtotime($entryTime);
    $totalFee    = calculateFee($elapsed);
    $amountDue   = getAmountDue($elapsed, $row['paid_until'], $entryTime);


    if ($amountDue <= 0) {
        echo json_encode(['success' => false, 'message' => 'No outstanding balance at this time.']); exit;
    }


    $paidUntilNew  = date('Y-m-d H:i:s');
    $receiptNumber = generateReceiptNumber();
    $extensionFee  = ($sessionType === 'reservation') ? (float)($row['extension_fee'] ?? 0) : 0.0;


    try {
        $pdo->beginTransaction();


        // Insert payment
        $ins = $pdo->prepare("
            INSERT INTO payments (session_id, session_type, base_amount, extension_fee, total_amount, method, source, receipt_number)
            VALUES (?, ?, ?, ?, ?, ?, 'kiosk', ?)
        ");
        $ins->execute([$sessionId, $sessionType, $amountDue, $extensionFee, $amountDue, $method, $receiptNumber]);


        // Update session
        if ($sessionType === 'walkin') {
            $upd = $pdo->prepare("UPDATE sessions_walkin SET payment_status = 'paid', paid_until = ? WHERE id = ?");
        } else {
            $upd = $pdo->prepare("UPDATE reservations SET payment_status = 'paid', paid_until = ? WHERE id = ?");
        }
        $upd->execute([$paidUntilNew, $sessionId]);


        $pdo->commit();


        // Send receipt email if provided
        $emailSent = false;
        $emailAddr = trim($_POST['email'] ?? '');
        if ($emailAddr && filter_var($emailAddr, FILTER_VALIDATE_EMAIL)) {
            try {
                require_once __DIR__ . '/../config/mailer.php';
                $mail = createMailer();
                $mail->addAddress($emailAddr);
                $mail->Subject = "Parky Parking Receipt — {$receiptNumber}";


                $slotStmt = $pdo->prepare("SELECT ps.slot_code, ps.floor FROM parking_slots ps JOIN " . ($sessionType === 'walkin' ? 'sessions_walkin' : 'reservations') . " s ON s.slot_id = ps.id WHERE s.id = ?");
                $slotStmt->execute([$sessionId]);
                $slotInfo = $slotStmt->fetch(PDO::FETCH_ASSOC);
                $slotCode  = $slotInfo['slot_code'] ?? '—';
                $slotFloor = $slotInfo['floor'] ?? '—';


                $plate    = htmlspecialchars($row['plate_number']);
                $carType  = htmlspecialchars($row['car_type'] ?? '—');
                $carColor = htmlspecialchars($row['car_color'] ?? '—');
                $elapsed_fmt = formatElapsed($elapsed);
                $amtFmt   = '₱' . number_format($amountDue, 2);
                $paidAt   = date('M d, Y h:i A');
                $methodUC = strtoupper($method);


                $mail->Body = "
                <div style='font-family:Nunito,sans-serif;background:#151815;color:#eaf2ea;padding:2rem;border-radius:16px;max-width:480px;margin:auto;'>
                  <div style='text-align:center;margin-bottom:1.5rem;'>
                    <div style='display:inline-block;background:rgba(52,211,153,0.15);border:1.5px solid rgba(52,211,153,0.3);border-radius:12px;padding:10px 20px;font-size:1.2rem;font-weight:800;color:#34d399;'>P Parky</div>
                  </div>
                  <h2 style='font-size:1rem;font-weight:800;color:#eaf2ea;margin-bottom:0.25rem;'>Payment Receipt</h2>
                  <p style='font-size:0.82rem;color:#7a907a;margin-bottom:1.5rem;'>{$paidAt} &nbsp;·&nbsp; {$receiptNumber}</p>
                  <table style='width:100%;font-size:0.85rem;border-collapse:collapse;'>
                    <tr><td style='padding:6px 0;color:#7a907a;'>Plate</td><td style='text-align:right;font-weight:700;'>{$plate}</td></tr>
                    <tr><td style='padding:6px 0;color:#7a907a;'>Slot</td><td style='text-align:right;font-weight:700;'>{$slotCode} &nbsp; Floor {$slotFloor}</td></tr>
                    <tr><td style='padding:6px 0;color:#7a907a;'>Car</td><td style='text-align:right;font-weight:700;'>{$carType} · {$carColor}</td></tr>
                    <tr><td style='padding:6px 0;color:#7a907a;'>Time parked</td><td style='text-align:right;font-weight:700;'>{$elapsed_fmt}</td></tr>
                    <tr><td style='padding:6px 0;color:#7a907a;'>Method</td><td style='text-align:right;font-weight:700;'>{$methodUC}</td></tr>
                  </table>
                  <div style='margin-top:1rem;background:rgba(52,211,153,0.08);border:1px solid rgba(52,211,153,0.2);border-radius:11px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;'>
                    <span style='font-size:0.85rem;color:#7a907a;font-weight:700;'>Amount paid</span>
                    <span style='font-size:1.3rem;font-weight:800;color:#34d399;'>{$amtFmt}</span>
                  </div>
                  <p style='font-size:0.75rem;color:#4f5f4f;text-align:center;margin-top:1.5rem;'>Thank you for parking with Parky. Please proceed to the exit kiosk when you're ready to leave.</p>
                </div>";
                $mail->AltBody = "Parky Receipt {$receiptNumber} | Plate: {$plate} | Slot: {$slotCode} | Amount: {$amtFmt} | Method: {$methodUC} | Paid: {$paidAt}";
                $mail->send();
                $emailSent = true;
            } catch (Exception $e) {
                // Email failure is non-fatal — payment already committed
            }
        }


        echo json_encode([
            'success'        => true,
            'receipt_number' => $receiptNumber,
            'amount_paid'    => $amountDue,
            'paid_until'     => $paidUntilNew,
            'method'         => $method,
            'email_sent'     => $emailSent,
        ]);


    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Payment failed. Please try again.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parky — Payment Kiosk</title>
          <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg:#151815; --bg2:#1a1e1a; --bg3:#1f241f; --surface:#252a25; --surface2:#2c322c; --surface3:#323832;
            --border:rgba(255,255,255,0.07); --border2:rgba(255,255,255,0.11);
            --text:#eaf2ea; --muted:#7a907a; --muted2:#4f5f4f;
            --emerald:#34d399; --emerald2:#10b981; --emeraldbg:rgba(52,211,153,0.08); --emeraldbg2:rgba(52,211,153,0.15);
            --danger:#f87171; --dangerbg:rgba(248,113,113,0.10);
            --warning:#fbbf24; --warningbg:rgba(251,191,36,0.10);
            --info:#60a5fa; --infobg:rgba(96,165,250,0.10);
        }
        body { font-family:'Nunito',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }


        /* NAV */
        nav { height:60px; background:var(--bg2); border-bottom:1px solid var(--border2); display:flex; align-items:center; padding:0 2rem; gap:1rem; position:sticky; top:0; z-index:50; }
        .nav-logo { display:flex; align-items:center; gap:9px; text-decoration:none; margin-right:auto; }
        .nav-logo-icon { width:32px; height:32px; background:var(--emeraldbg2); border:1.5px solid rgba(52,211,153,0.3); border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:0.85rem; font-weight:800; color:var(--emerald); }
        .nav-logo-text { font-size:1.1rem; font-weight:800; color:var(--emerald); }
        .nav-kiosk-badge { background:var(--emeraldbg); border:1px solid rgba(52,211,153,0.25); color:var(--emerald); font-size:0.72rem; font-weight:700; padding:4px 12px; border-radius:20px; }
        .btn-tts { display:flex; align-items:center; gap:7px; background:var(--surface); border:1.5px solid var(--border2); border-radius:10px; padding:7px 14px; font-family:'Nunito',sans-serif; font-size:0.8rem; font-weight:700; color:var(--muted); cursor:pointer; transition:all 0.2s; }
        .btn-tts svg { width:15px; height:15px; stroke:var(--muted); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; transition:stroke 0.2s; }
        .btn-tts:hover, .btn-tts.speaking { background:var(--emeraldbg2); border-color:var(--emerald); color:var(--emerald); }
        .btn-tts:hover svg, .btn-tts.speaking svg { stroke:var(--emerald); }
        @keyframes ttsPulse { 0%,100%{box-shadow:0 0 0 0 rgba(52,211,153,0.35);}50%{box-shadow:0 0 0 8px rgba(52,211,153,0);} }
        .btn-tts.speaking { animation:ttsPulse 1.2s ease-in-out infinite; }


        /* PAGE */
        .page { max-width:960px; margin:0 auto; padding:2.5rem 1.5rem 4rem; }


        /* IDLE SCREEN */
        #idleScreen { display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:70vh; text-align:center; gap:1.5rem; }
        .idle-icon { width:72px; height:72px; background:var(--emeraldbg2); border:2px solid rgba(52,211,153,0.3); border-radius:20px; display:flex; align-items:center; justify-content:center; font-size:1.6rem; font-weight:800; color:var(--emerald); }
        .idle-title { font-size:2rem; font-weight:800; color:var(--text); letter-spacing:-0.03em; }
        .idle-sub   { font-size:0.95rem; color:var(--muted); font-weight:500; max-width:380px; line-height:1.6; }
        .idle-rates { display:flex; gap:1rem; flex-wrap:wrap; justify-content:center; margin-top:0.5rem; }
        .rate-chip  { background:var(--surface); border:1px solid var(--border2); border-radius:10px; padding:10px 18px; }
        .rate-chip .rc-lbl { font-size:0.68rem; font-weight:700; color:var(--muted2); text-transform:uppercase; letter-spacing:0.04em; }
        .rate-chip .rc-val { font-size:0.92rem; font-weight:800; color:var(--text); margin-top:2px; }


        /* SEARCH */
        .search-wrap { display:flex; flex-direction:column; gap:1.25rem; max-width:500px; margin:0 auto; width:100%; }
        .search-label { font-size:0.75rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; }
        .search-input-row { display:flex; gap:10px; }
        .search-input {
            flex:1; background:var(--surface); border:1.5px solid var(--border2); border-radius:12px;
            padding:14px 18px; font-family:'Nunito',sans-serif; font-size:1.1rem; font-weight:800;
            color:var(--text); letter-spacing:0.06em; text-transform:uppercase; outline:none;
            transition:border-color 0.2s;
        }
        .search-input::placeholder { color:var(--muted2); letter-spacing:0.02em; font-weight:600; }
        .search-input:focus { border-color:rgba(52,211,153,0.5); }
        .btn-stt {
            background:var(--surface); border:1.5px solid var(--border2); border-radius:12px;
            padding:14px 16px; cursor:pointer; display:flex; align-items:center; justify-content:center;
            transition:all 0.2s; flex-shrink:0;
        }
        .btn-stt svg { width:20px; height:20px; stroke:var(--muted); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; transition:stroke 0.2s; }
        .btn-stt:hover, .btn-stt.listening { background:var(--emeraldbg2); border-color:var(--emerald); }
        .btn-stt:hover svg, .btn-stt.listening svg { stroke:var(--emerald); }
        @keyframes sttPulse { 0%,100%{box-shadow:0 0 0 0 rgba(52,211,153,0.4);}50%{box-shadow:0 0 0 10px rgba(52,211,153,0);} }
        .btn-stt.listening { animation:sttPulse 1s ease-in-out infinite; }
        .btn-search {
            width:100%; background:var(--emerald2); color:#0a1a12; border:none; border-radius:12px;
            padding:14px; font-family:'Nunito',sans-serif; font-size:0.95rem; font-weight:800;
            cursor:pointer; transition:background 0.2s, transform 0.1s;
        }
        .btn-search:hover  { background:var(--emerald); }
        .btn-search:active { transform:scale(0.98); }
        .btn-search:disabled { background:var(--surface3); color:var(--muted); cursor:not-allowed; }
        .search-hint { font-size:0.78rem; color:var(--muted2); font-weight:600; text-align:center; }


        /* ERROR STATE */
        .state-box { border-radius:14px; padding:1.25rem 1.5rem; display:flex; align-items:flex-start; gap:14px; margin-bottom:1rem; }
        .state-box.error    { background:var(--dangerbg); border:1px solid rgba(248,113,113,0.2); }
        .state-box.warning  { background:var(--warningbg); border:1px solid rgba(251,191,36,0.25); }
        .state-box.info     { background:var(--infobg); border:1px solid rgba(96,165,250,0.2); }
        .state-icon { width:36px; height:36px; border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .state-icon svg { width:17px; height:17px; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .state-box.error   .state-icon { background:rgba(248,113,113,0.15); } .state-box.error   .state-icon svg { stroke:var(--danger); }
        .state-box.warning .state-icon { background:rgba(251,191,36,0.15);  } .state-box.warning .state-icon svg { stroke:var(--warning); }
        .state-box.info    .state-icon { background:rgba(96,165,250,0.15);   } .state-box.info    .state-icon svg { stroke:var(--info); }
        .state-title { font-size:0.9rem; font-weight:800; margin-bottom:3px; }
        .state-box.error   .state-title { color:var(--danger); }
        .state-box.warning .state-title { color:var(--warning); }
        .state-box.info    .state-title { color:var(--info); }
        .state-sub { font-size:0.8rem; color:var(--muted); font-weight:500; line-height:1.6; }


        /* SESSION LAYOUT */
        #sessionView { display:none; }
        .session-grid { display:grid; grid-template-columns:1fr 320px; gap:1rem; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);} }
        .session-grid { animation:fadeUp 0.35s cubic-bezier(0.22,1,0.36,1) both; }


        /* MAIN CARD */
        .s-card { background:var(--bg3); border:1px solid var(--border2); border-radius:16px; padding:1.5rem; }
        .s-card + .s-card { margin-top:1rem; }
        .card-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.25rem; gap:1rem; }
        .slot-badge { background:var(--emeraldbg2); border:1.5px solid rgba(52,211,153,0.35); border-radius:10px; padding:8px 14px; text-align:center; flex-shrink:0; }
        .slot-badge .sb-code  { font-size:1.1rem; font-weight:800; color:var(--emerald); line-height:1; }
        .slot-badge .sb-floor { font-size:0.65rem; color:var(--emerald); opacity:0.7; margin-top:2px; font-weight:700; }
        .card-head-text h2 { font-size:1rem; font-weight:800; color:var(--text); }
        .card-head-text p  { font-size:0.78rem; color:var(--muted); margin-top:2px; font-weight:500; }
        .badges { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
        .badge { font-size:0.68rem; font-weight:700; padding:4px 10px; border-radius:20px; }
        .badge-active  { background:var(--emeraldbg);  border:1px solid rgba(52,211,153,0.25); color:var(--emerald); }
        .badge-res     { background:var(--infobg);      border:1px solid rgba(96,165,250,0.2);   color:var(--info); }
        .badge-paid    { background:var(--emeraldbg);   border:1px solid rgba(52,211,153,0.25); color:var(--emerald); }
        .badge-unpaid  { background:var(--dangerbg);    border:1px solid rgba(248,113,113,0.25); color:var(--danger); }
        .badge-owed    { background:var(--warningbg);   border:1px solid rgba(251,191,36,0.25);  color:var(--warning); }


        .stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:1.25rem; }
        .stat { background:var(--surface); border-radius:10px; padding:11px 13px; }
        .stat-lbl { font-size:0.65rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px; }
        .stat-val { font-size:0.95rem; font-weight:800; color:var(--text); line-height:1.2; }
        .stat-val.green  { color:var(--emerald); }
        .stat-val.yellow { color:var(--warning); }
        .stat-val.red    { color:var(--danger); }


        .car-row { background:var(--surface); border-radius:10px; padding:11px 14px; display:flex; gap:2rem; flex-wrap:wrap; margin-bottom:1.25rem; }
        .cr-item .cr-lbl { font-size:0.65rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:3px; }
        .cr-item .cr-val { font-size:0.85rem; font-weight:700; color:var(--text); }


        /* FEE BREAKDOWN */
        .fee-row { border-radius:10px; padding:11px 14px; display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
        .fee-row.f-total  { background:var(--emeraldbg);  border:1px solid rgba(52,211,153,0.18); }
        .fee-row.f-paid   { background:rgba(52,211,153,0.05); border:1px solid rgba(52,211,153,0.12); }
        .fee-row.f-owed   { background:var(--warningbg);  border:1px solid rgba(251,191,36,0.25); }
        .fee-row.f-zero   { background:var(--infobg);     border:1px solid rgba(96,165,250,0.2); }
        .fr-lbl { font-size:0.78rem; font-weight:700; color:var(--muted); }
        .fr-lbl small { display:block; font-size:0.67rem; color:var(--muted2); margin-top:2px; }
        .fr-amt { font-size:1.35rem; font-weight:800; font-variant-numeric:tabular-nums; }
        .fr-amt.green  { color:var(--emerald); }
        .fr-amt.green2 { color:var(--emerald2); }
        .fr-amt.yellow { color:var(--warning); }
        .fr-amt.blue   { color:var(--info); }


        .notice { border-radius:10px; padding:10px 13px; display:flex; align-items:center; gap:9px; margin-bottom:8px; }
        .notice svg { width:14px; height:14px; fill:none; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; flex-shrink:0; }
        .notice p { font-size:0.77rem; font-weight:600; line-height:1.5; color:var(--muted); }
        .notice p strong { font-weight:800; }
        .notice.yellow { background:var(--warningbg); border:1px solid rgba(251,191,36,0.25); } .notice.yellow svg { stroke:var(--warning); } .notice.yellow p strong { color:var(--warning); }
        .notice.green  { background:var(--emeraldbg);  border:1px solid rgba(52,211,153,0.18); } .notice.green  svg { stroke:var(--emerald); } .notice.green  p strong { color:var(--emerald); }
        .notice.blue   { background:var(--infobg);     border:1px solid rgba(96,165,250,0.2);  } .notice.blue   svg { stroke:var(--info);    } .notice.blue   p strong { color:var(--info); }


        .action-row { margin-top:1rem; display:flex; flex-direction:column; gap:8px; }
        .btn-pay { width:100%; border:none; border-radius:12px; padding:13px; font-family:'Nunito',sans-serif; font-size:0.92rem; font-weight:800; cursor:pointer; transition:background 0.2s, transform 0.1s; }
        .btn-pay.primary { background:var(--emerald2); color:#0a1a12; }
        .btn-pay.primary:hover  { background:var(--emerald); }
        .btn-pay:active  { transform:scale(0.98); }
        .btn-pay:disabled { background:var(--surface3); color:var(--muted); cursor:not-allowed; }
        .btn-back { width:100%; background:transparent; border:1.5px solid var(--border2); border-radius:12px; padding:11px; font-family:'Nunito',sans-serif; font-size:0.85rem; font-weight:700; color:var(--muted); cursor:pointer; transition:all 0.2s; }
        .btn-back:hover { background:var(--surface); color:var(--text); }
        .pay-note { font-size:0.71rem; color:var(--muted2); font-weight:600; text-align:center; margin-top:2px; }


        .pay-history { margin-top:1.25rem; padding-top:1rem; border-top:1px solid var(--border); }
        .ph-title { font-size:0.7rem; font-weight:700; color:var(--muted2); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:8px; }
        .ph-row { display:flex; justify-content:space-between; align-items:center; padding:7px 10px; border-radius:8px; background:var(--surface); margin-bottom:5px; }
        .ph-left  { font-size:0.77rem; font-weight:600; color:var(--muted); }
        .ph-right { font-size:0.82rem; font-weight:800; color:var(--emerald); }
        .ph-rec   { font-size:0.65rem; color:var(--muted2); margin-top:1px; }


        /* SIDE */
        .session-side { display:flex; flex-direction:column; gap:1rem; }
        .side-card { background:var(--bg3); border:1px solid var(--border2); border-radius:16px; padding:1.25rem; }
        .sc-title { font-size:0.72rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:1rem; display:flex; align-items:center; gap:7px; }
        .sc-title svg { width:13px; height:13px; stroke:var(--muted); fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .elapsed-display { text-align:center; padding:0.5rem 0; }
        .elapsed-time { font-size:2rem; font-weight:800; color:var(--text); font-variant-numeric:tabular-nums; letter-spacing:0.02em; line-height:1; }
        .elapsed-sub  { font-size:0.73rem; color:var(--muted); font-weight:600; margin-top:6px; }
        .rate-table { background:var(--surface); border-radius:9px; overflow:hidden; margin-top:8px; }
        .rate-trow { display:flex; justify-content:space-between; align-items:center; padding:9px 12px; border-bottom:1px solid var(--border); }
        .rate-trow:last-child { border-bottom:none; }
        .rate-trow.active { background:var(--emeraldbg); }
        .rt-lbl { font-size:0.77rem; color:var(--muted); font-weight:600; }
        .rt-val { font-size:0.77rem; color:var(--text); font-weight:800; }
        .rate-trow.active .rt-lbl { color:var(--emerald); }
        .rate-trow.active .rt-val { color:var(--emerald); }
        .kiosk-info { background:var(--surface); border-radius:9px; padding:11px 13px; }
        .kiosk-info p { font-size:0.77rem; color:var(--muted); font-weight:600; line-height:1.65; }
        .kiosk-info p strong { color:var(--text); }


        /* MODAL */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.78); z-index:200; align-items:center; justify-content:center; padding:1.5rem; }
        .modal-overlay.open { display:flex; }
        .modal { background:var(--bg2); border:1px solid var(--border2); border-radius:20px; padding:2rem; width:100%; max-width:400px; animation:fadeUp 0.3s cubic-bezier(0.22,1,0.36,1) both; }
        .modal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.25rem; }
        .modal-title { font-size:1.05rem; font-weight:800; color:var(--text); }
        .modal-close { width:30px; height:30px; background:var(--surface); border:none; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center; }
        .modal-close svg { width:13px; height:13px; stroke:var(--muted); fill:none; stroke-width:2.5; stroke-linecap:round; }
        .modal-close:hover { background:var(--surface2); }
        .modal-divider { height:1px; background:var(--border); margin:1rem 0; }
        .summary-row { display:flex; justify-content:space-between; font-size:0.85rem; margin-bottom:8px; }
        .sr-l { color:var(--muted); font-weight:600; } .sr-r { color:var(--text); font-weight:700; }
        .total-row { display:flex; justify-content:space-between; align-items:center; background:var(--emeraldbg); border:1px solid rgba(52,211,153,0.2); border-radius:11px; padding:12px 14px; margin-bottom:1.25rem; }
        .tr-l { font-size:0.85rem; font-weight:700; color:var(--muted); } .tr-r { font-size:1.35rem; font-weight:800; color:var(--emerald); }
        .method-label { font-size:0.73rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:8px; }
        .method-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:1.25rem; }
        .method-opt { border:1.5px solid var(--border2); border-radius:10px; padding:10px 12px; cursor:pointer; text-align:center; transition:all 0.2s; }
        .method-opt input { display:none; }
        .method-opt-lbl { font-size:0.82rem; font-weight:700; color:var(--muted); display:block; }
        .method-opt:has(input:checked) { border-color:rgba(52,211,153,0.4); background:var(--emeraldbg); }
        .method-opt:has(input:checked) .method-opt-lbl { color:var(--emerald); }


        /* Email field in modal */
        .email-section { margin-bottom:1.25rem; }
        .email-lbl { font-size:0.73rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:6px; display:flex; align-items:center; gap:6px; }
        .email-optional { background:var(--surface); border-radius:6px; padding:2px 7px; font-size:0.63rem; color:var(--muted2); font-weight:700; text-transform:uppercase; }
        .email-input { width:100%; background:var(--surface); border:1.5px solid var(--border2); border-radius:10px; padding:10px 14px; font-family:'Nunito',sans-serif; font-size:0.88rem; font-weight:600; color:var(--text); outline:none; transition:border-color 0.2s; }
        .email-input::placeholder { color:var(--muted2); }
        .email-input:focus { border-color:rgba(52,211,153,0.45); }
        .email-hint { font-size:0.71rem; color:var(--muted2); font-weight:600; margin-top:5px; }


        .btn-confirm { width:100%; background:var(--emerald2); color:#0a1a12; border:none; border-radius:12px; padding:13px; font-family:'Nunito',sans-serif; font-size:0.92rem; font-weight:800; cursor:pointer; transition:background 0.2s, transform 0.1s; }
        .btn-confirm:hover  { background:var(--emerald); }
        .btn-confirm:active { transform:scale(0.98); }
        .btn-confirm:disabled { background:var(--surface3); color:var(--muted); cursor:not-allowed; }


        /* SUCCESS MODAL */
        .success-body { text-align:center; padding:0.5rem 0; }
        .success-icon { width:60px; height:60px; border-radius:50%; background:var(--emeraldbg2); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem; }
        .success-icon svg { width:26px; height:26px; stroke:var(--emerald); fill:none; stroke-width:2.5; stroke-linecap:round; stroke-linejoin:round; }
        .success-body h2 { font-size:1.1rem; font-weight:800; margin-bottom:5px; color:var(--text); }
        .success-body p  { font-size:0.82rem; color:var(--muted); font-weight:500; line-height:1.65; }
        .receipt-chip { background:var(--surface); border-radius:9px; padding:8px 16px; font-size:0.8rem; font-weight:700; color:var(--emerald); display:inline-block; margin:1rem 0 0.5rem; letter-spacing:0.04em; }
        .paid-until-line { font-size:0.8rem; color:var(--muted2); font-weight:600; margin-top:6px; }
        .paid-until-line strong { color:var(--info); }
        .success-actions { display:flex; flex-direction:column; gap:8px; margin-top:1.25rem; }
        .btn-new-search { width:100%; background:var(--emerald2); color:#0a1a12; border:none; border-radius:12px; padding:12px; font-family:'Nunito',sans-serif; font-size:0.88rem; font-weight:800; cursor:pointer; transition:background 0.2s; }
        .btn-new-search:hover { background:var(--emerald); }
        .btn-dismiss { width:100%; background:transparent; border:1.5px solid var(--border2); border-radius:12px; padding:11px; font-family:'Nunito',sans-serif; font-size:0.85rem; font-weight:700; color:var(--muted); cursor:pointer; transition:all 0.2s; }
        .btn-dismiss:hover { background:var(--surface); color:var(--text); }


        @media (max-width:700px) {
            .session-grid { grid-template-columns:1fr; }
            .stats-row { grid-template-columns:1fr 1fr; }
            .page { padding:1.5rem 1rem 3rem; }
        }
    </style>
</head>
<body>


<nav>
    <a class="nav-logo" href="#">
        <div class="nav-logo-icon">P</div>
        <span class="nav-logo-text">Parky</span>
    </a>
    <span class="nav-kiosk-badge">Payment Kiosk</span>
    <button class="btn-tts" id="ttsBtn" onclick="toggleTTS()" title="Read aloud">
        <svg viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
        Read aloud
    </button>
</nav>


<div class="page">


    <!-- IDLE / SEARCH SCREEN -->
    <div id="idleScreen">
        <div class="idle-icon">₱</div>
        <div>
            <h1 class="idle-title">Payment Kiosk</h1>
            <p class="idle-sub">Enter your plate number to view your parking session and pay your fee.</p>
        </div>
        <div class="idle-rates">
            <div class="rate-chip"><div class="rc-lbl">First 3 hours</div><div class="rc-val">₱60 flat</div></div>
            <div class="rate-chip"><div class="rc-lbl">Grace (1–15 min)</div><div class="rc-val">+₱10</div></div>
            <div class="rate-chip"><div class="rc-lbl">Each extra hour</div><div class="rc-val">+₱30</div></div>
        </div>
        <div class="search-wrap">
            <div class="search-label">Enter plate number</div>
            <div class="search-input-row">
                <input type="text" id="plateInput" class="search-input" placeholder="e.g. ABC 123" maxlength="20" oninput="this.value=this.value.toUpperCase()">
                <button class="btn-stt" id="sttBtn" onclick="toggleSTT()" title="Speak your plate number">
                    <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                </button>
            </div>
            <button class="btn-search" id="searchBtn" onclick="lookupPlate()">Search Plate</button>
            <div id="searchError" style="display:none;"></div>
            <p class="search-hint">Make sure your vehicle has been scanned at the entrance kiosk.</p>
        </div>
    </div>


    <!-- SESSION VIEW -->
    <div id="sessionView">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap;">
            <div>
                <h1 style="font-size:1.6rem;font-weight:800;letter-spacing:-0.03em;">Parking Session</h1>
                <p style="font-size:0.85rem;color:var(--muted);margin-top:4px;font-weight:500;">Live fee details for your vehicle.</p>
            </div>
            <button class="btn-tts" onclick="toggleTTS()">
                <svg viewBox="0 0 24 24"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                Read aloud
            </button>
        </div>


        <div class="session-grid">
            <!-- MAIN -->
            <div>
                <div class="s-card" id="mainCard">
                    <!-- Injected by JS -->
                </div>
                <div class="s-card" style="margin-top:1rem;" id="feeCard">
                    <!-- Injected by JS -->
                </div>
            </div>
            <!-- SIDE -->
            <div class="session-side">
                <div class="side-card">
                    <div class="sc-title">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Time elapsed
                    </div>
                    <div class="elapsed-display">
                        <div class="elapsed-time" id="elapsedDisplay">00:00:00</div>
                        <div class="elapsed-sub">hours : minutes : seconds</div>
                    </div>
                    <div class="rate-table" style="margin-top:10px;">
                        <div class="rate-trow" id="rt1"><span class="rt-lbl">First 3 hours</span><span class="rt-val">₱60 flat</span></div>
                        <div class="rate-trow" id="rt2"><span class="rt-lbl">Grace (1–15 min)</span><span class="rt-val">+₱10</span></div>
                        <div class="rate-trow" id="rt3"><span class="rt-lbl">Each extra hour</span><span class="rt-val">+₱30</span></div>
                    </div>
                </div>
                <div class="side-card">
                    <div class="sc-title">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        How to exit
                    </div>
                    <div class="kiosk-info">
                        <p>
                            <strong>Step 1</strong> — Pay your fee here at the kiosk.<br><br>
                            <strong>Step 2</strong> — Proceed to the exit kiosk and have your plate scanned.<br><br>
                            <strong>Step 3</strong> — Once confirmed, your slot is freed.
                        </p>
                    </div>
                </div>
                <button class="btn-back" onclick="resetKiosk()">← Search another plate</button>
            </div>
        </div>
    </div>


</div>


<!-- PAYMENT MODAL -->
<div class="modal-overlay" id="payModal">
    <div class="modal">
        <div class="modal-head">
            <span class="modal-title">Confirm Payment</span>
            <button class="modal-close" onclick="closeModal('payModal')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="summary-rows" id="modalSummary"></div>
        <div class="modal-divider"></div>
        <div class="total-row">
            <span class="tr-l">Amount due</span>
            <span class="tr-r" id="modalTotal">₱0.00</span>
        </div>
        <div class="method-label">Payment method</div>
        <div class="method-grid">
            <label class="method-opt"><input type="radio" name="method" value="cash" checked><span class="method-opt-lbl">Cash</span></label>
            <label class="method-opt"><input type="radio" name="method" value="card"><span class="method-opt-lbl">Card</span></label>
            <label class="method-opt"><input type="radio" name="method" value="gcash"><span class="method-opt-lbl">GCash</span></label>
            <label class="method-opt"><input type="radio" name="method" value="maya"><span class="method-opt-lbl">Maya</span></label>
        </div>
        <div class="email-section">
            <div class="email-lbl">Send receipt to email <span class="email-optional">Optional</span></div>
            <input type="email" id="receiptEmail" class="email-input" placeholder="e.g. yourname@gmail.com">
            <p class="email-hint" id="emailHint">Leave blank to skip. A digital receipt will be sent to this address.</p>
        </div>
        <button class="btn-confirm" id="confirmPayBtn" onclick="confirmPayment()">Confirm Payment</button>
    </div>
</div>


<!-- SUCCESS MODAL -->
<div class="modal-overlay" id="successModal">
    <div class="modal">
        <div class="success-body">
            <div class="success-icon">
                <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h2>Payment Confirmed!</h2>
            <p>Your parking session has been updated successfully.</p>
            <div class="receipt-chip" id="successReceipt">RCP-—</div>
            <p class="paid-until-line">Paid as of <strong id="successPaidUntil">—</strong></p>
            <p id="successEmailNote" style="font-size:0.78rem;color:var(--emerald);font-weight:600;margin-top:8px;display:none;">Receipt sent to your email.</p>
            <div class="success-actions">
                <button class="btn-new-search" onclick="resetKiosk()">Search another plate</button>
                <button class="btn-dismiss" onclick="closeModal('successModal')">View session details</button>
            </div>
        </div>
    </div>
</div>


<script>
// ── State ──────────────────────────────────────────────────
let currentSession = null;
let elapsedTimer   = null;
let ttsUtterance   = null;


// ── TTS ────────────────────────────────────────────────────
function speak(text) {
    if (!window.speechSynthesis) return;
    window.speechSynthesis.cancel();
    ttsUtterance = new SpeechSynthesisUtterance(text);
    ttsUtterance.lang = 'en-PH';
    ttsUtterance.rate = 0.95;
    const btn = document.getElementById('ttsBtn');
    if (btn) btn.classList.add('speaking');
    ttsUtterance.onend = () => { if (btn) btn.classList.remove('speaking'); };
    window.speechSynthesis.speak(ttsUtterance);
}
function toggleTTS() {
    if (window.speechSynthesis?.speaking) {
        window.speechSynthesis.cancel();
        document.getElementById('ttsBtn')?.classList.remove('speaking');
        return;
    }
    if (!currentSession) {
        speak('Welcome to the Parky Payment Kiosk. Please enter your plate number to begin.');
        return;
    }
    const s = currentSession;
    const elapsed = Math.floor((Date.now() / 1000) - s.entryTs);
    const fee  = calculateFee(elapsed);
    const due  = Math.max(0, fee - (s.paidFee || 0));
    const hh   = Math.floor(elapsed/3600);
    const mm   = Math.floor((elapsed%3600)/60);
    const timeStr = hh > 0 ? `${hh} hour${hh>1?'s':''} and ${mm} minute${mm!==1?'s':''}` : `${mm} minute${mm!==1?'s':''}`;
    if (due <= 0) {
        speak(`Plate ${s.plate_number}. You are parked at Slot ${s.slot_code}, Floor ${s.floor}. You have been parked for ${timeStr}. You have no outstanding balance at this time.`);
    } else {
        speak(`Plate ${s.plate_number}. You are parked at Slot ${s.slot_code}, Floor ${s.floor}. You have been parked for ${timeStr}. Your current balance due is ${due} pesos. Please select a payment method to proceed.`);
    }
}


// ── STT ────────────────────────────────────────────────────
let recognition = null;
function toggleSTT() {
    if (!('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) {
        alert('Speech recognition is not supported in this browser.'); return;
    }
    const btn = document.getElementById('sttBtn');
    if (recognition) { recognition.stop(); recognition = null; btn.classList.remove('listening'); return; }
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    recognition = new SR();
    recognition.lang = 'en-PH';
    recognition.interimResults = false;
    recognition.onresult = e => {
        let raw = e.results[0][0].transcript.toUpperCase().replace(/[^A-Z0-9 ]/g,'').trim();
        document.getElementById('plateInput').value = raw;
    };
    recognition.onend = () => { btn.classList.remove('listening'); recognition = null; };
    recognition.start();
    btn.classList.add('listening');
}


// ── Fee (mirrors PHP rates.php) ────────────────────────────
function calculateFee(seconds) {
    if (seconds <= 0) return 60;
    const minutes = seconds / 60;
    const firstBlock = 180;
    if (minutes <= firstBlock) return 60;
    let fee = 60;
    const extra = minutes - firstBlock;
    const fullHours = Math.floor(extra / 60);
    const remainder = extra - (fullHours * 60);
    fee += fullHours * 30;
    if (remainder > 0 && remainder <= 15) fee += 10;
    else if (remainder > 15) fee += 30;
    return fee;
}
function getCurrentTier(seconds) {
    const minutes = seconds / 60;
    if (minutes <= 180) return 1;
    const extra = minutes - 180;
    const remainder = extra % 60;
    if (remainder > 0 && remainder <= 15) return 2;
    return 3;
}


// ── Plate Lookup ───────────────────────────────────────────
function lookupPlate() {
    const plate = document.getElementById('plateInput').value.trim();
    if (!plate) { showSearchError('Please enter a plate number.'); return; }
    const btn = document.getElementById('searchBtn');
    btn.disabled = true; btn.textContent = 'Searching…';
    hideSearchError();


    fetch(`?action=lookup&plate=${encodeURIComponent(plate)}`)
        .then(r => r.json())
        .then(data => {
            btn.disabled = false; btn.textContent = 'Search Plate';
            if (!data.found) { showSearchError(data.message); return; }
            loadSession(data.session);
        })
        .catch(() => { btn.disabled = false; btn.textContent = 'Search Plate'; showSearchError('Connection error. Please try again.'); });
}


function showSearchError(msg) {
    const el = document.getElementById('searchError');
    el.style.display = 'block';
    el.innerHTML = `<div class="state-box error">
        <div class="state-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
        <div><div class="state-title">Session not found</div><div class="state-sub">${msg}</div></div>
    </div>`;
    speak('Session not found. ' + msg.replace(/<[^>]+>/g,''));
}
function hideSearchError() { document.getElementById('searchError').style.display = 'none'; }


document.getElementById('plateInput').addEventListener('keydown', e => { if (e.key === 'Enter') lookupPlate(); });


// ── Load Session ───────────────────────────────────────────
function loadSession(s) {
    currentSession = s;
    currentSession.entryTs   = Math.floor(new Date(s.entry_time).getTime() / 1000);
    currentSession.paidFee   = s.paid_until ? calculateFee(Math.floor(new Date(s.paid_until).getTime()/1000) - currentSession.entryTs) : 0;


    document.getElementById('idleScreen').style.display   = 'none';
    document.getElementById('sessionView').style.display  = 'block';


    renderMainCard(s);
    startElapsedTimer();
    speak(`Plate ${s.plate_number} found. You are parked at Slot ${s.slot_code}, Floor ${s.floor}. Your session is active.`);


    // Pre-fill email if registered user
    if (s.user_email) {
        document.getElementById('receiptEmail').value = s.user_email;
        document.getElementById('emailHint').textContent = 'Pre-filled from your registered account. Change if needed.';
    }
}


function renderMainCard(s) {
    const typeBadge = s.type === 'reservation'
        ? '<span class="badge badge-res">Reservation</span>'
        : '<span class="badge badge-active">Walk-in</span>';
    const dateStr = new Date(s.entry_time).toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'});
    const timeStr = new Date(s.entry_time).toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit'});


    let historyHTML = '';
    if (s.history && s.history.length > 0) {
        historyHTML = `<div class="pay-history"><div class="ph-title">Payment history</div>`;
        s.history.forEach(p => {
            const d = new Date(p.paid_at).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
            historyHTML += `<div class="ph-row">
                <div><div class="ph-left">${d} · ${p.method.toUpperCase()}</div><div class="ph-rec">${p.receipt_number}</div></div>
                <div class="ph-right">₱${parseFloat(p.total_amount).toFixed(2)}</div>
            </div>`;
        });
        historyHTML += `</div>`;
    }


    document.getElementById('mainCard').innerHTML = `
        <div class="card-head">
            <div style="display:flex;align-items:center;gap:12px;">
                <div class="slot-badge"><div class="sb-code">${s.slot_code}</div><div class="sb-floor">Floor ${s.floor}</div></div>
                <div class="card-head-text"><h2>Slot ${s.slot_code}, Floor ${s.floor}</h2><p>Plate: ${s.plate_number}</p></div>
            </div>
            <div class="badges">${typeBadge}<span class="badge badge-active">Active</span></div>
        </div>
        <div class="stats-row">
            <div class="stat"><div class="stat-lbl">Entry time</div><div class="stat-val">${timeStr}</div></div>
            <div class="stat"><div class="stat-lbl">Date</div><div class="stat-val">${dateStr}</div></div>
            <div class="stat"><div class="stat-lbl">Payment</div><div class="stat-val" id="payStatusBadge">—</div></div>
        </div>
        <div class="car-row">
            <div class="cr-item"><div class="cr-lbl">Plate</div><div class="cr-val">${s.plate_number}</div></div>
            <div class="cr-item"><div class="cr-lbl">Type</div><div class="cr-val">${s.car_type}</div></div>
            <div class="cr-item"><div class="cr-lbl">Color</div><div class="cr-val">${s.car_color}</div></div>
        </div>
        ${historyHTML}
    `;
}


// ── Elapsed Timer + Fee Updater ────────────────────────────
function startElapsedTimer() {
    if (elapsedTimer) clearInterval(elapsedTimer);
    elapsedTimer = setInterval(updateFeeDisplay, 1000);
    updateFeeDisplay();
}


function updateFeeDisplay() {
    if (!currentSession) return;
    const s = currentSession;
    const now     = Math.floor(Date.now() / 1000);
    const elapsed = now - s.entryTs;
    const totalFee = calculateFee(elapsed);
    const amountDue = Math.max(0, totalFee - (s.paidFee || 0));
    const tier = getCurrentTier(elapsed);


    // Elapsed clock
    const hh = String(Math.floor(elapsed/3600)).padStart(2,'0');
    const mm = String(Math.floor((elapsed%3600)/60)).padStart(2,'0');
    const ss = String(elapsed%60).padStart(2,'0');
    document.getElementById('elapsedDisplay').textContent = `${hh}:${mm}:${ss}`;


    // Highlight active rate row
    [1,2,3].forEach(i => document.getElementById(`rt${i}`)?.classList.remove('active'));
    document.getElementById(`rt${tier}`)?.classList.add('active');


    // Pay status badge
    const psEl = document.getElementById('payStatusBadge');
    if (psEl) {
        if (amountDue > 0) psEl.innerHTML = '<span class="badge badge-owed">Extra owed</span>';
        else if (s.paidFee > 0) psEl.innerHTML = '<span class="badge badge-paid">Paid</span>';
        else psEl.innerHTML = '<span class="badge badge-unpaid">Unpaid</span>';
    }


    // Fee card
    let noticeHTML = '';
    if (amountDue <= 0) {
        noticeHTML = `<div class="notice green">
            <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <p><strong>No outstanding balance.</strong> Your session is currently within your paid window.</p>
        </div>`;
    } else if (tier === 2) {
        noticeHTML = `<div class="notice yellow">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <p>You're in the <strong>15-minute grace period</strong>. Pay now to avoid the full ₱30 hourly charge.</p>
        </div>`;
    } else if (amountDue > 0) {
        noticeHTML = `<div class="notice yellow">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <p>Your car has been parked past the paid window. <strong>An additional fee of ₱${amountDue.toFixed(2)} is now owed.</strong></p>
        </div>`;
    }


    let paidRowHTML = '';
    if (s.paidFee > 0) {
        paidRowHTML = `<div class="fee-row f-paid">
            <div class="fr-lbl">Previously paid<small>Total across all payments</small></div>
            <div class="fr-amt green2">₱${s.paidFee.toFixed(2)}</div>
        </div>`;
    }


    const btnDisabled = amountDue <= 0 ? 'disabled' : '';
    const btnClass    = amountDue <= 0 ? 'btn-pay primary' : 'btn-pay primary';


    document.getElementById('feeCard').innerHTML = `
        <div class="fee-row f-total">
            <div class="fr-lbl">Total session fee<small>Updates every second</small></div>
            <div class="fr-amt green">₱${totalFee.toFixed(2)}</div>
        </div>
        ${paidRowHTML}
        <div class="fee-row ${amountDue <= 0 ? 'f-zero' : 'f-owed'}">
            <div class="fr-lbl">Amount due now<small>₱60 first 3hrs, then ₱30/hr</small></div>
            <div class="fr-amt ${amountDue <= 0 ? 'blue' : 'yellow'}">₱${amountDue.toFixed(2)}</div>
        </div>
        ${noticeHTML}
        <div class="action-row">
            <button class="${btnClass}" ${btnDisabled} onclick="openPayModal(${amountDue.toFixed(2)}, ${totalFee.toFixed(2)})">
                ${amountDue <= 0 ? 'No balance due' : `Pay ₱${amountDue.toFixed(2)}`}
            </button>
            ${amountDue <= 0 ? '<p class="pay-note">Come back when additional time has elapsed.</p>' : '<p class="pay-note">Cash, Card, GCash, and Maya accepted.</p>'}
        </div>
    `;
}


// ── Pay Modal ──────────────────────────────────────────────
function openPayModal(amountDue, totalFee) {
    const s = currentSession;
    const elapsed = Math.floor(Date.now()/1000) - s.entryTs;
    const hh = Math.floor(elapsed/3600);
    const mm = Math.floor((elapsed%3600)/60);
    const timeStr = `${String(hh).padStart(2,'0')}h ${String(mm).padStart(2,'0')}m`;


    document.getElementById('modalSummary').innerHTML = `
        <div class="summary-row"><span class="sr-l">Plate</span><span class="sr-r">${s.plate_number}</span></div>
        <div class="summary-row"><span class="sr-l">Slot</span><span class="sr-r">${s.slot_code} — Floor ${s.floor}</span></div>
        <div class="summary-row"><span class="sr-l">Time parked</span><span class="sr-r">${timeStr}</span></div>
        <div class="summary-row"><span class="sr-l">Total session fee</span><span class="sr-r">₱${totalFee.toFixed(2)}</span></div>
        ${s.paidFee > 0 ? `<div class="summary-row"><span class="sr-l">Previously paid</span><span class="sr-r">₱${s.paidFee.toFixed(2)}</span></div>` : ''}
    `;
    document.getElementById('modalTotal').textContent = `₱${amountDue.toFixed(2)}`;
    document.getElementById('payModal').classList.add('open');
    speak(`You are about to pay ${amountDue} pesos. Please select your payment method and confirm.`);
}


function closeModal(id) { document.getElementById(id).classList.remove('open'); }


function confirmPayment() {
    const s      = currentSession;
    const method = document.querySelector('input[name="method"]:checked')?.value || 'cash';
    const email  = document.getElementById('receiptEmail').value.trim();
    const btn    = document.getElementById('confirmPayBtn');


    btn.disabled = true; btn.textContent = 'Processing…';


    const body = new FormData();
    body.append('action',       'pay');
    body.append('session_id',   s.session_id);
    body.append('session_type', s.type);
    body.append('method',       method);
    body.append('email',        email);


    fetch('', { method:'POST', body })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false; btn.textContent = 'Confirm Payment';
            if (!data.success) { alert(data.message || 'Payment failed.'); return; }


            closeModal('payModal');


            // Update local paid state
            const now = Math.floor(Date.now()/1000);
            const newElapsed = now - s.entryTs;
            currentSession.paidFee = calculateFee(newElapsed);


            document.getElementById('successReceipt').textContent = data.receipt_number;
            document.getElementById('successPaidUntil').textContent = new Date(data.paid_until).toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit'});
            const emailNote = document.getElementById('successEmailNote');
            if (data.email_sent) emailNote.style.display = 'block'; else emailNote.style.display = 'none';
            document.getElementById('successModal').classList.add('open');


            speak(`Payment confirmed. Receipt number ${data.receipt_number}. Thank you for using Parky. Please proceed to the exit kiosk when you are ready to leave.`);
        })
        .catch(() => { btn.disabled = false; btn.textContent = 'Confirm Payment'; alert('Connection error. Please try again.'); });
}


// ── Reset ──────────────────────────────────────────────────
function resetKiosk() {
    if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
    if (window.speechSynthesis) window.speechSynthesis.cancel();
    currentSession = null;
    document.getElementById('idleScreen').style.display   = 'flex';
    document.getElementById('sessionView').style.display  = 'none';
    document.getElementById('plateInput').value = '';
    document.getElementById('receiptEmail').value = '';
    document.getElementById('emailHint').textContent = 'Leave blank to skip. A digital receipt will be sent to this address.';
    hideSearchError();
    ['payModal','successModal'].forEach(closeModal);
    speak('Welcome to the Parky Payment Kiosk. Please enter your plate number to begin.');
}


// Auto-speak on idle load
window.addEventListener('load', () => {
    setTimeout(() => speak('Welcome to the Parky Payment Kiosk. Please enter your plate number to search for your parking session.'), 800);
});
</script>
</body>
</html>

