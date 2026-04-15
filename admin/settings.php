<?php
session_start();
require_once 'includes/auth_check.php';
require_once '../config/db.php';
require_once __DIR__ . '/../config/rates.php';


require_once 'includes/header.php';
?>


<div class="admin-page-head">
  <div>
    <h1>Settings</h1>
    <p class="sub">Application configuration (read-only)</p>
  </div>
</div>


<div class="admin-detail-grid">
  <div class="admin-card">
    <div class="admin-card-title">General</div>
    <div class="detail-list">
      <div class="detail-row"><span class="k">Application</span><span class="v">Parky</span></div>
      <div class="detail-row"><span class="k">PHP timezone</span><span class="v"><?= htmlspecialchars(date_default_timezone_get()) ?></span></div>
      <div class="detail-row"><span class="k">Database session TZ</span><span class="v"><?= htmlspecialchars(defined('APP_DB_TIMEZONE_OFFSET') ? APP_DB_TIMEZONE_OFFSET : '—') ?></span></div>
    </div>
  </div>
  <div class="admin-card">
    <div class="admin-card-title">Parking rates</div>
    <p style="font-size:0.82rem;color:var(--muted);margin-bottom:1rem;line-height:1.5;">
      These values are shared with the customer dashboard (<code style="color:var(--emerald);">config/rates.php</code>). Edit that file to change pricing site-wide.
    </p>
    <div class="detail-list">
      <div class="detail-row"><span class="k">Rate per hour</span><span class="v">₱<?= number_format(RATE_PER_HOUR, 2) ?></span></div>
      <div class="detail-row"><span class="k">Extension fee</span><span class="v">₱<?= number_format(EXTENSION_FEE, 2) ?></span></div>
    </div>
  </div>
</div>


<?php require_once 'includes/footer.php'; ?>

