<?php
session_start();
require_once 'includes/auth_check.php';
require_once '../config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/delete_user.php';


$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: users.php');
    exit;
}


$flash     = $_SESSION['admin_flash'] ?? '';
$flash_err = $_SESSION['admin_flash_err'] ?? '';
unset($_SESSION['admin_flash'], $_SESSION['admin_flash_err']);


// Fetch the user — includes plate_number directly from the users table
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header('Location: users.php');
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    if (!admin_csrf_validate($_POST['csrf'] ?? null)) {
        $_SESSION['admin_flash_err'] = 'Invalid session token. Please try again.';
        header('Location: user-view.php?id=' . $id);
        exit;
    }
    if ((int) ($_POST['delete_user_id'] ?? 0) === $id) {
        try {
            parky_admin_delete_user($pdo, $id);
            $_SESSION['admin_flash'] = 'User removed.';
            header('Location: users.php');
            exit;
        } catch (Throwable $e) {
            error_log('admin delete user: ' . $e->getMessage());
            $_SESSION['admin_flash_err'] = 'Could not remove user. Try again or check the error log.';
        }
    }
    header('Location: user-view.php?id=' . $id);
    exit;
}


$csrf = admin_csrf_token();


$userDisplayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($userDisplayName === '') {
    $userDisplayName = $user['username'] ?? '';
}


// ── Stats ────────────────────────────────────────────────────
$resCntStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ?");
$resCntStmt->execute([$id]);
$resCnt = (int)$resCntStmt->fetchColumn();


$spentStmt = $pdo->prepare("
    SELECT COALESCE(SUM(p.total_amount), 0)
    FROM payments p
    JOIN reservations r ON r.id = p.session_id AND p.session_type = 'reservation'
    WHERE r.user_id = ?
");
$spentStmt->execute([$id]);
$totalSpent = (float)$spentStmt->fetchColumn();


$lastStmt = $pdo->prepare("
    SELECT GREATEST(
        IFNULL((SELECT MAX(reserved_at) FROM reservations WHERE user_id = ?), '1970-01-01'),
        IFNULL((SELECT MAX(paid_at) FROM payments p JOIN reservations r ON r.id = p.session_id AND p.session_type = 'reservation' WHERE r.user_id = ?), '1970-01-01'),
        IFNULL((SELECT MAX(arrival_time) FROM reservations WHERE user_id = ?), '1970-01-01')
    ) AS mx
");
$lastStmt->execute([$id, $id, $id]);
$lastRow = $lastStmt->fetch(PDO::FETCH_ASSOC);
$lastVisit = ($lastRow && $lastRow['mx'] && $lastRow['mx'] !== '1970-01-01')
    ? date('M j, Y', strtotime($lastRow['mx']))
    : '—';


// ── Vehicle details ──────────────────────────────────────────
// Primary: plate_number comes from users.plate_number (set at registration or profile edit).
// car_type and car_color come from the most recent reservation since those are
// entered per-booking and not stored on the users table.
$profilePlate = !empty($user['plate_number']) ? $user['plate_number'] : '';


$latestRes = $pdo->prepare("
    SELECT plate_number, car_type, car_color
    FROM reservations
    WHERE user_id = ?
    ORDER BY reserved_at DESC, id DESC
    LIMIT 1
");
$latestRes->execute([$id]);
$latestResRow = $latestRes->fetch(PDO::FETCH_ASSOC) ?: [];


// Show profile plate as the canonical plate; fall back to last reservation plate
// only if the user hasn't set one on their profile yet.
$displayPlate = $profilePlate
    ?: (!empty($latestResRow['plate_number']) ? $latestResRow['plate_number'] : '');


$displayCarType  = $latestResRow['car_type']  ?? '';
$displayCarColor = $latestResRow['car_color'] ?? '';


// ── Reservation history ──────────────────────────────────────
$resList = $pdo->prepare("
    SELECT r.*, ps.slot_code, ps.floor
    FROM reservations r
    JOIN parking_slots ps ON ps.id = r.slot_id
    WHERE r.user_id = ?
    ORDER BY r.reserved_at DESC
    LIMIT 50
");
$resList->execute([$id]);
$reservations = $resList->fetchAll(PDO::FETCH_ASSOC);


// ── Payment history ──────────────────────────────────────────
$payList = $pdo->prepare("
    SELECT p.*, r.plate_number, ps.slot_code
    FROM payments p
    JOIN reservations r ON r.id = p.session_id AND p.session_type = 'reservation'
    JOIN parking_slots ps ON ps.id = r.slot_id
    WHERE r.user_id = ?
    ORDER BY p.paid_at DESC
    LIMIT 50
");
$payList->execute([$id]);
$payments = $payList->fetchAll(PDO::FETCH_ASSOC);


// ── Helpers ──────────────────────────────────────────────────
function user_initials(string $name): string
{
    $p = preg_split('/\s+/', trim($name));
    $a = strtoupper(substr($p[0] ?? '?', 0, 1));
    $b = strtoupper(substr($p[count($p) - 1] ?? '', 0, 1));
    return $a . ($b !== $a ? $b : '');
}


function res_badge(string $status): string
{
    $map = [
        'pending'   => 'badge-pending',
        'confirmed' => 'badge-confirmed',
        'active'    => 'badge-active',
        'completed' => 'badge-completed',
        'expired'   => 'badge-expired',
        'cancelled' => 'badge-cancelled',
    ];
    $c = $map[$status] ?? 'badge-completed';
    return '<span class="badge ' . $c . '">' . htmlspecialchars($status) . '</span>';
}


require_once 'includes/header.php';
$hasRes    = $resCnt > 0;
$emailOk   = (int) ($user['is_verified'] ?? 0) === 1;
?>


<?php if ($flash): ?><div class="admin-flash ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="admin-flash err"><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>


<a href="users.php" class="admin-back">← Back to users</a>


<div class="admin-page-head">
  <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;flex:1;min-width:0;">
    <div class="admin-avatar-lg" style="width:56px;height:56px;font-size:1.1rem;">
      <?= htmlspecialchars(user_initials($userDisplayName)) ?>
    </div>
    <div>
      <h1><?= htmlspecialchars($userDisplayName) ?></h1>
      <p class="sub">Member since <?= date('M j, Y', strtotime($user['created_at'])) ?></p>
      <p style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
        <?php if ($emailOk): ?>
          <span class="badge badge-confirmed">Email verified</span>
        <?php else: ?>
          <span class="badge badge-pending">Email not verified</span>
        <?php endif; ?>
        <?php if ($hasRes): ?>
          <span class="badge badge-reserved-user">Reserved user</span>
        <?php else: ?>
          <span class="badge badge-guest">No reservations yet</span>
        <?php endif; ?>
      </p>
    </div>
  </div>
  <form method="post" action="user-view.php?id=<?= (int)$id ?>"
        style="flex-shrink:0;margin-top:0.5rem;"
        onsubmit="return confirm('Remove this user and all their reservations and payments? This cannot be undone.');">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="delete_user_id" value="<?= (int)$id ?>">
    <button type="submit" class="btn-admin btn-admin-danger-outline">Remove user</button>
  </form>
</div>


<!-- Summary stats -->
<div class="admin-stat-row">
  <div class="admin-stat-card">
    <div class="lbl">Total reservations</div>
    <div class="val"><?= $resCnt ?></div>
  </div>
  <div class="admin-stat-card">
    <div class="lbl">Total spent</div>
    <div class="val emerald">₱<?= number_format($totalSpent, 2) ?></div>
  </div>
  <div class="admin-stat-card">
    <div class="lbl">Last activity</div>
    <div class="val" style="font-size:1rem;"><?= htmlspecialchars($lastVisit) ?></div>
  </div>
</div>


<!-- Account + vehicle details -->
<div class="admin-detail-grid">


  <div class="admin-card">
    <div class="admin-card-title">Account details</div>
    <div class="detail-list">
      <div class="detail-row">
        <span class="k">First name</span>
        <span class="v"><?= htmlspecialchars($user['first_name'] ?? '—') ?></span>
      </div>
      <div class="detail-row">
        <span class="k">Last name</span>
        <span class="v"><?= htmlspecialchars($user['last_name'] ?? '—') ?></span>
      </div>
      <div class="detail-row">
        <span class="k">Username</span>
        <span class="v">@<?= htmlspecialchars($user['username']) ?></span>
      </div>
      <div class="detail-row">
        <span class="k">Email</span>
        <span class="v"><?= htmlspecialchars($user['email']) ?></span>
      </div>
      <div class="detail-row">
        <span class="k">Contact</span>
        <span class="v"><?= htmlspecialchars($user['phone'] ?: '—') ?></span>
      </div>
      <div class="detail-row">
        <span class="k">Registered</span>
        <span class="v"><?= date('M j, Y g:i A', strtotime($user['created_at'])) ?></span>
      </div>
      <div class="detail-row">
        <span class="k">Email verification</span>
        <span class="v">
          <?php if ($emailOk): ?>
            <span class="badge badge-confirmed">Verified</span>
          <?php else: ?>
            <span class="badge badge-pending">Pending</span>
          <?php endif; ?>
        </span>
      </div>
      <div class="detail-row">
        <span class="k">Reservation activity</span>
        <span class="v">
          <?php if ($hasRes): ?>
            <span class="badge badge-reserved-user">Reserved user</span>
          <?php else: ?>
            <span class="badge badge-guest">No reservations</span>
          <?php endif; ?>
        </span>
      </div>
    </div>
  </div>


  <div class="admin-card">
    <div class="admin-card-title">Vehicle details</div>
    <div class="detail-list">
      <div class="detail-row">
        <span class="k">Plate number</span>
        <span class="v">
          <?php if ($displayPlate): ?>
            <?= htmlspecialchars($displayPlate) ?>
            <?php if ($profilePlate && $latestResRow && $latestResRow['plate_number'] && $latestResRow['plate_number'] !== $profilePlate): ?>
              <span style="display:block;font-size:0.68rem;color:var(--muted2);margin-top:2px;">
                Profile: <?= htmlspecialchars($profilePlate) ?> · Last res: <?= htmlspecialchars($latestResRow['plate_number']) ?>
              </span>
            <?php endif; ?>
          <?php else: ?>
            <span style="color:var(--muted2);">—</span>
          <?php endif; ?>
        </span>
      </div>
      <div class="detail-row">
        <span class="k">Car type</span>
        <span class="v"><?= htmlspecialchars($displayCarType ?: '—') ?></span>
      </div>
      <div class="detail-row">
        <span class="k">Car color</span>
        <span class="v"><?= htmlspecialchars($displayCarColor ?: '—') ?></span>
      </div>
    </div>
    <?php if (!$displayPlate && !$hasRes): ?>
    <div style="margin-top:0.85rem;padding:8px 12px;background:var(--surface);border-radius:8px;
                font-size:0.74rem;color:var(--muted2);font-weight:600;line-height:1.5;">
      No vehicle details yet — these are set during registration or when a reservation is made.
    </div>
    <?php endif; ?>
  </div>


</div>


<!-- Reservation history -->
<div class="admin-card">
  <div class="admin-card-title">Reservation history</div>
  <div class="admin-table-wrap" style="border:none;">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Slot</th>
          <th>Plate</th>
          <th>Scheduled arrival</th>
          <th>Status</th>
          <th>Reserved at</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reservations as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['slot_code']) ?>, F<?= (int)$r['floor'] ?></td>
            <td><?= htmlspecialchars($r['plate_number']) ?></td>
            <td><?= date('M j, g:i A', strtotime($r['arrival_time'])) ?></td>
            <td><?= res_badge($r['status']) ?></td>
            <td><?= date('M j, g:i A', strtotime($r['reserved_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($reservations)): ?>
          <tr>
            <td colspan="5" style="text-align:center;color:var(--muted);padding:1.5rem;">
              No reservations yet.
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>


<!-- Transaction history -->
<div class="admin-card">
  <div class="admin-card-title">Transaction history</div>
  <div class="admin-table-wrap" style="border:none;">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Receipt</th>
          <th>Slot</th>
          <th>Amount</th>
          <th>Method</th>
          <th>Source</th>
          <th>Paid at</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td><code style="font-size:0.72rem;color:var(--emerald);"><?= htmlspecialchars($p['receipt_number']) ?></code></td>
            <td><?= htmlspecialchars($p['slot_code']) ?></td>
            <td>₱<?= number_format((float)$p['total_amount'], 2) ?></td>
            <td><?= htmlspecialchars(ucfirst($p['method'])) ?></td>
            <td><?= ucfirst($p['source'] ?? 'kiosk') ?></td>
            <td><?= date('M j, g:i A', strtotime($p['paid_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($payments)): ?>
          <tr>
            <td colspan="6" style="text-align:center;color:var(--muted);padding:1.5rem;">
              No payments yet.
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>


<?php require_once 'includes/footer.php'; ?>

