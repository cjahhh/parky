<?php
session_start();
require_once 'config/db.php';


// Navbar: same as index — show current first/last from DB (not stale session after profile/schema changes)
$loggedInUser = null;
if (isset($_SESSION['user_id'])) {
    $stmtU = $pdo->prepare('SELECT first_name, last_name, username FROM users WHERE id = ? LIMIT 1');
    $stmtU->execute([$_SESSION['user_id']]);
    $loggedInUser = $stmtU->fetch();
    if ($loggedInUser) {
        $navFullName = trim(($loggedInUser['first_name'] ?? '') . ' ' . ($loggedInUser['last_name'] ?? ''));
        $loggedInUser['display_name'] = $navFullName !== '' ? $navFullName : ($loggedInUser['username'] ?? '');
    }
}
$navBarDisplayName = ($loggedInUser && !empty($loggedInUser['display_name']))
    ? $loggedInUser['display_name']
    : (isset($_SESSION['user_name']) ? (string) $_SESSION['user_name'] : 'Account');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky — About Us</title>
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
    body {
      font-family: 'Nunito', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      overflow-x: hidden;
    }
    a { text-decoration: none; color: inherit; }


    /* ── BACKGROUND GLOW (matching index.php hero glow) ── */
    .page-glow-top {
      position: fixed; top: -200px; right: -200px;
      width: 700px; height: 700px;
      background: radial-gradient(circle, rgba(52,211,153,0.055) 0%, transparent 65%);
      pointer-events: none; z-index: 0;
    }
    .page-glow-bottom {
      position: fixed; bottom: -200px; left: -200px;
      width: 600px; height: 600px;
      background: radial-gradient(circle, rgba(52,211,153,0.035) 0%, transparent 65%);
      pointer-events: none; z-index: 0;
    }


    /* ── NAVBAR (exact copy from index.php) ── */
    .navbar {
      position: sticky; top: 0; z-index: 100; height: 64px;
      background: rgba(24,28,24,0.88); backdrop-filter: blur(16px);
      border-bottom: 1px solid var(--border2);
      display: flex; align-items: center; padding: 0 2rem; gap: 1.5rem;
    }
    .nav-logo { display: flex; align-items: center; gap: 9px; flex-shrink: 0; }
    .nav-logo-icon { width: 36px; height: 36px; background: var(--emeraldbg2); border: 1.5px solid rgba(52,211,153,0.3); border-radius: 10px; display: flex; align-items: center; justify-content: center; }
    .nav-logo-icon svg { width: 18px; height: 18px; fill: none; stroke: var(--emerald); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .nav-logo-text { font-size: 1.2rem; font-weight: 900; color: var(--emerald); letter-spacing: -0.02em; }


    .nav-links { display: flex; align-items: center; gap: 2px; margin-left: auto; }
    .nav-links a { font-size: 0.87rem; font-weight: 700; color: var(--muted); padding: 7px 13px; border-radius: 9px; transition: color 0.2s, background 0.2s; }
    .nav-links a:hover, .nav-links a.active { color: var(--text); background: var(--surface); }


    .nav-cta { display: flex; align-items: center; gap: 8px; margin-left: 1rem; }
    .btn-nav-outline { font-size: 0.84rem; font-weight: 700; color: var(--muted); border: 1.5px solid var(--border2); border-radius: 10px; padding: 7px 15px; transition: color 0.2s, border-color 0.2s; }
    .btn-nav-outline:hover { color: var(--text); border-color: var(--muted); }
    .btn-nav-primary { font-size: 0.84rem; font-weight: 800; color: #0a1a12; background: var(--emerald2); border-radius: 10px; padding: 8px 17px; transition: background 0.2s; }
    .btn-nav-primary:hover { background: var(--emerald); }


    .nav-user-wrap { position: relative; }
    .nav-user-chip { display: flex; align-items: center; gap: 7px; background: var(--surface); border: 1px solid var(--border2); border-radius: 10px; padding: 6px 13px; font-size: 0.83rem; font-weight: 700; color: var(--text); cursor: pointer; user-select: none; transition: background 0.2s, border-color 0.2s; }
    .nav-user-chip:hover { background: var(--surface2); border-color: var(--muted2); }
    .nav-user-chip .dot { width: 7px; height: 7px; background: var(--emerald); border-radius: 50%; }
    .nav-user-chip .chevron { width: 13px; height: 13px; fill: none; stroke: var(--muted); stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; transition: transform 0.2s; }
    .nav-user-wrap.open .chevron { transform: rotate(180deg); }
    .nav-dropdown { display: none; position: absolute; top: calc(100% + 8px); right: 0; background: var(--bg2); border: 1px solid var(--border2); border-radius: 12px; padding: 6px; min-width: 160px; box-shadow: 0 8px 24px rgba(0,0,0,0.35); z-index: 200; animation: dropIn 0.18s cubic-bezier(0.22,1,0.36,1) both; }
    @keyframes dropIn { from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);} }
    .nav-user-wrap.open .nav-dropdown { display: block; }
    .dropdown-item { display: flex; align-items: center; gap: 9px; padding: 9px 12px; border-radius: 8px; font-size: 0.83rem; font-weight: 700; color: var(--muted); text-decoration: none; cursor: pointer; transition: background 0.15s, color 0.15s; }
    .dropdown-item:hover { background: var(--surface); color: var(--text); }
    .dropdown-item svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
    .dropdown-item.danger { color: var(--danger); }
    .dropdown-item.danger:hover { background: rgba(248,113,113,0.1); }
    .dropdown-divider { height: 1px; background: var(--border); margin: 4px 0; }


    /* ── PAGE WRAPPER ── */
    .page { position: relative; z-index: 1; }


    /* ── HERO SECTION ── */
    .about-hero {
      max-width: 1200px; margin: 0 auto;
      padding: 5rem 2rem 3.5rem;
      display: grid; grid-template-columns: 1fr 1fr;
      gap: 4rem; align-items: center;
    }
    .about-hero-left { animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) both; }
    @keyframes fadeUp { from{opacity:0;transform:translateY(22px);}to{opacity:1;transform:translateY(0);} }


    .section-eyebrow {
      display: inline-flex; align-items: center; gap: 7px;
      font-size: 0.74rem; font-weight: 700; color: var(--emerald);
      letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 0.75rem;
    }
    .section-eyebrow::before { content: ''; width: 18px; height: 2px; background: var(--emerald); border-radius: 2px; }


    .about-hero h1 {
      font-size: 2.8rem; font-weight: 900; line-height: 1.1;
      letter-spacing: -0.04em; color: var(--text); margin-bottom: 1rem;
    }
    .about-hero h1 .accent { color: var(--emerald); }
    .about-hero p {
      font-size: 0.97rem; color: var(--muted); font-weight: 500;
      line-height: 1.7; max-width: 430px;
    }


    /* Stats row inside hero */
    .hero-stats-row {
      display: flex; gap: 0; margin-top: 2.5rem;
      background: var(--bg2); border: 1px solid var(--border2);
      border-radius: 16px; overflow: hidden;
    }
    .hstat {
      flex: 1; padding: 1.1rem 1.25rem;
      border-right: 1px solid var(--border);
      transition: background 0.2s;
    }
    .hstat:last-child { border-right: none; }
    .hstat:hover { background: var(--surface); }
    .hstat-num {
      font-size: 1.7rem; font-weight: 900; letter-spacing: -0.04em;
      color: var(--emerald); line-height: 1;
    }
    .hstat-label { font-size: 0.72rem; font-weight: 600; color: var(--muted); margin-top: 4px; }


    /* Right side: mission card */
    .mission-card {
      background: var(--bg2); border: 1px solid var(--border2);
      border-radius: 20px; padding: 2rem;
      animation: fadeUp 0.55s cubic-bezier(0.22,1,0.36,1) 0.1s both;
      position: relative; overflow: hidden;
    }
    .mission-card::before {
      content: ''; position: absolute; top: -60px; right: -60px;
      width: 200px; height: 200px;
      background: radial-gradient(circle, rgba(52,211,153,0.08) 0%, transparent 70%);
      pointer-events: none;
    }
    .mission-icon {
      width: 48px; height: 48px; background: var(--emeraldbg);
      border: 1.5px solid rgba(52,211,153,0.25); border-radius: 14px;
      display: flex; align-items: center; justify-content: center; margin-bottom: 1.25rem;
    }
    .mission-icon svg { width: 24px; height: 24px; fill: none; stroke: var(--emerald); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .mission-card h3 { font-size: 1.1rem; font-weight: 800; color: var(--text); margin-bottom: 0.6rem; letter-spacing: -0.02em; }
    .mission-card p { font-size: 0.85rem; color: var(--muted); font-weight: 500; line-height: 1.7; }


    .mission-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 1.5rem; }
    .pill {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--surface); border: 1px solid var(--border2);
      border-radius: 99px; padding: 5px 13px;
      font-size: 0.76rem; font-weight: 700; color: var(--muted);
      transition: border-color 0.2s, color 0.2s;
    }
    .pill:hover { border-color: rgba(52,211,153,0.3); color: var(--emerald); }
    .pill-dot { width: 5px; height: 5px; background: var(--emerald); border-radius: 50%; }


    /* ── SECTION DIVIDER ── */
    .section-divider { height: 1px; background: var(--border); max-width: 1200px; margin: 0 auto; }


    /* ── FEATURES STRIP ── */
    .features-strip {
      max-width: 1200px; margin: 0 auto; padding: 4rem 2rem;
    }
    .features-strip-header { margin-bottom: 2.5rem; }
    .features-strip-header h2 { font-size: 1.9rem; font-weight: 900; letter-spacing: -0.03em; color: var(--text); margin-bottom: 0.4rem; }
    .features-strip-header p { font-size: 0.9rem; color: var(--muted); font-weight: 500; }


    .features-grid {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem;
    }
    .feature-card {
      background: var(--bg2); border: 1px solid var(--border2);
      border-radius: 16px; padding: 1.4rem;
      transition: border-color 0.2s, transform 0.2s;
    }
    .feature-card:hover { border-color: rgba(52,211,153,0.22); transform: translateY(-3px); }
    .feature-icon {
      width: 44px; height: 44px; background: var(--emeraldbg);
      border: 1px solid rgba(52,211,153,0.2); border-radius: 13px;
      display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;
    }
    .feature-icon svg { width: 22px; height: 22px; fill: none; stroke: var(--emerald); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .feature-title { font-size: 0.93rem; font-weight: 800; color: var(--text); margin-bottom: 5px; }
    .feature-desc  { font-size: 0.8rem; color: var(--muted); font-weight: 500; line-height: 1.6; }


    /* ── TEAM SECTION ── */
    .team-section { max-width: 1200px; margin: 0 auto; padding: 4rem 2rem; }
    .team-header { margin-bottom: 2.5rem; }
    .team-header h2 { font-size: 1.9rem; font-weight: 900; letter-spacing: -0.03em; color: var(--text); margin-bottom: 0.4rem; }
    .team-header p { font-size: 0.9rem; color: var(--muted); font-weight: 500; }


    .team-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }


    .team-card {
      background: var(--bg2); border: 1px solid var(--border2);
      border-radius: 20px; overflow: hidden;
      transition: border-color 0.25s, transform 0.25s;
      animation: fadeUp 0.5s cubic-bezier(0.22,1,0.36,1) both;
    }
    .team-card:nth-child(1) { animation-delay: 0.05s; }
    .team-card:nth-child(2) { animation-delay: 0.12s; }
    .team-card:nth-child(3) { animation-delay: 0.19s; }
    .team-card:hover { border-color: rgba(52,211,153,0.28); transform: translateY(-4px); }


    /* Photo area */
    .team-photo-wrap {
      position: relative; width: 100%;
      padding-top: 75%; /* 4:3 aspect */
      background: var(--surface);
      overflow: hidden;
    }
    .team-photo-wrap img {
      position: absolute; inset: 0;
      width: 100%; height: 100%; object-fit: cover;
      transition: transform 0.4s cubic-bezier(0.22,1,0.36,1);
    }
    .team-card:hover .team-photo-wrap img { transform: scale(1.04); }


    /* Fallback initials avatar (shown when no image / image errors) */
    .team-avatar-fallback {
      position: absolute; inset: 0;
      display: flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg, var(--surface) 0%, var(--surface2) 100%);
    }
    .team-avatar-fallback .initials {
      width: 72px; height: 72px;
      background: var(--emeraldbg2); border: 2px solid rgba(52,211,153,0.3);
      border-radius: 50%; display: flex; align-items: center; justify-content: center;
      font-size: 1.4rem; font-weight: 900; color: var(--emerald); letter-spacing: -0.02em;
    }
    /* Hide fallback when real image loads */
    .team-photo-wrap img.loaded + .team-avatar-fallback,
    .team-photo-wrap img:not([src=""]) + .team-avatar-fallback {
      /* JS will handle this */
    }


    /* Emerald accent bar at top of card */
    .team-card-accent {
      height: 3px;
      background: linear-gradient(90deg, var(--emerald2), var(--emerald), transparent);
      opacity: 0; transition: opacity 0.3s;
    }
    .team-card:hover .team-card-accent { opacity: 1; }


    .team-info { padding: 1.35rem 1.35rem 1.5rem; }
    .team-name {
      font-size: 1rem; font-weight: 800; color: var(--text);
      letter-spacing: -0.02em; margin-bottom: 3px;
    }
    .team-role {
      display: inline-flex; align-items: center; gap: 5px;
      font-size: 0.74rem; font-weight: 700; color: var(--emerald);
      background: var(--emeraldbg); border: 1px solid rgba(52,211,153,0.2);
      border-radius: 99px; padding: 3px 10px; margin-bottom: 0.75rem;
    }
    .team-role-dot { width: 5px; height: 5px; background: var(--emerald); border-radius: 50%; }
    .team-bio { font-size: 0.8rem; color: var(--muted); font-weight: 500; line-height: 1.65; }


    /* ── CONTACT SECTION ── */
    .contact-section { max-width: 1200px; margin: 0 auto; padding: 0 2rem 4rem; }


    .contact-card {
      background: var(--bg2); border: 1px solid var(--border2);
      border-radius: 20px; padding: 2rem 2.25rem;
      display: grid; grid-template-columns: 1fr 1fr; gap: 2.5rem; align-items: center;
      position: relative; overflow: hidden;
    }
    .contact-card::before {
      content: ''; position: absolute; bottom: -80px; right: -80px;
      width: 250px; height: 250px;
      background: radial-gradient(circle, rgba(52,211,153,0.06) 0%, transparent 70%);
      pointer-events: none;
    }
    .contact-left h2 { font-size: 1.5rem; font-weight: 900; letter-spacing: -0.03em; color: var(--text); margin-bottom: 0.4rem; }
    .contact-left p  { font-size: 0.85rem; color: var(--muted); font-weight: 500; line-height: 1.6; }


    .contact-items { display: flex; flex-direction: column; gap: 12px; }
    .contact-item {
      display: flex; align-items: center; gap: 13px;
      background: var(--surface); border: 1px solid var(--border2);
      border-radius: 13px; padding: 13px 16px;
      transition: border-color 0.2s, background 0.2s;
    }
    .contact-item:hover { border-color: rgba(52,211,153,0.25); background: var(--surface2); }
    .contact-item-icon {
      width: 38px; height: 38px; background: var(--emeraldbg);
      border: 1px solid rgba(52,211,153,0.2); border-radius: 10px;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .contact-item-icon svg { width: 18px; height: 18px; fill: none; stroke: var(--emerald); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .contact-item-label { font-size: 0.69rem; font-weight: 700; color: var(--muted2); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px; }
    .contact-item-value { font-size: 0.88rem; font-weight: 700; color: var(--text); }


    /* ── CTA BANNER ── */
    .cta-wrap { max-width: 1200px; margin: 0 auto; padding: 0 2rem 4rem; }
    .cta-inner {
      background: linear-gradient(135deg, rgba(16,185,129,0.12) 0%, rgba(52,211,153,0.05) 100%);
      border: 1px solid rgba(52,211,153,0.2);
      border-radius: 20px; padding: 2.5rem;
      display: flex; align-items: center; justify-content: space-between; gap: 2rem;
    }
    .cta-text h2 { font-size: 1.4rem; font-weight: 900; letter-spacing: -0.02em; color: var(--text); margin-bottom: 5px; }
    .cta-text p  { font-size: 0.87rem; color: var(--muted); font-weight: 500; }
    .cta-actions { display: flex; gap: 10px; flex-shrink: 0; }


    .btn-primary {
      display: inline-flex; align-items: center; gap: 8px;
      background: var(--emerald2); color: #0a1a12;
      font-family: 'Nunito', sans-serif; font-size: 0.92rem; font-weight: 800;
      border: none; border-radius: 13px; padding: 13px 22px;
      cursor: pointer; transition: background 0.2s, transform 0.1s;
    }
    .btn-primary:hover { background: var(--emerald); }
    .btn-primary:active { transform: scale(0.97); }
    .btn-primary svg { width: 16px; height: 16px; fill: none; stroke: #0a1a12; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }


    .btn-secondary {
      display: inline-flex; align-items: center; gap: 8px;
      background: var(--surface); color: var(--text);
      font-family: 'Nunito', sans-serif; font-size: 0.92rem; font-weight: 700;
      border: 1.5px solid var(--border2); border-radius: 13px; padding: 13px 22px;
      cursor: pointer; transition: background 0.2s, border-color 0.2s;
    }
    .btn-secondary:hover { background: var(--surface2); border-color: var(--muted2); }


    /* ── FOOTER ── */
    .footer-wrap { border-top: 1px solid var(--border); }
    footer {
      max-width: 1200px; margin: 0 auto; padding: 1.6rem 2rem;
      display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    }
    .footer-logo  { font-size: 0.92rem; font-weight: 900; color: var(--emerald); }
    .footer-copy  { font-size: 0.77rem; color: var(--muted2); font-weight: 600; }
    .footer-links { display: flex; gap: 1.25rem; }
    .footer-links a { font-size: 0.77rem; font-weight: 600; color: var(--muted2); transition: color 0.2s; }
    .footer-links a:hover { color: var(--muted); }


    /* ── RESPONSIVE ── */
    @media (max-width: 1024px) {
      .about-hero { grid-template-columns: 1fr; gap: 2.5rem; }
      .features-grid { grid-template-columns: repeat(2, 1fr); }
      .team-grid { grid-template-columns: repeat(2, 1fr); }
      .contact-card { grid-template-columns: 1fr; gap: 1.5rem; }
    }
    @media (max-width: 640px) {
      .about-hero { padding: 3rem 1.25rem 2rem; }
      .about-hero h1 { font-size: 2rem; }
      .features-grid, .team-grid { grid-template-columns: 1fr; }
      .cta-inner { flex-direction: column; text-align: center; }
      .cta-actions { justify-content: center; }
      .nav-links { display: none; }
      footer { flex-direction: column; text-align: center; }
      .hero-stats-row { flex-direction: column; }
      .hstat { border-right: none; border-bottom: 1px solid var(--border); }
      .hstat:last-child { border-bottom: none; }
    }
  </style>
</head>
<body>


<!-- Background glows — matching index.php hero atmosphere -->
<div class="page-glow-top"></div>
<div class="page-glow-bottom"></div>


<!-- ── NAVBAR ── -->
<nav class="navbar">
  <a class="nav-logo" href="index.php">
    <div class="nav-logo-icon">
      <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    </div>
    <span class="nav-logo-text">Parky</span>
  </a>


  <div class="nav-links">
    <a href="index.php">Home</a>
    <a href="reserve.php">Reserve a parking</a>
    <a href="find-my-car.php">Find my car</a>
    <a href="about.php" class="active">About</a>
  </div>


  <div class="nav-cta">
    <?php if (isset($_SESSION['user_id'])): ?>
      <div class="nav-user-wrap" id="userWrap">
        <div class="nav-user-chip" onclick="toggleDropdown()">
          <span class="dot"></span>
          <?= htmlspecialchars($navBarDisplayName) ?>
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


<div class="page">


  <!-- ── HERO ── -->
  <section class="about-hero">
    <div class="about-hero-left">
      <div class="section-eyebrow">About Parky</div>
      <h1>Built to make<br>parking <span class="accent">effortless.</span></h1>
      <p>Parky is a smart, web-based parking management system that combines real-time slot tracking, Plate recognition, and voice assistance — so that finding and securing a parking spot is never stressful again.</p>


      <div class="hero-stats-row">
        <div class="hstat">
          <div class="hstat-num">90</div>
          <div class="hstat-label">Total slots</div>
        </div>
        <div class="hstat">
          <div class="hstat-num">3</div>
          <div class="hstat-label">Floors</div>
        </div>
        <div class="hstat">
          <div class="hstat-num">4</div>
          <div class="hstat-label">Payment modes</div>
        </div>
        <div class="hstat">
          <div class="hstat-num">24/7</div>
          <div class="hstat-label">Live tracking</div>
        </div>
      </div>
    </div>


    <!-- Mission card -->
    <div class="mission-card">
      <div class="mission-icon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <h3>Our Mission</h3>
      <p>We set out to eliminate the frustration of hunting for parking. By combining smart hardware at the kiosk — license plate OCR, voice input — with a clean web experience for reservations, tracking, and payment, Parky brings every part of parking under one roof.</p>
      <div class="mission-pills">
        <span class="pill"><span class="pill-dot"></span>Real-time availability</span>
        <span class="pill"><span class="pill-dot"></span>Plate recognition</span>
        <span class="pill"><span class="pill-dot"></span>Voice-assisted kiosks</span>
        <span class="pill"><span class="pill-dot"></span>Online reservations</span>
        <span class="pill"><span class="pill-dot"></span>Payment system</span>
      </div>
    </div>
  </section>


  <div class="section-divider"></div>


  <!-- ── FEATURES ── -->
  <section class="features-strip">
    <div class="features-strip-header">
      <div class="section-eyebrow">What we built</div>
      <h2>Every feature, explained.</h2>
      <p>From the moment you drive in to the moment you exit, Parky has it covered.</p>
    </div>


    <div class="features-grid">
      <div class="feature-card">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </div>
        <div class="feature-title">License Plate Recognition</div>
        <div class="feature-desc">Reads your plate on entry and exit — no tickets, no cards, no friction.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        </div>
        <div class="feature-title">Voice & Speech Input</div>
        <div class="feature-desc">Speak your plate number at any kiosk. Hands-free entry for a faster, more accessible experience.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        </div>
        <div class="feature-title">Live Slot Map</div>
        <div class="feature-desc">Colour-coded grid across all 3 floors — green, yellow, red, and black — updated on every transaction.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div class="feature-title">Advance Reservations</div>
        <div class="feature-desc">Registered users can book their slot ahead of time and get a 1-hour grace period on arrival.</div>
      </div>
      <div class="feature-card">
        <div class="feature-icon">
          <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        </div>
        <div class="feature-title">Flexible Payments</div>
        <div class="feature-desc">Cash, card, or online — pay how you want at the kiosk or directly from the website</div>
      </div>
    </div>
  </section>


  <div class="section-divider"></div>


  <!-- ── TEAM ── -->
  <section class="team-section">
    <div class="team-header">
      <div class="section-eyebrow">The team</div>
      <h2>Meet the people behind Parky.</h2>
      <p>Three developers, one shared goal: making parking smarter.</p>
    </div>


    <div class="team-grid">


      <!-- ─ Member 1 ─ -->
      <div class="team-card">
        <div class="team-card-accent"></div>
        <div class="team-photo-wrap">
          <!--
            REPLACEABLE PHOTO — Karl Howard Cascayo
            Place the actual photo as: images/karl.jpg  (or .png / .webp)
            The fallback initials avatar shows automatically if the image fails to load.
          -->
          <img
            src="images/karl.jpg"
            alt="Karl Howard Cascayo"
            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
            onload="this.classList.add('loaded');"
          />
          <div class="team-avatar-fallback" style="display:none;">
            <div class="initials">KC</div>
          </div>
          <!-- Fallback shown by default if src is empty or missing -->
          <script>
            (function(){
              var imgs = document.querySelectorAll('.team-photo-wrap img');
              imgs.forEach(function(img){
                if(!img.complete || img.naturalWidth === 0){
                  img.style.display = 'none';
                  if(img.nextElementSibling) img.nextElementSibling.style.display = 'flex';
                }
              });
            })();
          </script>
        </div>
        <div class="team-info">
          <div class="team-name">Karl Howard Cascayo</div>
          <div class="team-role"><span class="team-role-dot"></span>Developer</div>
          <div class="team-bio">Designed and developed all user-facing pages, overseeing the UI/UX to ensure a clean, intuitive, and seamless experience for registered users.</div>
        </div>
      </div>


      <!-- ─ Member 2 ─ -->
      <div class="team-card">
        <div class="team-card-accent"></div>
        <div class="team-photo-wrap">
          <!--
            REPLACEABLE PHOTO — James Romel Aquino
            Place the actual photo as: images/james.jpg  (or .png / .webp)
          -->
          <img
            src="images/james.jpg"
            alt="James Romel Aquino"
            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
          />
          <div class="team-avatar-fallback" style="display:none;">
            <div class="initials">JA</div>
          </div>
        </div>
        <div class="team-info">
          <div class="team-name">James Romel Aquino</div>
          <div class="team-role"><span class="team-role-dot"></span>Developer</div>
          <div class="team-bio">Built all admin pages and the logic behind them, including the analytics dashboard, slot management, transaction reports, and the full user management system.</div>
        </div>
      </div>


      <!-- ─ Member 3 ─ -->
      <div class="team-card">
        <div class="team-card-accent"></div>
        <div class="team-photo-wrap">
          <!--
            REPLACEABLE PHOTO — Christian Joseph Garcia
            Place the actual photo as: images/christian.jpg  (or .png / .webp)
          -->
          <img
            src="images/christian.jpg"
            alt="Christian Joseph Garcia"
            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
          />
          <div class="team-avatar-fallback" style="display:none;">
            <div class="initials">CG</div>
          </div>
        </div>
        <div class="team-info">
          <div class="team-name">Christian Joseph Garcia</div>
          <div class="team-role"><span class="team-role-dot"></span>Developer</div>
          <div class="team-bio">Developed kiosk interfaces — entrance, exit, and payment — powered by Roboflow API and EasyOCR models for accurate vehicle and license plate recognition.</div>
        </div>
      </div>


    </div>
  </section>


  <div class="section-divider"></div>


  <!-- ── CONTACT ── -->
  <section class="contact-section" style="padding-top: 4rem;">
    <div class="contact-card">
      <div class="contact-left">
        <div class="section-eyebrow">Contact us</div>
        <h2>Get in touch.</h2>
        <p>Have questions about Parky, or want to know more about the system? Reach out to us anytime — we're happy to help.</p>
      </div>
      <div class="contact-items">
        <div class="contact-item">
          <div class="contact-item-icon">
            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          </div>
          <div>
            <div class="contact-item-label">Email</div>
            <div class="contact-item-value">parky@email.com</div>
          </div>
        </div>
        <div class="contact-item">
          <div class="contact-item-icon">
            <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81 19.79 19.79 0 01.07 1.18 2 2 0 012.03 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
          </div>
          <div>
            <div class="contact-item-label">Contact number</div>
            <div class="contact-item-value">09123456789</div>
          </div>
        </div>
        <a href="https://facebook.com/parky" target="_blank" rel="noopener noreferrer" class="contact-item" style="text-decoration:none;">
          <div class="contact-item-icon" style="background:rgba(24,119,242,0.1); border-color:rgba(24,119,242,0.2);">
            <svg viewBox="0 0 24 24" fill="none" stroke="#1877f2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/>
            </svg>
          </div>
          <div>
            <div class="contact-item-label">Facebook</div>
            <div class="contact-item-value">facebook.com/parky</div>
          </div>
        </a>
      </div>
    </div>
  </section>


  <!-- ── CTA BANNER ── -->
  <div class="cta-wrap" style="margin-top: 3rem;">
    <div class="cta-inner">
      <div class="cta-text">
        <h2>Ready to reserve your spot?</h2>
        <p>Sign up for free and book your parking slot in under a minute.</p>
      </div>
      <div class="cta-actions">
        <a href="<?= isset($_SESSION['user_id']) ? 'reserve.php' : 'register.php' ?>" class="btn-primary">
          <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle cx="12" cy="9" r="2.5"/></svg>
          Get started
        </a>
        <a href="find-my-car.php" class="btn-secondary">Find my car</a>
      </div>
    </div>
  </div>


</div><!-- /page -->


<!-- ── FOOTER ── -->
<div class="footer-wrap">
  <footer>
    <div class="footer-logo">Parky</div>
    <div class="footer-copy">© <?= date('Y') ?> Parky Smart Parking System</div>
    <div class="footer-links">
      <a href="about.php">About</a>
      <a href="find-my-car.php">Find my car</a>
      <a href="reserve.php">Reserve</a>
    </div>
  </footer>
</div>


<script>
  // ── Navbar dropdown ──
  function toggleDropdown() {
    document.getElementById('userWrap').classList.toggle('open');
  }
  document.addEventListener('click', function(e) {
    var wrap = document.getElementById('userWrap');
    if (wrap && !wrap.contains(e.target)) wrap.classList.remove('open');
  });


  // ── Image fallback check on load ──
  // Ensures fallback initials show immediately if the file doesn't exist
  document.querySelectorAll('.team-photo-wrap img').forEach(function(img) {
    function checkImg() {
      if (!img.complete || img.naturalWidth === 0) {
        img.style.display = 'none';
        var fallback = img.nextElementSibling;
        if (fallback) fallback.style.display = 'flex';
      }
    }
    if (img.complete) { checkImg(); }
    else { img.addEventListener('load', checkImg); img.addEventListener('error', checkImg); }
  });
</script>


</body>
</html>

