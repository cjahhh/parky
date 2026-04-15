<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$userDisplayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($userDisplayName === '') {
    $userDisplayName = $user['username'] ?? '';
}
$userInitial = strtoupper(substr($user['first_name'] ?? '', 0, 1));
if ($userInitial === '') {
    $userInitial = strtoupper(substr($user['username'] ?? '?', 0, 1));
}

require_once __DIR__ . '/config/rates.php';

// ── Auto-expiry ─────────────────────────────────────────────
$stmtExpired = $pdo->prepare("
    SELECT r.id, r.slot_id FROM reservations r
    WHERE r.user_id = ? AND r.status IN ('pending','confirmed') AND r.expires_at < NOW()
");
$stmtExpired->execute([$userId]);
foreach ($stmtExpired->fetchAll(PDO::FETCH_ASSOC) as $ex) {
    $pdo->prepare("UPDATE reservations SET status='expired' WHERE id=?")->execute([$ex['id']]);
    $pdo->prepare("UPDATE parking_slots SET status='available' WHERE id=? AND status='reserved'")->execute([$ex['slot_id']]);
}

// ── Active reservation ──────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.*, ps.slot_code, ps.floor FROM reservations r
    JOIN parking_slots ps ON r.slot_id = ps.id
    WHERE r.user_id = ? AND r.status IN ('pending','confirmed','active')
    ORDER BY CASE r.status WHEN 'active' THEN 1 WHEN 'confirmed' THEN 2 WHEN 'pending' THEN 3 END ASC,
             r.arrival_time ASC LIMIT 1
");
$stmt->execute([$userId]);
$activeRecord = $stmt->fetch();

// ── Payments for active reservation ────────────────────────
$allResPayments = [];
if ($activeRecord && $activeRecord['status'] === 'active') {
    $pstmt = $pdo->prepare("SELECT * FROM payments WHERE session_id=? AND session_type='reservation' ORDER BY paid_at ASC");
    $pstmt->execute([$activeRecord['id']]);
    $allResPayments = $pstmt->fetchAll();
}

// ── Reservation history ─────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.*, ps.slot_code, ps.floor FROM reservations r
    JOIN parking_slots ps ON r.slot_id = ps.id
    WHERE r.user_id = ? ORDER BY r.reserved_at DESC LIMIT 20
");
$stmt->execute([$userId]);
$reservationHistory = $stmt->fetchAll();

// ── Transaction history (reservation payments + walk-in payments for this account / plate) ─
$userPlateNorm = strtoupper(trim($user['plate_number'] ?? ''));
$txnSql = "
    SELECT * FROM (
        SELECT p.*, ps.slot_code, 'Reservation' AS txn_kind
        FROM payments p
        INNER JOIN reservations r ON p.session_id = r.id AND p.session_type = 'reservation'
        INNER JOIN parking_slots ps ON r.slot_id = ps.id
        WHERE r.user_id = ?

        UNION ALL

        SELECT p.*, ps.slot_code, 'Walk-in' AS txn_kind
        FROM payments p
        INNER JOIN sessions_walkin sw ON p.session_id = sw.id AND p.session_type = 'walkin'
        INNER JOIN parking_slots ps ON sw.slot_id = ps.id
        WHERE sw.user_id = ?
           OR (? <> '' AND UPPER(TRIM(sw.plate_number)) = ?)
    ) AS u
    ORDER BY u.paid_at DESC
    LIMIT 50
";
$txnStmt = $pdo->prepare($txnSql);
$txnStmt->execute([$userId, $userId, $userPlateNorm, $userPlateNorm]);
$transactions = $txnStmt->fetchAll();

// ── POST actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $resId  = (int)($_POST['reservation_id'] ?? 0);

    if ($action === 'extend' && $activeRecord && $activeRecord['id'] === $resId) {
        if (in_array($activeRecord['status'], ['pending','confirmed']) && !$activeRecord['extended']) {
            $newExpiry = date('Y-m-d H:i:s', strtotime($activeRecord['expires_at'] . ' +1 hour'));
            $pdo->prepare("UPDATE reservations SET extended=1, extension_fee=?, expires_at=? WHERE id=? AND user_id=?")
                ->execute([EXTENSION_FEE, $newExpiry, $resId, $userId]);
            $_SESSION['toast'] = ['type'=>'success','msg'=>'Reservation extended by 1 hour. ₱'.EXTENSION_FEE.' extension fee added.'];
        } else {
            $_SESSION['toast'] = ['type'=>'error','msg'=>'Cannot extend this reservation.'];
        }
        header('Location: dashboard.php'); exit;
    }

    if ($action === 'cancel' && $activeRecord && $activeRecord['id'] === $resId) {
        if (in_array($activeRecord['status'], ['pending','confirmed'])) {
            $pdo->prepare("UPDATE parking_slots SET status='available' WHERE id=?")->execute([$activeRecord['slot_id']]);
            $pdo->prepare("UPDATE reservations SET status='cancelled' WHERE id=? AND user_id=?")->execute([$resId, $userId]);
            $_SESSION['toast'] = ['type'=>'success','msg'=>'Reservation cancelled successfully.'];
        } else {
            $_SESSION['toast'] = ['type'=>'error','msg'=>'Only pending or confirmed reservations can be cancelled.'];
        }
        header('Location: dashboard.php'); exit;
    }

    if ($action === 'edit' && $activeRecord && $activeRecord['id'] === $resId) {
        if (in_array($activeRecord['status'], ['pending','confirmed'])) {
            $newDate = trim($_POST['new_date'] ?? '');
            $newTime = trim($_POST['new_time'] ?? '');
            if ($newDate && $newTime) {
                $newArrival = $newDate . ' ' . $newTime . ':00';
                $arrivalTs  = strtotime($newArrival);
                if ($arrivalTs < time()) {
                    $_SESSION['toast'] = ['type'=>'error','msg'=>'Arrival time must be in the future.'];
                } else {
                    $extraHours = $activeRecord['extended'] ? 2 : 1;
                    $newExpiry  = date('Y-m-d H:i:s', $arrivalTs + ($extraHours * 3600));
                    $pdo->prepare("UPDATE reservations SET arrival_time=?, expires_at=? WHERE id=? AND user_id=?")
                        ->execute([$newArrival, $newExpiry, $resId, $userId]);
                    $_SESSION['toast'] = ['type'=>'success','msg'=>'Reservation updated successfully.'];
                }
            } else {
                $_SESSION['toast'] = ['type'=>'error','msg'=>'Please provide a valid date and time.'];
            }
        } else {
            $_SESSION['toast'] = ['type'=>'error','msg'=>'Only pending or confirmed reservations can be edited.'];
        }
        header('Location: dashboard.php'); exit;
    }

    if ($action === 'pay_online' && $activeRecord && $activeRecord['id'] === $resId) {
        if ($activeRecord['status'] === 'active') {
            $method    = $_POST['method'] ?? 'gcash';
            $now_dt    = new DateTime();
            $entry_dt  = new DateTime($activeRecord['arrival_time']);
            $elapsed_total = max(0, $now_dt->getTimestamp() - $entry_dt->getTimestamp());
            $paidUntilCol = ($activeRecord['payment_status'] === 'paid' && !empty($activeRecord['paid_until']))
                ? $activeRecord['paid_until']
                : null;
            $baseFee = getAmountDue($elapsed_total, $paidUntilCol, $activeRecord['arrival_time']);
            $extFeeAmt = ($activeRecord['payment_status'] === 'unpaid' && !empty($activeRecord['extended'])) ? EXTENSION_FEE : 0;
            $totalFee  = $baseFee + $extFeeAmt;
            if ($totalFee <= 0.009) {
                $_SESSION['toast'] = ['type'=>'error','msg'=>'No payment is due right now.'];
                header('Location: dashboard.php'); exit;
            }
            if ($activeRecord['payment_status'] === 'unpaid' || empty($activeRecord['paid_until'])) {
                $covered_end = (clone $entry_dt);
                $covered_end->modify('+' . RATE_FIRST_HOURS . ' hours');
                $paid_until_new = $covered_end->format('Y-m-d H:i:s');
            } else {
                $paid_until_new = $now_dt->format('Y-m-d H:i:s');
            }
            $receipt = 'RCP-' . strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare("INSERT INTO payments (session_id,session_type,base_amount,extension_fee,total_amount,method,source,receipt_number) VALUES (?,'reservation',?,?,?,?,'online',?)")
                ->execute([$resId, $baseFee, $extFeeAmt, $totalFee, $method, $receipt]);
            $pdo->prepare("UPDATE reservations SET payment_status='paid', paid_until=? WHERE id=?")->execute([$paid_until_new, $resId]);
            $_SESSION['toast'] = ['type'=>'success','msg'=>"Payment of ₱{$totalFee} via ".strtoupper($method)." confirmed! Receipt: {$receipt}."];
        } else {
            $_SESSION['toast'] = ['type'=>'error','msg'=>'Payment not applicable for this reservation.'];
        }
        header('Location: dashboard.php'); exit;
    }
}

$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);

// ── Fee state (same model as find-my-car: getAmountDue + first pay → paid_until = entry + 3h) ─
$fee_state           = 'none';
$elapsed_secs        = 0;
$paid_until_ts       = 0;
$paid_until_credit_ts_js = 0;
$window_countdown_ts = 0;
$running_fee         = 0;
$extra_fee           = 0;
$total_paid_so_far   = array_sum(array_column($allResPayments, 'total_amount'));
$last_res_payment    = !empty($allResPayments) ? end($allResPayments) : null;

if ($activeRecord && $activeRecord['status'] === 'active') {
    $entry_dt     = new DateTime($activeRecord['arrival_time']);
    $now_dt       = new DateTime();
    $elapsed_secs = max(0, $now_dt->getTimestamp() - $entry_dt->getTimestamp());
    $entry_ts     = $entry_dt->getTimestamp();

    if ($activeRecord['payment_status'] === 'unpaid') {
        $fee_state   = 'unpaid';
        $running_fee = getAmountDue($elapsed_secs, null, $activeRecord['arrival_time']);
        if (!empty($activeRecord['extended'])) {
            $running_fee += EXTENSION_FEE;
        }
    } elseif ($activeRecord['payment_status'] === 'paid') {
        $resolved_paid_until = $activeRecord['paid_until'] ?? null;
        if (empty($resolved_paid_until) && !empty($allResPayments)) {
            $resolved_paid_until = end($allResPayments)['paid_at'];
            $pdo->prepare("UPDATE reservations SET paid_until=? WHERE id=?")->execute([$resolved_paid_until, $activeRecord['id']]);
        }
        if (!empty($resolved_paid_until)) {
            $paid_until_ts = (new DateTime($resolved_paid_until))->getTimestamp();
            $paid_until_credit_ts_js = $paid_until_ts;
            $due           = getAmountDue($elapsed_secs, $resolved_paid_until, $activeRecord['arrival_time']);
            if ($due <= 0.009) {
                $fee_state           = 'within_window';
                $window_countdown_ts = max($paid_until_ts, $entry_ts + RATE_FIRST_HOURS * 3600);
            } else {
                $fee_state = 'extra_owed';
                $extra_fee = $due;
            }
        } else {
            $fee_state   = 'unpaid';
            $running_fee = getAmountDue($elapsed_secs, null, $activeRecord['arrival_time']);
            if (!empty($activeRecord['extended'])) {
                $running_fee += EXTENSION_FEE;
            }
        }
    }
}

$canEdit     = $activeRecord && in_array($activeRecord['status'], ['pending','confirmed']);
$canCancel   = $canEdit;
$canExtend   = $canEdit && !$activeRecord['extended'];
$canPayFirst = $activeRecord && $activeRecord['status'] === 'active' && $fee_state === 'unpaid';
$canPayExtra = $activeRecord && $activeRecord['status'] === 'active' && $fee_state === 'extra_owed';
$canPay      = $canPayFirst || $canPayExtra;

$totalReservations = count($reservationHistory);
$totalSpent        = array_sum(array_column($transactions, 'total_amount'));
$currentDate       = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky — Dashboard</title>
          <link rel="icon" href="favicon.ico" type="image/x-icon">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:          #181c18;
      --bg2:         #1f231f;
      --bg3:         #242824;
      --surface:     #252a25;
      --surface2:    #2c312c;
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
      --dangerbg:    rgba(248,113,113,0.1);
      --dangerborder:rgba(248,113,113,0.25);
      --warning:     #fbbf24;
      --warningbg:   rgba(251,191,36,0.1);
      --info:        #60a5fa;
      --infobg:      rgba(96,165,250,0.1);
      --sidebar-w:   220px;
    }
    html { scroll-behavior: smooth; }
    body { font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex; }
    a { text-decoration:none;color:inherit; }

    /* ── SIDEBAR ── */
    .sidebar {
      width:var(--sidebar-w);min-height:100vh;
      background:var(--bg2);border-right:1px solid var(--border2);
      display:flex;flex-direction:column;
      position:fixed;top:0;left:0;bottom:0;z-index:100;overflow-y:auto;
    }
    .sidebar-logo {
      display:flex;align-items:center;gap:10px;
      padding:1.4rem 1.25rem 1.2rem;
      border-bottom:1px solid var(--border);
    }
    .sidebar-logo-icon {
      width:34px;height:34px;background:var(--emeraldbg2);
      border:1.5px solid rgba(52,211,153,0.3);border-radius:9px;
      display:flex;align-items:center;justify-content:center;
      font-size:0.95rem;font-weight:900;color:var(--emerald);flex-shrink:0;
    }
    .sidebar-logo-text { font-size:1.15rem;font-weight:900;color:var(--emerald);letter-spacing:-0.02em; }
    .sidebar-nav { padding:1rem 0.75rem;flex:1;display:flex;flex-direction:column;gap:2px; }
    .nav-section-label {
      font-size:0.63rem;font-weight:700;color:var(--muted2);
      text-transform:uppercase;letter-spacing:0.08em;
      padding:0.6rem 0.5rem 0.3rem;margin-top:0.4rem;
    }
    .nav-section-label:first-child { margin-top:0; }
    .nav-item {
      display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:9px;
      font-size:0.82rem;font-weight:700;color:var(--muted);
      cursor:pointer;transition:background 0.15s,color 0.15s;text-decoration:none;
    }
    .nav-item svg { width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0; }
    .nav-item:hover { background:var(--surface);color:var(--text); }
    .nav-item.active { background:var(--emeraldbg);color:var(--emerald);border:1px solid rgba(52,211,153,0.15); }
    .nav-item.danger { color:var(--danger); }
    .nav-item.danger:hover { background:var(--dangerbg);color:var(--danger); }
    .nav-item.disabled { opacity:0.4;pointer-events:none;cursor:not-allowed; }
    .sidebar-bottom { padding:1rem 0.75rem;border-top:1px solid var(--border); }
    .sidebar-user {
      display:flex;align-items:center;gap:10px;padding:10px;
      background:var(--surface);border-radius:10px;margin-bottom:8px;
    }
    .sidebar-user-avatar {
      width:32px;height:32px;background:var(--emeraldbg2);
      border:1.5px solid rgba(52,211,153,0.3);border-radius:8px;
      display:flex;align-items:center;justify-content:center;
      font-size:0.85rem;font-weight:900;color:var(--emerald);flex-shrink:0;
    }
    .sidebar-user-info { min-width:0; }
    .sidebar-user-name { font-size:0.79rem;font-weight:800;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
    .sidebar-user-plate { font-size:0.67rem;color:var(--emerald2);font-weight:700;margin-top:1px; }

    /* ── MAIN ── */
    .main-wrap { margin-left:var(--sidebar-w);flex:1;min-width:0;display:flex;flex-direction:column; }
    .topbar {
      height:56px;background:rgba(24,28,24,0.88);backdrop-filter:blur(12px);
      border-bottom:1px solid var(--border2);
      display:flex;align-items:center;padding:0 2rem;gap:1rem;
      position:sticky;top:0;z-index:50;
    }
    .topbar-title { font-size:0.98rem;font-weight:800;color:var(--text);letter-spacing:-0.01em; }
    .topbar-date  { font-size:0.76rem;color:var(--muted);font-weight:600;margin-left:auto; }
    .page-content { padding:1.75rem 2rem 3rem; }

    /* ── GRID ── */
    .content-grid { display:grid;grid-template-columns:1fr 295px;gap:1.25rem;align-items:start; }
    .content-main { min-width:0;display:flex;flex-direction:column;gap:1.25rem; }
    .content-side { min-width:0;display:flex;flex-direction:column;gap:1.25rem; }

    /* ── CARDS ── */
    .card { background:var(--bg3);border:1px solid var(--border2);border-radius:14px;padding:1.3rem; }
    .card-title { font-size:0.84rem;font-weight:800;color:var(--text);margin-bottom:1rem;display:flex;align-items:center;gap:8px; }
    .card-title svg { width:15px;height:15px;fill:none;stroke:var(--emerald);stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .card-title-row { display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem; }
    .card-title-row .card-title { margin-bottom:0; }

    .section-label {
      font-size:0.67rem;font-weight:700;color:var(--muted2);
      text-transform:uppercase;letter-spacing:0.07em;
      margin-bottom:0.65rem;display:flex;align-items:center;gap:6px;
    }
    .section-label svg { width:12px;height:12px;stroke:var(--muted2);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }

    /* ── ACTIVE CARD ── */
    .active-card { background:linear-gradient(135deg,rgba(16,185,129,0.1) 0%,rgba(52,211,153,0.05) 100%);border-color:rgba(52,211,153,0.25); }
    .slot-header-row { display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap; }
    .slot-display { display:flex;align-items:center;gap:13px; }
    .slot-box { width:58px;height:58px;background:var(--emeraldbg2);border:2px solid rgba(52,211,153,0.35);border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0; }
    .slot-box .slot-code  { font-size:0.98rem;font-weight:900;color:var(--emerald);letter-spacing:-0.02em; }
    .slot-box .slot-floor { font-size:0.6rem;font-weight:700;color:var(--muted);margin-top:1px; }
    .slot-info h2 { font-size:0.93rem;font-weight:800;color:var(--text); }
    .slot-info p  { font-size:0.78rem;color:var(--muted);font-weight:500;margin-top:2px; }
    .countdown-wrap { text-align:right;flex-shrink:0; }
    .countdown-label { font-size:0.66rem;font-weight:700;color:var(--muted);letter-spacing:0.04em;text-transform:uppercase;margin-bottom:3px; }
    .countdown { font-size:1.75rem;font-weight:900;letter-spacing:-0.03em;color:var(--emerald);font-variant-numeric:tabular-nums; }
    .countdown.warning { color:var(--warning); }
    .countdown.danger  { color:var(--danger); }

    .info-row { display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:1rem;margin-bottom:1rem; }
    .info-item { background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:9px 11px; }
    .info-item-label { font-size:0.65rem;font-weight:700;color:var(--muted2);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:3px; }
    .info-item-value { font-size:0.86rem;font-weight:800;color:var(--text); }

    /* ── FEE BLOCKS ── */
    .fee-block { border-radius:10px;padding:10px 13px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between; }
    .fee-block.running  { background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.18); }
    .fee-block.paid-box { background:rgba(52,211,153,0.05);border:1px solid rgba(52,211,153,0.15); }
    .fee-block.extra    { background:var(--warningbg);border:1px solid rgba(251,191,36,0.25); }
    .fee-block.window   { background:var(--infobg);border:1px solid rgba(96,165,250,0.2); }
    .fee-lbl { font-size:0.75rem;font-weight:700;color:var(--muted); }
    .fee-lbl span { display:block;font-size:0.65rem;color:var(--muted2);margin-top:2px; }
    .fee-amt { font-size:1.25rem;font-weight:800;font-variant-numeric:tabular-nums; }
    .fee-amt.green  { color:var(--emerald); }
    .fee-amt.green2 { color:var(--emerald2); }
    .fee-amt.yellow { color:var(--warning); }
    .window-countdown { font-size:1rem;font-weight:800;color:var(--info);font-variant-numeric:tabular-nums; }

    .notice { border-radius:9px;padding:9px 12px;display:flex;align-items:center;gap:9px;margin-bottom:8px; }
    .notice svg { width:14px;height:14px;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0; }
    .notice p { font-size:0.75rem;font-weight:600;line-height:1.5; }
    .notice p strong { font-weight:800; }
    .notice.green  { background:rgba(52,211,153,0.07);border:1px solid rgba(52,211,153,0.18); }
    .notice.green svg { stroke:var(--emerald); }
    .notice.green p { color:var(--muted); }
    .notice.green p strong { color:var(--emerald); }
    .notice.yellow { background:var(--warningbg);border:1px solid rgba(251,191,36,0.25); }
    .notice.yellow svg { stroke:var(--warning); }
    .notice.yellow p { color:var(--muted); }
    .notice.yellow p strong { color:var(--warning); }
    .notice.blue   { background:var(--infobg);border:1px solid rgba(96,165,250,0.2); }
    .notice.blue svg { stroke:var(--info); }
    .notice.blue p { color:var(--muted); }
    .notice.blue p strong { color:var(--info); }

    .pay-history { margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border); }
    .pay-history-title { font-size:0.67rem;font-weight:700;color:var(--muted2);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:7px; }
    .pay-history-row { display:flex;justify-content:space-between;align-items:center;padding:6px 9px;border-radius:8px;background:var(--surface);margin-bottom:5px; }
    .pay-history-row:last-child { margin-bottom:0; }
    .phi-left { font-size:0.74rem;font-weight:600;color:var(--muted); }
    .phi-right { font-size:0.78rem;font-weight:800;color:var(--emerald); }
    .phi-receipt { font-size:0.64rem;color:var(--muted2);margin-top:1px; }

    /* ── BADGES ── */
    .badge { display:inline-flex;align-items:center;gap:5px;font-size:0.69rem;font-weight:700;padding:3px 9px;border-radius:99px; }
    .badge-dot { width:5px;height:5px;border-radius:50%; }
    .badge.pending   { background:var(--warningbg);color:var(--warning); }
    .badge.pending   .badge-dot { background:var(--warning); }
    .badge.confirmed { background:var(--infobg);color:var(--info); }
    .badge.confirmed .badge-dot { background:var(--info); }
    .badge.active    { background:var(--emeraldbg2);color:var(--emerald); }
    .badge.active    .badge-dot { background:var(--emerald);animation:pulse 2s infinite; }
    .badge.completed { background:var(--emeraldbg);color:var(--muted); }
    .badge.completed .badge-dot { background:var(--muted); }
    .badge.expired,.badge.cancelled { background:var(--dangerbg);color:var(--danger); }
    .badge.expired .badge-dot,.badge.cancelled .badge-dot { background:var(--danger); }
    .badge.paid   { background:var(--emeraldbg);color:var(--emerald2); }
    .badge.unpaid { background:var(--dangerbg);color:var(--danger); }
    .badge.extra  { background:var(--warningbg);color:var(--warning); }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.5);} }

    /* ── BUTTONS ── */
    .action-row { display:flex;gap:8px;flex-wrap:wrap;margin-top:0.7rem; }
    .btn { display:inline-flex;align-items:center;gap:7px;font-family:'Nunito',sans-serif;font-size:0.81rem;font-weight:800;border:none;border-radius:9px;padding:8px 15px;cursor:pointer;transition:background 0.2s,transform 0.1s;text-decoration:none; }
    .btn:active { transform:scale(0.97); }
    .btn svg { width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .btn-primary { background:var(--emerald2);color:#0a1a12; }
    .btn-primary:hover { background:var(--emerald); }
    .btn-outline { background:var(--surface);color:var(--text);border:1.5px solid var(--border2); }
    .btn-outline:hover { background:var(--surface2);border-color:var(--muted2); }
    .btn-warning { background:var(--warningbg);color:var(--warning);border:1.5px solid rgba(251,191,36,0.25); }
    .btn-warning:hover { background:rgba(251,191,36,0.18); }
    .btn-warning-solid { background:rgba(251,191,36,0.15);color:var(--warning);border:1.5px solid rgba(251,191,36,0.3); }
    .btn-warning-solid:hover { background:rgba(251,191,36,0.25); }
    .btn-danger { background:var(--dangerbg);color:var(--danger);border:1.5px solid var(--dangerborder); }
    .btn-danger:hover { background:rgba(248,113,113,0.18); }

    .btn-tts { display:flex;align-items:center;gap:6px;background:var(--surface);border:1.5px solid var(--border2);border-radius:8px;padding:6px 12px;font-family:'Nunito',sans-serif;font-size:0.74rem;font-weight:700;color:var(--muted);cursor:pointer;transition:background 0.2s,border-color 0.2s,color 0.2s; }
    .btn-tts svg { width:13px;height:13px;stroke:var(--muted);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;transition:stroke 0.2s; }
    .btn-tts:hover { background:var(--surface2);border-color:var(--emerald2);color:var(--emerald); }
    .btn-tts:hover svg { stroke:var(--emerald); }
    .btn-tts.speaking { background:var(--emeraldbg2);border-color:var(--emerald);color:var(--emerald);animation:ttsPulse 1.2s ease-in-out infinite; }
    .btn-tts.speaking svg { stroke:var(--emerald); }
    @keyframes ttsPulse { 0%,100%{box-shadow:0 0 0 0 rgba(52,211,153,0.35);}50%{box-shadow:0 0 0 6px rgba(52,211,153,0);} }

    /* ── EMPTY STATE ── */
    .empty-state { text-align:center;padding:2rem 1rem; }
    .empty-icon { width:46px;height:46px;background:var(--surface);border:1px solid var(--border2);border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 0.9rem; }
    .empty-icon svg { width:21px;height:21px;fill:none;stroke:var(--muted2);stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .empty-state h3 { font-size:0.9rem;font-weight:800;color:var(--text);margin-bottom:4px; }
    .empty-state p  { font-size:0.78rem;color:var(--muted);font-weight:500; }

    /* ── TABLE ── */
    .table-wrap { overflow-x:auto; }
    table { width:100%;border-collapse:collapse; }
    th { font-size:0.67rem;font-weight:800;color:var(--muted2);text-transform:uppercase;letter-spacing:0.05em;padding:0 11px 8px;text-align:left;border-bottom:1px solid var(--border); }
    td { font-size:0.8rem;font-weight:600;color:var(--text);padding:9px 11px;border-bottom:1px solid var(--border); }
    tr:last-child td { border-bottom:none; }
    tr:hover td { background:var(--surface); }

    /* ── TABS ── */
    .tabs { display:flex;gap:3px;background:var(--surface);border-radius:9px;padding:3px;margin-bottom:1rem;width:fit-content; }
    .tab { font-size:0.79rem;font-weight:700;color:var(--muted);padding:6px 13px;border-radius:7px;cursor:pointer;transition:all 0.2s;border:none;background:none;font-family:'Nunito',sans-serif; }
    .tab.active { background:var(--bg2);color:var(--text);box-shadow:0 1px 4px rgba(0,0,0,0.3); }
    .tab-content { display:none; }
    .tab-content.active { display:block; }

    /* ── SIDE CARDS ── */
    .stat-chips { display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:0.9rem; }
    .stat-chip { background:var(--surface);border:1px solid var(--border);border-radius:9px;padding:9px 11px; }
    .stat-chip-num   { font-size:1.05rem;font-weight:900;color:var(--text);letter-spacing:-0.02em; }
    .stat-chip-label { font-size:0.67rem;font-weight:600;color:var(--muted2);margin-top:2px; }
    .rates-grid { display:grid;grid-template-columns:1fr 1fr;gap:6px; }
    .rate-chip { background:var(--surface);border:1px solid var(--border);border-radius:9px;padding:9px 10px; }
    .rate-chip-lbl { font-size:0.62rem;font-weight:700;color:var(--muted2);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:3px; }
    .rate-chip-val { font-size:0.88rem;font-weight:800; }
    .quick-actions { display:flex;flex-direction:column;gap:7px; }

    /* ── MODALS ── */
    .modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);backdrop-filter:blur(4px);z-index:400;align-items:center;justify-content:center;padding:1.5rem; }
    .modal-overlay.open { display:flex; }
    .modal { background:var(--bg2);border:1px solid var(--border2);border-radius:18px;padding:1.6rem;width:100%;max-width:420px;animation:fadeUp 0.3s cubic-bezier(0.22,1,0.36,1) both; }
    @keyframes fadeUp { from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);} }
    .modal-title { font-size:1rem;font-weight:800;color:var(--text);margin-bottom:0.35rem; }
    .modal-sub   { font-size:0.79rem;color:var(--muted);font-weight:500;margin-bottom:1.1rem;line-height:1.5; }
    .form-group  { margin-bottom:0.85rem; }
    .form-label  { display:block;font-size:0.69rem;font-weight:700;color:var(--muted);margin-bottom:5px;letter-spacing:0.04em;text-transform:uppercase; }
    .form-input  { width:100%;background:var(--surface);border:1.5px solid var(--border2);border-radius:9px;padding:9px 12px;font-family:'Nunito',sans-serif;font-size:0.85rem;font-weight:600;color:var(--text);outline:none;transition:border-color 0.2s,background 0.2s; }
    .form-input:focus { border-color:var(--emerald2);background:var(--surface2); }
    .payment-methods { display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:1rem; }
    .payment-method { border:1.5px solid var(--border2);border-radius:9px;padding:10px;cursor:pointer;text-align:center;transition:all 0.2s; }
    .payment-method input { display:none; }
    .payment-method-label { font-size:0.8rem;font-weight:700;color:var(--muted); }
    .payment-method:has(input:checked) { border-color:rgba(52,211,153,0.4);background:var(--emeraldbg); }
    .payment-method:has(input:checked) .payment-method-label { color:var(--emerald); }
    .modal-actions { display:flex;gap:8px;margin-top:1rem; }
    .modal-actions .btn { flex:1;justify-content:center; }
    .modal-divider { height:1px;background:var(--border);margin:0.85rem 0; }
    .summary-row { display:flex;justify-content:space-between;font-size:0.82rem;margin-bottom:6px; }
    .sr-label { color:var(--muted);font-weight:600; }
    .sr-val   { color:var(--text);font-weight:700; }
    .total-row { display:flex;justify-content:space-between;align-items:center;background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.18);border-radius:9px;padding:10px 12px;margin-bottom:0.9rem; }
    .tr-label  { font-size:0.82rem;font-weight:700;color:var(--muted); }
    .tr-amount { font-size:1.12rem;font-weight:800;color:var(--emerald); }

    /* ── TOAST ── */
    .toast { position:fixed;bottom:1.5rem;right:1.5rem;z-index:500;background:var(--bg2);border:1px solid var(--border2);border-radius:13px;padding:12px 17px;display:flex;align-items:center;gap:10px;box-shadow:0 8px 24px rgba(0,0,0,0.4);font-size:0.82rem;font-weight:700;color:var(--text);animation:slideIn 0.35s cubic-bezier(0.22,1,0.36,1) both;max-width:340px; }
    @keyframes slideIn { from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);} }
    .toast-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0; }
    .toast.success .toast-dot { background:var(--emerald); }
    .toast.error   .toast-dot { background:var(--danger); }

    @media (max-width:960px) { .content-grid{grid-template-columns:1fr;} }
    @media (max-width:680px) { :root{--sidebar-w:0px;} .sidebar{display:none;} .info-row{grid-template-columns:1fr 1fr;} }
  </style>
</head>
<body>

<!-- ════════════ SIDEBAR ════════════ -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="sidebar-logo-icon">P</div>
    <span class="sidebar-logo-text">Parky</span>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Menu</div>
    <a href="dashboard.php" class="nav-item active">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </a>
    <a href="index.php" class="nav-item">
      <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Home
    </a>

    <div class="nav-section-label">Parking</div>
    <a href="reserve.php" class="nav-item <?= $activeRecord ? 'disabled' : '' ?>">
      <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
      Reserve a Parking
    </a>
    <a href="find-my-car.php" class="nav-item">
      <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Find My Car
    </a>

    <div class="nav-section-label">Info</div>
    <a href="about.php" class="nav-item">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      About
    </a>
  </nav>

  <div class="sidebar-bottom">
    <div class="sidebar-user">
      <div class="sidebar-user-avatar"><?= htmlspecialchars($userInitial) ?></div>
      <div class="sidebar-user-info">
        <div class="sidebar-user-name"><?= htmlspecialchars($userDisplayName) ?></div>
        <?php if (!empty($user['plate_number'])): ?>
          <div class="sidebar-user-plate"><?= htmlspecialchars($user['plate_number']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <a href="logout.php" class="nav-item danger">
      <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Log out
    </a>
  </div>
</aside>

<!-- ════════════ MAIN ════════════ -->
<div class="main-wrap">

  <div class="topbar">
    <span class="topbar-title">User Dashboard</span>
    <span class="topbar-date"><?= $currentDate ?></span>
  </div>

  <div class="page-content">
    <div class="content-grid">

      <!-- ══ MAIN COLUMN ══ -->
      <div class="content-main">

        <!-- Upcoming / Active Reservation -->
        <div class="section-label">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          <?= $activeRecord ? 'Current reservation' : 'Reservation' ?>
        </div>

        <?php if ($activeRecord): ?>
        <div class="card active-card">
          <div class="card-title-row">
            <div class="card-title">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <?php
                if ($activeRecord['status'] === 'active')        echo 'Currently Parked';
                elseif ($activeRecord['status'] === 'confirmed') echo 'Reservation Confirmed';
                else                                             echo 'Upcoming Reservation';
              ?>
            </div>
            <button class="btn-tts" id="ttsBtn" onclick="toggleTTS()" title="Read details aloud">
              <svg id="ttsIcon" viewBox="0 0 24 24">
                <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                <path d="M19.07 4.93a10 10 0 010 14.14"/>
                <path d="M15.54 8.46a5 5 0 010 7.07"/>
              </svg>
              <span id="ttsBtnLabel">Read aloud</span>
            </button>
          </div>

          <div class="slot-header-row">
            <div class="slot-display">
              <div class="slot-box">
                <span class="slot-code"><?= htmlspecialchars($activeRecord['slot_code']) ?></span>
                <span class="slot-floor">Floor <?= $activeRecord['floor'] ?></span>
              </div>
              <div class="slot-info">
                <h2>Slot <?= htmlspecialchars($activeRecord['slot_code']) ?>, Floor <?= $activeRecord['floor'] ?></h2>
                <p>Plate: <?= htmlspecialchars($activeRecord['plate_number']) ?></p>
                <p>Car: <?= htmlspecialchars($activeRecord['car_type']) ?> · <?= htmlspecialchars($activeRecord['car_color']) ?></p>
                <p style="margin-top:5px;">
                  <span class="badge <?= $activeRecord['status'] ?>"><span class="badge-dot"></span><?= ucfirst($activeRecord['status']) ?></span>
                  <?php if ($activeRecord['extended']): ?>
                    <span class="badge" style="background:var(--infobg);color:var(--info);margin-left:4px;"><span class="badge-dot" style="background:var(--info);"></span>Extended</span>
                  <?php endif; ?>
                  <?php if ($fee_state === 'extra_owed'): ?>
                    <span class="badge extra" style="margin-left:4px;"><span class="badge-dot" style="background:var(--warning);"></span>Extra owed</span>
                  <?php endif; ?>
                </p>
              </div>
            </div>
            <?php if ($activeRecord['status'] !== 'active'): ?>
            <div class="countdown-wrap">
              <div class="countdown-label" id="countdownLabel">Arrives in</div>
              <div class="countdown" id="countdown">--:--:--</div>
              <div style="font-size:0.64rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;margin-top:3px;">1-hr grace period</div>
            </div>
            <?php endif; ?>
          </div>

          <div class="info-row">
            <div class="info-item">
              <div class="info-item-label">Arrival Time</div>
              <div class="info-item-value"><?= date('M d, g:i A', strtotime($activeRecord['arrival_time'])) ?></div>
            </div>
            <div class="info-item">
              <div class="info-item-label"><?= $activeRecord['status'] === 'active' ? 'Time Elapsed' : 'Expires At' ?></div>
              <div class="info-item-value" id="elapsedDisplay">
                <?php if ($activeRecord['status'] === 'active'): ?>
                  <?= floor($elapsed_secs/3600) ?>h <?= floor(($elapsed_secs%3600)/60) ?>m
                <?php else: ?>
                  <?= date('M d, g:i A', strtotime($activeRecord['expires_at'])) ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="info-item">
              <div class="info-item-label">Payment</div>
              <div class="info-item-value">
                <?php if ($fee_state === 'extra_owed'): ?>
                  <span class="badge extra"><span class="badge-dot" style="background:var(--warning);"></span>Extra owed</span>
                <?php else: ?>
                  <span class="badge <?= $activeRecord['payment_status'] ?>"><?= ucfirst($activeRecord['payment_status']) ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if ($activeRecord['status'] === 'active'): ?>
            <?php if ($fee_state === 'unpaid'): ?>
            <div class="fee-block running">
              <div class="fee-lbl">Running fee<span>Updates every second · ₱<?= RATE_FIRST_BLOCK ?> first <?= RATE_FIRST_HOURS ?>hrs · ₱<?= RATE_PER_HOUR ?>/hr after</span></div>
              <div class="fee-amt green" id="liveFee">₱<?= number_format($running_fee, 2) ?></div>
            </div>
            <div class="action-row">
              <button class="btn btn-primary" onclick="openPayModal('first')">
                <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Pay Online
              </button>
            </div>

            <?php elseif ($fee_state === 'within_window'): ?>
            <?php if ($last_res_payment): ?>
            <div class="fee-block paid-box">
              <div class="fee-lbl">Last payment<span>via <?= strtoupper(htmlspecialchars($last_res_payment['method'])) ?> · <?= date('M d, h:i A', strtotime($last_res_payment['paid_at'])) ?></span></div>
              <div class="fee-amt green2">₱<?= number_format($last_res_payment['total_amount'], 2) ?></div>
            </div>
            <?php endif; ?>
            <div class="fee-block window">
              <div class="fee-lbl">Paid parking window<span>Additional fee starts after this</span></div>
              <div class="window-countdown" id="windowCountdown">--:--:--</div>
            </div>
            <div class="notice blue">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              <p>Your parking is covered until <strong><?= date('h:i A', $window_countdown_ts) ?></strong>. No action needed.</p>
            </div>

            <?php elseif ($fee_state === 'extra_owed'): ?>
            <?php if ($last_res_payment): ?>
            <div class="fee-block paid-box">
              <div class="fee-lbl">Previously paid<span>via <?= strtoupper(htmlspecialchars($last_res_payment['method'])) ?> · <?= date('M d, h:i A', strtotime($last_res_payment['paid_at'])) ?></span></div>
              <div class="fee-amt green2">₱<?= number_format($total_paid_so_far, 2) ?></div>
            </div>
            <?php endif; ?>
            <div class="fee-block extra">
              <div class="fee-lbl">Additional fee due<span>Credited through <?= date('h:i A', $paid_until_ts) ?> · updates every second</span></div>
              <div class="fee-amt yellow" id="liveFee">₱<?= number_format($extra_fee, 2) ?></div>
            </div>
            <div class="notice yellow">
              <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              <p>Your stay exceeds the time already paid for. <strong>An additional ₱<?= number_format($extra_fee, 2) ?> is due now.</strong></p>
            </div>
            <div class="action-row">
              <button class="btn btn-warning-solid" onclick="openPayModal('extra')">
                <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Pay Additional Fee
              </button>
            </div>
            <?php endif; ?>

            <?php if (!empty($allResPayments)): ?>
            <div class="pay-history">
              <div class="pay-history-title">Payment history</div>
              <?php foreach ($allResPayments as $p): ?>
              <div class="pay-history-row">
                <div class="phi-left"><?= date('M d, h:i A', strtotime($p['paid_at'])) ?> · <?= strtoupper(htmlspecialchars($p['method'])) ?><div class="phi-receipt"><?= htmlspecialchars($p['receipt_number']) ?></div></div>
                <div class="phi-right">₱<?= number_format($p['total_amount'], 2) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($canEdit || $canCancel): ?>
          <div class="action-row">
            <?php if ($canExtend): ?>
              <form method="POST" style="display:contents;">
                <input type="hidden" name="action" value="extend"/>
                <input type="hidden" name="reservation_id" value="<?= $activeRecord['id'] ?>"/>
                <button type="submit" class="btn btn-warning">
                  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  Extend 1 Hour (+₱<?= EXTENSION_FEE ?>)
                </button>
              </form>
            <?php else: ?>
              <button class="btn btn-warning" disabled style="opacity:0.45;cursor:not-allowed;">Already Extended</button>
            <?php endif; ?>
            <button class="btn btn-outline" onclick="openEditModal()">
              <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Edit Schedule
            </button>
            <button class="btn btn-danger" onclick="openCancelModal()">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
              Cancel
            </button>
          </div>
          <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="card">
          <div class="empty-state">
            <div class="empty-icon"><svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg></div>
            <h3>No active reservation</h3>
            <p>You don't have any upcoming or active reservation. Walk-in parking still appears under <strong>Transaction history</strong> below; use <strong>Find My Car</strong> for live walk-in session details.</p>
            <a href="reserve.php" class="btn btn-primary" style="margin-top:1rem;display:inline-flex;">
              <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Reserve a spot
            </a>
          </div>
        </div>
        <?php endif; ?>

        <!-- Reservation history -->
        <div class="section-label">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14.5 14.5"/></svg>
          Reservation history
        </div>
        <div class="card">
          <?php if (empty($reservationHistory)): ?>
            <div class="empty-state" style="padding:1.25rem;"><p>No reservation history yet.</p></div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Slot</th><th>Floor</th><th>Arrival</th><th>Status</th><th>Extended</th><th>Payment</th></tr></thead>
                <tbody>
                  <?php foreach ($reservationHistory as $r): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($r['slot_code']) ?></strong></td>
                    <td>Floor <?= $r['floor'] ?></td>
                    <td><?= date('M d, Y g:i A', strtotime($r['arrival_time'])) ?></td>
                    <td><span class="badge <?= $r['status'] ?>"><span class="badge-dot"></span><?= ucfirst($r['status']) ?></span></td>
                    <td><?= $r['extended'] ? '<span style="color:var(--emerald);font-weight:700;">Yes +₱'.EXTENSION_FEE.'</span>' : '<span style="color:var(--muted2);">No</span>' ?></td>
                    <td><span class="badge <?= $r['payment_status'] ?>"><?= ucfirst($r['payment_status']) ?></span></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- Transaction history (reservations + walk-in for your account / plate) -->
        <div class="section-label">
          <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          Transaction history
        </div>
        <div class="card">
          <?php if (empty($transactions)): ?>
            <div class="empty-state" style="padding:1.25rem;"><p>No payments recorded yet. Reservation and walk-in kiosk payments appear here.</p></div>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Type</th><th>Receipt</th><th>Slot</th><th>Base</th><th>Ext.</th><th>Total</th><th>Method</th><th>Date</th></tr></thead>
                <tbody>
                  <?php foreach ($transactions as $t): ?>
                  <tr>
                    <td><span style="font-size:0.72rem;font-weight:800;color:var(--muted);text-transform:uppercase;"><?= htmlspecialchars($t['txn_kind'] ?? '—') ?></span></td>
                    <td><code style="font-size:0.72rem;color:var(--emerald);"><?= htmlspecialchars($t['receipt_number']) ?></code></td>
                    <td><?= htmlspecialchars($t['slot_code']) ?></td>
                    <td>₱<?= number_format($t['base_amount'],2) ?></td>
                    <td>₱<?= number_format($t['extension_fee'],2) ?></td>
                    <td><strong>₱<?= number_format($t['total_amount'],2) ?></strong></td>
                    <td><span style="text-transform:uppercase;font-size:0.75rem;"><?= htmlspecialchars($t['method']) ?></span></td>
                    <td><?= date('M d, Y g:i A', strtotime($t['paid_at'])) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      </div><!-- /content-main -->

      <!-- ══ SIDE COLUMN ══ -->
      <div class="content-side">

        <!-- Online Account -->
        <div class="section-label">
          <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Online Account
        </div>
        <div class="card">
          <div style="display:flex;align-items:center;gap:11px;margin-bottom:0.9rem;">
            <div style="width:42px;height:42px;background:var(--emeraldbg2);border:1.5px solid rgba(52,211,153,0.3);border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:0.95rem;font-weight:900;color:var(--emerald);flex-shrink:0;">
              <?= htmlspecialchars($userInitial) ?>
            </div>
            <div>
              <div style="font-size:0.9rem;font-weight:800;color:var(--text);"><?= htmlspecialchars($userDisplayName) ?></div>
              <div style="font-size:0.73rem;color:var(--muted);font-weight:500;">@<?= htmlspecialchars($user['username']) ?></div>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:3px;font-size:0.74rem;color:var(--muted);font-weight:500;">
            <div><?= htmlspecialchars($user['email']) ?></div>
            <?php if (!empty($user['phone'])): ?>
              <div><?= htmlspecialchars($user['phone']) ?></div>
            <?php endif; ?>
            <?php if (!empty($user['plate_number'])): ?>
              <div style="color:var(--emerald2);font-weight:700;margin-top:2px;"><?= htmlspecialchars($user['plate_number']) ?></div>
            <?php endif; ?>
          </div>
          <div class="stat-chips">
            <div class="stat-chip">
              <div class="stat-chip-num"><?= $totalReservations ?></div>
              <div class="stat-chip-label">Total Reservations</div>
            </div>
            <div class="stat-chip">
              <div class="stat-chip-num">₱<?= number_format($totalSpent,0) ?></div>
              <div class="stat-chip-label">Total Spent</div>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="section-label">
          <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          Quick Actions
        </div>
        <div class="card">
          <div class="quick-actions">
            <?php if (!$activeRecord): ?>
              <a href="reserve.php" class="btn btn-primary" style="justify-content:center;">
                <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
                Reserve a Spot
              </a>
            <?php elseif ($canPayFirst): ?>
              <button class="btn btn-primary" onclick="openPayModal('first')" style="justify-content:center;">
                <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Pay Now
              </button>
            <?php elseif ($canPayExtra): ?>
              <button class="btn btn-warning-solid" onclick="openPayModal('extra')" style="justify-content:center;">
                <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Pay Additional Fee
              </button>
            <?php elseif ($fee_state === 'within_window'): ?>
              <div style="background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.2);border-radius:9px;padding:9px 12px;font-size:0.76rem;color:var(--muted);font-weight:600;text-align:center;">
                You're parked and paid.<br>Exit when ready.
              </div>
            <?php elseif ($activeRecord && in_array($activeRecord['status'],['pending','confirmed'])): ?>
              <div style="background:var(--surface);border:1px solid var(--border2);border-radius:9px;padding:9px 12px;font-size:0.76rem;color:var(--muted);font-weight:600;text-align:center;line-height:1.6;">
                Your reservation is active.<br>Manage it from the card above.
              </div>
            <?php endif; ?>
            <a href="find-my-car.php" class="btn btn-outline" style="justify-content:center;">
              <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Find My Car
            </a>
          </div>
        </div>

        <!-- Parking Rates -->
        <div class="section-label">
          <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
          Parking Rates
        </div>
        <div class="card">
          <div class="rates-grid">
            <div class="rate-chip">
              <div class="rate-chip-lbl">First <?= RATE_FIRST_HOURS ?>hrs</div>
              <div class="rate-chip-val" style="color:var(--emerald);">₱<?= RATE_FIRST_BLOCK ?> flat</div>
            </div>
            <div class="rate-chip">
              <div class="rate-chip-lbl">Grace (1–<?= GRACE_MINUTES ?>min)</div>
              <div class="rate-chip-val" style="color:var(--warning);">₱<?= RATE_GRACE ?></div>
            </div>
            <div class="rate-chip">
              <div class="rate-chip-lbl">Per hour after</div>
              <div class="rate-chip-val" style="color:var(--text);">₱<?= RATE_PER_HOUR ?></div>
            </div>
            <div class="rate-chip">
              <div class="rate-chip-lbl">Extension fee</div>
              <div class="rate-chip-val" style="color:var(--info);">₱<?= EXTENSION_FEE ?></div>
            </div>
          </div>
        </div>

      </div><!-- /content-side -->

    </div><!-- /content-grid -->
  </div><!-- /page-content -->
</div><!-- /main-wrap -->


<!-- EDIT MODAL -->
<?php if ($canEdit): ?>
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-title">Edit Reservation</div>
    <div class="modal-sub">Update your scheduled arrival date and time.</div>
    <form method="POST">
      <input type="hidden" name="action" value="edit"/>
      <input type="hidden" name="reservation_id" value="<?= $activeRecord['id'] ?>"/>
      <div class="form-group">
        <label class="form-label">New Date</label>
        <input type="date" name="new_date" class="form-input" value="<?= date('Y-m-d', strtotime($activeRecord['arrival_time'])) ?>" min="<?= date('Y-m-d') ?>" required/>
      </div>
      <div class="form-group">
        <label class="form-label">New Time</label>
        <input type="time" name="new_time" class="form-input" value="<?= date('H:i', strtotime($activeRecord['arrival_time'])) ?>" required/>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- CANCEL MODAL -->
<?php if ($canCancel): ?>
<div class="modal-overlay" id="cancelModal">
  <div class="modal">
    <div class="modal-title">Cancel Reservation?</div>
    <div class="modal-sub">This will release slot <strong><?= htmlspecialchars($activeRecord['slot_code']) ?></strong> and permanently cancel your reservation.</div>
    <form method="POST">
      <input type="hidden" name="action" value="cancel"/>
      <input type="hidden" name="reservation_id" value="<?= $activeRecord['id'] ?>"/>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeModal('cancelModal')">Keep it</button>
        <button type="submit" class="btn btn-danger">Yes, Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- PAY MODAL -->
<?php if ($canPay): ?>
<div class="modal-overlay" id="payModal">
  <div class="modal">
    <div class="modal-title"><?= $canPayExtra ? 'Additional Payment' : 'Pay Online' ?></div>
    <div class="modal-sub">Dummy payment — no real transaction will occur.</div>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:9px;padding:11px 13px;margin-bottom:0.9rem;">
      <div class="summary-row"><span class="sr-label">Slot</span><span class="sr-val"><?= htmlspecialchars($activeRecord['slot_code']) ?>, Floor <?= $activeRecord['floor'] ?></span></div>
      <div class="summary-row"><span class="sr-label">Billing from</span><span class="sr-val"><?= $canPayExtra ? 'Since '.date('h:i A',$paid_until_ts) : 'Since '.date('h:i A',strtotime($activeRecord['arrival_time'])) ?></span></div>
      <div class="summary-row"><span class="sr-label">Time elapsed</span><span class="sr-val" id="modalDuration">—</span></div>
      <div class="summary-row"><span class="sr-label">Rate</span><span class="sr-val">₱<?= RATE_FIRST_BLOCK ?> first <?= RATE_FIRST_HOURS ?>hrs · ₱<?= RATE_PER_HOUR ?>/hr after</span></div>
      <?php if ($canPayFirst && $activeRecord['extended']): ?>
        <div class="summary-row"><span class="sr-label">Extension fee</span><span class="sr-val">₱<?= EXTENSION_FEE ?>.00</span></div>
      <?php endif; ?>
      <div class="modal-divider"></div>
      <div class="total-row"><span class="tr-label">Total</span><span class="tr-amount" id="modalTotal">₱—</span></div>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="pay_online"/>
      <input type="hidden" name="reservation_id" value="<?= $activeRecord['id'] ?>"/>
      <div class="payment-methods">
        <label class="payment-method"><input type="radio" name="method" value="gcash" checked/><div class="payment-method-label">GCash</div></label>
        <label class="payment-method"><input type="radio" name="method" value="maya"/><div class="payment-method-label">Maya</div></label>
      </div>
      <p style="font-size:0.69rem;color:var(--muted2);font-weight:600;margin-bottom:0.85rem;">Dummy payment — no real transaction will occur.</p>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeModal('payModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Confirm Payment</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- TOAST -->
<?php if ($toast): ?>
<div class="toast <?= $toast['type'] ?>" id="toast">
  <span class="toast-dot"></span><?= htmlspecialchars($toast['msg']) ?>
</div>
<script>
  setTimeout(() => {
    var t = document.getElementById('toast');
    if (t) { t.style.opacity='0'; t.style.transform='translateY(12px)'; t.style.transition='0.3s'; }
    setTimeout(() => t && t.remove(), 400);
  }, 4000);
</script>
<?php endif; ?>

<script>
var elapsedBase  = <?= (int)$elapsed_secs ?>;
var entryTs      = <?= ($activeRecord && $activeRecord['status'] === 'active') ? (int) strtotime($activeRecord['arrival_time']) : 0 ?>;
var paidUntilCreditTs = <?= (int) $paid_until_credit_ts_js ?>;
var windowCountdownTs = <?= (int) $window_countdown_ts ?>;
var feeState     = '<?= $fee_state ?>';
var isActive     = <?= ($activeRecord && $activeRecord['status'] === 'active') ? 'true' : 'false' ?>;
var extFeeFirst  = <?= ($canPayFirst && $activeRecord && $activeRecord['extended']) ? EXTENSION_FEE : 0 ?>;
var pageLoadedAt = Date.now();
var ttsSlot     = '<?= htmlspecialchars($activeRecord['slot_code'] ?? '', ENT_QUOTES) ?>';
var ttsFloor    = '<?= htmlspecialchars((string)($activeRecord['floor'] ?? ''), ENT_QUOTES) ?>';
var ttsPlate    = '<?= htmlspecialchars($activeRecord['plate_number'] ?? '', ENT_QUOTES) ?>';
var ttsCarType  = '<?= htmlspecialchars($activeRecord['car_type'] ?? '', ENT_QUOTES) ?>';
var ttsCarColor = '<?= htmlspecialchars($activeRecord['car_color'] ?? '', ENT_QUOTES) ?>';
var ttsStatus   = '<?= $activeRecord['status'] ?? '' ?>';
var ttsArrival  = '<?= $activeRecord ? date('F j, g:i A', strtotime($activeRecord['arrival_time'])) : '' ?>';
var ttsExpiry   = '<?= $activeRecord ? date('F j, g:i A', strtotime($activeRecord['expires_at'])) : '' ?>';
var RATE_FIRST_BLOCK = <?= RATE_FIRST_BLOCK ?>;
var RATE_FIRST_HOURS = <?= RATE_FIRST_HOURS ?>;
var GRACE_MINUTES    = <?= GRACE_MINUTES ?>;
var RATE_GRACE       = <?= RATE_GRACE ?>;
var RATE_PER_HOUR    = <?= RATE_PER_HOUR ?>;

function calculateFee(s) {
  if (s <= 0) return RATE_FIRST_BLOCK;
  var m = s/60, fb = RATE_FIRST_HOURS*60;
  if (m <= fb) return RATE_FIRST_BLOCK;
  var fee=RATE_FIRST_BLOCK, em=m-fb, fh=Math.floor(em/60), r=em-(fh*60);
  fee += fh*RATE_PER_HOUR;
  if (r>0 && r<=GRACE_MINUTES) fee+=RATE_GRACE; else if (r>GRACE_MINUTES) fee+=RATE_PER_HOUR;
  return fee;
}
function getAmountDueJs(totalSecsElapsed) {
  var totalFee = calculateFee(totalSecsElapsed);
  if (feeState === 'unpaid' || !paidUntilCreditTs) return totalFee;
  var span = Math.max(0, paidUntilCreditTs - entryTs);
  var credit = calculateFee(span);
  return Math.max(0, totalFee - credit);
}
function pad(n) { return String(n).padStart(2,'0'); }

function tick() {
  var add = Math.floor((Date.now()-pageLoadedAt)/1000), tot = elapsedBase+add;
  var h=Math.floor(tot/3600), m=Math.floor((tot%3600)/60);
  var el=document.getElementById('elapsedDisplay');
  if (el && isActive) el.textContent = h+'h '+pad(m)+'m';
  var fe=document.getElementById('liveFee');
  if (feeState==='unpaid') { if(fe) fe.textContent='₱'+(getAmountDueJs(tot)+extFeeFirst).toFixed(2); }
  else if (feeState==='within_window') {
    var rem=windowCountdownTs-Math.floor(Date.now()/1000), we=document.getElementById('windowCountdown');
    if (rem>0) { if(we) we.textContent=pad(Math.floor(rem/3600))+':'+pad(Math.floor((rem%3600)/60))+':'+pad(rem%60); }
    else location.reload();
  } else if (feeState==='extra_owed') { if(fe) fe.textContent='₱'+getAmountDueJs(tot).toFixed(2); }
}
if (isActive) { tick(); setInterval(tick,1000); }

<?php if ($activeRecord && in_array($activeRecord['status'],['pending','confirmed'])): ?>
var arrivalTime = new Date("<?= $activeRecord['arrival_time'] ?>").getTime();
var expiryTime  = new Date("<?= $activeRecord['expires_at'] ?>").getTime();
function updateCountdown() {
  var now=Date.now(), diff=arrivalTime-now;
  var el=document.getElementById('countdown'), lbl=document.getElementById('countdownLabel');
  if (!el) return;
  if (diff<=0) {
    var g=expiryTime-now;
    if (g<=0) { el.textContent='Expired'; el.className='countdown danger'; if(lbl) lbl.textContent='Status'; setTimeout(()=>location.reload(),3000); return; }
    diff=g; if(lbl) lbl.textContent='Grace period left'; el.className='countdown warning';
  } else { if(lbl) lbl.textContent='Arrives in'; el.className='countdown'+(diff<1800000?' warning':'')+(diff<600000?' danger':''); }
  var h=Math.floor(diff/3600000), mn=Math.floor((diff%3600000)/60000), s=Math.floor((diff%60000)/1000);
  el.textContent=pad(h)+':'+pad(mn)+':'+pad(s);
}
setInterval(updateCountdown,1000); updateCountdown();
<?php endif; ?>

var ttsSpeaking=false;
function buildTTSText() {
  var add=Math.floor((Date.now()-pageLoadedAt)/1000), tot=elapsedBase+add;
  var h=Math.floor(tot/3600), m=Math.floor((tot%3600)/60);
  var car=(ttsCarColor?ttsCarColor+' ':'')+( ttsCarType||'vehicle');
  if (ttsStatus==='active') {
    var tt=h>0?'You have been parked for '+h+' hour'+(h>1?'s':'')+' and '+m+' minute'+(m!==1?'s':'')+'.':'You have been parked for '+m+' minute'+(m!==1?'s':'')+'.';
    var ft='';
    if (feeState==='unpaid') { ft='Your current fee is '+(getAmountDueJs(tot)+extFeeFirst).toFixed(2)+' pesos. Please pay before exiting.'; }
    else if (feeState==='within_window') { ft='Your parking fee is paid. You may exit when ready.'; }
    else if (feeState==='extra_owed') { ft='An additional fee of '+getAmountDueJs(tot).toFixed(2)+' pesos is owed beyond your paid coverage.'; }
    return 'You are currently parked at slot '+ttsSlot+', Floor '+ttsFloor+'. Vehicle plate: '+ttsPlate.split('').join(' ')+'. Car: '+car+'. '+tt+' '+ft;
  } else {
    var dm=Math.max(0,Math.floor((new Date(ttsArrival).getTime()-Date.now())/60000));
    var dh=Math.floor(dm/60), dr=dm%60;
    var tu=dh>0?dh+' hour'+(dh>1?'s':'')+' and '+dr+' minute'+(dr!==1?'s':''):dr+' minute'+(dr!==1?'s':'');
    return 'You have an upcoming reservation at slot '+ttsSlot+', Floor '+ttsFloor+'. Vehicle plate: '+ttsPlate.split('').join(' ')+'. Car: '+car+'. Scheduled arrival: '+ttsArrival+'. '+(dm>0?'That is in about '+tu+'.':'Your arrival time has passed. Please head to the entrance.')+' Your reservation expires at '+ttsExpiry+'.';
  }
}
function toggleTTS() {
  if (!('speechSynthesis' in window)) { alert('Text to speech is not supported. Please use Chrome or Edge.'); return; }
  var btn=document.getElementById('ttsBtn'), label=document.getElementById('ttsBtnLabel'), icon=document.getElementById('ttsIcon');
  if (ttsSpeaking) {
    window.speechSynthesis.cancel(); ttsSpeaking=false; btn.classList.remove('speaking'); label.textContent='Read aloud';
    icon.innerHTML='<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14"/><path d="M15.54 8.46a5 5 0 010 7.07"/>'; return;
  }
  var u=new SpeechSynthesisUtterance(buildTTSText()); u.lang='en-US'; u.rate=0.95; u.pitch=1;
  u.onstart=function(){ ttsSpeaking=true; btn.classList.add('speaking'); label.textContent='Stop reading'; icon.innerHTML='<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/>'; };
  u.onend=u.onerror=function(){ ttsSpeaking=false; btn.classList.remove('speaking'); label.textContent='Read aloud'; icon.innerHTML='<polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 010 14.14"/><path d="M15.54 8.46a5 5 0 010 7.07"/>'; };
  window.speechSynthesis.speak(u);
}

function openPayModal(type) {
  var add=Math.floor((Date.now()-pageLoadedAt)/1000), bs=elapsedBase+add, fee;
  fee = (type==='extra') ? getAmountDueJs(bs) : (getAmountDueJs(bs)+extFeeFirst);
  var h=Math.floor(bs/3600), m=Math.floor((bs%3600)/60);
  document.getElementById('modalDuration').textContent=h+'h '+pad(m)+'m';
  document.getElementById('modalTotal').textContent='₱'+fee.toFixed(2);
  document.getElementById('payModal').classList.add('open');
}
function openEditModal()   { var m=document.getElementById('editModal');   if(m) m.classList.add('open'); }
function openCancelModal() { var m=document.getElementById('cancelModal'); if(m) m.classList.add('open'); }
function closeModal(id)    { var m=document.getElementById(id);            if(m) m.classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');}));
</script>
</body>
</html>

