<?php
session_start();
require_once 'includes/auth_check.php';
require_once '../config/db.php';
require_once 'includes/csrf.php';
require_once 'includes/delete_user.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    if (!admin_csrf_validate($_POST['csrf'] ?? null)) {
        $_SESSION['admin_flash_err'] = 'Invalid session token. Please try again.';
    } else {
        $delId = (int) ($_POST['delete_user_id'] ?? 0);
        if ($delId > 0) {
            try {
                parky_admin_delete_user($pdo, $delId);
                $_SESSION['admin_flash'] = 'User removed.';
            } catch (Throwable $e) {
                error_log('admin delete user: ' . $e->getMessage());
                $_SESSION['admin_flash_err'] = 'Could not remove user. Try again or check the error log.';
            }
        }
    }
    header('Location: users.php');
    exit;
}


$flash     = $_SESSION['admin_flash'] ?? '';
$flash_err = $_SESSION['admin_flash_err'] ?? '';
unset($_SESSION['admin_flash'], $_SESSION['admin_flash_err']);


$q      = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'has_reservations', 'no_reservations'], true)) {
    $filter = 'all';
}


// ── Main query ───────────────────────────────────────────────
// Plate: prefer users.plate_number (set at registration); fall back to
// the plate from the most recent reservation (entered per-booking).
// car_type / car_color always come from the latest reservation since
// they are not stored on the users table.
$sql = "
    SELECT
        u.*,
        (SELECT COUNT(*) FROM reservations r WHERE r.user_id = u.id) AS res_cnt,


        -- Canonical plate: profile first, latest reservation as fallback
        COALESCE(
            NULLIF(u.plate_number, ''),
            (SELECT r.plate_number FROM reservations r WHERE r.user_id = u.id
             ORDER BY r.reserved_at DESC, r.id DESC LIMIT 1)
        ) AS display_plate,


        -- Car details always from latest reservation
        (SELECT r.car_type  FROM reservations r WHERE r.user_id = u.id
         ORDER BY r.reserved_at DESC, r.id DESC LIMIT 1) AS car_type,
        (SELECT r.car_color FROM reservations r WHERE r.user_id = u.id
         ORDER BY r.reserved_at DESC, r.id DESC LIMIT 1) AS car_color,


        (SELECT MAX(r.reserved_at) FROM reservations r WHERE r.user_id = u.id) AS last_res_at


    FROM users u
    WHERE 1=1
";
$params = [];


if ($q !== '') {
    // Search: full name (first + last), username, email, or plate (profile + reservations)
    $sql .= " AND (
        CONCAT_WS(' ', u.first_name, u.last_name) LIKE ? OR
        u.username     LIKE ? OR
        u.email        LIKE ? OR
        u.plate_number LIKE ? OR
        EXISTS (SELECT 1 FROM reservations r2
                WHERE r2.user_id = u.id AND r2.plate_number LIKE ?)
    )";
    $like = '%' . $q . '%';
    $params = array_fill(0, 5, $like);
}


if ($filter === 'has_reservations') {
    $sql .= " AND EXISTS (SELECT 1 FROM reservations r WHERE r.user_id = u.id)";
} elseif ($filter === 'no_reservations') {
    $sql .= " AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.user_id = u.id)";
}


$sql .= " ORDER BY u.created_at DESC";


$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();


require_once 'includes/header.php';


$csrf = admin_csrf_token();


function initials(string $name): string
{
    $p = preg_split('/\s+/', trim($name));
    $a = strtoupper(substr($p[0] ?? '?', 0, 1));
    $b = strtoupper(substr($p[count($p) - 1] ?? '', 0, 1));
    return $a . ($b !== $a ? $b : '');
}
?>


<div class="admin-page-head">
  <div>
    <h1>Users</h1>
    <p class="sub"><?= $totalCount ?> registered user<?= $totalCount !== 1 ? 's' : '' ?> total</p>
  </div>
</div>


<?php if ($flash): ?><div class="admin-flash ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($flash_err): ?><div class="admin-flash err"><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>


<form class="admin-toolbar" method="get" action="users.php">
  <input type="search" class="admin-search" name="q"
         value="<?= htmlspecialchars($q) ?>"
         placeholder="Search by name, email, or plate…">
  <div class="filter-pills">
    <?php $fq = $q !== '' ? '&q=' . urlencode($q) : ''; ?>
    <a href="users.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>"
       class="filter-pill <?= $filter === 'all' ? 'active' : '' ?>">All</a>
    <a href="users.php?filter=has_reservations<?= $fq ?>"
       class="filter-pill <?= $filter === 'has_reservations' ? 'active' : '' ?>">Reserved users</a>
    <a href="users.php?filter=no_reservations<?= $fq ?>"
       class="filter-pill <?= $filter === 'no_reservations' ? 'active' : '' ?>">No reservations</a>
  </div>
</form>


<div class="admin-table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Plate</th>
        <th>Vehicle</th>
        <th>Color</th>
        <th>Reservations</th>
        <th>Email verified</th>
        <th>Last visit</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $u):
        $hasRes = (int)$u['res_cnt'] > 0;
        $plate  = $u['display_plate'] ?: '—';
        $ctype  = $u['car_type']      ?: '—';
        $ccol   = $u['car_color']     ?: '—';
        $lastV  = $u['last_res_at']   ? date('M j, Y', strtotime($u['last_res_at'])) : '—';
        $uName  = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        if ($uName === '') {
            $uName = $u['username'] ?? '';
        }
        $emailOk = (int) ($u['is_verified'] ?? 0) === 1;
      ?>
        <tr>
          <td>
            <div class="admin-avatar-row">
              <div class="admin-avatar-lg" style="width:36px;height:36px;font-size:0.75rem;">
                <?= htmlspecialchars(initials($uName)) ?>
              </div>
              <div>
                <span style="font-weight:700;"><?= htmlspecialchars($uName) ?></span>
                <div style="font-size:0.7rem;color:var(--muted2);margin-top:1px;">
                  @<?= htmlspecialchars($u['username']) ?>
                </div>
              </div>
            </div>
          </td>
          <td class="cell-ellipsis"><?= htmlspecialchars($plate) ?></td>
          <td class="cell-ellipsis"><?= htmlspecialchars($ctype) ?></td>
          <td class="cell-ellipsis"><?= htmlspecialchars($ccol) ?></td>
          <td>
            <?php if ($hasRes): ?>
              <span class="badge badge-reserved-user">Reserved</span>
            <?php else: ?>
              <span class="badge badge-guest">No res.</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($emailOk): ?>
              <span class="badge badge-confirmed">Verified</span>
            <?php else: ?>
              <span class="badge badge-pending">Pending</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($lastV) ?></td>
          <td style="white-space:nowrap;">
            <a href="user-view.php?id=<?= (int)$u['id'] ?>"
               class="btn-admin btn-admin-outline btn-admin-sm">View</a>
            <form method="post" action="users.php" style="display:inline;margin-left:6px;"
                  onsubmit="return confirm('Remove this user and all their reservations and payments? This cannot be undone.');">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="delete_user_id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="btn-admin btn-admin-danger-outline btn-admin-sm">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?>
        <tr>
          <td colspan="8" style="text-align:center;color:var(--muted);padding:2rem;">
            No users match.
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>


<?php require_once 'includes/footer.php'; ?>

