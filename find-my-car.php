<?php
session_start();
require_once 'config/db.php';
require_once 'config/rates.php';


// ── Auth guard ────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=find-my-car.php');
    exit;
}


$loggedInUserId = $_SESSION['user_id'];


/*
 * PAYMENT MODEL (aligned with kiosks: getAmountDue in config/rates.php)
 * ─────────────────────────────────────────────────────────────
 * Additional balance = calculateFee(elapsed) − calculateFee(paid_until − entry),
 * never calculateFee(seconds_since_paid_until) — that wrongly applies the
 * “first 3 hours = ₱60” rule to a short delta and shows ₱60 immediately.
 *
 * FEE STATES while status = 'active':
 *   A) unpaid → running fee = getAmountDue(elapsed, null, entry)
 *   B) paid & getAmountDue(...) <= 0 → within_window (countdown to coverage end)
 *   C) paid & getAmountDue(...) > 0 → extra_owed (show getAmountDue as additional)
 */


$session            = null;
$error              = '';
$already_exited     = false;
$all_payments       = [];
$upcoming_reservation = null;


// ── Fetch logged-in user's info ──────────────────────────────
$userStmt = $pdo->prepare("SELECT first_name, last_name, username, plate_number FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$loggedInUserId]);
$loggedInUser = $userStmt->fetch();


$lu = $loggedInUser ?: [];
$navFromDb = trim(($lu['first_name'] ?? '') . ' ' . ($lu['last_name'] ?? ''));
$navDisplayName = $_SESSION['user_name'] ?? ($navFromDb !== '' ? $navFromDb : ($lu['username'] ?? 'Account'));
$ownPlate       = strtoupper(trim($lu['plate_number'] ?? ''));


// ── Also get the plate from their active/pending reservation ─
// Covers the case where they reserved for someone else's car
$resPlateStmt = $pdo->prepare("
    SELECT plate_number FROM reservations
    WHERE user_id = ?
      AND status IN ('pending', 'confirmed', 'active')
    ORDER BY reserved_at DESC
    LIMIT 1
");
$resPlateStmt->execute([$loggedInUserId]);
$resPlatRow   = $resPlateStmt->fetch();
$reservedPlate = strtoupper(trim($resPlatRow['plate_number'] ?? ''));


// Build the list of plates to check (deduplicated)
$platesToCheck = array_values(array_unique(array_filter([$ownPlate, $reservedPlate])));


// ── Look for an active session under any of their plates ─────
foreach ($platesToCheck as $checkPlate) {
    // Walk-in session first
    $stmt = $pdo->prepare("
        SELECT sw.*, ps.slot_code, ps.floor, 'walkin' AS session_source
        FROM sessions_walkin sw
        JOIN parking_slots ps ON sw.slot_id = ps.id
        WHERE sw.plate_number = ?
          AND sw.status = 'active'
        ORDER BY sw.entry_time DESC
        LIMIT 1
    ");
    $stmt->execute([$checkPlate]);
    $session = $stmt->fetch();


    if (!$session) {
        // Then active reservation
        $stmt2 = $pdo->prepare("
            SELECT r.*, ps.slot_code, ps.floor, 'reservation' AS session_source,
                   r.arrival_time AS entry_time
            FROM reservations r
            JOIN parking_slots ps ON r.slot_id = ps.id
            WHERE r.plate_number = ?
              AND r.status = 'active'
            ORDER BY r.arrival_time DESC
            LIMIT 1
        ");
        $stmt2->execute([$checkPlate]);
        $session = $stmt2->fetch();
    }


    if ($session) break; // found one, stop
}


// ── If no active session, check for already-exited ──────────
if (!$session && $ownPlate) {
    $stmtComp = $pdo->prepare("
        SELECT sw.*, ps.slot_code, ps.floor, 'walkin' AS session_source
        FROM sessions_walkin sw
        JOIN parking_slots ps ON sw.slot_id = ps.id
        WHERE sw.plate_number = ?
        ORDER BY sw.entry_time DESC
        LIMIT 1
    ");
    $stmtComp->execute([$ownPlate]);
    $maybeComp = $stmtComp->fetch();
    if ($maybeComp && $maybeComp['status'] === 'completed') {
        $session        = $maybeComp;
        $already_exited = true;
    }
}


// ── If still no active session, check for upcoming reservation
// (pending or confirmed but not yet scanned at entrance)
if (!$session && !$already_exited) {
    $upcomingStmt = $pdo->prepare("
        SELECT r.*, ps.slot_code, ps.floor
        FROM reservations r
        JOIN parking_slots ps ON r.slot_id = ps.id
        WHERE r.user_id = ?
          AND r.status IN ('pending', 'confirmed')
        ORDER BY r.arrival_time ASC
        LIMIT 1
    ");
    $upcomingStmt->execute([$loggedInUserId]);
    $upcoming_reservation = $upcomingStmt->fetch();
}


// ── Fetch all payments for this active session ───────────────
if ($session && !$already_exited) {
    $src   = $session['session_source'];
    $pstmt = $pdo->prepare("
        SELECT * FROM payments
        WHERE session_id = ? AND session_type = ?
        ORDER BY paid_at ASC
    ");
    $pstmt->execute([$session['id'], $src === 'walkin' ? 'walkin' : 'reservation']);
    $all_payments = $pstmt->fetchAll();
}


// ── Handle payment confirmation (AJAX POST) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    header('Content-Type: application/json');
    $pay_plate = strtoupper(trim($_POST['plate'] ?? ''));
    $method    = $_POST['method'] ?? 'gcash';


    $sess      = null;
    $sess_type = 'walkin';


    $stmt = $pdo->prepare("
        SELECT sw.*, ps.slot_code, ps.floor
        FROM sessions_walkin sw
        JOIN parking_slots ps ON sw.slot_id = ps.id
        WHERE sw.plate_number = ? AND sw.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$pay_plate]);
    $sess = $stmt->fetch();


    if (!$sess) {
        $stmt2 = $pdo->prepare("
            SELECT r.*, ps.slot_code, ps.floor,
                   r.arrival_time AS entry_time
            FROM reservations r
            JOIN parking_slots ps ON r.slot_id = ps.id
            WHERE r.plate_number = ? AND r.status = 'active'
            LIMIT 1
        ");
        $stmt2->execute([$pay_plate]);
        $sess      = $stmt2->fetch();
        $sess_type = 'reservation';
    }


    if (!$sess) {
        echo json_encode(['success' => false, 'message' => 'Session not found.']);
        exit;
    }


    $now_dt   = new DateTime();
    $entry_dt = new DateTime($sess['entry_time']);
    $elapsed_total = max(0, $now_dt->getTimestamp() - $entry_dt->getTimestamp());


    $total = getAmountDue(
        $elapsed_total,
        ($sess['payment_status'] === 'paid' && !empty($sess['paid_until'])) ? $sess['paid_until'] : null,
        $sess['entry_time']
    );


    if ($total <= 0.009) {
        echo json_encode(['success' => false, 'message' => 'No payment is due right now.']);
        exit;
    }


    // First payment: paid_until = end of first flat-rate block (same as kiosk intent).
    // Additional payment: paid_until = now (credit through current moment; matches exit kiosk).
    if ($sess['payment_status'] === 'unpaid' || empty($sess['paid_until'])) {
        $covered_end = (clone $entry_dt);
        $covered_end->modify('+' . RATE_FIRST_HOURS . ' hours');
        $paid_until = $covered_end->format('Y-m-d H:i:s');
    } else {
        $paid_until = $now_dt->format('Y-m-d H:i:s');
    }
    $paid_until_ts = strtotime($paid_until);
    $receipt       = 'RCP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));


    $ins = $pdo->prepare("
        INSERT INTO payments
            (session_id, session_type, base_amount, extension_fee, total_amount,
             method, source, receipt_number)
        VALUES (?, ?, ?, 0, ?, ?, 'online', ?)
    ");
    $ins->execute([$sess['id'], $sess_type, $total, $total, $method, $receipt]);


    if ($sess_type === 'walkin') {
        $upd = $pdo->prepare("UPDATE sessions_walkin SET payment_status = 'paid', paid_until = ? WHERE id = ?");
    } else {
        $upd = $pdo->prepare("UPDATE reservations SET payment_status = 'paid', paid_until = ? WHERE id = ?");
    }
    $upd->execute([$paid_until, $sess['id']]);


    // Link walk-in session to user if not yet linked
    if ($sess_type === 'walkin' && empty($sess['user_id'])) {
        $linkStmt = $pdo->prepare("UPDATE sessions_walkin SET user_id = ? WHERE id = ?");
        $linkStmt->execute([$loggedInUserId, $sess['id']]);
    }


    echo json_encode([
        'success'       => true,
        'receipt'       => $receipt,
        'total'         => $total,
        'slot'          => $sess['slot_code'],
        'floor'         => $sess['floor'],
        'plate'         => $sess['plate_number'] ?? $pay_plate,
        'paid_until'    => date('M d, g:i A', $paid_until_ts),
        'paid_until_ts' => $paid_until_ts,
    ]);
    exit;
}


// ── Compute display values server-side ───────────────────────
$elapsed_secs        = 0;
$paid_until_ts       = 0;
$window_countdown_ts = 0;
$fee_state           = 'none';
$running_fee         = 0;
$extra_fee           = 0;
$total_paid_so_far   = array_sum(array_column($all_payments, 'total_amount'));


if ($session && !$already_exited) {
    $entry_dt     = new DateTime($session['entry_time']);
    $now_dt       = new DateTime();
    $elapsed_secs = max(0, $now_dt->getTimestamp() - $entry_dt->getTimestamp());
    $entry_ts     = $entry_dt->getTimestamp();


    if ($session['payment_status'] === 'unpaid') {
        $fee_state   = 'unpaid';
        $running_fee = getAmountDue($elapsed_secs, null, $session['entry_time']);


    } elseif (!empty($session['paid_until'])) {
        $paid_until_ts = (new DateTime($session['paid_until']))->getTimestamp();
        $due           = getAmountDue($elapsed_secs, $session['paid_until'], $session['entry_time']);


        if ($due <= 0.009) {
            $fee_state           = 'within_window';
            $window_countdown_ts = max($paid_until_ts, $entry_ts + RATE_FIRST_HOURS * 3600);
        } else {
            $fee_state = 'extra_owed';
            $extra_fee = $due;
        }
    }
}


$last_payment = !empty($all_payments) ? end($all_payments) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky — Find My Car</title>
          <link rel="icon" href="favicon.ico" type="image/x-icon">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }


    :root {
      --bg:          #151815;
      --bg2:         #1a1e1a;
      --bg3:         #1f241f;
      --surface:     #252a25;
      --surface2:    #2c322c;
      --surface3:    #323832;
      --border:      rgba(255,255,255,0.07);
      --border2:     rgba(255,255,255,0.11);
      --text:        #eaf2ea;
      --muted:       #7a907a;
      --muted2:      #4f5f4f;
      --emerald:     #34d399;
      --emerald2:    #10b981;
      --emeraldbg:   rgba(52,211,153,0.08);
      --emeraldbg2:  rgba(52,211,153,0.15);
      --danger:      #f87171;
      --dangerbg:    rgba(248,113,113,0.10);
      --warning:     #fbbf24;
      --warningbg:   rgba(251,191,36,0.10);
      --info:        #60a5fa;
      --infobg:      rgba(96,165,250,0.10);
      --nav-h:       60px;
    }


    body { font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);min-height:100vh; }


    /* ── NAV ── */
    nav { height:var(--nav-h);background:var(--bg2);border-bottom:1px solid var(--border2);display:flex;align-items:center;padding:0 2rem;gap:1.5rem;position:sticky;top:0;z-index:50; }
    .nav-logo { display:flex;align-items:center;gap:9px;text-decoration:none;margin-right:auto; }
    .nav-logo-icon { width:32px;height:32px;background:var(--emeraldbg2);border:1.5px solid rgba(52,211,153,0.3);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:800;color:var(--emerald); }
    .nav-logo-text { font-size:1.1rem;font-weight:800;color:var(--emerald);letter-spacing:-0.02em; }
    .nav-links { display:flex;align-items:center;gap:0.2rem; }
    .nav-links a { font-size:0.83rem;font-weight:600;color:var(--muted);text-decoration:none;padding:5px 12px;border-radius:8px;transition:color 0.2s,background 0.2s; }
    .nav-links a:hover  { color:var(--text);background:var(--surface); }
    .nav-links a.active { color:var(--emerald);background:var(--emeraldbg); }
    .nav-btn { font-family:'Nunito',sans-serif;font-size:0.82rem;font-weight:700;padding:6px 16px;border:1.5px solid rgba(52,211,153,0.4);background:transparent;color:var(--emerald);border-radius:8px;cursor:pointer;text-decoration:none;transition:background 0.2s,color 0.2s; }
    .nav-btn:hover { background:var(--emerald2);color:#0a1a12;border-color:var(--emerald2); }


    /* ── PAGE ── */
    .page { max-width:860px;margin:0 auto;padding:2.5rem 1.5rem 4rem; }
    .page-header { margin-bottom:2rem;display:flex;align-items:flex-start;justify-content:space-between;gap:1rem; }
    .page-header-text h1 { font-size:1.75rem;font-weight:800;color:var(--text);letter-spacing:-0.03em;line-height:1.15; }
    .page-header-text p  { font-size:0.88rem;color:var(--muted);margin-top:5px;font-weight:500; }


    /* ── TTS BUTTON ── */
    .btn-tts {
      display:flex;align-items:center;gap:8px;
      background:var(--surface);border:1.5px solid var(--border2);
      border-radius:11px;padding:9px 16px;
      font-family:'Nunito',sans-serif;font-size:0.82rem;font-weight:700;
      color:var(--muted);cursor:pointer;
      transition:background 0.2s,border-color 0.2s,color 0.2s;
      flex-shrink:0;
    }
    .btn-tts svg { width:16px;height:16px;stroke:var(--muted);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;transition:stroke 0.2s; }
    .btn-tts:hover { background:var(--surface2);border-color:var(--emerald2);color:var(--emerald); }
    .btn-tts:hover svg { stroke:var(--emerald); }
    .btn-tts.speaking { background:var(--emeraldbg2);border-color:var(--emerald);color:var(--emerald);animation:ttsPulse 1.2s ease-in-out infinite; }
    .btn-tts.speaking svg { stroke:var(--emerald); }
    @keyframes ttsPulse { 0%,100%{box-shadow:0 0 0 0 rgba(52,211,153,0.35);}50%{box-shadow:0 0 0 7px rgba(52,211,153,0);} }


    /* ── STATE BOXES ── */
    .state-box { border-radius:14px;padding:1.5rem;display:flex;align-items:flex-start;gap:14px; }
    .state-box.error    { background:var(--dangerbg); border:1px solid rgba(248,113,113,0.2); }
    .state-box.exited   { background:var(--warningbg);border:1px solid rgba(251,191,36,0.2); }
    .state-box.upcoming { background:var(--infobg);   border:1px solid rgba(96,165,250,0.2); }
    .state-icon { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
    .state-icon svg { width:18px;height:18px;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .state-box.error    .state-icon     { background:rgba(248,113,113,0.15); }
    .state-box.error    .state-icon svg { stroke:var(--danger); }
    .state-box.exited   .state-icon     { background:rgba(251,191,36,0.15); }
    .state-box.exited   .state-icon svg { stroke:var(--warning); }
    .state-box.upcoming .state-icon     { background:rgba(96,165,250,0.15); }
    .state-box.upcoming .state-icon svg { stroke:var(--info); }
    .state-title { font-size:0.92rem;font-weight:800;margin-bottom:3px; }
    .state-box.error    .state-title { color:var(--danger); }
    .state-box.exited   .state-title { color:var(--warning); }
    .state-box.upcoming .state-title { color:var(--info); }
    .state-sub  { font-size:0.82rem;color:var(--muted);font-weight:500;line-height:1.6; }
    .state-sub a { color:var(--emerald);text-decoration:none;font-weight:700; }
    .state-sub a:hover { text-decoration:underline; }


    /* Upcoming reservation detail inside state box */
    .upcoming-details { margin-top:0.9rem;display:flex;flex-wrap:wrap;gap:0.6rem; }
    .upd-chip { background:rgba(96,165,250,0.08);border:1px solid rgba(96,165,250,0.18);border-radius:8px;padding:6px 12px; }
    .upd-chip .chip-lbl { font-size:0.65rem;font-weight:700;color:var(--muted2);text-transform:uppercase;letter-spacing:0.04em; }
    .upd-chip .chip-val { font-size:0.84rem;font-weight:800;color:var(--text);margin-top:1px; }


    /* ── SESSION LAYOUT ── */
    .session-grid { display:grid;grid-template-columns:1fr 340px;gap:1rem;animation:fadeUp 0.4s cubic-bezier(0.22,1,0.36,1) both; }
    @keyframes fadeUp { from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);} }


    .session-main { background:var(--bg3);border:1px solid var(--border2);border-radius:16px;padding:1.5rem; }
    .session-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem; }
    .session-title-row { display:flex;align-items:center;gap:12px; }
    .slot-badge { background:var(--emeraldbg2);border:1.5px solid rgba(52,211,153,0.35);border-radius:10px;padding:8px 14px;text-align:center; }
    .slot-badge .slot-code  { font-size:1.1rem;font-weight:800;color:var(--emerald);line-height:1; }
    .slot-badge .slot-floor { font-size:0.68rem;color:var(--emerald);opacity:0.7;margin-top:2px;font-weight:600; }
    .session-slot-info h2 { font-size:1rem;font-weight:800;color:var(--text); }
    .session-slot-info p  { font-size:0.8rem;color:var(--muted);font-weight:500;margin-top:2px; }
    .badge-active { background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.25);color:var(--emerald);font-size:0.7rem;font-weight:700;padding:4px 10px;border-radius:20px; }
    .badge-paid   { background:rgba(52,211,153,0.1);border:1px solid rgba(52,211,153,0.25);color:var(--emerald);font-size:0.7rem;font-weight:700;padding:4px 10px;border-radius:20px; }
    .badge-unpaid { background:var(--dangerbg);border:1px solid rgba(248,113,113,0.25);color:var(--danger);font-size:0.7rem;font-weight:700;padding:4px 10px;border-radius:20px; }
    .badge-extra  { background:var(--warningbg);border:1px solid rgba(251,191,36,0.25);color:var(--warning);font-size:0.7rem;font-weight:700;padding:4px 10px;border-radius:20px; }


    .stats-row { display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:1.25rem; }
    .stat-card { background:var(--surface);border-radius:11px;padding:12px 14px; }
    .stat-lbl { font-size:0.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:5px; }
    .stat-val { font-size:1rem;font-weight:800;color:var(--text);line-height:1.2; }
    .stat-val.green  { color:var(--emerald); }
    .stat-val.yellow { color:var(--warning); }


    .car-details { background:var(--surface);border-radius:11px;padding:12px 14px;display:flex;gap:1.5rem;margin-bottom:1.25rem;flex-wrap:wrap; }
    .cd-lbl { font-size:0.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px; }
    .cd-val { font-size:0.88rem;font-weight:700;color:var(--text); }


    /* ── FEE BLOCKS ── */
    .fee-block { border-radius:11px;padding:12px 16px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between; }
    .fee-block.running  { background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.18); }
    .fee-block.paid-box { background:rgba(52,211,153,0.05);border:1px solid rgba(52,211,153,0.15); }
    .fee-block.extra    { background:var(--warningbg);border:1px solid rgba(251,191,36,0.25); }
    .fee-block.window   { background:var(--infobg);border:1px solid rgba(96,165,250,0.2); }
    .fee-lbl { font-size:0.78rem;font-weight:700;color:var(--muted); }
    .fee-lbl span { display:block;font-size:0.68rem;color:var(--muted2);margin-top:2px; }
    .fee-amt { font-size:1.4rem;font-weight:800;font-variant-numeric:tabular-nums; }
    .fee-amt.green  { color:var(--emerald); }
    .fee-amt.green2 { color:var(--emerald2); }
    .fee-amt.yellow { color:var(--warning); }
    .fee-amt.blue   { color:var(--info); }
    .window-countdown { font-size:1.1rem;font-weight:800;color:var(--info);font-variant-numeric:tabular-nums; }


    .notice { border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:10px;margin-bottom:10px; }
    .notice svg { width:15px;height:15px;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0; }
    .notice p { font-size:0.78rem;font-weight:600;line-height:1.5; }
    .notice p strong { font-weight:800; }
    .notice.green  { background:rgba(52,211,153,0.07);border:1px solid rgba(52,211,153,0.18); }
    .notice.green svg { stroke:var(--emerald); }
    .notice.green p { color:var(--muted); }
    .notice.green p strong { color:var(--emerald); }
    .notice.yellow { background:var(--warningbg);border:1px solid rgba(251,191,36,0.25); }
    .notice.yellow svg { stroke:var(--warning); }
    .notice.yellow p { color:var(--muted); }
    .notice.yellow p strong { color:var(--warning); }
    .notice.blue { background:var(--infobg);border:1px solid rgba(96,165,250,0.2); }
    .notice.blue svg { stroke:var(--info); }
    .notice.blue p { color:var(--muted); }
    .notice.blue p strong { color:var(--info); }


    .action-row { margin-top:1rem;display:flex;flex-direction:column;gap:6px; }
    .btn-pay { width:100%;border:none;border-radius:12px;padding:13px;font-family:'Nunito',sans-serif;font-size:0.92rem;font-weight:800;cursor:pointer;transition:background 0.2s,transform 0.1s; }
    .btn-pay.primary { background:var(--emerald2);color:#0a1a12; }
    .btn-pay.primary:hover  { background:var(--emerald); }
    .btn-pay.warning { background:rgba(251,191,36,0.15);color:var(--warning);border:1.5px solid rgba(251,191,36,0.3); }
    .btn-pay.warning:hover  { background:rgba(251,191,36,0.25); }
    .btn-pay:active { transform:scale(0.98); }
    .btn-pay:disabled { background:var(--surface3);color:var(--muted);cursor:not-allowed; }
    .pay-note { font-size:0.72rem;color:var(--muted2);font-weight:600;text-align:center;margin-top:4px; }


    .pay-history { margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border); }
    .pay-history-title { font-size:0.72rem;font-weight:700;color:var(--muted2);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8px; }
    .pay-history-row { display:flex;justify-content:space-between;align-items:center;padding:7px 10px;border-radius:8px;background:var(--surface);margin-bottom:5px; }
    .pay-history-row:last-child { margin-bottom:0; }
    .phi-left { font-size:0.78rem;font-weight:600;color:var(--muted); }
    .phi-right { font-size:0.82rem;font-weight:800;color:var(--emerald); }
    .phi-receipt { font-size:0.68rem;color:var(--muted2);margin-top:1px; }


    /* ── SIDE PANEL ── */
    .session-side { display:flex;flex-direction:column;gap:1rem; }
    .side-card { background:var(--bg3);border:1px solid var(--border2);border-radius:16px;padding:1.25rem; }
    .side-card-title { font-size:0.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:1rem;display:flex;align-items:center;gap:7px; }
    .side-card-title svg { width:14px;height:14px;stroke:var(--muted);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .elapsed-display { text-align:center;padding:0.5rem 0; }
    .elapsed-time { font-size:2rem;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;letter-spacing:0.02em;line-height:1; }
    .elapsed-sub  { font-size:0.75rem;color:var(--muted);font-weight:600;margin-top:6px; }
    .rate-info { background:var(--surface);border-radius:9px;padding:10px 12px;margin-top:6px;display:flex;justify-content:space-between;font-size:0.8rem; }
    .ri-label { color:var(--muted);font-weight:600; }
    .ri-val   { color:var(--text); font-weight:700; }
    .kiosk-note { background:var(--surface);border-radius:10px;padding:12px 14px; }
    .kiosk-note p { font-size:0.78rem;color:var(--muted);font-weight:600;line-height:1.6; }
    .kiosk-note p strong { color:var(--text); }


    /* ── MODAL ── */
    .modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:200;align-items:center;justify-content:center;padding:1.5rem; }
    .modal-overlay.open { display:flex; }
    .modal { background:var(--bg2);border:1px solid var(--border2);border-radius:20px;padding:2rem;width:100%;max-width:400px;animation:fadeUp 0.35s cubic-bezier(0.22,1,0.36,1) both; }
    .modal-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem; }
    .modal-title  { font-size:1.05rem;font-weight:800;color:var(--text); }
    .modal-close  { width:30px;height:30px;background:var(--surface);border:none;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center; }
    .modal-close svg { width:14px;height:14px;stroke:var(--muted);fill:none;stroke-width:2.5;stroke-linecap:round; }
    .modal-close:hover { background:var(--surface2); }
    .modal-divider { height:1px;background:var(--border);margin:1rem 0; }
    .summary-rows { display:flex;flex-direction:column;gap:8px;margin-bottom:1rem; }
    .summary-row { display:flex;justify-content:space-between;font-size:0.85rem; }
    .sr-label { color:var(--muted);font-weight:600; }
    .sr-val   { color:var(--text); font-weight:700; }
    .total-row { display:flex;justify-content:space-between;align-items:center;background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.18);border-radius:11px;padding:12px 14px;margin-bottom:1.25rem; }
    .tr-label  { font-size:0.85rem;font-weight:700;color:var(--muted); }
    .tr-amount { font-size:1.3rem;font-weight:800;color:var(--emerald); }
    .method-label { font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:8px; }
    .method-options { display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:1.25rem; }
    .method-opt { border:1.5px solid var(--border2);border-radius:10px;padding:10px 12px;cursor:pointer;text-align:center;transition:all 0.2s; }
    .method-opt input { display:none; }
    .method-opt-label { font-size:0.82rem;font-weight:700;color:var(--muted); }
    .method-opt:has(input:checked) { border-color:rgba(52,211,153,0.4);background:var(--emeraldbg); }
    .method-opt:has(input:checked) .method-opt-label { color:var(--emerald); }
    .btn-confirm { width:100%;background:var(--emerald2);color:#0a1a12;border:none;border-radius:12px;padding:13px;font-family:'Nunito',sans-serif;font-size:0.92rem;font-weight:800;cursor:pointer;transition:background 0.2s,transform 0.1s; }
    .btn-confirm:hover  { background:var(--emerald); }
    .btn-confirm:active { transform:scale(0.98); }
    .success-modal { text-align:center;padding:0.5rem 0; }
    .success-icon { width:60px;height:60px;border-radius:50%;background:var(--emeraldbg2);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem; }
    .success-icon svg { width:28px;height:28px;stroke:var(--emerald);fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round; }
    .success-modal h2 { font-size:1.1rem;font-weight:800;color:var(--text);margin-bottom:5px; }
    .success-modal p  { font-size:0.83rem;color:var(--muted);font-weight:500;line-height:1.6; }
    .receipt-num { background:var(--surface);border-radius:9px;padding:8px 14px;font-size:0.8rem;font-weight:700;color:var(--emerald);margin:1rem 0;display:inline-block; }
    .paid-until-line { font-size:0.82rem;color:var(--muted2);font-weight:600;margin-top:6px; }
    .paid-until-line strong { color:var(--info); }


    @media (max-width:680px) {
      .session-grid { grid-template-columns:1fr; }
      .stats-row    { grid-template-columns:1fr 1fr; }
      nav { padding:0 1rem;gap:0.75rem; }
      .nav-links { display:none; }
      .page { padding:1.5rem 1rem 3rem; }
      .page-header { flex-direction:column;gap:0.75rem; }
    }
  </style>
</head>
<body>


<nav>
  <a href="index.php" class="nav-logo">
    <div class="nav-logo-icon">P</div>
    <span class="nav-logo-text">Parky</span>
  </a>
  <div class="nav-links">
    <a href="index.php">Home</a>
    <a href="reserve.php">Reserve a parking</a>
    <a href="find-my-car.php" class="active">Find my car</a>
    <a href="about.php">About</a>
  </div>
  <a href="dashboard.php" class="nav-btn"><?= htmlspecialchars($navDisplayName) ?></a>
</nav>


<div class="page">


  <div class="page-header">
    <div class="page-header-text">
      <h1>Find my car</h1>
      <p>Your live parking details, fee, and online payment — all in one place.</p>
    </div>
    <?php if ($session && !$already_exited): ?>
      <!-- TTS button only shows when there's an active session -->
      <button class="btn-tts" id="ttsBtn" onclick="toggleTTS()" title="Read parking details aloud">
        <svg id="ttsIcon" viewBox="0 0 24 24">
          <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
          <path d="M19.07 4.93a10 10 0 010 14.14"/>
          <path d="M15.54 8.46a5 5 0 010 7.07"/>
        </svg>
        <span id="ttsBtnLabel">Read aloud</span>
      </button>
    <?php endif; ?>
  </div>


  <?php /* ════════ STATES ════════ */ ?>


  <?php if ($already_exited): ?>
    <div class="state-box exited">
      <div class="state-icon">
        <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
      </div>
      <div>
        <div class="state-title">Vehicle has exited</div>
        <div class="state-sub">
          Your vehicle has already completed its session and exited the parking.
          View your full history on your <a href="dashboard.php">dashboard</a>.
        </div>
      </div>
    </div>


  <?php elseif (!$session && $upcoming_reservation): ?>
    <?php
      $arrivalTs    = strtotime($upcoming_reservation['arrival_time']);
      $nowTs        = time();
      $diffSecs     = $arrivalTs - $nowTs;
      $diffMins     = (int)floor($diffSecs / 60);
      $diffHrs      = (int)floor($diffMins / 60);
      $diffMinsRem  = $diffMins % 60;
      $countdownStr = $diffSecs > 0
        ? ($diffHrs > 0 ? "{$diffHrs}h {$diffMinsRem}m" : "{$diffMins}m")
        : 'Arrival time passed — head to the entrance';
    ?>
    <div class="state-box upcoming">
      <div class="state-icon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <div style="width:100%;">
        <div class="state-title">You have an upcoming reservation</div>
        <div class="state-sub">
          Your parking spot is reserved. This page will show your live fee and payment options
          once you arrive and are scanned at the entrance kiosk.
          <br>Manage your reservation on your <a href="dashboard.php">dashboard</a>.
        </div>
        <div class="upcoming-details">
          <div class="upd-chip">
            <div class="chip-lbl">Slot</div>
            <div class="chip-val"><?= htmlspecialchars($upcoming_reservation['slot_code']) ?>, Floor <?= $upcoming_reservation['floor'] ?></div>
          </div>
          <div class="upd-chip">
            <div class="chip-lbl">Scheduled arrival</div>
            <div class="chip-val"><?= date('M d, h:i A', $arrivalTs) ?></div>
          </div>
          <div class="upd-chip">
            <div class="chip-lbl">Plate</div>
            <div class="chip-val"><?= htmlspecialchars($upcoming_reservation['plate_number']) ?></div>
          </div>
          <div class="upd-chip">
            <div class="chip-lbl"><?= $diffSecs > 0 ? 'Arriving in' : 'Status' ?></div>
            <div class="chip-val" style="color:var(--info);"><?= $countdownStr ?></div>
          </div>
        </div>
      </div>
    </div>


  <?php elseif (!$session): ?>
    <div class="state-box error">
      <div class="state-icon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <div>
        <div class="state-title">No active parking session</div>
        <div class="state-sub">
          <?php if ($ownPlate): ?>
            No active session was found for your registered plate <strong><?= htmlspecialchars($ownPlate) ?></strong>.
            If you just arrived, make sure you've been scanned at the entrance kiosk.
          <?php else: ?>
            Your account doesn't have a plate number registered yet.
            <a href="dashboard.php">Update your profile</a> to add one.
          <?php endif; ?>
          <br>Want to park? <a href="reserve.php">Reserve a spot</a> or use the entrance kiosk as a walk-in.
        </div>
      </div>
    </div>


  <?php else: ?>
  <?php /* ════════ ACTIVE SESSION ════════ */ ?>
  <div class="session-grid">


    <!-- ── MAIN ── -->
    <div class="session-main">


      <div class="session-header">
        <div class="session-title-row">
          <div class="slot-badge">
            <div class="slot-code"><?= htmlspecialchars($session['slot_code']) ?></div>
            <div class="slot-floor">Floor <?= $session['floor'] ?></div>
          </div>
          <div class="session-slot-info">
            <h2>Slot <?= htmlspecialchars($session['slot_code']) ?>, Floor <?= $session['floor'] ?></h2>
            <p>Plate: <?= htmlspecialchars($session['plate_number']) ?></p>
          </div>
        </div>
        <span class="badge-active">Active</span>
      </div>


      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-lbl">Entry time</div>
          <div class="stat-val"><?= date('h:i A', strtotime($session['entry_time'])) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-lbl">Time parked</div>
          <div class="stat-val green" id="elapsedDisplay">
            <?= floor(($elapsed_secs/60) / 60) ?>h <?= str_pad(floor($elapsed_secs/60) % 60, 2, '0', STR_PAD_LEFT) ?>m
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-lbl">Payment</div>
          <div class="stat-val">
            <?php if ($fee_state === 'unpaid'): ?>
              <span class="badge-unpaid">Unpaid</span>
            <?php elseif ($fee_state === 'extra_owed'): ?>
              <span class="badge-extra">Extra owed</span>
            <?php else: ?>
              <span class="badge-paid">Paid</span>
            <?php endif; ?>
          </div>
        </div>
      </div>


      <div class="car-details">
        <div><div class="cd-lbl">Plate</div><div class="cd-val"><?= htmlspecialchars($session['plate_number']) ?></div></div>
        <?php if (!empty($session['car_type'])): ?>
          <div><div class="cd-lbl">Type</div><div class="cd-val"><?= htmlspecialchars($session['car_type']) ?></div></div>
        <?php endif; ?>
        <?php if (!empty($session['car_color'])): ?>
          <div><div class="cd-lbl">Color</div><div class="cd-val"><?= htmlspecialchars($session['car_color']) ?></div></div>
        <?php endif; ?>
        <div><div class="cd-lbl">Date</div><div class="cd-val"><?= date('M d, Y', strtotime($session['entry_time'])) ?></div></div>
      </div>


      <?php /* ════════ FEE DISPLAY — STATE-DRIVEN ════════ */ ?>


      <?php if ($fee_state === 'unpaid'): ?>
        <div class="fee-block running">
          <div class="fee-lbl">
            Running fee
            <span>Updates every second · ₱60 first 3 hrs, then ₱30/hr</span>
          </div>
          <div class="fee-amt green" id="liveFee">₱<?= number_format($running_fee, 2) ?></div>
        </div>
        <div class="action-row">
          <button class="btn-pay primary" onclick="openPayModal('first')">Pay online now</button>
          <p class="pay-note">Online: GCash or Maya only — cash/card available at the payment kiosk.</p>
        </div>


      <?php elseif ($fee_state === 'within_window'): ?>
        <?php if ($last_payment): ?>
        <div class="fee-block paid-box">
          <div class="fee-lbl">
            Last payment
            <span>via <?= strtoupper(htmlspecialchars($last_payment['method'])) ?> · <?= date('M d, h:i A', strtotime($last_payment['paid_at'])) ?></span>
          </div>
          <div class="fee-amt green2">₱<?= number_format($last_payment['total_amount'], 2) ?></div>
        </div>
        <?php endif; ?>
        <div class="fee-block window">
          <div class="fee-lbl">
            Paid parking window
            <span>Additional fee starts after this</span>
          </div>
          <div class="window-countdown" id="windowCountdown">--:--:--</div>
        </div>
        <div class="notice blue">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <p>Your parking is covered until <strong id="paidUntilLabel"><?= date('h:i A', $window_countdown_ts) ?></strong>. No action needed.</p>
        </div>
        <div class="action-row">
          <button class="btn-pay primary" disabled>Payment confirmed</button>
          <p class="pay-note">Proceed to the exit kiosk when you're ready to leave.</p>
        </div>


      <?php elseif ($fee_state === 'extra_owed'): ?>
        <?php if ($last_payment): ?>
        <div class="fee-block paid-box">
          <div class="fee-lbl">
            Previously paid
            <span>via <?= strtoupper(htmlspecialchars($last_payment['method'])) ?> · <?= date('M d, h:i A', strtotime($last_payment['paid_at'])) ?></span>
          </div>
          <div class="fee-amt green2">₱<?= number_format($total_paid_so_far, 2) ?></div>
        </div>
        <?php endif; ?>
        <div class="fee-block extra">
          <div class="fee-lbl">
            Additional fee due
            <span>Credited through <?= date('h:i A', $paid_until_ts) ?> · updates every second · ₱60 first 3 hrs, then ₱30/hr</span>
          </div>
          <div class="fee-amt yellow" id="liveFee">₱<?= number_format($extra_fee, 2) ?></div>
        </div>
        <div class="notice yellow">
          <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <p>Your car has been parked past the paid window. <strong>An additional fee of ₱<?= number_format($extra_fee, 2) ?> is now owed.</strong></p>
        </div>
        <div class="action-row">
          <button class="btn-pay warning" onclick="openPayModal('extra')">Pay additional fee</button>
          <p class="pay-note">Online: GCash or Maya only — cash/card available at the payment kiosk.</p>
        </div>


      <?php endif; ?>


      <?php if (!empty($all_payments)): ?>
      <div class="pay-history">
        <div class="pay-history-title">Payment history</div>
        <?php foreach ($all_payments as $p): ?>
        <div class="pay-history-row">
          <div class="phi-left">
            <?= date('M d, h:i A', strtotime($p['paid_at'])) ?> · <?= strtoupper(htmlspecialchars($p['method'])) ?>
            <div class="phi-receipt"><?= htmlspecialchars($p['receipt_number']) ?></div>
          </div>
          <div class="phi-right">₱<?= number_format($p['total_amount'], 2) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>


    </div><!-- /session-main -->


    <!-- ── SIDE ── -->
    <div class="session-side">
      <div class="side-card">
        <div class="side-card-title">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Time elapsed
        </div>
        <div class="elapsed-display">
          <?php
            $hh = str_pad(floor($elapsed_secs / 3600), 2, '0', STR_PAD_LEFT);
            $mm = str_pad(floor(($elapsed_secs % 3600) / 60), 2, '0', STR_PAD_LEFT);
            $ss = str_pad($elapsed_secs % 60, 2, '0', STR_PAD_LEFT);
          ?>
          <div class="elapsed-time" id="elapsedClock"><?= $hh ?>:<?= $mm ?>:<?= $ss ?></div>
          <div class="elapsed-sub">hours : minutes : seconds</div>
        </div>
        <div class="rate-info">
          <span class="ri-label">First 3 hours</span>
          <span class="ri-val">₱<?= RATE_FIRST_BLOCK ?> flat</span>
        </div>
        <div class="rate-info">
          <span class="ri-label">Grace (1–<?= GRACE_MINUTES ?>min overage)</span>
          <span class="ri-val">₱<?= RATE_GRACE ?></span>
        </div>
        <div class="rate-info">
          <span class="ri-label">Each extra hour</span>
          <span class="ri-val">₱<?= RATE_PER_HOUR ?></span>
        </div>
        <?php if ($total_paid_so_far > 0): ?>
        <div class="rate-info" style="margin-top:4px;background:rgba(52,211,153,0.06);border:1px solid rgba(52,211,153,0.15);">
          <span class="ri-label" style="color:var(--emerald2);">Total paid</span>
          <span class="ri-val"   style="color:var(--emerald);">₱<?= number_format($total_paid_so_far, 2) ?></span>
        </div>
        <?php endif; ?>
      </div>


      <div class="side-card">
        <div class="side-card-title">
          <svg viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
          How to exit
        </div>
        <div class="kiosk-note">
          <p>
            <strong>Step 1</strong> — Pay your fee online here, or at the payment kiosk.<br><br>
            <strong>Step 2</strong> — Go to the exit kiosk and have your plate scanned.<br><br>
            <strong>Step 3</strong> — Once payment is confirmed, the barrier opens and your slot is freed.
          </p>
        </div>
      </div>
    </div>


  </div><!-- /session-grid -->
  <?php endif; ?>


</div><!-- /page -->




<!-- PAYMENT MODAL -->
<div class="modal-overlay" id="payModal">
  <div class="modal">
    <div id="summaryView">
      <div class="modal-header">
        <span class="modal-title" id="modalTitle">Payment summary</span>
        <button class="modal-close" onclick="closePayModal()">
          <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="summary-rows">
        <div class="summary-row">
          <span class="sr-label">Plate</span>
          <span class="sr-val"><?= htmlspecialchars($session['plate_number'] ?? '') ?></span>
        </div>
        <div class="summary-row">
          <span class="sr-label">Slot</span>
          <span class="sr-val"><?= htmlspecialchars(($session['slot_code'] ?? '') . ', Floor ' . ($session['floor'] ?? '')) ?></span>
        </div>
        <div class="summary-row">
          <span class="sr-label">Billing from</span>
          <span class="sr-val" id="modalBillingPeriod">—</span>
        </div>
        <div class="summary-row">
          <span class="sr-label">Time elapsed</span>
          <span class="sr-val" id="modalDuration">—</span>
        </div>
        <div class="summary-row">
          <span class="sr-label">Rate</span>
          <span class="sr-val">₱60 first 3 hrs · ₱30/hr after</span>
        </div>
      </div>
      <div class="modal-divider"></div>
      <div class="total-row">
        <span class="tr-label">Total amount</span>
        <span class="tr-amount" id="modalTotal">₱—</span>
      </div>
      <div class="method-label">Choose payment method</div>
      <div class="method-options">
        <label class="method-opt"><input type="radio" name="payMethod" value="gcash" checked/><div class="method-opt-label">GCash</div></label>
        <label class="method-opt"><input type="radio" name="payMethod" value="maya"/><div class="method-opt-label">Maya</div></label>
      </div>
      <p style="font-size:0.72rem;color:var(--muted2);font-weight:600;margin-bottom:1rem;">
        Dummy payment — no real transaction will occur.
      </p>
      <button class="btn-confirm" id="confirmBtn" onclick="confirmPayment()">Confirm payment</button>
    </div>


    <div id="successView" style="display:none;">
      <div class="success-modal">
        <div class="success-icon">
          <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2>Payment confirmed!</h2>
        <p>Your parking fee has been paid. Proceed to the exit kiosk when ready.</p>
        <div class="receipt-num" id="receiptNum">RCP-—</div>
        <p class="paid-until-line">Paid as of <strong id="paidUntilResult">—</strong></p>
        <br>
        <button class="btn-confirm" onclick="closePayModal(); location.reload();">Done</button>
      </div>
    </div>
  </div>
</div>


<script>
var elapsedBase  = <?= (int)$elapsed_secs ?>;
var entryTs      = <?= ($session && !$already_exited) ? (int) strtotime($session['entry_time']) : 0 ?>;
var paidUntilCreditTs = <?= ($session && !$already_exited && !empty($session['paid_until'])) ? (int) strtotime($session['paid_until']) : 0 ?>;
var windowCountdownTs = <?= (int) $window_countdown_ts ?>;
var feeState     = '<?= $fee_state ?>';
var pageLoadedAt = Date.now();
var isActive     = <?= ($session && !$already_exited) ? 'true' : 'false' ?>;


var RATE_FIRST_BLOCK = <?= RATE_FIRST_BLOCK ?>;
var RATE_FIRST_HOURS = <?= RATE_FIRST_HOURS ?>;
var GRACE_MINUTES    = <?= GRACE_MINUTES ?>;
var RATE_GRACE       = <?= RATE_GRACE ?>;
var RATE_PER_HOUR    = <?= RATE_PER_HOUR ?>;


// ── TTS data from PHP ─────────────────────────────────────────
var ttsSlot    = '<?= htmlspecialchars($session['slot_code'] ?? '', ENT_QUOTES) ?>';
var ttsFloor   = '<?= htmlspecialchars($session['floor'] ?? '', ENT_QUOTES) ?>';
var ttsPlate   = '<?= htmlspecialchars($session['plate_number'] ?? '', ENT_QUOTES) ?>';
var ttsEntryTs = <?= $session ? strtotime($session['entry_time']) : 0 ?>;


function calculateFee(seconds) {
  if (seconds <= 0) return RATE_FIRST_BLOCK;
  var minutes        = seconds / 60;
  var firstBlockMins = RATE_FIRST_HOURS * 60;
  if (minutes <= firstBlockMins) return RATE_FIRST_BLOCK;
  var fee          = RATE_FIRST_BLOCK;
  var extraMinutes = minutes - firstBlockMins;
  var fullHours    = Math.floor(extraMinutes / 60);
  var remainder    = extraMinutes - (fullHours * 60);
  fee += fullHours * RATE_PER_HOUR;
  if (remainder > 0 && remainder <= GRACE_MINUTES) {
    fee += RATE_GRACE;
  } else if (remainder > GRACE_MINUTES) {
    fee += RATE_PER_HOUR;
  }
  return fee;
}


/** Mirrors config/rates.php getAmountDue — never fee only "past paid_until" seconds. */
function getAmountDueJs(totalSecsElapsed) {
  var totalFee = calculateFee(totalSecsElapsed);
  if (feeState === 'unpaid' || !paidUntilCreditTs) {
    return totalFee;
  }
  var span   = Math.max(0, paidUntilCreditTs - entryTs);
  var credit = calculateFee(span);
  return Math.max(0, totalFee - credit);
}


function pad(n) { return String(n).padStart(2, '0'); }


function tick() {
  var addSecs   = Math.floor((Date.now() - pageLoadedAt) / 1000);
  var totalSecs = elapsedBase + addSecs;


  var h = Math.floor(totalSecs / 3600);
  var m = Math.floor((totalSecs % 3600) / 60);
  var s = totalSecs % 60;


  var clockEl = document.getElementById('elapsedClock');
  var dispEl  = document.getElementById('elapsedDisplay');
  if (clockEl) clockEl.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
  if (dispEl)  dispEl.textContent  = h + 'h ' + pad(m) + 'm';


  var feeEl = document.getElementById('liveFee');


  if (feeState === 'unpaid') {
    if (feeEl) feeEl.textContent = '₱' + getAmountDueJs(totalSecs).toFixed(2);


  } else if (feeState === 'within_window') {
    var nowTs     = Math.floor(Date.now() / 1000);
    var remaining = windowCountdownTs - nowTs;
    var wEl       = document.getElementById('windowCountdown');
    if (remaining > 0) {
      var wh = Math.floor(remaining / 3600);
      var wm = Math.floor((remaining % 3600) / 60);
      var ws = remaining % 60;
      if (wEl) wEl.textContent = pad(wh) + ':' + pad(wm) + ':' + pad(ws);
    } else {
      location.reload();
    }


  } else if (feeState === 'extra_owed') {
    if (feeEl) feeEl.textContent = '₱' + getAmountDueJs(totalSecs).toFixed(2);
  }
}


if (isActive) {
  tick();
  setInterval(tick, 1000);
}


// ── TEXT-TO-SPEECH ────────────────────────────────────────────
var ttsSpeaking = false;


function buildTTSText() {
  var addSecs   = Math.floor((Date.now() - pageLoadedAt) / 1000);
  var totalSecs = elapsedBase + addSecs;
  var h = Math.floor(totalSecs / 3600);
  var m = Math.floor((totalSecs % 3600) / 60);


  var feeText = '';
  if (feeState === 'unpaid') {
    var fee = getAmountDueJs(totalSecs);
    feeText = 'Your current fee is ' + fee.toFixed(2) + ' pesos. Please proceed to pay before exiting.';
  } else if (feeState === 'within_window') {
    feeText = 'Your parking fee has been paid. No additional action is needed right now.';
  } else if (feeState === 'extra_owed') {
    var extraFee = getAmountDueJs(totalSecs);
    feeText = 'An additional fee of ' + extraFee.toFixed(2) + ' pesos is owed beyond your paid coverage.';
  }


  var timeText = h > 0
    ? 'You have been parked for ' + h + ' hour' + (h > 1 ? 's' : '') + ' and ' + m + ' minute' + (m !== 1 ? 's' : '') + '.'
    : 'You have been parked for ' + m + ' minute' + (m !== 1 ? 's' : '') + '.';


  return 'You are parked at slot ' + ttsSlot + ', Floor ' + ttsFloor + '. '
       + 'Vehicle plate: ' + ttsPlate.split('').join(' ') + '. '
       + timeText + ' '
       + feeText;
}


function toggleTTS() {
  if (!('speechSynthesis' in window)) {
    alert('Text to speech is not supported in your browser. Please use Chrome or Edge.');
    return;
  }


  var btn   = document.getElementById('ttsBtn');
  var label = document.getElementById('ttsBtnLabel');
  var icon  = document.getElementById('ttsIcon');


  if (ttsSpeaking) {
    window.speechSynthesis.cancel();
    ttsSpeaking = false;
    btn.classList.remove('speaking');
    label.textContent = 'Read aloud';
    icon.innerHTML = '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14"/><path d="M15.54 8.46a5 5 0 010 7.07"/>';
    return;
  }


  var utterance  = new SpeechSynthesisUtterance(buildTTSText());
  utterance.lang = 'en-US';
  utterance.rate = 0.95;
  utterance.pitch = 1;


  utterance.onstart = function() {
    ttsSpeaking = true;
    btn.classList.add('speaking');
    label.textContent = 'Stop reading';
    // Switch to stop/mute icon
    icon.innerHTML = '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/>';
  };


  utterance.onend = utterance.onerror = function() {
    ttsSpeaking = false;
    btn.classList.remove('speaking');
    label.textContent = 'Read aloud';
    icon.innerHTML = '<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14"/><path d="M15.54 8.46a5 5 0 010 7.07"/>';
  };


  window.speechSynthesis.speak(utterance);
}


// ── PAYMENT MODAL ─────────────────────────────────────────────
var currentPayType = 'first';


function openPayModal(type) {
  currentPayType = type || 'first';
  var addSecs    = Math.floor((Date.now() - pageLoadedAt) / 1000);
  var billingSecs, billingFrom, fee;


  if (currentPayType === 'extra') {
    billingSecs = elapsedBase + addSecs;
    billingFrom = 'Credited through <?= !empty($session['paid_until']) ? date('h:i A', strtotime($session['paid_until'])) : '' ?>';
    fee         = getAmountDueJs(billingSecs);
  } else {
    billingSecs = elapsedBase + addSecs;
    billingFrom = 'Since <?= $session ? date('h:i A', strtotime($session['entry_time'])) : '' ?>';
    fee         = getAmountDueJs(billingSecs);
  }


  var h = Math.floor(billingSecs / 3600);
  var m = Math.floor((billingSecs % 3600) / 60);


  document.getElementById('modalTitle').textContent         = currentPayType === 'extra' ? 'Additional payment' : 'Payment summary';
  document.getElementById('modalBillingPeriod').textContent = billingFrom;
  document.getElementById('modalDuration').textContent      = h + 'h ' + pad(m) + 'm';
  document.getElementById('modalTotal').textContent         = '₱' + fee.toFixed(2);
  document.getElementById('summaryView').style.display      = 'block';
  document.getElementById('successView').style.display      = 'none';


  var btn = document.getElementById('confirmBtn');
  btn.textContent = 'Confirm payment';
  btn.disabled    = false;


  document.getElementById('payModal').classList.add('open');
}


function closePayModal() {
  document.getElementById('payModal').classList.remove('open');
}


function confirmPayment() {
  var btn    = document.getElementById('confirmBtn');
  var method = document.querySelector('input[name="payMethod"]:checked')?.value || 'gcash';


  btn.textContent = 'Processing...';
  btn.disabled    = true;


  var fd = new FormData();
  fd.append('confirm_payment', '1');
  fd.append('plate',  '<?= htmlspecialchars($session['plate_number'] ?? '', ENT_QUOTES) ?>');
  fd.append('method', method);


  fetch('find-my-car.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        document.getElementById('receiptNum').textContent      = data.receipt;
        document.getElementById('paidUntilResult').textContent = data.paid_until;
        document.getElementById('summaryView').style.display   = 'none';
        document.getElementById('successView').style.display   = 'block';
      } else {
        alert(data.message || 'Payment failed. Please try again.');
        btn.textContent = 'Confirm payment';
        btn.disabled    = false;
      }
    })
    .catch(() => {
      alert('Something went wrong. Please try again.');
      btn.textContent = 'Confirm payment';
      btn.disabled    = false;
    });
}


document.getElementById('payModal').addEventListener('click', function(e) {
  if (e.target === this) closePayModal();
});
</script>


</body>
</html>

