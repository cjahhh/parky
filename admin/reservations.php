<?php
session_start();
require_once 'includes/auth_check.php';
require_once '../config/db.php';


$q      = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$allowed = ['all', 'pending', 'confirmed', 'active', 'expired', 'cancelled', 'completed'];
if (!in_array($filter, $allowed, true)) {
    $filter = 'all';
}


$sql = "
    SELECT r.*,
           COALESCE(
               NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''),
               u.username
           ) AS user_name,
           u.username, ps.slot_code, ps.floor
    FROM reservations r
    JOIN users u ON u.id = r.user_id
    JOIN parking_slots ps ON ps.id = r.slot_id
    WHERE 1=1
";
$params = [];


if ($q !== '') {
    $sql .= " AND (CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR u.username LIKE ? OR r.plate_number LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}


if ($filter !== 'all') {
    $sql .= " AND r.status = ?";
    $params[] = $filter;
}


$sql .= " ORDER BY r.reserved_at DESC LIMIT 200";


$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


$totalAll = (int)$pdo->query("SELECT COUNT(*) FROM reservations")->fetchColumn();


function rb(string $status): string {
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
?>


<div class="admin-page-head">
  <div>
    <h1>Reservations</h1>
    <p class="sub"><?= $totalAll ?> total — all statuses</p>
  </div>
</div>


<form class="admin-toolbar" method="get" action="reservations.php">
  <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
  <input type="search" class="admin-search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by name or plate…">
  <div class="filter-pills">
    <?php
    $base = 'reservations.php';
    $fq = $q !== '' ? '&q=' . urlencode($q) : '';
    foreach (['all' => 'All', 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'active' => 'Active', 'expired' => 'Expired', 'cancelled' => 'Cancelled', 'completed' => 'Completed'] as $k => $label):
        $active = $filter === $k ? 'active' : '';
        $href = $base . '?filter=' . urlencode($k) . $fq;
        ?>
    <a href="<?= htmlspecialchars($href) ?>" class="filter-pill <?= $active ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
  </div>
</form>


<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>User</th>
        <th>Plate</th>
        <th>Slot</th>
        <th>Scheduled arrival</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="cell-ellipsis"><?= htmlspecialchars($r['user_name']) ?></td>
          <td><?= htmlspecialchars($r['plate_number']) ?></td>
          <td><?= htmlspecialchars($r['slot_code']) ?> · F<?= (int)$r['floor'] ?></td>
          <td><?= date('M j, g:i A', strtotime($r['arrival_time'])) ?></td>
          <td><?= rb($r['status']) ?></td>
          <td>
            <a href="reservation-view.php?id=<?= (int)$r['id'] ?>" class="btn-admin btn-admin-outline btn-admin-sm">View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--muted);">No reservations match.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>


<?php require_once 'includes/footer.php'; ?>

