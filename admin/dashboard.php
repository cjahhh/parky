<?php
// ============================================================
//  admin/dashboard.php
// ============================================================
session_start();
require_once 'includes/auth_check.php';
require_once '../config/db.php';
require_once '../config/rates.php';   // ← needed for live fee calculation on first load


// ── Initial server-side render ────────────────────────────────
// These values are shown immediately; the JS poller overwrites them every 5 s.


$slotRow = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(status='available')   AS available,
           SUM(status='occupied')    AS occupied,
           SUM(status='reserved')    AS reserved,
           SUM(status='maintenance') AS maintenance
    FROM parking_slots
")->fetch();


$vehicles_inside = (int)$pdo->query("
    SELECT (SELECT COUNT(*) FROM sessions_walkin WHERE status='active') +
           (SELECT COUNT(*) FROM reservations    WHERE status='active')
")->fetchColumn();


// ── Tiered live fee: mirrors get-dashboard-stats.php logic ───
$active_walkin_rows = $pdo->query("
    SELECT id, entry_time, payment_status, paid_until
    FROM sessions_walkin WHERE status = 'active'
")->fetchAll();


$active_res_rows = $pdo->query("
    SELECT id, arrival_time, payment_status, paid_until
    FROM reservations WHERE status = 'active'
")->fetchAll();


$now          = time();
$live_fee_sum = 0.0;


foreach ($active_walkin_rows as $s) {
    $start = strtotime($s['entry_time']);
    if ($s['payment_status'] === 'paid' && $s['paid_until']) {
        $start = strtotime($s['paid_until']);
    }
    $live_fee_sum += calculateFee(max(0, $now - $start));
}


foreach ($active_res_rows as $r) {
    $start = strtotime($r['arrival_time']);
    if ($start > $now) continue;
    if ($r['payment_status'] === 'paid' && $r['paid_until']) {
        $start = strtotime($r['paid_until']);
    }
    $live_fee_sum += calculateFee(max(0, $now - $start));
}


$paid_revenue  = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM payments WHERE DATE(paid_at)=CURDATE()")->fetchColumn();
$today_revenue = $paid_revenue + $live_fee_sum;


$unpaid_active = (int)$pdo->query("
    SELECT (SELECT COUNT(*) FROM sessions_walkin WHERE payment_status='unpaid' AND status='active') +
           (SELECT COUNT(*) FROM reservations    WHERE payment_status='unpaid' AND status='active')
")->fetchColumn();


$total_users        = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$verified_users     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(is_verified, 0) = 1")->fetchColumn();
$unverified_users   = max(0, $total_users - $verified_users);
$today_entries     = (int)$pdo->query("SELECT COUNT(*) FROM sessions_walkin WHERE DATE(entry_time)=CURDATE()")->fetchColumn();
$today_reservations = (int)$pdo->query("SELECT COUNT(*) FROM reservations   WHERE DATE(reserved_at)=CURDATE()")->fetchColumn();


$floor_summary = $pdo->query("
    SELECT floor, total, available, occupied, reserved, maintenance
    FROM view_floor_summary ORDER BY floor ASC
")->fetchAll();


$last_entry   = $pdo->query("SELECT MAX(entry_time) FROM sessions_walkin")->fetchColumn();
$last_exit    = $pdo->query("SELECT MAX(exit_time)  FROM sessions_walkin WHERE exit_time IS NOT NULL")->fetchColumn();
$last_payment = $pdo->query("SELECT MAX(paid_at)    FROM payments")->fetchColumn();


$recent_activity = $pdo->query("
    SELECT * FROM (
        SELECT 'entry' AS type, sw.plate_number AS plate, ps.slot_code AS slot, ps.floor,
               'Walk-in entry' AS action, sw.car_type, sw.car_color, sw.entry_time AS event_time
        FROM sessions_walkin sw JOIN parking_slots ps ON sw.slot_id = ps.id


        UNION ALL


        SELECT 'exit', sw.plate_number, ps.slot_code, ps.floor,
               'Walk-in exit', sw.car_type, sw.car_color, sw.exit_time
        FROM sessions_walkin sw JOIN parking_slots ps ON sw.slot_id = ps.id
        WHERE sw.exit_time IS NOT NULL


        UNION ALL


        SELECT 'payment',
               CASE WHEN p.session_type='walkin' THEN sw2.plate_number ELSE r.plate_number END,
               CASE WHEN p.session_type='walkin' THEN ps2.slot_code    ELSE ps3.slot_code  END,
               CASE WHEN p.session_type='walkin' THEN ps2.floor        ELSE ps3.floor      END,
               CONCAT('Paid ₱', FORMAT(p.total_amount,2), ' (', p.method, ')'),
               NULL, NULL, p.paid_at
        FROM payments p
        LEFT JOIN sessions_walkin sw2 ON p.session_id=sw2.id AND p.session_type='walkin'
        LEFT JOIN reservations r      ON p.session_id=r.id   AND p.session_type='reservation'
        LEFT JOIN parking_slots ps2   ON sw2.slot_id=ps2.id
        LEFT JOIN parking_slots ps3   ON r.slot_id=ps3.id


        UNION ALL


        SELECT 'reservation', r2.plate_number, ps4.slot_code, ps4.floor,
               CONCAT('Reserved slot ', ps4.slot_code), r2.car_type, r2.car_color, r2.reserved_at
        FROM reservations r2 JOIN parking_slots ps4 ON r2.slot_id=ps4.id


    ) AS combined ORDER BY event_time DESC LIMIT 10
")->fetchAll();


function timeAgo($datetime): string
{
    if (!$datetime) return 'Never';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff / 60)   . 'm ago';
    if ($diff < 86400) return floor($diff / 3600)  . 'h ago';
    return floor($diff / 86400) . 'd ago';
}


require_once 'includes/header.php';
?>


<style>
  /* ── DASHBOARD SPECIFIC STYLES ── */
  .dash-section-title {
    font-size: 0.72rem; font-weight: 700;
    color: var(--muted2); text-transform: uppercase;
    letter-spacing: 0.07em; margin-bottom: 0.75rem;
    display: flex; align-items: center; gap: 7px;
  }
  .dash-section-title svg {
    width: 13px; height: 13px; stroke: var(--muted2);
    fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
  }


  /* ── STAT CARDS ── */
  .stat-grid {
    display: grid; grid-template-columns: repeat(4,1fr);
    gap: 12px; margin-bottom: 1.5rem;
  }
  .stat-card {
    background: var(--bg3); border: 1px solid var(--border2);
    border-radius: 14px; padding: 1.1rem 1.25rem;
    position: relative; overflow: hidden; transition: border-color 0.2s;
  }
  .stat-card:hover { border-color: rgba(52,211,153,0.2); }
  .stat-card .sc-label {
    font-size: 0.72rem; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;
    display: flex; align-items: center; gap: 6px;
  }
  .stat-card .sc-label svg {
    width: 13px; height: 13px; stroke: currentColor;
    fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
  }
  .stat-card .sc-value {
    font-size: 1.9rem; font-weight: 800; color: var(--text);
    line-height: 1; letter-spacing: -0.02em;
  }
  .stat-card .sc-sub { font-size: 0.72rem; color: var(--muted2); font-weight: 600; margin-top: 5px; }
  .stat-card .sc-accent {
    position: absolute; top: 0; right: 0; width: 60px; height: 60px;
    border-radius: 0 14px 0 60px;
    display: flex; align-items: flex-start; justify-content: flex-end; padding: 10px 10px 0 0;
  }
  .stat-card .sc-accent svg {
    width: 18px; height: 18px; fill: none; stroke-width: 2;
    stroke-linecap: round; stroke-linejoin: round; opacity: 0.5;
  }
  .sc-green  { border-left: 3px solid var(--emerald2); }
  .sc-green  .sc-value { color: var(--emerald); }
  .sc-green  .sc-accent { background: var(--emeraldbg); }
  .sc-green  .sc-accent svg { stroke: var(--emerald); }
  .sc-red    { border-left: 3px solid #f87171; }
  .sc-red    .sc-value { color: #f87171; }
  .sc-red    .sc-accent { background: rgba(248,113,113,0.08); }
  .sc-red    .sc-accent svg { stroke: #f87171; }
  .sc-yellow { border-left: 3px solid #fbbf24; }
  .sc-yellow .sc-value { color: #fbbf24; }
  .sc-yellow .sc-accent { background: rgba(251,191,36,0.08); }
  .sc-yellow .sc-accent svg { stroke: #fbbf24; }
  .sc-blue   { border-left: 3px solid #60a5fa; }
  .sc-blue   .sc-value { color: #60a5fa; }
  .sc-blue   .sc-accent { background: rgba(96,165,250,0.08); }
  .sc-blue   .sc-accent svg { stroke: #60a5fa; }
  .sc-purple { border-left: 3px solid #a78bfa; }
  .sc-purple .sc-value { color: #a78bfa; }
  .sc-purple .sc-accent { background: rgba(167,139,250,0.08); }
  .sc-purple .sc-accent svg { stroke: #a78bfa; }
  .sc-neutral { border-left: 3px solid var(--border2); }


  .mid-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 1.5rem; }
  .bottom-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 1.5rem; }


  .panel {
    background: var(--bg3); border: 1px solid var(--border2);
    border-radius: 14px; padding: 1.25rem;
  }
  .panel-title {
    font-size: 0.82rem; font-weight: 700; color: var(--text);
    margin-bottom: 1rem; display: flex; align-items: center; gap: 8px;
  }
  .panel-title svg { width:15px;height:15px;stroke:var(--emerald);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }


  .floor-item { margin-bottom: 1rem; }
  .floor-item:last-child { margin-bottom: 0; }
  .floor-header { display:flex;justify-content:space-between;align-items:center;margin-bottom:6px; }
  .floor-label  { font-size:0.8rem;font-weight:700;color:var(--text); }
  .floor-counts { display:flex;gap:10px; }
  .floor-count-item { font-size:0.7rem;font-weight:600;display:flex;align-items:center;gap:4px; }
  .floor-count-item::before { content:'';width:7px;height:7px;border-radius:2px; }
  .fc-available::before   { background:var(--emerald); }
  .fc-occupied::before    { background:#f87171; }
  .fc-reserved::before    { background:#fbbf24; }
  .fc-maintenance::before { background:#6b7280; }
  .fc-available   { color:var(--emerald); }
  .fc-occupied    { color:#f87171; }
  .fc-reserved    { color:#fbbf24; }
  .fc-maintenance { color:#6b7280; }
  .floor-bar { height:8px;border-radius:4px;background:var(--surface2);overflow:hidden;display:flex; }
  .floor-bar-seg { height:100%;transition:width 0.4s; }
  .fbs-available   { background:var(--emerald2); }
  .fbs-occupied    { background:#f87171; }
  .fbs-reserved    { background:#fbbf24; }
  .fbs-maintenance { background:#6b7280; }


  .kiosk-item { display:flex;align-items:center;gap:12px;padding:10px 12px;background:var(--surface);border-radius:10px;margin-bottom:8px; }
  .kiosk-item:last-child { margin-bottom:0; }
  .kiosk-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0; }
  .kiosk-dot.active   { background:var(--emerald);box-shadow:0 0 0 3px rgba(52,211,153,0.2); }
  .kiosk-dot.inactive { background:var(--muted2); }
  .kiosk-info { flex:1;min-width:0; }
  .kiosk-name { font-size:0.82rem;font-weight:700;color:var(--text); }
  .kiosk-time { font-size:0.72rem;color:var(--muted);font-weight:500;margin-top:1px; }
  .kiosk-badge { font-size:0.68rem;font-weight:700;padding:3px 8px;border-radius:6px; }
  .kiosk-badge.active   { background:var(--emeraldbg);color:var(--emerald); }
  .kiosk-badge.inactive { background:var(--surface2);color:var(--muted2); }


  .activity-panel { background:var(--bg3);border:1px solid var(--border2);border-radius:14px;padding:1.25rem;margin-bottom:1.5rem; }
  .activity-list  { display:flex;flex-direction:column;gap:6px; }
  .activity-item  { display:flex;align-items:center;gap:12px;padding:10px 12px;background:var(--surface);border-radius:10px;transition:background 0.15s; }
  .activity-item:hover { background:var(--surface2); }
  .activity-icon  { width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
  .activity-icon svg { width:14px;height:14px;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
  .ai-entry       { background:var(--emeraldbg2); }
  .ai-entry svg   { stroke:var(--emerald); }
  .ai-exit        { background:rgba(248,113,113,0.12); }
  .ai-exit svg    { stroke:#f87171; }
  .ai-payment     { background:rgba(96,165,250,0.12); }
  .ai-payment svg { stroke:#60a5fa; }
  .ai-reservation { background:rgba(251,191,36,0.1); }
  .ai-reservation svg { stroke:#fbbf24; }
  .activity-info  { flex:1;min-width:0; }
  .activity-main  { font-size:0.82rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
  .activity-main span { color:var(--emerald); }
  .activity-sub   { font-size:0.72rem;color:var(--muted);font-weight:500;margin-top:1px; }
  .activity-time  { font-size:0.7rem;color:var(--muted2);font-weight:600;white-space:nowrap;flex-shrink:0; }


  .live-badge {
    display:inline-flex;align-items:center;gap:5px;
    background:var(--emeraldbg);border:1px solid rgba(52,211,153,0.2);
    border-radius:20px;padding:3px 10px;font-size:0.68rem;font-weight:700;color:var(--emerald);
  }
  .live-badge::before { content:'';width:6px;height:6px;background:var(--emerald);border-radius:50%;animation:livePulse 1.8s infinite; }


  @keyframes livePulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.3;transform:scale(0.7)} }


  .quick-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:1.5rem; }
  .quick-link { background:var(--bg3);border:1px solid var(--border2);border-radius:12px;padding:1rem;text-decoration:none;display:flex;align-items:center;gap:10px;transition:background 0.15s,border-color 0.15s; }
  .quick-link:hover { background:var(--surface);border-color:rgba(52,211,153,0.2); }
  .quick-link-icon { width:34px;height:34px;border-radius:9px;background:var(--emeraldbg2);display:flex;align-items:center;justify-content:center;flex-shrink:0; }
  .quick-link-icon svg { width:15px;height:15px;stroke:var(--emerald);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
  .quick-link-text { font-size:0.82rem;font-weight:700;color:var(--text); }
  .quick-link-sub  { font-size:0.68rem;color:var(--muted2);font-weight:500;margin-top:1px; }


  @media (max-width:1100px) {
    .stat-grid  { grid-template-columns:repeat(2,1fr); }
    .mid-grid   { grid-template-columns:1fr 1fr; }
    .quick-grid { grid-template-columns:repeat(2,1fr); }
  }
</style>


<!-- Page header -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
  <div>
    <h1 style="font-size:1.4rem;font-weight:800;color:var(--text);letter-spacing:-0.02em;">Dashboard</h1>
    <p style="font-size:0.82rem;color:var(--muted);margin-top:3px;font-weight:500;">
      Welcome back, <?= htmlspecialchars($_SESSION['admin_name']) ?>.
      Here's what's happening today.
    </p>
  </div>
  <span class="live-badge">Live</span>
</div>


<!-- ── SLOT OVERVIEW ── -->
<div class="dash-section-title">
  <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>
  Slot overview
</div>


<div class="stat-grid">
  <div class="stat-card sc-green">
    <div class="sc-label">
      <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      Available
    </div>
    <div class="sc-value" id="val-available"><?= (int)$slotRow['available'] ?></div>
    <div class="sc-sub">of <?= (int)$slotRow['total'] ?> total slots</div>
    <div class="sc-accent"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
  </div>
  <div class="stat-card sc-red">
    <div class="sc-label">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      Occupied
    </div>
    <div class="sc-value" id="val-occupied"><?= (int)$slotRow['occupied'] ?></div>
    <div class="sc-sub">slots in use</div>
    <div class="sc-accent"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
  </div>
  <div class="stat-card sc-yellow">
    <div class="sc-label">
      <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Reserved
    </div>
    <div class="sc-value" id="val-reserved"><?= (int)$slotRow['reserved'] ?></div>
    <div class="sc-sub">pre-booked slots</div>
    <div class="sc-accent"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
  </div>
  <div class="stat-card sc-neutral">
    <div class="sc-label">
      <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      Maintenance
    </div>
    <div class="sc-value" id="val-maintenance"><?= (int)$slotRow['maintenance'] ?></div>
    <div class="sc-sub">slots unavailable</div>
    <div class="sc-accent"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg></div>
  </div>
</div>


<!-- ── TODAY'S ACTIVITY ── -->
<div class="dash-section-title" style="margin-top:0.25rem;">
  <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
  Today's activity
</div>


<div class="mid-grid">
  <div class="stat-card sc-green" style="border-left-width:3px;">
    <div class="sc-label">
      <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      Today's revenue
    </div>
    <div class="sc-value" id="val-revenue" style="font-size:1.5rem;">₱<?= number_format($today_revenue, 2) ?></div>
    <div class="sc-sub" id="val-revenue-sub">
      ₱<?= number_format($paid_revenue, 2) ?> paid
      + ₱<?= number_format($live_fee_sum, 2) ?> live
    </div>
    <div class="sc-accent"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
  </div>
  <div class="stat-card sc-blue">
    <div class="sc-label">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
      Vehicles inside
    </div>
    <div class="sc-value" id="val-vehicles"><?= $vehicles_inside ?></div>
    <div class="sc-sub" id="val-unpaid-sub"><?= $unpaid_active ?> unpaid active sessions</div>
    <div class="sc-accent"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
  </div>
  <div class="stat-card sc-purple">
    <div class="sc-label">
      <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Registered users
    </div>
    <div class="sc-value" id="val-users"><?= $total_users ?></div>
    <div class="sc-sub" id="val-users-sub"><?= $verified_users ?> verified · <?= $unverified_users ?> pending email</div>
    <div class="sc-sub" id="val-users-res-today" style="margin-top:4px;"><?= $today_reservations ?> reservation<?= $today_reservations !== 1 ? 's' : '' ?> today</div>
    <div class="sc-accent"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
  </div>
</div>


<!-- ── FLOOR SUMMARY + KIOSK STATUS ── -->
<div class="bottom-grid">


  <div class="panel">
    <div class="panel-title">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>
      Live occupancy per floor
    </div>
    <div id="floorBars">
      <?php foreach ($floor_summary as $f):
        $total = max(1, (int)$f['total']); ?>
        <div class="floor-item">
          <div class="floor-header">
            <span class="floor-label">Floor <?= $f['floor'] ?></span>
            <div class="floor-counts">
              <span class="floor-count-item fc-available"><?= (int)$f['available'] ?> avail</span>
              <span class="floor-count-item fc-occupied"><?= (int)$f['occupied'] ?> occ</span>
              <?php if ((int)$f['reserved'] > 0): ?>
                <span class="floor-count-item fc-reserved"><?= (int)$f['reserved'] ?> res</span>
              <?php endif; ?>
              <?php if ((int)$f['maintenance'] > 0): ?>
                <span class="floor-count-item fc-maintenance"><?= (int)$f['maintenance'] ?> maint</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="floor-bar">
            <div class="floor-bar-seg fbs-occupied"    style="width:<?= round((int)$f['occupied']   / $total * 100) ?>%"></div>
            <div class="floor-bar-seg fbs-reserved"    style="width:<?= round((int)$f['reserved']   / $total * 100) ?>%"></div>
            <div class="floor-bar-seg fbs-maintenance" style="width:<?= round((int)$f['maintenance'] / $total * 100) ?>%"></div>
            <div class="floor-bar-seg fbs-available"   style="width:<?= round((int)$f['available']   / $total * 100) ?>%"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>


  <div class="panel">
    <div class="panel-title">
      <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
      Kiosk status
    </div>
    <?php
    $kioskItems = [
      ['name' => 'Entrance kiosk', 'time' => $last_entry,   'label' => 'Last car entry',
       'icon' => '<path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>'],
      ['name' => 'Exit kiosk',     'time' => $last_exit,    'label' => 'Last car exit',
       'icon' => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>'],
      ['name' => 'Payment kiosk',  'time' => $last_payment, 'label' => 'Last payment',
       'icon' => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>'],
    ];
    foreach ($kioskItems as $k):
      $isActive = $k['time'] && (time() - strtotime($k['time'])) < 3600;
    ?>
    <div class="kiosk-item">
      <div class="kiosk-dot <?= $isActive ? 'active' : 'inactive' ?>"></div>
      <div class="kiosk-info">
        <div class="kiosk-name"><?= $k['name'] ?></div>
        <div class="kiosk-time">
          <?= $k['label'] ?>:
          <?= $k['time']
              ? date('M d, h:i A', strtotime($k['time'])) . ' (' . timeAgo($k['time']) . ')'
              : 'No activity yet' ?>
        </div>
      </div>
      <span class="kiosk-badge <?= $isActive ? 'active' : 'inactive' ?>">
        <?= $isActive ? 'Active' : 'Idle' ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
</div>


<!-- ── QUICK ACTIONS ── -->
<div class="dash-section-title">
  <svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
  Quick actions
</div>


<div class="quick-grid">
  <a href="users.php" class="quick-link">
    <div class="quick-link-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></div>
    <div>
      <div class="quick-link-text">Manage users</div>
      <div class="quick-link-sub"><?= $total_users ?> registered · <?= $verified_users ?> verified</div>
    </div>
  </a>
  <a href="slots.php" class="quick-link">
    <div class="quick-link-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg></div>
    <div>
      <div class="quick-link-text">Slot management</div>
      <div class="quick-link-sub"><?= (int)$slotRow['available'] ?> available</div>
    </div>
  </a>
  <a href="reservations.php" class="quick-link">
    <div class="quick-link-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
    <div>
      <div class="quick-link-text">Reservations</div>
      <div class="quick-link-sub"><?= (int)$slotRow['reserved'] ?> active</div>
    </div>
  </a>
  <a href="transactions.php" class="quick-link">
    <div class="quick-link-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
    <div>
      <div class="quick-link-text">Transactions</div>
      <div class="quick-link-sub">₱<?= number_format($today_revenue, 2) ?> today</div>
    </div>
  </a>
</div>


<!-- ── RECENT ACTIVITY ── -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
  <div class="dash-section-title" style="margin-bottom:0;">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    Recent activity
  </div>
  <span class="live-badge">Auto-refresh</span>
</div>


<div class="activity-panel">
  <div class="activity-list" id="activityFeed">
    <?php foreach ($recent_activity as $act):
      $iconClass = ['entry'=>'ai-entry','exit'=>'ai-exit','payment'=>'ai-payment','reservation'=>'ai-reservation'][$act['type']] ?? 'ai-entry';
      $iconSvg   = [
        'entry'       => '<path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'exit'        => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'payment'     => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>',
        'reservation' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
      ][$act['type']] ?? '';
    ?>
    <div class="activity-item">
      <div class="activity-icon <?= $iconClass ?>">
        <svg viewBox="0 0 24 24"><?= $iconSvg ?></svg>
      </div>
      <div class="activity-info">
        <div class="activity-main">
          <span><?= htmlspecialchars($act['plate'] ?? '—') ?></span>
          — <?= htmlspecialchars($act['action']) ?>
        </div>
        <div class="activity-sub">
          Slot <?= htmlspecialchars($act['slot'] ?? '—') ?>, Floor <?= htmlspecialchars($act['floor'] ?? '—') ?>
          <?php if ($act['car_type'] || $act['car_color']): ?>
            · <?= htmlspecialchars(trim(($act['car_color'] ?? '') . ' ' . ($act['car_type'] ?? ''))) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="activity-time"><?= timeAgo($act['event_time']) ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($recent_activity)): ?>
      <div style="text-align:center;padding:2rem;color:var(--muted2);font-size:0.82rem;font-weight:600;">No activity yet today.</div>
    <?php endif; ?>
  </div>
</div>


<!-- ── REAL-TIME POLLING ── -->
<script>
(function () {
  'use strict';


  function pad(n) { return String(n).padStart(2, '0'); }


  function timeAgoJS(dateStr) {
    if (!dateStr) return 'Never';
    var diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (diff < 60)    return 'Just now';
    if (diff < 3600)  return Math.floor(diff / 60)   + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600)  + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }


  function fmtMoney(v) {
    return '₱' + parseFloat(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }


  function updateStats(d) {
    // Slot cards
    document.getElementById('val-available').textContent   = d.slots.available;
    document.getElementById('val-occupied').textContent    = d.slots.occupied;
    document.getElementById('val-reserved').textContent    = d.slots.reserved;
    document.getElementById('val-maintenance').textContent = d.slots.maintenance;


    // Activity cards
    document.getElementById('val-vehicles').textContent = d.vehicles_inside;
    document.getElementById('val-users').textContent = d.total_users;
    var usub = document.getElementById('val-users-sub');
    if (usub && d.verified_users !== undefined && d.unverified_users !== undefined) {
      usub.textContent = d.verified_users + ' verified · ' + d.unverified_users + ' pending email';
    }
    var urt = document.getElementById('val-users-res-today');
    if (urt && d.today_reservations !== undefined) {
      urt.textContent = d.today_reservations + ' reservation' + (d.today_reservations !== 1 ? 's' : '') + ' today';
    }


    // Revenue — shows confirmed + live breakdown
    document.getElementById('val-revenue').textContent     = fmtMoney(d.today_revenue);
    document.getElementById('val-revenue-sub').textContent =
      fmtMoney(d.paid_revenue) + ' paid + ' + fmtMoney(d.live_fee_sum) + ' live';
    document.getElementById('val-unpaid-sub').textContent  =
      d.unpaid_active + ' unpaid active session' + (d.unpaid_active !== 1 ? 's' : '');


    // Floor bars
    var floorHTML = '';
    d.floor_summary.forEach(function (f) {
      var t = Math.max(1, f.total);
      floorHTML += '<div class="floor-item">';
      floorHTML += '<div class="floor-header"><span class="floor-label">Floor ' + f.floor + '</span>';
      floorHTML += '<div class="floor-counts">';
      floorHTML += '<span class="floor-count-item fc-available">' + f.available + ' avail</span>';
      floorHTML += '<span class="floor-count-item fc-occupied">'  + f.occupied  + ' occ</span>';
      if (f.reserved    > 0) floorHTML += '<span class="floor-count-item fc-reserved">'    + f.reserved    + ' res</span>';
      if (f.maintenance > 0) floorHTML += '<span class="floor-count-item fc-maintenance">' + f.maintenance + ' maint</span>';
      floorHTML += '</div></div><div class="floor-bar">';
      floorHTML += '<div class="floor-bar-seg fbs-occupied"    style="width:' + Math.round(f.occupied    / t * 100) + '%"></div>';
      floorHTML += '<div class="floor-bar-seg fbs-reserved"    style="width:' + Math.round(f.reserved    / t * 100) + '%"></div>';
      floorHTML += '<div class="floor-bar-seg fbs-maintenance" style="width:' + Math.round(f.maintenance / t * 100) + '%"></div>';
      floorHTML += '<div class="floor-bar-seg fbs-available"   style="width:' + Math.round(f.available   / t * 100) + '%"></div>';
      floorHTML += '</div></div>';
    });
    document.getElementById('floorBars').innerHTML = floorHTML;


    // Activity feed
    var icons = {
      entry:       { cls: 'ai-entry',       svg: '<path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>' },
      exit:        { cls: 'ai-exit',        svg: '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>' },
      payment:     { cls: 'ai-payment',     svg: '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>' },
      reservation: { cls: 'ai-reservation', svg: '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>' },
    };


    var feedHTML = '';
    if (!d.recent_activity || d.recent_activity.length === 0) {
      feedHTML = '<div style="text-align:center;padding:2rem;color:var(--muted2);font-size:0.82rem;font-weight:600;">No activity yet today.</div>';
    } else {
      d.recent_activity.forEach(function (act) {
        var ic = icons[act.type] || icons.entry;
        var carDetail = (act.car_color || act.car_type)
          ? ' · ' + [act.car_color, act.car_type].filter(Boolean).join(' ') : '';
        feedHTML += '<div class="activity-item">';
        feedHTML += '<div class="activity-icon ' + ic.cls + '"><svg viewBox="0 0 24 24">' + ic.svg + '</svg></div>';
        feedHTML += '<div class="activity-info">';
        feedHTML += '<div class="activity-main"><span>' + (act.plate || '—') + '</span> — ' + act.action + '</div>';
        feedHTML += '<div class="activity-sub">Slot ' + (act.slot || '—') + ', Floor ' + (act.floor || '—') + carDetail + '</div>';
        feedHTML += '</div>';
        feedHTML += '<div class="activity-time">' + timeAgoJS(act.event_time) + '</div>';
        feedHTML += '</div>';
      });
    }
    document.getElementById('activityFeed').innerHTML = feedHTML;
  }


  function poll() {
    fetch('../ajax/get-dashboard-stats.php')
      .then(function (r) { return r.json(); })
      .then(function (d) { if (!d.error) updateStats(d); })
      .catch(function () {});
  }


  setInterval(poll, 5000);
}());
</script>


<?php require_once 'includes/footer.php'; ?>

