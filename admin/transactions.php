<?php
// ============================================================
//  admin/transactions.php
// ============================================================
session_start();
require_once 'includes/auth_check.php';
require_once '../config/db.php';
require_once '../config/rates.php';


// ── Summary counts (top cards) ───────────────────────────────
$today_paid_revenue = (float)$pdo->query("
    SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE DATE(paid_at) = CURDATE()
")->fetchColumn();


$completed_today = (int)$pdo->query("
    SELECT COUNT(*) FROM payments WHERE DATE(paid_at) = CURDATE()
")->fetchColumn();


$active_sessions = (int)$pdo->query("
    SELECT (SELECT COUNT(*) FROM sessions_walkin WHERE status = 'active') +
           (SELECT COUNT(*) FROM reservations    WHERE status = 'active')
")->fetchColumn();


$unpaid_count = (int)$pdo->query("
    SELECT (SELECT COUNT(*) FROM sessions_walkin WHERE status = 'active' AND payment_status = 'unpaid') +
           (SELECT COUNT(*) FROM reservations    WHERE status = 'active' AND payment_status = 'unpaid')
")->fetchColumn();


// ── Live fee for all active sessions (tiered calculateFee) ───
// We need this so the "Active sessions" fee column shows correct amounts.
$active_walkin_rows = $pdo->query("
    SELECT id, entry_time, payment_status, paid_until
    FROM sessions_walkin WHERE status = 'active'
")->fetchAll();


$active_res_rows = $pdo->query("
    SELECT id, arrival_time, payment_status, paid_until
    FROM reservations WHERE status = 'active'
")->fetchAll();


$now = time();


// Build lookup: session_id => live fee
$live_walkin_fees = [];
foreach ($active_walkin_rows as $s) {
    $start = strtotime($s['entry_time']);
    if ($s['payment_status'] === 'paid' && $s['paid_until']) {
        $start = strtotime($s['paid_until']);
    }
    $live_walkin_fees[$s['id']] = calculateFee(max(0, $now - $start));
}


$live_res_fees = [];
foreach ($active_res_rows as $r) {
    $start = strtotime($r['arrival_time']);
    if ($start > $now) {
        $live_res_fees[$r['id']] = 0.0;
        continue;
    }
    if ($r['payment_status'] === 'paid' && $r['paid_until']) {
        $start = strtotime($r['paid_until']);
    }
    $live_res_fees[$r['id']] = calculateFee(max(0, $now - $start));
}


// ── Search / filter ──────────────────────────────────────────
$q      = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'active', 'paid', 'unpaid'], true)) {
    $filter = 'all';
}


// ── Main query — walk-ins UNION reservations ─────────────────
// We pull paid_sum from payments so completed rows still show
// what was actually charged.
$sql = "
    SELECT
        'walkin'           AS sess_type,
        sw.id              AS sess_id,
        sw.plate_number,
        ps.slot_code,
        ps.floor,
        sw.entry_time,
        sw.exit_time,
        sw.status          AS sess_status,
        sw.payment_status,
        sw.car_type,
        sw.car_color,
        (SELECT COALESCE(SUM(p.total_amount), 0)
         FROM payments p
         WHERE p.session_id = sw.id AND p.session_type = 'walkin') AS paid_sum
    FROM sessions_walkin sw
    JOIN parking_slots ps ON ps.id = sw.slot_id


    UNION ALL


    SELECT
        'reservation'      AS sess_type,
        r.id               AS sess_id,
        r.plate_number,
        ps.slot_code,
        ps.floor,
        r.arrival_time     AS entry_time,
        IF(r.status = 'completed', r.expires_at, NULL) AS exit_time,
        r.status           AS sess_status,
        r.payment_status,
        r.car_type,
        r.car_color,
        (SELECT COALESCE(SUM(p.total_amount), 0)
         FROM payments p
         WHERE p.session_id = r.id AND p.session_type = 'reservation') AS paid_sum
    FROM reservations r
    JOIN parking_slots ps ON ps.id = r.slot_id
";


$params = [];
$where  = [];


if ($q !== '') {
    $where[]  = 'plate_number LIKE ?';
    $params[] = '%' . $q . '%';
}


// Push status filter into SQL where possible to avoid scanning all rows
if ($filter === 'active') {
    $where[] = "sess_status = 'active'";
} elseif ($filter === 'unpaid') {
    $where[] = "sess_status = 'active' AND payment_status = 'unpaid'";
}
// 'paid' filter still handled in PHP because it maps to multiple DB statuses


$full = "SELECT * FROM ($sql) AS combined";
if ($where) {
    $full .= ' WHERE ' . implode(' AND ', $where);
}
$full .= ' ORDER BY entry_time DESC LIMIT 300';


$stmt = $pdo->prepare($full);
$stmt->execute($params);
$raw  = $stmt->fetchAll();


// ── Determine display status per row ─────────────────────────
function txn_ui_status(array $row): string
{
    $is_walkin = $row['sess_type'] === 'walkin';
    $st        = $row['sess_status'];
    $paid      = (float)$row['paid_sum'] > 0;


    if ($st === 'active') {
        return $row['payment_status'] === 'unpaid' ? 'unpaid' : 'active';
    }
    if ($st === 'completed') {
        return $paid ? 'paid' : 'completed';
    }
    if (in_array($st, ['pending', 'confirmed'], true)) {
        return 'pending';
    }
    return $st; // expired, cancelled, etc.
}


function txn_badge(string $ui): string
{
    return match ($ui) {
        'paid', 'completed' => '<span class="badge badge-paid">Paid</span>',
        'unpaid'            => '<span class="badge badge-unpaid">Unpaid</span>',
        'active'            => '<span class="badge badge-active">Active</span>',
        'pending'           => '<span class="badge badge-pending">Pending</span>',
        default             => '<span class="badge badge-pending">' . htmlspecialchars($ui) . '</span>',
    };
}


$rows = [];
foreach ($raw as $row) {
    $ui = txn_ui_status($row);


    // PHP-side 'paid' filter: keep completed/paid only
    if ($filter === 'paid' && !in_array($ui, ['paid', 'completed'], true)) {
        continue;
    }


    $row['_ui'] = $ui;


    // Determine the fee to display:
    // - Active sessions → live recalculated fee via calculateFee()
    // - Completed/paid  → what was actually recorded in payments
    if ($ui === 'active' || $ui === 'unpaid') {
        if ($row['sess_type'] === 'walkin') {
            $row['_fee'] = $live_walkin_fees[$row['sess_id']] ?? 0.0;
        } else {
            $row['_fee'] = $live_res_fees[$row['sess_id']] ?? 0.0;
        }
        $row['_fee_is_live'] = true;
    } else {
        $row['_fee']         = (float)$row['paid_sum'];
        $row['_fee_is_live'] = false;
    }


    $rows[] = $row;
}


require_once 'includes/header.php';
?>


<div class="admin-page-head">
  <div>
    <h1>Transactions</h1>
    <p class="sub">All parking sessions — walk-ins and reservations</p>
  </div>
</div>


<!-- Summary cards -->
<div class="admin-card" style="margin-bottom:1.25rem;">
  <div class="admin-detail-grid" style="grid-template-columns:repeat(4,1fr);">
    <div>
      <div class="lbl">Revenue today (paid)</div>
      <div class="val emerald" style="font-size:1.2rem;">₱<?= number_format($today_paid_revenue, 2) ?></div>
    </div>
    <div>
      <div class="lbl">Payments completed</div>
      <div class="val"><?= $completed_today ?></div>
    </div>
    <div>
      <div class="lbl">Active sessions</div>
      <div class="val"><?= $active_sessions ?></div>
    </div>
    <div>
      <div class="lbl">Unpaid active</div>
      <div class="val" style="color:var(--danger);"><?= $unpaid_count ?></div>
    </div>
  </div>
</div>


<!-- Rate reminder -->
<div class="admin-card" style="margin-bottom:1.25rem;background:var(--emeraldbg);border-color:rgba(52,211,153,0.2);">
  <div style="font-size:0.78rem;font-weight:700;color:var(--emerald);display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
    <span>Fee structure:</span>
    <span>₱<?= RATE_FIRST_BLOCK ?> flat for first <?= RATE_FIRST_HOURS ?> hrs</span>
    <span>+₱<?= RATE_GRACE ?> grace period (<?= GRACE_MINUTES ?> min overage)</span>
    <span>+₱<?= RATE_PER_HOUR ?>/hr thereafter</span>
    <span style="margin-left:auto;opacity:0.7;">Active fees update in real time</span>
  </div>
</div>


<!-- Toolbar -->
<form class="admin-toolbar" method="get" action="transactions.php">
  <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
  <input type="search" class="admin-search" name="q"
         value="<?= htmlspecialchars($q) ?>"
         placeholder="Search by plate number…">
  <div class="filter-pills">
    <?php $fq = $q !== '' ? '&q=' . urlencode($q) : ''; ?>
    <a href="transactions.php?filter=all<?= $fq ?>"    class="filter-pill <?= $filter==='all'    ? 'active':'' ?>">All</a>
    <a href="transactions.php?filter=active<?= $fq ?>" class="filter-pill <?= $filter==='active' ? 'active':'' ?>">Active</a>
    <a href="transactions.php?filter=paid<?= $fq ?>"   class="filter-pill <?= $filter==='paid'   ? 'active':'' ?>">Paid</a>
    <a href="transactions.php?filter=unpaid<?= $fq ?>" class="filter-pill <?= $filter==='unpaid' ? 'active':'' ?>">Unpaid</a>
  </div>
</form>


<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Plate</th>
        <th>Type</th>
        <th>Slot</th>
        <th>Entry</th>
        <th>Exit</th>
        <th>Fee</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <?php
        $exitDisp = '—';
        if (!empty($row['exit_time'])) {
            $exitDisp = date('M j, g:i A', strtotime($row['exit_time']));
        }


        if ($row['_fee'] > 0) {
            $feeDisp = '₱' . number_format($row['_fee'], 2);
            if ($row['_fee_is_live']) {
                $feeDisp .= ' <span style="font-size:0.65rem;color:var(--emerald);font-weight:700;">LIVE</span>';
            }
        } else {
            $feeDisp = '—';
        }


        $typeLabel = $row['sess_type'] === 'walkin'
            ? '<span class="badge badge-pending">Walk-in</span>'
            : '<span class="badge badge-active">Reserved</span>';
        ?>
        <tr>
          <td style="font-weight:700;"><?= htmlspecialchars($row['plate_number']) ?></td>
          <td><?= $typeLabel ?></td>
          <td><?= htmlspecialchars($row['slot_code']) ?>, F<?= (int)$row['floor'] ?></td>
          <td><?= date('M j, g:i A', strtotime($row['entry_time'])) ?></td>
          <td><?= htmlspecialchars($exitDisp) ?></td>
          <td><?= $feeDisp ?></td>
          <td><?= txn_badge($row['_ui']) ?></td>
          <td>
            <a href="transaction-view.php?type=<?= htmlspecialchars($row['sess_type']) ?>&id=<?= (int)$row['sess_id'] ?>"
               class="btn-admin btn-admin-outline btn-admin-sm">View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr>
          <td colspan="8" style="text-align:center;padding:2rem;color:var(--muted);">
            No rows match this filter.
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>


<?php require_once 'includes/footer.php'; ?>

