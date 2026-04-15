<?php
// ============================================================
//  admin/transaction-view.php
//  Detailed view of a single walk-in session or reservation.
//  Shows full tiered fee breakdown + timeline.
// ============================================================
session_start();
require_once 'includes/auth_check.php';
require_once '../config/db.php';
require_once '../config/rates.php';


$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
if ($id < 1 || !in_array($type, ['walkin', 'reservation'], true)) {
    header('Location: transactions.php');
    exit;
}


// ── Fetch session + payment rows ─────────────────────────────
if ($type === 'walkin') {
    $stmt = $pdo->prepare("
        SELECT sw.*, ps.slot_code, ps.floor
        FROM sessions_walkin sw
        JOIN parking_slots ps ON ps.id = sw.slot_id
        WHERE sw.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $sess = $stmt->fetch();
    if (!$sess) { header('Location: transactions.php'); exit; }


    $payStmt = $pdo->prepare("
        SELECT * FROM payments
        WHERE session_id = ? AND session_type = 'walkin'
        ORDER BY paid_at ASC
    ");
    $payStmt->execute([$id]);
    $payRows = $payStmt->fetchAll();


    $pageTitle = 'Walk-in #' . $id;
    $plate     = $sess['plate_number'];
    $entryTime = $sess['entry_time'];
    $exitTime  = $sess['exit_time'];
    $carLabel  = trim(($sess['car_color'] ?? '') . ' ' . ($sess['car_type'] ?? ''));
    $userLabel = 'Walk-in guest';
    $slotLabel = $sess['slot_code'] . ', Floor ' . (int)$sess['floor'];
    $initials  = 'WI';
    $userName  = 'Walk-in';


} else {
    $stmt = $pdo->prepare("
        SELECT r.*,
               COALESCE(
                   NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
                   u.username
               ) AS user_name,
               u.email AS user_email,
               u.phone AS user_phone, u.plate_number AS user_plate,
               ps.slot_code, ps.floor
        FROM reservations r
        JOIN users u        ON u.id  = r.user_id
        JOIN parking_slots ps ON ps.id = r.slot_id
        WHERE r.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $sess = $stmt->fetch();
    if (!$sess) { header('Location: transactions.php'); exit; }


    $payStmt = $pdo->prepare("
        SELECT * FROM payments
        WHERE session_id = ? AND session_type = 'reservation'
        ORDER BY paid_at ASC
    ");
    $payStmt->execute([$id]);
    $payRows = $payStmt->fetchAll();


    $pageTitle = 'Reservation #' . $id;
    $plate     = $sess['plate_number'];
    $entryTime = $sess['arrival_time'];
    // Completed: exit time — exit-kiosk currently stores this in expires_at; use exit_time first if column exists.
    $exitTime  = null;
    if (($sess['status'] ?? '') === 'completed') {
        $exitTime = !empty($sess['exit_time']) ? $sess['exit_time'] : ($sess['expires_at'] ?? null);
    }
    $carLabel  = trim(($sess['car_color'] ?? '') . ' ' . ($sess['car_type'] ?? ''));
    $userLabel = $sess['user_name'];
    $slotLabel = $sess['slot_code'] . ', Floor ' . (int)$sess['floor'];


    // Initials for avatar
    $parts    = preg_split('/\s+/', trim($sess['user_name']));
    $initials = strtoupper(substr($parts[0] ?? '?', 0, 1))
              . strtoupper(substr($parts[count($parts) - 1] ?? '', 0, 1));
    $userName = $sess['user_name'];
}


$lastPay  = $payRows ? end($payRows)  : null;
$paidSum  = array_sum(array_column($payRows, 'total_amount'));


// ── Determine UI status ───────────────────────────────────────
$sessStatus = $sess['status'] ?? ($sess['sess_status'] ?? '');
if ($sessStatus === 'active') {
    $uiStatus = ($sess['payment_status'] === 'unpaid') ? 'unpaid' : 'active';
} elseif ($sessStatus === 'completed') {
    $uiStatus = $paidSum > 0 ? 'paid' : 'completed';
} else {
    $uiStatus = $sessStatus; // pending, confirmed, expired, cancelled
}


$badgeClass = match ($uiStatus) {
    'paid', 'completed' => 'badge-paid',
    'unpaid'            => 'badge-unpaid',
    'active'            => 'badge-active',
    default             => 'badge-pending',
};


// ── Duration & tiered fee computation ────────────────────────
$now = time();


if ($uiStatus === 'active' || $uiStatus === 'unpaid') {
    // Live: compute from start (or paid_until if partially paid)
    $billStart = strtotime($entryTime);
    if ($sess['payment_status'] === 'paid' && !empty($sess['paid_until'])) {
        $billStart = strtotime($sess['paid_until']);
    }
    $elapsedSec      = max(0, $now - $billStart);
    $totalElapsedSec = max(0, $now - strtotime($entryTime));
    $computedFee     = calculateFee($elapsedSec);
    $isLive          = true;
    $displayFee      = $computedFee;
} else {
    // Completed: use total elapsed from entry to exit (walk-in) or now (reservation)
    $endTime         = $exitTime ? strtotime($exitTime) : $now;
    $totalElapsedSec = max(0, $endTime - strtotime($entryTime));
    $elapsedSec      = $totalElapsedSec;
    $computedFee     = calculateFee($elapsedSec);
    $isLive          = false;
    // Show what was actually paid; fall back to computed if no payment recorded
    $displayFee      = $paidSum > 0 ? $paidSum : $computedFee;
}


// Duration human-readable
$durMins  = (int)floor($totalElapsedSec / 60);
$durHours = intdiv($durMins, 60);
$durRem   = $durMins % 60;
$durLabel = $durHours > 0 ? $durHours . ' hr ' . $durRem . ' min' : $durRem . ' min';
if ($uiStatus === 'active' || $uiStatus === 'unpaid') {
    $durLabel .= ' (running)';
}


// ── Fee tier breakdown for the breakdown table ────────────────
// Reproduce the same logic as calculateFee() step by step so we
// can display it clearly to the admin.
function buildFeeBreakdown(int $seconds): array
{
    $rows        = [];
    $firstMins   = RATE_FIRST_HOURS * 60;
    $totalMins   = $seconds / 60;


    if ($totalMins <= 0) {
        $rows[] = ['tier' => 'First ' . RATE_FIRST_HOURS . ' hours (flat)', 'detail' => 'Minimum charge', 'amount' => (float)RATE_FIRST_BLOCK];
        return $rows;
    }


    // First block
    $billedFirstMins = min($totalMins, $firstMins);
    $billedFirstHrs  = round($billedFirstMins / 60, 2);
    $rows[] = [
        'tier'   => 'First ' . RATE_FIRST_HOURS . ' hrs (flat rate)',
        'detail' => number_format($billedFirstHrs, 2) . ' hr × flat ₱' . RATE_FIRST_BLOCK,
        'amount' => (float)RATE_FIRST_BLOCK,
    ];


    if ($totalMins <= $firstMins) {
        return $rows;
    }


    $extraMins  = $totalMins - $firstMins;
    $fullHours  = (int)floor($extraMins / 60);
    $remainder  = $extraMins - ($fullHours * 60);


    if ($fullHours > 0) {
        $rows[] = [
            'tier'   => 'Additional full hour' . ($fullHours > 1 ? 's' : ''),
            'detail' => $fullHours . ' hr × ₱' . RATE_PER_HOUR,
            'amount' => (float)($fullHours * RATE_PER_HOUR),
        ];
    }


    if ($remainder > 0 && $remainder <= GRACE_MINUTES) {
        $rows[] = [
            'tier'   => 'Grace period overage',
            'detail' => number_format($remainder, 0) . ' min (≤' . GRACE_MINUTES . ' min grace)',
            'amount' => (float)RATE_GRACE,
        ];
    } elseif ($remainder > GRACE_MINUTES) {
        $rows[] = [
            'tier'   => 'Partial hour (beyond grace)',
            'detail' => number_format($remainder, 0) . ' min (>' . GRACE_MINUTES . ' min → full hour)',
            'amount' => (float)RATE_PER_HOUR,
        ];
    }


    return $rows;
}


$feeBreakdown = buildFeeBreakdown($elapsedSec);
$breakdownTotal = array_sum(array_column($feeBreakdown, 'amount'));


require_once 'includes/header.php';
?>


<a href="transactions.php" class="admin-back">← Back to transactions</a>


<!-- Page header -->
<div class="admin-page-head">
  <div>
    <h1 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <?= htmlspecialchars($pageTitle) ?>
      <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($uiStatus)) ?></span>
      <?php if ($isLive): ?>
        <span class="badge badge-active" style="font-size:0.65rem;animation:livePulse 1.8s infinite;">LIVE</span>
      <?php endif; ?>
    </h1>
    <p class="sub">
      <?php if ($lastPay): ?>
        Receipt <?= htmlspecialchars($lastPay['receipt_number']) ?>
        · Paid <?= date('M j, Y g:i A', strtotime($lastPay['paid_at'])) ?>
      <?php else: ?>
        No payment recorded yet
      <?php endif; ?>
    </p>
  </div>
  <?php if ($isLive): ?>
    <button onclick="location.reload()" class="btn-admin btn-admin-outline btn-admin-sm" style="align-self:flex-start;">
      ↻ Refresh fee
    </button>
  <?php endif; ?>
</div>


<!-- Stat row: entry / exit / duration -->
<div class="admin-stat-row">
  <div class="admin-stat-card">
    <div class="lbl">Entry time</div>
    <div class="val" style="font-size:1rem;"><?= date('g:i A', strtotime($entryTime)) ?></div>
    <div class="lbl" style="margin-top:4px;"><?= date('M j, Y', strtotime($entryTime)) ?></div>
  </div>
  <div class="admin-stat-card">
    <div class="lbl">Exit time</div>
    <div class="val" style="font-size:1rem;">
      <?= $exitTime ? date('g:i A', strtotime($exitTime)) : ($isLive ? '<span style="color:var(--emerald)">Still parked</span>' : '—') ?>
    </div>
    <?php if ($exitTime): ?>
      <div class="lbl" style="margin-top:4px;"><?= date('M j, Y', strtotime($exitTime)) ?></div>
    <?php endif; ?>
  </div>
  <div class="admin-stat-card">
    <div class="lbl">Duration</div>
    <div class="val" style="font-size:1rem;"><?= $durLabel ?></div>
  </div>
  <div class="admin-stat-card">
    <div class="lbl"><?= $isLive ? 'Current fee (live)' : 'Total fee charged' ?></div>
    <div class="val emerald" style="font-size:1.1rem;">₱<?= number_format($displayFee, 2) ?></div>
    <?php if ($paidSum > 0 && $isLive): ?>
      <div class="lbl" style="margin-top:4px;">₱<?= number_format($paidSum, 2) ?> already paid</div>
    <?php endif; ?>
  </div>
</div>


<div class="admin-detail-grid">


  <!-- Left: user / vehicle details -->
  <div class="admin-card">
    <div class="admin-card-title">
      <?= $type === 'reservation' ? 'Registered user' : 'Walk-in guest' ?>
    </div>
    <div class="admin-avatar-row" style="margin-bottom:1rem;">
      <div class="admin-avatar-lg"><?= htmlspecialchars($initials) ?></div>
      <div>
        <div style="font-weight:800;"><?= htmlspecialchars($userName) ?></div>
        <div style="font-size:0.78rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars($plate) ?></div>
      </div>
    </div>
    <div class="detail-list">
      <div class="detail-row">
        <span class="k">Plate number</span>
        <span class="v"><?= htmlspecialchars($plate) ?></span>
      </div>
      <div class="detail-row">
        <span class="k">Vehicle</span>
        <span class="v"><?= htmlspecialchars($carLabel ?: '—') ?></span>
      </div>
      <div class="detail-row">
        <span class="k">Session type</span>
        <span class="v">
          <span class="badge <?= $type === 'reservation' ? 'badge-active' : 'badge-pending' ?>">
            <?= $type === 'reservation' ? 'Reservation' : 'Walk-in' ?>
          </span>
        </span>
      </div>
      <div class="detail-row">
        <span class="k">Slot</span>
        <span class="v"><span class="badge badge-pending"><?= htmlspecialchars($slotLabel) ?></span></span>
      </div>
      <?php if ($type === 'reservation' && !empty($sess['user_email'])): ?>
      <div class="detail-row">
        <span class="k">Email</span>
        <span class="v"><?= htmlspecialchars($sess['user_email']) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($type === 'reservation' && !empty($sess['user_phone'])): ?>
      <div class="detail-row">
        <span class="k">Phone</span>
        <span class="v"><?= htmlspecialchars($sess['user_phone']) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($type === 'reservation' && !empty($sess['extended'])): ?>
      <div class="detail-row">
        <span class="k">Extension</span>
        <span class="v">
          <span class="badge badge-active">Extended +<?= EXTENSION_HOURS ?>h</span>
          (₱<?= number_format(EXTENSION_FEE, 2) ?>)
        </span>
      </div>
      <?php endif; ?>
    </div>
  </div>


  <!-- Right: fee breakdown + payment details -->
  <div>


    <!-- Fee breakdown card -->
    <div class="admin-card" style="margin-bottom:12px;">
      <div class="admin-card-title">
        Fee breakdown
        <?php if ($isLive): ?>
          <span style="font-size:0.65rem;font-weight:700;color:var(--emerald);margin-left:6px;">
            (live — refreshed on load)
          </span>
        <?php endif; ?>
      </div>


      <!-- Rate key -->
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;
                  padding:8px 12px;background:var(--surface);border-radius:8px;
                  font-size:0.72rem;font-weight:700;color:var(--muted);">
        <span>₱<?= RATE_FIRST_BLOCK ?> / first <?= RATE_FIRST_HOURS ?> hrs</span>
        <span>·</span>
        <span>₱<?= RATE_GRACE ?> grace (<?= GRACE_MINUTES ?> min)</span>
        <span>·</span>
        <span>₱<?= RATE_PER_HOUR ?>/hr after</span>
      </div>


      <div class="detail-list">
        <?php foreach ($feeBreakdown as $tier): ?>
        <div class="detail-row">
          <span class="k" style="flex:1.4;">
            <?= htmlspecialchars($tier['tier']) ?>
            <span style="display:block;font-size:0.68rem;color:var(--muted2);font-weight:500;margin-top:1px;">
              <?= htmlspecialchars($tier['detail']) ?>
            </span>
          </span>
          <span class="v">₱<?= number_format($tier['amount'], 2) ?></span>
        </div>
        <?php endforeach; ?>


        <div class="detail-row" style="border-top:1px solid var(--border2);margin-top:4px;padding-top:8px;">
          <span class="k" style="font-weight:700;">Computed total</span>
          <span class="v emerald" style="font-size:1rem;font-weight:800;">
            ₱<?= number_format($breakdownTotal, 2) ?>
          </span>
        </div>


        <?php if ($paidSum > 0): ?>
        <div class="detail-row">
          <span class="k">Amount paid</span>
          <span class="v">₱<?= number_format($paidSum, 2) ?></span>
        </div>
        <?php endif; ?>


        <?php if ($isLive && $paidSum > 0): ?>
        <div class="detail-row">
          <span class="k" style="color:var(--danger);">Outstanding balance</span>
          <span class="v" style="color:var(--danger);font-weight:800;">
            ₱<?= number_format(max(0, $breakdownTotal - $paidSum), 2) ?>
          </span>
        </div>
        <?php endif; ?>
      </div>
    </div>


    <!-- Payment details -->
    <div class="admin-card">
      <div class="admin-card-title">Payment details</div>
      <?php if ($payRows): ?>
        <?php foreach ($payRows as $p): ?>
        <div class="detail-list" style="margin-bottom:<?= count($payRows) > 1 ? '12px' : '0' ?>;">
          <div class="detail-row">
            <span class="k">Amount</span>
            <span class="v emerald">₱<?= number_format((float)$p['total_amount'], 2) ?></span>
          </div>
          <div class="detail-row">
            <span class="k">Method</span>
            <span class="v"><?= htmlspecialchars(ucfirst($p['method'])) ?></span>
          </div>
          <div class="detail-row">
            <span class="k">Source</span>
            <span class="v"><?= ucfirst($p['source'] ?? 'kiosk') ?></span>
          </div>
          <div class="detail-row">
            <span class="k">Paid at</span>
            <span class="v"><?= date('M j, Y g:i A', strtotime($p['paid_at'])) ?></span>
          </div>
          <div class="detail-row">
            <span class="k">Receipt</span>
            <span class="v"><?= htmlspecialchars($p['receipt_number']) ?></span>
          </div>
          <?php if (!empty($p['extension_fee']) && (float)$p['extension_fee'] > 0): ?>
          <div class="detail-row">
            <span class="k">Extension fee</span>
            <span class="v">₱<?= number_format((float)$p['extension_fee'], 2) ?></span>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="color:var(--muted);font-size:0.85rem;font-weight:600;">No payments recorded yet.</p>
      <?php endif; ?>
    </div>


  </div>
</div>


<!-- Timeline -->
<div class="admin-card">
  <div class="admin-card-title">Transaction timeline</div>
  <div class="timeline">


    <div class="timeline-item">
      <div class="timeline-dot green"></div>
      <div class="timeline-title">Vehicle entered</div>
      <div class="timeline-meta">
        <?= date('M j, Y · g:i A', strtotime($entryTime)) ?>
        — Plate <?= htmlspecialchars($plate) ?>, Slot <?= htmlspecialchars($sess['slot_code'] ?? '') ?>
        <?php if ($carLabel): ?> · <?= htmlspecialchars($carLabel) ?><?php endif; ?>
      </div>
    </div>


    <?php if ($type === 'reservation' && !empty($sess['reserved_at'])): ?>
    <div class="timeline-item">
      <div class="timeline-dot blue" style="background:#fbbf24;"></div>
      <div class="timeline-title">Reservation created</div>
      <div class="timeline-meta">
        <?= date('M j, Y · g:i A', strtotime($sess['reserved_at'])) ?>
        — Scheduled arrival <?= date('g:i A', strtotime($sess['arrival_time'])) ?>
      </div>
    </div>
    <?php endif; ?>


    <?php foreach ($payRows as $p): ?>
    <div class="timeline-item">
      <div class="timeline-dot blue"></div>
      <div class="timeline-title">Payment recorded</div>
      <div class="timeline-meta">
        <?= date('M j, Y · g:i A', strtotime($p['paid_at'])) ?>
        — ₱<?= number_format((float)$p['total_amount'], 2) ?>
        via <?= htmlspecialchars(ucfirst($p['method'])) ?>
        (<?= htmlspecialchars($p['receipt_number']) ?>)
      </div>
    </div>
    <?php endforeach; ?>


    <?php if ($type === 'reservation' && !empty($sess['extended'])): ?>
    <div class="timeline-item">
      <div class="timeline-dot blue" style="background:#a78bfa;"></div>
      <div class="timeline-title">Reservation extended</div>
      <div class="timeline-meta">+<?= EXTENSION_HOURS ?> hour — ₱<?= number_format(EXTENSION_FEE, 2) ?></div>
    </div>
    <?php endif; ?>


    <?php if ($exitTime): ?>
    <div class="timeline-item">
      <div class="timeline-dot red"></div>
      <div class="timeline-title">Vehicle exited</div>
      <div class="timeline-meta">
        <?= date('M j, Y · g:i A', strtotime($exitTime)) ?>
        — Slot <?= htmlspecialchars($sess['slot_code'] ?? '') ?> freed
      </div>
    </div>
    <?php elseif ($isLive): ?>
    <div class="timeline-item" style="opacity:0.5;">
      <div class="timeline-dot" style="background:var(--muted2);"></div>
      <div class="timeline-title">Exit pending</div>
      <div class="timeline-meta">Vehicle still parked — fee accumulating</div>
    </div>
    <?php endif; ?>


  </div>
</div>


<style>
@keyframes livePulse {
  0%,100% { opacity:1; }
  50%      { opacity:0.4; }
}
</style>


<?php require_once 'includes/footer.php'; ?>

