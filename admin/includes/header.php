<?php
// admin/includes/header.php
// Shared sidebar + topbar for all admin pages
// Usage: require_once 'includes/header.php';


$current_page = basename($_SERVER['PHP_SELF'], '.php');
$admin_nav = $current_page;
if ($current_page === 'user-view') {
    $admin_nav = 'users';
}
if ($current_page === 'reservation-view') {
    $admin_nav = 'reservations';
}
if ($current_page === 'transaction-view') {
    $admin_nav = 'transactions';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky Admin — <?= ucfirst($current_page) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }


    :root {
      --bg:          #111411;
      --bg2:         #161a16;
      --bg3:         #1a1f1a;
      --surface:     #1d221d;
      --surface2:    #232923;
      --surface3:    #2a302a;
      --border:      rgba(255,255,255,0.06);
      --border2:     rgba(255,255,255,0.10);
      --text:        #eaf2ea;
      --muted:       #7a907a;
      --muted2:      #4a5a4a;
      --emerald:     #34d399;
      --emerald2:    #10b981;
      --emeraldbg:   rgba(52,211,153,0.08);
      --emeraldbg2:  rgba(52,211,153,0.13);
      --danger:      #f87171;
      --dangerbg:    rgba(248,113,113,0.1);
      --warning:     #fbbf24;
      --sidebar-w:   230px;
      --topbar-h:    58px;
    }


    body {
      font-family: 'Nunito', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
    }


    /* ── SIDEBAR ── */
    .sidebar {
      width: var(--sidebar-w);
      min-height: 100vh;
      background: var(--bg2);
      border-right: 1px solid var(--border2);
      display: flex;
      flex-direction: column;
      position: fixed;
      top: 0; left: 0;
      z-index: 100;
    }


    .sidebar-logo {
      display: flex; align-items: center; gap: 10px;
      padding: 1.25rem 1.25rem 1rem;
      border-bottom: 1px solid var(--border);
      text-decoration: none;
    }


    .sidebar-logo-icon {
      width: 34px; height: 34px;
      background: var(--emeraldbg2);
      border: 1.5px solid rgba(52,211,153,0.3);
      border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
    }


    .sidebar-logo-icon svg {
      width: 16px; height: 16px;
      fill: none; stroke: var(--emerald);
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
    }


    .sidebar-logo-text {
      font-size: 1.05rem; font-weight: 800;
      color: var(--emerald); letter-spacing: -0.02em;
      line-height: 1;
    }


    .sidebar-logo-sub {
      font-size: 0.62rem; color: var(--muted2);
      font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.06em; margin-top: 1px;
    }


    .sidebar-nav {
      flex: 1;
      padding: 1rem 0.75rem;
      display: flex; flex-direction: column; gap: 2px;
    }


    .nav-section-label {
      font-size: 0.65rem; font-weight: 700;
      color: var(--muted2); text-transform: uppercase;
      letter-spacing: 0.08em;
      padding: 0.6rem 0.6rem 0.3rem;
      margin-top: 0.5rem;
    }


    .nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 8px 12px;
      border-radius: 9px;
      text-decoration: none;
      font-size: 0.85rem; font-weight: 600;
      color: var(--muted);
      transition: background 0.15s, color 0.15s;
      cursor: pointer;
    }


    .nav-item svg {
      width: 16px; height: 16px;
      stroke: currentColor; fill: none;
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
      flex-shrink: 0;
    }


    .nav-item:hover {
      background: var(--surface);
      color: var(--text);
    }


    .nav-item.active {
      background: var(--emeraldbg);
      color: var(--emerald);
    }


    .sidebar-footer {
      padding: 0.75rem;
      border-top: 1px solid var(--border);
    }


    .admin-info {
      display: flex; align-items: center; gap: 9px;
      padding: 8px 10px;
      border-radius: 9px;
      background: var(--surface);
      margin-bottom: 6px;
    }


    .admin-avatar {
      width: 30px; height: 30px; border-radius: 8px;
      background: var(--emeraldbg2);
      border: 1px solid rgba(52,211,153,0.25);
      display: flex; align-items: center; justify-content: center;
      font-size: 0.72rem; font-weight: 800; color: var(--emerald);
      flex-shrink: 0;
    }


    .admin-details { min-width: 0; }


    .admin-name {
      font-size: 0.8rem; font-weight: 700;
      color: var(--text); white-space: nowrap;
      overflow: hidden; text-overflow: ellipsis;
    }


    .admin-role {
      font-size: 0.65rem; color: var(--muted2);
      font-weight: 600; text-transform: uppercase;
      letter-spacing: 0.05em;
    }


    .btn-logout {
      display: flex; align-items: center; gap: 8px;
      width: 100%; padding: 8px 12px;
      background: none; border: none;
      border-radius: 9px; cursor: pointer;
      font-family: 'Nunito', sans-serif;
      font-size: 0.82rem; font-weight: 600;
      color: var(--muted); text-decoration: none;
      transition: background 0.15s, color 0.15s;
    }


    .btn-logout svg {
      width: 15px; height: 15px;
      stroke: currentColor; fill: none;
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
    }


    .btn-logout:hover { background: var(--dangerbg); color: var(--danger); }


    /* ── TOPBAR ── */
    .topbar {
      position: fixed;
      top: 0; left: var(--sidebar-w);
      right: 0; height: var(--topbar-h);
      background: var(--bg2);
      border-bottom: 1px solid var(--border2);
      display: flex; align-items: center;
      padding: 0 1.75rem;
      gap: 1rem; z-index: 90;
    }


    .topbar-title {
      font-size: 1rem; font-weight: 800;
      color: var(--text); letter-spacing: -0.01em;
    }


    .topbar-sub {
      font-size: 0.78rem; color: var(--muted);
      font-weight: 500; margin-left: 4px;
    }


    .topbar-right {
      margin-left: auto;
      display: flex; align-items: center; gap: 10px;
    }


    .topbar-date {
      font-size: 0.78rem; color: var(--muted);
      font-weight: 600;
    }


    /* ── MAIN CONTENT WRAPPER ── */
    .admin-main {
      margin-left: var(--sidebar-w);
      margin-top: var(--topbar-h);
      flex: 1;
      padding: 2rem 2rem 3rem;
      min-height: calc(100vh - var(--topbar-h));
    }


    .nav-item { position: relative; }
    .nav-item.active::after {
      content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%);
      width: 3px; height: 60%; background: var(--emerald); border-radius: 3px 0 0 3px;
    }
  </style>
  <?php require_once __DIR__ . '/admin-styles.php'; ?>
</head>
<body>


<!-- ── SIDEBAR ── -->
<aside class="sidebar">
  <a href="dashboard.php" class="sidebar-logo">
    <div class="sidebar-logo-icon">
      <svg viewBox="0 0 24 24">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      </svg>
    </div>
    <div>
      <div class="sidebar-logo-text">Parky Admin</div>
      <div class="sidebar-logo-sub">Control panel</div>
    </div>
  </a>


  <nav class="sidebar-nav">
    <div class="nav-section-label">Main</div>
    <a href="dashboard.php" class="nav-item <?= $admin_nav === 'dashboard' ? 'active' : '' ?>">
      <span class="nav-dot"></span>
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>


    <div class="nav-section-label">Manage</div>
    <a href="users.php" class="nav-item <?= $admin_nav === 'users' ? 'active' : '' ?>">
      <span class="nav-dot"></span>
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
      Users
    </a>
    <a href="slots.php" class="nav-item <?= $admin_nav === 'slots' ? 'active' : '' ?>">
      <span class="nav-dot"></span>
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>
      Slots
    </a>
    <a href="reservations.php" class="nav-item <?= $admin_nav === 'reservations' ? 'active' : '' ?>">
      <span class="nav-dot"></span>
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Reservations
    </a>
    <a href="transactions.php" class="nav-item <?= $admin_nav === 'transactions' ? 'active' : '' ?>">
      <span class="nav-dot"></span>
      <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Transactions
    </a>


    <div class="nav-section-label">System</div>
    <a href="settings.php" class="nav-item <?= $admin_nav === 'settings' ? 'active' : '' ?>">
      <span class="nav-dot"></span>
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
      Settings
    </a>
  </nav>


  <div class="sidebar-footer">
    <div class="sidebar-status-online">Online</div>
    <div class="admin-info">
      <div class="admin-avatar">
        <?= strtoupper(substr($_SESSION['admin_name'] ?? 'A', 0, 1)) ?>
      </div>
      <div class="admin-details">
        <div class="admin-name"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></div>
        <div class="admin-role">Administrator</div>
      </div>
    </div>
    <a href="logout.php" class="btn-logout">
      <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Log out
    </a>
  </div>
</aside>


<!-- ── TOPBAR ── -->
<div class="topbar">
  <div>
    <span class="topbar-title">
      <?php
        $titles = [
          'dashboard'         => 'ADMIN — Dashboard',
          'users'             => 'ADMIN — Users',
          'user-view'         => 'ADMIN — User profile',
          'slots'             => 'ADMIN — Slot management',
          'reservations'      => 'ADMIN — Reservations',
          'reservation-view'  => 'ADMIN — View reservation',
          'transactions'      => 'ADMIN — Transactions',
          'transaction-view'  => 'ADMIN — View transaction',
          'settings'          => 'ADMIN — Settings',
        ];
        echo $titles[$current_page] ?? ('ADMIN — ' . ucfirst(str_replace('-', ' ', $current_page)));
      ?>
    </span>
  </div>
  <div class="topbar-right">
    <span class="topbar-date" id="topbarDate"></span>
  </div>
</div>


<!-- ── MAIN CONTENT starts here (closed in footer.php) ── -->
<main class="admin-main">


<script>
  // Live date in topbar
  function updateDate(){
    var d = new Date();
    var opts = { weekday:'short', year:'numeric', month:'short', day:'numeric' };
    document.getElementById('topbarDate').textContent = d.toLocaleDateString('en-PH', opts);
  }
  updateDate();
  setInterval(updateDate, 60000);
</script>

