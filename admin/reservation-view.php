<?php
session_start();
require_once 'includes/auth_check.php';
require_once '../config/db.php';
require_once 'includes/csrf.php';


$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: reservations.php');
    exit;
}


$flash = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!admin_csrf_validate($_POST['csrf'] ?? null)) {
        $_SESSION['admin_flash'] = 'Invalid token.';
    } else {
        $chk = $pdo->prepare("SELECT id, status, slot_id FROM reservations WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['status'], ['pending', 'confirmed'], true)) {
            $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?")->execute([$id]);
            if (in_array($row['status'], ['pending', 'confirmed'], true)) {
                $pdo->prepare("UPDATE parking_slots SET status = 'available' WHERE id = ? AND status = 'reserved'")->execute([$row['slot_id']]);
            }
            $_SESSION['admin_flash'] = 'Reservation cancelled.';
        }
    }
    header('Location: reservation-view.php?id=' . $id);
    exit;
}


$stmt = $pdo->prepare("
    SELECT r.*,
           COALESCE(
               NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
               u.username
           ) AS user_name,
           u.email, u.phone, ps.slot_code, ps.floor
    FROM reservations r
    JOIN users u ON u.id = r.user_id
    JOIN parking_slots ps ON ps.id = r.slot_id
    WHERE r.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) {
    header('Location: reservations.php');
    exit;
}


$canCancel = in_array($r['status'], ['pending', 'confirmed'], true);


function initials_n(string $name): string {
    $p = preg_split('/\s+/', trim($name));
    return strtoupper(substr($p[0] ?? '?', 0, 1)) . strtoupper(substr($p[count($p) - 1] ?? '', 0, 1));
}


$badgeClass = [
    'pending'   => 'badge-pending',
    'confirmed' => 'badge-confirmed',
    'active'    => 'badge-active',
    'completed' => 'badge-completed',
    'expired'   => 'badge-expired',
    'cancelled' => 'badge-cancelled',
][$r['status']] ?? 'badge-completed';


require_once 'includes/header.php';
?>


<a href="reservations.php" class="admin-back">← Back to reservations</a>


<?php if ($flash): ?><div class="admin-flash ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>


<div class="admin-page-head">
  <div>
    <h1 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      Reservation #<?= (int)$r['id'] ?>
      <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($r['status']) ?></span>
    </h1>
    <p class="sub">Created <?= date('M j, Y \a\t g:i A', strtotime($r['reserved_at'])) ?></p>
  </div>
  <?php if ($canCancel): ?>
    <form method="post" action="reservation-view.php?id=<?= $id ?>" onsubmit="return confirm('Cancel this reservation?');">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="cancel">
      <button type="submit" class="btn-admin btn-admin-danger-outline">Cancel reservation</button>
    </form>
  <?php endif; ?>
</div>


<div class="admin-stat-row">
  <div class="admin-stat-card">
    <div class="lbl">Scheduled arrival</div>
    <div class="val" style="font-size:1rem;"><?= date('M j, g:i A', strtotime($r['arrival_time'])) ?></div>
  </div>
  <div class="admin-stat-card">
    <div class="lbl">Assigned slot</div>
    <div class="val" style="font-size:1rem;"><?= htmlspecialchars($r['slot_code']) ?> — Floor <?= (int)$r['floor'] ?></div>
  </div>
  <div class="admin-stat-card">
    <div class="lbl">Auto-release at</div>
    <div class="val" style="font-size:1rem;"><?= date('M j, g:i A', strtotime($r['expires_at'])) ?></div>
  </div>
</div>


<div class="admin-detail-grid">
  <div class="admin-card">
    <div class="admin-card-title">User details</div>
    <div class="admin-avatar-row" style="margin-bottom:1rem;">
      <div class="admin-avatar-lg"><?= htmlspecialchars(initials_n($r['user_name'])) ?></div>
      <div>
        <div style="font-weight:800;"><?= htmlspecialchars($r['user_name']) ?></div>
        <div style="font-size:0.78rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars($r['email']) ?></div>
      </div>
    </div>
    <div class="detail-list">
      <div class="detail-row"><span class="k">Contact</span><span class="v"><?= htmlspecialchars($r['phone'] ?: '—') ?></span></div>
      <div class="detail-row"><span class="k">User type</span><span class="v"><span class="badge badge-reserved-user">Reserved user</span></span></div>
    </div>
  </div>
  <div class="admin-card">
    <div class="admin-card-title">Vehicle details</div>
    <div class="detail-list">
      <div class="detail-row"><span class="k">Plate number</span><span class="v"><?= htmlspecialchars($r['plate_number']) ?></span></div>
      <div class="detail-row"><span class="k">Car type</span><span class="v"><?= htmlspecialchars($r['car_type']) ?></span></div>
      <div class="detail-row"><span class="k">Car color</span><span class="v"><?= htmlspecialchars($r['car_color']) ?></span></div>
      <div class="detail-row"><span class="k">Slot reserved</span><span class="v"><span class="badge badge-pending"><?= htmlspecialchars($r['slot_code']) ?>, Floor <?= (int)$r['floor'] ?></span></span></div>
    </div>
  </div>
</div>


<div class="admin-card">
  <div class="admin-card-title">Reservation timeline</div>
  <div class="timeline">
    <div class="timeline-item">
      <div class="timeline-dot green"></div>
      <div class="timeline-title">Reservation created</div>
      <div class="timeline-meta"><?= date('M j, Y · g:i A', strtotime($r['reserved_at'])) ?> — Slot <?= htmlspecialchars($r['slot_code']) ?> marked reserved for this session.</div>
    </div>
    <?php if (in_array($r['status'], ['pending', 'confirmed'], true)): ?>
    <div class="timeline-item">
      <div class="timeline-dot amber"></div>
      <div class="timeline-title">Awaiting arrival</div>
      <div class="timeline-meta">Auto-release scheduled at <?= date('g:i A', strtotime($r['expires_at'])) ?> on <?= date('M j', strtotime($r['expires_at'])) ?> if the vehicle is not checked in.</div>
    </div>
    <?php endif; ?>
    <?php if ($r['status'] === 'expired'): ?>
    <div class="timeline-item">
      <div class="timeline-dot red"></div>
      <div class="timeline-title">Expired</div>
      <div class="timeline-meta">This reservation passed its hold window and was released.</div>
    </div>
    <?php elseif ($r['status'] === 'cancelled'): ?>
    <div class="timeline-item">
      <div class="timeline-dot gray"></div>
      <div class="timeline-title">Cancelled</div>
      <div class="timeline-meta">Reservation was cancelled; slot may be available again.</div>
    </div>
    <?php elseif ($r['status'] === 'active'): ?>
    <div class="timeline-item">
      <div class="timeline-dot blue"></div>
      <div class="timeline-title">Checked in</div>
      <div class="timeline-meta">Vehicle is on site (active session).</div>
    </div>
    <?php elseif ($r['status'] === 'completed'): ?>
    <div class="timeline-item">
      <div class="timeline-dot green"></div>
      <div class="timeline-title">Completed</div>
      <div class="timeline-meta">Session finished and slot freed.</div>
    </div>
    <?php endif; ?>
  </div>
</div>


<?php require_once 'includes/footer.php'; ?>

