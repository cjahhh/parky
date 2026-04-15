<?php
session_start();
require_once 'config/db.php';
require_once 'config/slot_map_state.php';
require_once 'config/slot_sort.php';


// ════════════════════════════════════════════════════════════
// AUTO-EXPIRY CHECK
// ════════════════════════════════════════════════════════════
$expiredRows = $pdo->query("
    SELECT r.id, r.slot_id
    FROM reservations r
    WHERE r.status IN ('pending', 'confirmed')
      AND r.expires_at < NOW()
")->fetchAll(PDO::FETCH_ASSOC);


foreach ($expiredRows as $ex) {
    $pdo->prepare("UPDATE reservations SET status = 'expired' WHERE id = ?")
        ->execute([$ex['id']]);
    $pdo->prepare("UPDATE parking_slots SET status = 'available' WHERE id = ? AND status = 'reserved'")
        ->execute([$ex['slot_id']]);
}


// ── Fetch logged-in user name for navbar ────────────────────
$loggedInUser = null;
if (isset($_SESSION['user_id'])) {
    $stmtU = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE id = ? LIMIT 1");
    $stmtU->execute([$_SESSION['user_id']]);
    $loggedInUser = $stmtU->fetch();
    if ($loggedInUser) {
        $navFullName = trim(($loggedInUser['first_name'] ?? '') . ' ' . ($loggedInUser['last_name'] ?? ''));
        $loggedInUser['display_name'] = $navFullName !== '' ? $navFullName : ($loggedInUser['username'] ?? '');
    }
}


// ── Today's date + slot map state ──────────────────────────
$todayDate = date('Y-m-d');
$total = 90;


$mapState = parky_slot_map_state_for_date($pdo, $todayDate);
$allSlots = $mapState['allSlots'];
$displayAvailable   = $mapState['counts']['available'];
$displayReserved    = $mapState['counts']['reserved'];
$displayOccupied    = $mapState['counts']['occupied'];
$displayMaintenance = $mapState['counts']['maintenance'];


$slotsByFloor = [1 => [], 2 => [], 3 => []];
foreach ($allSlots as $s) {
    $slotsByFloor[(int) $s['floor']][] = $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky — Smart Parking System</title>
      <link rel="icon" href="favicon.ico" type="image/x-icon">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }


    :root {
      --bg:          #181c18;
      --bg2:         #1f231f;
      --surface:     #252a25;
      --surface2:    #2c312c;
      --border:      rgba(255,255,255,0.07);
      --border2:     rgba(255,255,255,0.12);
      --text:        #eaf2ea;
      --muted:       #7a907a;
      --muted2:      #556655;
      --emerald:     #34d399;
      --emerald2:    #10b981;
      --emeraldbg:   rgba(52,211,153,0.08);
      --emeraldbg2:  rgba(52,211,153,0.15);
      --danger:      #f87171;
      --warning:     #fbbf24;
    }


    html { scroll-behavior: smooth; }
    body { font-family:'Nunito',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden; }
    a { text-decoration:none;color:inherit; }


    /* ── NAVBAR ── */
    .navbar {
      position:sticky;top:0;z-index:100;height:64px;
      background:rgba(24,28,24,0.88);backdrop-filter:blur(16px);
      border-bottom:1px solid var(--border2);
      display:flex;align-items:center;padding:0 2rem;gap:1.5rem;
    }
    .nav-logo { display:flex;align-items:center;gap:9px;flex-shrink:0;text-decoration:none; }
    .nav-logo-icon {
      width:36px;height:36px;background:var(--emeraldbg2);
      border:1.5px solid rgba(52,211,153,0.3);border-radius:10px;
      display:flex;align-items:center;justify-content:center;
    }
    .nav-logo-icon span { font-size:1rem;font-weight:900;color:var(--emerald);line-height:1; }
    .nav-logo-text { font-size:1.2rem;font-weight:900;color:var(--emerald);letter-spacing:-0.02em; }


    .nav-links { display:flex;align-items:center;gap:2px;margin-left:auto; }
    .nav-links a { font-size:0.87rem;font-weight:700;color:var(--muted);padding:7px 13px;border-radius:9px;transition:color 0.2s,background 0.2s; }
    .nav-links a:hover,.nav-links a.active { color:var(--text);background:var(--surface); }


    .nav-cta { display:flex;align-items:center;gap:8px;margin-left:1rem; }
    .btn-nav-outline { font-size:0.84rem;font-weight:700;color:var(--muted);border:1.5px solid var(--border2);border-radius:10px;padding:7px 15px;transition:color 0.2s,border-color 0.2s; }
    .btn-nav-outline:hover { color:var(--text);border-color:var(--muted); }
    .btn-nav-primary { font-size:0.84rem;font-weight:800;color:#0a1a12;background:var(--emerald2);border-radius:10px;padding:8px 17px;transition:background 0.2s; }
    .btn-nav-primary:hover { background:var(--emerald); }


    .nav-user-wrap { position:relative; }
    .nav-user-chip { display:flex;align-items:center;gap:7px;background:var(--surface);border:1px solid var(--border2);border-radius:10px;padding:6px 13px;font-size:0.83rem;font-weight:700;color:var(--text);cursor:pointer;user-select:none;transition:background 0.2s,border-color 0.2s; }
    .nav-user-chip:hover { background:var(--surface2);border-color:var(--muted2); }
    .nav-user-chip .dot { width:7px;height:7px;background:var(--emerald);border-radius:50%; }
    .nav-user-chip .chevron { width:13px;height:13px;fill:none;stroke:var(--muted);stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;transition:transform 0.2s; }
    .nav-user-wrap.open .chevron { transform:rotate(180deg); }
    .nav-dropdown { display:none;position:absolute;top:calc(100% + 8px);right:0;background:var(--bg2);border:1px solid var(--border2);border-radius:12px;padding:6px;min-width:160px;box-shadow:0 8px 24px rgba(0,0,0,0.35);z-index:200;animation:dropIn 0.18s cubic-bezier(0.22,1,0.36,1) both; }
    @keyframes dropIn { from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);} }
    .nav-user-wrap.open .nav-dropdown { display:block; }
    .dropdown-item { display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:8px;font-size:0.83rem;font-weight:700;color:var(--muted);text-decoration:none;cursor:pointer;transition:background 0.15s,color 0.15s; }
    .dropdown-item:hover { background:var(--surface);color:var(--text); }
    .dropdown-item svg { width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0; }
    .dropdown-item.danger { color:var(--danger); }
    .dropdown-item.danger:hover { background:rgba(248,113,113,0.1); }
    .dropdown-divider { height:1px;background:var(--border);margin:4px 0; }


    /* ── HERO ── */
    .hero { display:grid;grid-template-columns:1fr 1fr;gap:3rem;align-items:center;max-width:1200px;margin:0 auto;padding:5rem 2rem 4rem;position:relative; }
    .hero::before { content:'';position:absolute;top:-120px;right:-60px;width:500px;height:500px;background:radial-gradient(circle,rgba(52,211,153,0.055) 0%,transparent 65%);pointer-events:none; }
    .hero-left { position:relative;z-index:1; }


    .hero-badge { display:inline-flex;align-items:center;gap:7px;background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.2);border-radius:99px;padding:5px 14px;font-size:0.77rem;font-weight:700;color:var(--emerald);margin-bottom:1.25rem;letter-spacing:0.02em; }
    .badge-dot { width:6px;height:6px;background:var(--emerald);border-radius:50%;animation:pulse 2s infinite; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.5);} }


    .hero h1 { font-size:3rem;font-weight:900;line-height:1.08;letter-spacing:-0.04em;color:var(--text);margin-bottom:1rem; }
    .hero h1 .accent { color:var(--emerald); }
    .hero p { font-size:1rem;color:var(--muted);font-weight:500;line-height:1.65;max-width:420px;margin-bottom:2rem; }


    .hero-actions { display:flex;gap:12px;flex-wrap:wrap; }
    .btn-primary { display:inline-flex;align-items:center;gap:8px;background:var(--emerald2);color:#0a1a12;font-family:'Nunito',sans-serif;font-size:0.92rem;font-weight:800;border:none;border-radius:13px;padding:13px 22px;cursor:pointer;transition:background 0.2s,transform 0.1s; }
    .btn-primary:hover { background:var(--emerald); }
    .btn-primary:active { transform:scale(0.97); }
    .btn-primary svg { width:16px;height:16px;fill:none;stroke:#0a1a12;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round; }
    .btn-secondary { display:inline-flex;align-items:center;gap:8px;background:var(--surface);color:var(--text);font-family:'Nunito',sans-serif;font-size:0.92rem;font-weight:700;border:1.5px solid var(--border2);border-radius:13px;padding:13px 22px;cursor:pointer;transition:background 0.2s,border-color 0.2s; }
    .btn-secondary:hover { background:var(--surface2);border-color:var(--muted2); }
    .btn-secondary svg { width:16px;height:16px;fill:none;stroke:var(--muted);stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }


    .hero-stats { display:flex;gap:2.5rem;margin-top:2.5rem; }
    .stat-num { font-size:1.65rem;font-weight:900;letter-spacing:-0.03em;color:var(--text);line-height:1; }
    .stat-num.green { color:var(--emerald); }
    .stat-label { font-size:0.77rem;font-weight:600;color:var(--muted);margin-top:3px; }


    /* ── SLOT PANEL ── */
    .slot-panel { background:var(--bg2);border:1px solid var(--border2);border-radius:20px;overflow:hidden;position:relative;z-index:1;animation:fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) 0.1s both; }
    @keyframes fadeUp { from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);} }


    .map-loading-overlay { display:none;position:absolute;inset:0;background:rgba(24,28,24,0.7);align-items:center;justify-content:center;z-index:10; }
    .map-loading-overlay.show { display:flex; }
    .map-spin { width:26px;height:26px;border:3px solid var(--border2);border-top-color:var(--emerald);border-radius:50%;animation:spin 0.7s linear infinite; }
    @keyframes spin { to{transform:rotate(360deg);} }


    .panel-header { display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.25rem 0.85rem;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:0.5rem; }
    .panel-title { font-size:0.87rem;font-weight:800;color:var(--text);display:flex;align-items:center;gap:7px; }
    .live-dot { width:7px;height:7px;background:var(--emerald);border-radius:50%;animation:pulse 2s infinite; }


    .panel-date-row { display:flex;align-items:center;gap:8px;padding:0.65rem 1.25rem;border-bottom:1px solid var(--border);background:rgba(255,255,255,0.02); }
    .panel-date-label { font-size:0.7rem;font-weight:700;color:var(--muted2);text-transform:uppercase;letter-spacing:0.05em;white-space:nowrap;flex-shrink:0; }
    .panel-date-input { flex:1;background:var(--surface);border:1.5px solid var(--border2);border-radius:8px;padding:5px 10px;font-family:'Nunito',sans-serif;font-size:0.8rem;font-weight:700;color:var(--text);outline:none;transition:border-color 0.2s,background 0.2s;cursor:pointer; }
    .panel-date-input:focus { border-color:var(--emerald2);background:var(--surface2); }
    .panel-date-badge { font-size:0.7rem;font-weight:700;color:var(--emerald);background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.2);border-radius:20px;padding:3px 10px;white-space:nowrap;flex-shrink:0; }


    .floor-tabs { display:flex;gap:5px;padding:0.85rem 1.25rem 0; }
    .floor-tab { flex:1;text-align:center;font-size:0.77rem;font-weight:700;color:var(--muted);background:var(--surface);border:1.5px solid var(--border);border-radius:8px;padding:6px 4px;cursor:pointer;transition:all 0.2s; }
    .floor-tab.active { color:var(--emerald);background:var(--emeraldbg);border-color:rgba(52,211,153,0.3); }


    .floor-grid { display:none;padding:0.85rem 1.25rem; }
    .floor-grid.active { display:block; }


    .grid-col-labels { display:grid;grid-template-columns:16px repeat(10,1fr);gap:3px;margin-bottom:3px; }
    .col-lbl { font-size:0.58rem;font-weight:700;color:var(--muted2);text-align:center; }
    .slot-row { display:grid;grid-template-columns:16px repeat(10,1fr);gap:3px;margin-bottom:3px; }
    .row-lbl { font-size:0.6rem;font-weight:700;color:var(--muted2);display:flex;align-items:center;justify-content:center; }


    .slot { aspect-ratio:1;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:0.48rem;font-weight:700;cursor:default;transition:transform 0.15s;border:1px solid transparent; }
    .slot:hover { transform:scale(1.15);z-index:2;position:relative; }
    .slot.available   { background:rgba(52,211,153,0.15); border-color:rgba(52,211,153,0.35); color:var(--emerald); }
    .slot.occupied    { background:rgba(248,113,113,0.13); border-color:rgba(248,113,113,0.3);  color:var(--danger); }
    .slot.reserved    { background:rgba(251,191,36,0.13);  border-color:rgba(251,191,36,0.3);   color:var(--warning); }
    .slot.maintenance { background:rgba(156,163,175,0.1);  border-color:rgba(156,163,175,0.2);  color:#6b7280; }


    .slot-summary { display:flex;gap:1rem;flex-wrap:wrap;padding:0.65rem 1.25rem;border-top:1px solid var(--border);background:rgba(255,255,255,0.02); }
    .slot-count { font-size:0.72rem;font-weight:700;color:var(--muted); }
    .slot-count span { font-weight:800; }
    .slot-count.green  span { color:var(--emerald); }
    .slot-count.yellow span { color:var(--warning); }
    .slot-count.red    span { color:var(--danger); }
    .slot-count.gray   span { color:#6b7280; }


    .slot-legend { display:flex;gap:12px;flex-wrap:wrap;padding:0.65rem 1.25rem;border-top:1px solid var(--border); }
    .legend-item { display:flex;align-items:center;gap:5px;font-size:0.71rem;font-weight:600;color:var(--muted); }
    .ldot { width:8px;height:8px;border-radius:2px; }
    .ldot.available   { background:var(--emerald); }
    .ldot.occupied    { background:var(--danger); }
    .ldot.reserved    { background:var(--warning); }
    .ldot.maintenance { background:#6b7280; }


    /* ── SECTIONS ── */
    .section-divider { height:1px;background:var(--border);max-width:1200px;margin:0 auto; }
    .section { max-width:1200px;margin:0 auto;padding:4rem 2rem; }
    .section-eyebrow { display:inline-flex;align-items:center;gap:7px;font-size:0.74rem;font-weight:700;color:var(--emerald);letter-spacing:0.08em;text-transform:uppercase;margin-bottom:0.6rem; }
    .section-eyebrow::before { content:'';width:18px;height:2px;background:var(--emerald);border-radius:2px; }
    .section-title { font-size:1.95rem;font-weight:900;letter-spacing:-0.03em;color:var(--text);margin-bottom:0.5rem;line-height:1.15; }
    .section-sub   { font-size:0.9rem;color:var(--muted);font-weight:500;max-width:480px;line-height:1.6; }


    .steps-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-top:2.5rem; }
    .step-card { background:var(--bg2);border:1px solid var(--border2);border-radius:16px;padding:1.4rem 1.25rem;transition:border-color 0.2s,transform 0.2s; }
    .step-card:hover { border-color:rgba(52,211,153,0.25);transform:translateY(-3px); }
    .step-num { font-size:0.68rem;font-weight:800;color:var(--emerald);letter-spacing:0.06em;background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.2);border-radius:6px;padding:2px 8px;display:inline-block;margin-bottom:1rem; }
    .step-icon { width:40px;height:40px;background:var(--surface);border:1px solid var(--border2);border-radius:11px;display:flex;align-items:center;justify-content:center;margin-bottom:0.9rem; }
    .step-icon svg { width:20px;height:20px;fill:none;stroke:var(--emerald);stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .step-title { font-size:0.91rem;font-weight:800;color:var(--text);margin-bottom:5px; }
    .step-desc  { font-size:0.79rem;color:var(--muted);font-weight:500;line-height:1.55; }


    .features-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-top:2.5rem; }
    .feature-card { background:var(--bg2);border:1px solid var(--border2);border-radius:16px;padding:1.4rem;transition:border-color 0.2s,transform 0.2s; }
    .feature-card:hover { border-color:rgba(52,211,153,0.22);transform:translateY(-2px); }
    .feature-icon { width:44px;height:44px;background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.2);border-radius:13px;display:flex;align-items:center;justify-content:center;margin-bottom:1rem; }
    .feature-icon svg { width:22px;height:22px;fill:none;stroke:var(--emerald);stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .feature-title { font-size:0.93rem;font-weight:800;color:var(--text);margin-bottom:5px; }
    .feature-desc  { font-size:0.8rem;color:var(--muted);font-weight:500;line-height:1.6; }


    .cta-wrap { max-width:1200px;margin:0 auto 4rem;padding:0 2rem; }
    .cta-inner { background:linear-gradient(135deg,rgba(16,185,129,0.12) 0%,rgba(52,211,153,0.05) 100%);border:1px solid rgba(52,211,153,0.2);border-radius:20px;padding:2.5rem;display:flex;align-items:center;justify-content:space-between;gap:2rem; }
    .cta-text h2 { font-size:1.4rem;font-weight:900;letter-spacing:-0.02em;color:var(--text);margin-bottom:5px; }
    .cta-text p  { font-size:0.87rem;color:var(--muted);font-weight:500; }
    .cta-actions { display:flex;gap:10px;flex-shrink:0; }


    .footer-wrap { border-top:1px solid var(--border); }
    footer { max-width:1200px;margin:0 auto;padding:1.6rem 2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem; }
    .footer-logo  { font-size:0.92rem;font-weight:900;color:var(--emerald); }
    .footer-copy  { font-size:0.77rem;color:var(--muted2);font-weight:600; }
    .footer-links { display:flex;gap:1.25rem; }
    .footer-links a { font-size:0.77rem;font-weight:600;color:var(--muted2);transition:color 0.2s; }
    .footer-links a:hover { color:var(--muted); }


    @media (max-width:1024px) {
      .hero { grid-template-columns:1fr;gap:2.5rem; }
      .hero::before { display:none; }
      .steps-grid { grid-template-columns:repeat(2,1fr); }
      .features-grid { grid-template-columns:repeat(2,1fr); }
    }
    @media (max-width:640px) {
      .hero h1 { font-size:2.1rem; }
      .steps-grid,.features-grid { grid-template-columns:1fr; }
      .cta-inner { flex-direction:column;text-align:center; }
      .cta-actions { justify-content:center; }
      .nav-links { display:none; }
      footer { flex-direction:column;text-align:center; }
    }
  </style>
</head>
<body>


<!-- NAVBAR -->
<nav class="navbar">
  <a class="nav-logo" href="index.php">
    <div class="nav-logo-icon"><span>P</span></div>
    <span class="nav-logo-text">Parky</span>
  </a>


  <div class="nav-links">
    <a href="index.php" class="active">Home</a>
    <a href="reserve.php">Reserve a parking</a>
    <a href="find-my-car.php">Find my car</a>
    <a href="about.php">About</a>
  </div>


  <div class="nav-cta">
    <?php if ($loggedInUser): ?>
      <div class="nav-user-wrap" id="userWrap">
        <div class="nav-user-chip" onclick="toggleDropdown()">
          <span class="dot"></span>
          <?= htmlspecialchars($loggedInUser['display_name']) ?>
          <svg class="chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="nav-dropdown">
          <a href="dashboard.php" class="dropdown-item">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Dashboard
          </a>
          <a href="reserve.php" class="dropdown-item">
            <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
            Reserve a spot
          </a>
          <div class="dropdown-divider"></div>
          <a href="logout.php" class="dropdown-item danger">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Log out
          </a>
        </div>
      </div>
      <a href="dashboard.php" class="btn-nav-primary">Dashboard</a>
    <?php else: ?>
      <a href="login.php" class="btn-nav-outline">Log in</a>
      <a href="register.php" class="btn-nav-primary">Sign up</a>
    <?php endif; ?>
  </div>
</nav>




<!-- HERO -->
<section class="hero">


  <div class="hero-left">
    <div class="hero-badge">
      <span class="badge-dot"></span>
      Live availability updated in real time
    </div>


    <h1>Park smarter,<br>not <span class="accent">harder.</span></h1>


    <p>Reserve your spot in advance, locate your car instantly, and never waste time hunting for a space again. Parky handles it all — from entry to exit.</p>


    <div class="hero-actions">
      <a href="<?= $loggedInUser ? 'reserve.php' : 'register.php' ?>" class="btn-primary">
        <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
        Reserve a spot
      </a>
      <a href="find-my-car.php" class="btn-secondary">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Find my car
      </a>
    </div>


    <div class="hero-stats">
      <div>
        <div class="stat-num"><?= $total ?></div>
        <div class="stat-label">Total slots</div>
      </div>
      <div>
        <div class="stat-num green" id="heroAvailable"><?= $displayAvailable ?></div>
        <div class="stat-label">Available now</div>
      </div>
      <div>
        <div class="stat-num">3</div>
        <div class="stat-label">Floors</div>
      </div>
    </div>
  </div>


  <!-- LIVE SLOT MAP -->
  <div class="slot-panel">


    <div class="map-loading-overlay" id="mapLoading">
      <div class="map-spin"></div>
    </div>


    <div class="panel-header">
      <div class="panel-title">
        <span class="live-dot"></span>
        Live Slot Availability
      </div>
    </div>


    <div class="panel-date-row">
      <span class="panel-date-label">View date:</span>
      <input
        type="date"
        id="mapDatePicker"
        class="panel-date-input"
        value="<?= $todayDate ?>"
        min="<?= $todayDate ?>"
        onchange="onDateChange(this.value)"
      />
      <span class="panel-date-badge" id="mapDateBadge">Today</span>
    </div>


    <div class="floor-tabs">
      <div class="floor-tab active" onclick="switchFloor(1,this)">Floor 1</div>
      <div class="floor-tab"       onclick="switchFloor(2,this)">Floor 2</div>
      <div class="floor-tab"       onclick="switchFloor(3,this)">Floor 3</div>
    </div>


    <?php foreach ([1,2,3] as $floor): ?>
    <div class="floor-grid <?= $floor===1?'active':'' ?>" id="floor-<?= $floor ?>">


      <div class="grid-col-labels">
        <div></div>
        <?php for($c=1;$c<=10;$c++): ?><div class="col-lbl"><?=$c?></div><?php endfor; ?>
      </div>


      <?php
        $rows = [];
        foreach ($slotsByFloor[$floor] as $slot) {
            preg_match('/^\d([A-C])(\d+)$/', $slot['slot_code'], $m);
            $rows[$m[1]][] = $slot;
        }
        ksort($rows);
        foreach ($rows as $rowLetter => &$rowSlots) {
            parky_usort_row_slots($rowSlots);
        }
        unset($rowSlots);
        foreach ($rows as $rowLetter => $rowSlots):
      ?>
      <div class="slot-row">
        <div class="row-lbl"><?= $rowLetter ?></div>
        <?php foreach($rowSlots as $s): ?>
          <div
            class="slot <?= $s['display_status'] ?>"
            id="slot-<?= $s['id'] ?>"
            data-id="<?= $s['id'] ?>"
            data-status="<?= $s['display_status'] ?>"
            title="<?= htmlspecialchars($s['slot_code']) ?> — <?= ucfirst($s['display_status']) ?>">
            <?= htmlspecialchars($s['slot_code']) ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>


    </div>
    <?php endforeach; ?>


    <div class="slot-summary">
      <div class="slot-count green">Available: <span id="cnt-available"><?= $displayAvailable ?></span></div>
      <div class="slot-count yellow">Reserved: <span id="cnt-reserved"><?= $displayReserved ?></span></div>
      <div class="slot-count red">Occupied: <span id="cnt-occupied"><?= $displayOccupied ?></span></div>
      <div class="slot-count gray">Maintenance: <span id="cnt-maintenance"><?= $displayMaintenance ?></span></div>
    </div>


    <div class="slot-legend">
      <div class="legend-item"><span class="ldot available"></span>Available</div>
      <div class="legend-item"><span class="ldot occupied"></span>Occupied</div>
      <div class="legend-item"><span class="ldot reserved"></span>Reserved</div>
      <div class="legend-item"><span class="ldot maintenance"></span>Maintenance</div>
    </div>


  </div><!-- /slot-panel -->


</section>


<div class="section-divider"></div>


<!-- HOW IT WORKS -->
<section class="section" id="about">
  <div class="section-eyebrow">How it works</div>
  <h2 class="section-title">Simple from start to finish.</h2>
  <p class="section-sub">Whether you pre-book online or walk in, Parky keeps the experience seamless at every step.</p>


  <div class="steps-grid">
    <div class="step-card">
      <div class="step-num">STEP 01</div>
      <div class="step-icon"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
      <div class="step-title">Register &amp; log in</div>
      <div class="step-desc">Create your free account in seconds. Walk-in users can skip this step entirely.</div>
    </div>
    <div class="step-card">
      <div class="step-num">STEP 02</div>
      <div class="step-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
      <div class="step-title">Reserve your slot</div>
      <div class="step-desc">Pick any available slot from the live map, choose your arrival time, and confirm.</div>
    </div>
    <div class="step-card">
      <div class="step-num">STEP 03</div>
      <div class="step-icon"><svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></div>
      <div class="step-title">Scan at the kiosk</div>
      <div class="step-desc">At the entrance, your plate is scanned automatically. Your reserved slot is confirmed instantly.</div>
    </div>
    <div class="step-card">
      <div class="step-num">STEP 04</div>
      <div class="step-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
      <div class="step-title">Pay &amp; exit</div>
      <div class="step-desc">Pay online via your dashboard or at the exit kiosk via cash, card, or online.</div>
    </div>
  </div>
</section>


<div class="section-divider"></div>


<!-- FEATURES -->
<section class="section">
  <div class="section-eyebrow">Features</div>
  <h2 class="section-title">Everything you need.</h2>
  <p class="section-sub">Smart hardware meets a clean web interface so parking is never a hassle again.</p>


  <div class="features-grid">
    <div class="feature-card">
      <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></div>
      <div class="feature-title">Plate Recognition</div>
      <div class="feature-desc">Reads your plate on entry and exit — no fumbling for tickets or cards.</div>
    </div>
    <div class="feature-card">
      <div class="feature-icon"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
      <div class="feature-title">Live Slot Tracking</div>
      <div class="feature-desc">Color-coded slot map across all 3 floors, updated in real time on every transaction.</div>
    </div>
    <div class="feature-card">
      <div class="feature-icon"><svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
      <div class="feature-title">Multiple Payment Modes</div>
      <div class="feature-desc">Cash, card, or online — pay how you want, at the kiosk or right from your dashboard.</div>
    </div>
    <div class="feature-card">
      <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
      <div class="feature-title">Voice &amp; Speech Input</div>
      <div class="feature-desc">Speak your plate number at the kiosk — hands-free convenience for a smoother experience.</div>
    </div>
    <div class="feature-card">
      <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
      <div class="feature-title">Digital Receipts</div>
      <div class="feature-desc">Instant receipts on-screen and in your account history after every completed parking session.</div>
    </div>
  </div>
</section>


<!-- CTA BANNER -->
<div class="cta-wrap">
  <div class="cta-inner">
    <div class="cta-text">
      <h2>Ready to reserve your spot?</h2>
      <p>Sign up for free and book your parking slot in under a minute.</p>
    </div>
    <div class="cta-actions">
      <a href="<?= $loggedInUser ? 'reserve.php' : 'register.php' ?>" class="btn-primary">
        <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
        Get started
      </a>
      <a href="find-my-car.php" class="btn-secondary">Find my car</a>
    </div>
  </div>
</div>


<!-- FOOTER -->
<div class="footer-wrap">
  <footer>
    <div class="footer-logo">Parky</div>
    <div class="footer-copy">© <?= date('Y') ?> Parky Smart Parking System</div>
    <div class="footer-links">
      <a href="#about">About</a>
      <a href="find-my-car.php">Find my car</a>
      <a href="reserve.php">Reserve</a>
    </div>
  </footer>
</div>


<script>
  const TODAY   = '<?= $todayDate ?>';
  const POLL_MS = 4000;
  let pollTimer = null;


  function switchFloor(floor, tab) {
    document.querySelectorAll('.floor-grid').forEach(g => g.classList.remove('active'));
    document.querySelectorAll('.floor-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('floor-' + floor).classList.add('active');
    tab.classList.add('active');
  }


  function toggleDropdown() {
    document.getElementById('userWrap').classList.toggle('open');
  }
  document.addEventListener('click', function(e) {
    var wrap = document.getElementById('userWrap');
    if (wrap && !wrap.contains(e.target)) wrap.classList.remove('open');
  });


  function updateBadgeForDate(dateVal) {
    const badge = document.getElementById('mapDateBadge');
    if (dateVal === TODAY) {
      badge.textContent = 'Today';
    } else {
      const d = new Date(dateVal + 'T00:00:00');
      badge.textContent = d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
    }
  }


  // ── FIX: only update counts if valid data is returned.
  // If physicalStatuses is empty (unauthenticated), skip slot DOM updates
  // but still apply real counts to the summary numbers.
  function applyCountsFromPayload(data) {
    if (data.counts) {
      const a = data.counts.available  ?? 0;
      const r = data.counts.reserved   ?? 0;
      const o = data.counts.occupied   ?? 0;
      const m = data.counts.maintenance ?? 0;


      // Only update if counts look real (not all-zero from a bad response)
      const total = a + r + o + m;
      if (total > 0 || data.auth === false) {
        document.getElementById('cnt-available').textContent   = a;
        document.getElementById('cnt-reserved').textContent    = r;
        document.getElementById('cnt-occupied').textContent    = o;
        document.getElementById('cnt-maintenance').textContent = m;
      }
    }
    syncHeroStat();
  }


  function fetchSlotMap(dateVal, showLoading) {
    const overlay = document.getElementById('mapLoading');
    if (showLoading) overlay.classList.add('show');
    return fetch('ajax/get-slot-availability.php?date=' + encodeURIComponent(dateVal), { cache: 'no-store' })
      .then(r => r.json())
      .then(data => {
        // ── FIX: only call applyAvailability if we actually have per-slot data.
        // Unauthenticated users get empty physicalStatuses — don't wipe the
        // server-rendered slot map colors with empty data.
        const hasSlotData = data.physicalStatuses &&
                            Object.keys(data.physicalStatuses).length > 0;
        if (hasSlotData) {
          applyAvailability(data.reservedOnDate, data.physicalStatuses);
        }
        applyCountsFromPayload(data);
      })
      .catch(function() { /* keep previous map on error */ })
      .finally(function() {
        if (showLoading) overlay.classList.remove('show');
      });
  }


  function onDateChange(dateVal) {
    updateBadgeForDate(dateVal);
    fetchSlotMap(dateVal, true);
  }


  function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }


  function startPolling() {
    stopPolling();
    if (document.hidden) return;
    pollTimer = setInterval(function() {
      var picker = document.getElementById('mapDatePicker');
      if (!picker) return;
      fetchSlotMap(picker.value, false);
    }, POLL_MS);
  }


  document.addEventListener('visibilitychange', function() {
    if (document.hidden) stopPolling();
    else {
      var picker = document.getElementById('mapDatePicker');
      if (picker) fetchSlotMap(picker.value, false);
      startPolling();
    }
  });


  function applyAvailability(reservedOnDate, physicalStatuses) {
    reservedOnDate   = reservedOnDate   || [];
    physicalStatuses = physicalStatuses || {};


    document.querySelectorAll('.slot').forEach(el => {
      const id       = el.dataset.id;
      const physical = physicalStatuses[id] || physicalStatuses[String(id)] || 'available';


      let newStatus = physical;
      if (physical === 'available' && reservedOnDate.includes(parseInt(id, 10))) {
        newStatus = 'reserved';
      }


      el.classList.remove('available', 'occupied', 'reserved', 'maintenance');
      el.classList.add(newStatus);
      el.dataset.status = newStatus;


      const code = el.textContent.trim();
      const tipMap = {
        available:   code + ' — Available',
        reserved:    code + ' — Reserved on this date',
        occupied:    code + ' — Occupied',
        maintenance: code + ' — Under maintenance',
      };
      el.title = tipMap[newStatus] || code;
    });
  }


  function updateCounts() {
    const counts = { available: 0, reserved: 0, occupied: 0, maintenance: 0 };
    document.querySelectorAll('.slot').forEach(el => {
      const s = el.dataset.status;
      if (counts.hasOwnProperty(s)) counts[s]++;
    });
    document.getElementById('cnt-available').textContent   = counts.available;
    document.getElementById('cnt-reserved').textContent    = counts.reserved;
    document.getElementById('cnt-occupied').textContent    = counts.occupied;
    document.getElementById('cnt-maintenance').textContent = counts.maintenance;
  }


  function syncHeroStat() {
    const avail = document.getElementById('cnt-available');
    const hero  = document.getElementById('heroAvailable');
    if (avail && hero) hero.textContent = avail.textContent;
  }


  startPolling();
</script>


</body>
</html>

