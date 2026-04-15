<?php
session_start();
require_once 'includes/auth_check.php';
require_once '../config/db.php';
require_once 'includes/slot_map_state.php';
require_once '../config/slot_sort.php';
require_once 'includes/csrf.php';


$validStatuses = ['available', 'occupied', 'reserved', 'maintenance'];
$flash = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);


$floor = (int)($_GET['floor'] ?? 1);
if ($floor < 1 || $floor > 3) {
    $floor = 1;
}


$selectedDate = trim($_GET['date'] ?? '');
if ($selectedDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
$tsSel = strtotime($selectedDate . ' 12:00:00');
if ($tsSel === false) {
    $selectedDate = date('Y-m-d');
    $tsSel = strtotime($selectedDate . ' 12:00:00');
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_validate($_POST['csrf'] ?? null)) {
        $_SESSION['admin_flash'] = 'Invalid session token.';
    } else {
        $sid    = (int)($_POST['slot_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if ($sid > 0 && in_array($status, $validStatuses, true)) {
            $u = $pdo->prepare("UPDATE parking_slots SET status = ? WHERE id = ?");
            $u->execute([$status, $sid]);
            $_SESSION['admin_flash'] = 'Slot updated (physical status in database).';
        } else {
            $_SESSION['admin_flash'] = 'Invalid slot or status.';
        }
    }
    $redirDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['map_date'] ?? ''))
        ? $_POST['map_date']
        : $selectedDate;
    header('Location: slots.php?floor=' . (int)($_POST['floor'] ?? $floor) . '&date=' . urlencode($redirDate));
    exit;
}


$mapState = parky_slot_map_state_for_date($pdo, $selectedDate);
$allSlots = $mapState['allSlots'];
$dispAvail = $mapState['counts']['available'];
$dispRes = $mapState['counts']['reserved'];
$dispOcc = $mapState['counts']['occupied'];
$dispMaint = $mapState['counts']['maintenance'];


$slotsByFloor = [1 => [], 2 => [], 3 => []];
foreach ($allSlots as $s) {
    $slotsByFloor[(int) $s['floor']][] = $s;
}


// Calendar: month of selected date
$calY = (int)date('Y', $tsSel);
$calM = (int)date('n', $tsSel);
$firstOfMonth = mktime(0, 0, 0, $calM, 1, $calY);
$daysInMonth  = (int)date('t', $firstOfMonth);
$weekdayFirst = (int)date('w', $firstOfMonth);
$leadingBlank = ($weekdayFirst + 6) % 7;


$monthStartStr = sprintf('%04d-%02d-01', $calY, $calM);
$monthEndStr   = date('Y-m-t', $firstOfMonth);


$busyByDay = [];
$busyStmt = $pdo->prepare("
    SELECT DATE(arrival_time) AS d, COUNT(*) AS c
    FROM reservations
    WHERE DATE(arrival_time) BETWEEN ? AND ?
    AND status IN ('pending','confirmed','active')
    GROUP BY DATE(arrival_time)
");
$busyStmt->execute([$monthStartStr, $monthEndStr]);
while ($row = $busyStmt->fetch(PDO::FETCH_ASSOC)) {
    $busyByDay[$row['d']] = (int)$row['c'];
}


$prevMonthTs = strtotime($monthStartStr . ' -1 day');
$nextMonthTs = strtotime($monthEndStr . ' +1 day');
$prevMonthUrl = 'slots.php?floor=' . $floor . '&date=' . date('Y-m-d', $prevMonthTs);
$nextMonthUrl = 'slots.php?floor=' . $floor . '&date=' . date('Y-m-d', $nextMonthTs);


$dayBefore = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$dayAfter  = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$todayStr  = date('Y-m-d');


$dateLabel = date('D, M j, Y', $tsSel);
$badgeLabel = 'Showing: ' . $dateLabel;


/** Base URL for GET navigation from this page (avoids ambiguous empty form action). */
$slotsDateUrlPrefix = 'slots.php?floor=' . $floor . '&date=';


require_once 'includes/header.php';


$qFloorDate = function (int $f) use ($selectedDate) {
    return 'slots.php?floor=' . $f . '&date=' . urlencode($selectedDate);
};
?>


<style>
  .admin-slot-page {
    --slot-available:      #34d399;
    --slot-available-bg:   rgba(52,211,153,0.10);
    --slot-available-bdr:  rgba(52,211,153,0.30);
    --slot-reserved:       #fbbf24;
    --slot-reserved-bg:    rgba(251,191,36,0.10);
    --slot-reserved-bdr:   rgba(251,191,36,0.35);
    --slot-occupied:       #f87171;
    --slot-occupied-bg:    rgba(248,113,113,0.10);
    --slot-occupied-bdr:   rgba(248,113,113,0.30);
    --slot-maintenance:    #6b7280;
    --slot-maintenance-bg: rgba(107,114,128,0.12);
    --slot-maintenance-bdr:rgba(107,114,128,0.30);
    --as-bg2:  #1a1f1a;
    --as-bg3:  #1f251f;
    --as-border: rgba(255,255,255,0.06);
    --as-border2: rgba(255,255,255,0.10);
    --as-surface: #252b25;
    --as-muted: #7a907a;
    --as-muted2: #556655;
    --as-emerald: #34d399;
    --as-emeraldbg2: rgba(52,211,153,0.15);
    --as-emeraldborder: rgba(52,211,153,0.25);
  }


  .admin-slot-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 1.25rem;
    align-items: start;
    max-width: 1220px;
  }
  @media (max-width: 960px) {
    .admin-slot-layout { grid-template-columns: 1fr; }
  }


  .admin-slot-page .calendar-card {
    background: var(--as-bg2);
    border: 1px solid var(--as-border2);
    border-radius: 20px;
    overflow: hidden;
    position: sticky;
    top: calc(var(--topbar-h, 58px) + 1rem);
  }


  .admin-slot-page .calendar-card-header {
    padding: 1rem 1rem 0.75rem;
    border-bottom: 1px solid var(--as-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
  }


  .admin-slot-page .calendar-card-title {
    font-size: 0.85rem;
    font-weight: 800;
    color: var(--text);
  }


  .admin-slot-page .cal-nav {
    display: flex;
    gap: 6px;
    align-items: center;
  }


  .admin-slot-page .cal-nav a {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: var(--as-surface);
    border: 1px solid var(--as-border2);
    color: var(--as-muted);
    text-decoration: none;
    font-size: 1rem;
    font-weight: 800;
    line-height: 1;
  }
  .admin-slot-page .cal-nav a:hover { color: var(--as-emerald); border-color: var(--as-emeraldborder); }


  .admin-slot-page .calendar-sub {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--as-border);
    background: var(--as-bg3);
  }


  .admin-slot-page .calendar-sub form {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }


  .admin-slot-page .cal-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }


  .admin-slot-page .cal-select,
  .admin-slot-page .cal-date-input {
    width: 100%;
    background: var(--as-surface);
    border: 1px solid var(--as-border2);
    border-radius: 10px;
    padding: 8px 10px;
    font-family: 'Nunito', sans-serif;
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--text);
  }


  .admin-slot-page .btn-cal-go {
    width: 100%;
    margin-top: 4px;
    padding: 8px;
    border-radius: 10px;
    border: none;
    background: var(--emerald2, #10b981);
    color: #0a1a12;
    font-family: 'Nunito', sans-serif;
    font-size: 0.78rem;
    font-weight: 800;
    cursor: pointer;
  }


  .admin-slot-page .cal-grid-wrap {
    padding: 0.75rem 0.75rem 1rem;
  }


  .admin-slot-page .cal-dow {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
    margin-bottom: 4px;
  }


  .admin-slot-page .cal-dow span {
    text-align: center;
    font-size: 0.62rem;
    font-weight: 800;
    color: var(--as-muted2);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }


  .admin-slot-page .cal-cells {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
  }


  .admin-slot-page .cal-cell {
    aspect-ratio: 1;
    max-height: 36px;
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    font-weight: 800;
    text-decoration: none;
    color: var(--as-muted);
    background: transparent;
    border: 1px solid transparent;
    position: relative;
    padding: 0;
    cursor: pointer;
    font-family: 'Nunito', sans-serif;
  }


  .admin-slot-page .cal-cell.empty { visibility: hidden; pointer-events: none; }


  .admin-slot-page .cal-cell:hover:not(.selected) {
    background: var(--as-surface);
    color: var(--text);
  }


  .admin-slot-page .cal-cell.selected {
    background: var(--as-emeraldbg2);
    border-color: var(--as-emeraldborder);
    color: var(--as-emerald);
  }


  .admin-slot-page .cal-cell.today:not(.selected) {
    box-shadow: inset 0 0 0 1px rgba(52,211,153,0.35);
  }


  .admin-slot-page .cal-dot {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: var(--slot-reserved);
    position: absolute;
    bottom: 3px;
    opacity: 0.85;
  }


  .admin-slot-page .cal-quick {
    padding: 0 1rem 1rem;
  }


  .admin-slot-page .cal-quick-inner {
    display: flex;
    width: 100%;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--as-border2);
    background: var(--as-bg3);
    box-shadow: 0 1px 0 rgba(0,0,0,0.2);
  }


  .admin-slot-page .cal-quick-btn {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 40px;
    padding: 0 10px;
    font-family: 'Nunito', sans-serif;
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0.02em;
    color: var(--as-muted);
    text-decoration: none;
    background: var(--as-surface);
    border: none;
    border-right: 1px solid var(--as-border2);
    white-space: nowrap;
    transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
  }


  .admin-slot-page .cal-quick-btn:last-child {
    border-right: none;
  }


  .admin-slot-page .cal-quick-btn svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    stroke: currentColor;
    fill: none;
    stroke-width: 2.2;
    stroke-linecap: round;
    stroke-linejoin: round;
    opacity: 0.9;
  }


  .admin-slot-page .cal-quick-btn:hover {
    background: var(--as-emeraldbg2);
    color: var(--as-emerald);
    z-index: 1;
    box-shadow: inset 0 0 0 1px var(--as-emeraldborder);
  }


  .admin-slot-page .cal-quick-btn:focus-visible {
    outline: 2px solid var(--as-emerald);
    outline-offset: -2px;
    z-index: 2;
  }


  .admin-slot-page .cal-quick-btn.is-today-pill {
    background: rgba(52, 211, 153, 0.1);
    color: var(--as-emerald);
  }


  .admin-slot-page .cal-quick-btn.is-today-pill:hover {
    background: var(--as-emeraldbg2);
  }


  .admin-slot-page .map-panel {
    background: var(--as-bg2);
    border: 1px solid var(--as-border2);
    border-radius: 20px;
    overflow: hidden;
    min-width: 0;
  }


  .admin-slot-page .map-panel-header {
    padding: 1.25rem 1.5rem 1rem;
    border-bottom: 1px solid var(--as-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
  }


  .admin-slot-page .map-panel-title {
    font-size: 1rem;
    font-weight: 800;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
  }


  .admin-slot-page .map-panel-title svg {
    width: 16px;
    height: 16px;
    stroke: var(--as-emerald);
    fill: none;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
  }


  .admin-slot-page .map-date-badge {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--as-emerald);
    background: rgba(52,211,153,0.08);
    border: 1px solid var(--as-emeraldborder);
    border-radius: 20px;
    padding: 4px 12px;
    max-width: 100%;
    text-align: center;
  }


  .admin-slot-page .map-header-badges {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
  }


  .admin-slot-page .map-live-pill {
    font-size: 0.68rem;
    font-weight: 700;
    color: var(--as-muted);
    background: var(--as-surface);
    border: 1px solid var(--as-border2);
    border-radius: 20px;
    padding: 4px 10px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }


  .admin-slot-page .map-live-pill .map-live-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--as-emerald);
    box-shadow: 0 0 0 2px rgba(52,211,153,0.25);
    animation: map-live-pulse 2s ease-in-out infinite;
  }


  .admin-slot-page .map-live-pill.is-paused .map-live-dot {
    animation: none;
    background: var(--as-muted2);
    box-shadow: none;
  }


  @keyframes map-live-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.45; }
  }


  .admin-slot-page .legend {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    padding: 0.75rem 1.5rem;
    border-bottom: 1px solid var(--as-border);
    background: var(--as-bg3);
    font-size: 0.68rem;
    color: var(--as-muted2);
    font-weight: 600;
  }


  .admin-slot-page .legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--as-muted);
  }


  .admin-slot-page .legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 3px;
  }


  .admin-slot-page .legend-dot.available   { background: var(--slot-available); }
  .admin-slot-page .legend-dot.reserved    { background: var(--slot-reserved); }
  .admin-slot-page .legend-dot.occupied    { background: var(--slot-occupied); }
  .admin-slot-page .legend-dot.maintenance { background: var(--slot-maintenance); }


  .admin-slot-page .floor-tabs {
    display: flex;
    gap: 0.5rem;
    padding: 1rem 1.5rem 0;
    flex-wrap: wrap;
  }


  .admin-slot-page .floor-tab {
    background: var(--as-surface);
    border: 1px solid var(--as-border2);
    border-radius: 10px;
    padding: 7px 18px;
    font-family: 'Nunito', sans-serif;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--as-muted);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-block;
  }


  .admin-slot-page .floor-tab:hover {
    color: var(--text);
    border-color: rgba(255,255,255,0.15);
  }


  .admin-slot-page .floor-tab.active {
    background: var(--as-emeraldbg2);
    border-color: var(--as-emeraldborder);
    color: var(--as-emerald);
  }


  .admin-slot-page .floor-map {
    display: none;
    padding: 1rem 1.5rem 1.5rem;
  }


  .admin-slot-page .floor-map.active { display: block; }


  .admin-slot-page .row-label {
    font-size: 0.65rem;
    font-weight: 800;
    color: var(--as-muted2);
    letter-spacing: 0.06em;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    margin-bottom: 0.4rem;
    gap: 8px;
  }


  .admin-slot-page .row-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--as-border);
  }


  .admin-slot-page .slots-row {
    display: grid;
    grid-template-columns: repeat(10, 1fr);
    gap: 6px;
    margin-bottom: 0.85rem;
  }


  @media (max-width: 700px) {
    .admin-slot-page .slots-row { grid-template-columns: repeat(5, 1fr); }
  }


  .admin-slot-page .slot-btn {
    aspect-ratio: 1;
    border-radius: 9px;
    border: 1.5px solid;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-family: 'Nunito', sans-serif;
    font-size: 0.65rem;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.18s;
    line-height: 1.2;
    padding: 2px;
    width: 100%;
  }


  .admin-slot-page .slot-btn.available {
    background: var(--slot-available-bg);
    border-color: var(--slot-available-bdr);
    color: var(--slot-available);
  }


  .admin-slot-page .slot-btn.reserved {
    background: var(--slot-reserved-bg);
    border-color: var(--slot-reserved-bdr);
    color: var(--slot-reserved);
  }


  .admin-slot-page .slot-btn.occupied {
    background: var(--slot-occupied-bg);
    border-color: var(--slot-occupied-bdr);
    color: var(--slot-occupied);
  }


  .admin-slot-page .slot-btn.maintenance {
    background: var(--slot-maintenance-bg);
    border-color: var(--slot-maintenance-bdr);
    color: var(--slot-maintenance);
  }


  .admin-slot-page .slot-btn.display-diff {
    box-shadow: inset 0 0 0 2px rgba(96,165,250,0.35);
  }


  .admin-slot-page .slot-btn:hover {
    transform: translateY(-2px);
    filter: brightness(1.08);
  }


  .admin-slot-page .slot-summary {
    display: flex;
    gap: 1rem;
    padding: 0.75rem 1.5rem;
    border-top: 1px solid var(--as-border);
    background: var(--as-bg3);
    flex-wrap: wrap;
  }


  .admin-slot-page .slot-count {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--as-muted);
  }


  .admin-slot-page .slot-count span { font-weight: 800; }


  .admin-slot-page .slot-count.green  span { color: var(--slot-available); }
  .admin-slot-page .slot-count.yellow span { color: var(--slot-reserved); }
  .admin-slot-page .slot-count.red    span { color: var(--slot-occupied); }
  .admin-slot-page .slot-count.gray   span { color: var(--slot-maintenance); }
</style>


<div class="admin-slot-page">
  <?php if ($flash): ?><div class="admin-flash ok" style="max-width:1220px;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>


  <div class="admin-page-head" style="max-width:1220px;">
    <div>
      <h1>Slot management</h1>
      <p class="sub">Pick any calendar day to see that day’s map (bookings + physical status). Click a slot to cycle its stored physical status.</p>
    </div>
  </div>


  <form id="slotForm" method="post" action="slots.php" style="display:none;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
    <input type="hidden" name="floor" value="<?= $floor ?>">
    <input type="hidden" name="map_date" value="<?= htmlspecialchars($selectedDate) ?>">
    <input type="hidden" name="slot_id" id="sf_slot_id">
    <input type="hidden" name="status" id="sf_status">
  </form>


  <div class="admin-slot-layout">
    <div class="calendar-card">
      <div class="calendar-card-header">
        <div class="calendar-card-title"><?= htmlspecialchars(date('F Y', $firstOfMonth)) ?></div>
        <div class="cal-nav">
          <a href="<?= htmlspecialchars($prevMonthUrl) ?>" title="Previous month">‹</a>
          <a href="<?= htmlspecialchars($nextMonthUrl) ?>" title="Next month">›</a>
        </div>
      </div>


      <div class="calendar-sub">
        <form method="get" action="slots.php" id="form_month_jump">
          <input type="hidden" name="floor" value="<?= $floor ?>">
          <input type="hidden" name="date" id="ym_jump" value="<?= htmlspecialchars($monthStartStr) ?>">
          <div class="cal-row">
            <select class="cal-select" id="ym_y" aria-label="Year">
              <?php for ($yy = $calY - 2; $yy <= $calY + 3; $yy++): ?>
                <option value="<?= $yy ?>" <?= $yy === $calY ? 'selected' : '' ?>><?= $yy ?></option>
              <?php endfor; ?>
            </select>
            <select class="cal-select" id="ym_m" aria-label="Month">
              <?php for ($mm = 1; $mm <= 12; $mm++): ?>
                <option value="<?= $mm ?>" <?= $mm === $calM ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $mm, 1)) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <button type="button" class="btn-cal-go" id="ym_apply">Go to month</button>
        </form>
        <form method="get" action="slots.php" id="form_date_jump" style="margin-top:10px;">
          <input type="hidden" name="floor" value="<?= $floor ?>">
          <label class="cal-row" style="grid-template-columns:1fr;margin:0;">
            <span style="font-size:0.65rem;font-weight:700;color:var(--muted2);text-transform:uppercase;">Jump to date</span>
            <input class="cal-date-input" type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" required aria-label="Select date to show on map" title="Choosing a date reloads the map for that day">
          </label>
          <button type="submit" class="btn-cal-go">Apply</button>
        </form>
      </div>


      <div class="cal-grid-wrap">
        <div class="cal-dow">
          <?php foreach (['Mo','Tu','We','Th','Fr','Sa','Su'] as $dow): ?>
            <span><?= $dow ?></span>
          <?php endforeach; ?>
        </div>
        <div class="cal-cells">
          <?php for ($i = 0; $i < $leadingBlank; $i++): ?>
            <span class="cal-cell empty"></span>
          <?php endfor; ?>
          <?php for ($d = 1; $d <= $daysInMonth; $d++):
              $cellDate = sprintf('%04d-%02d-%02d', $calY, $calM, $d);
              $isSel = ($cellDate === $selectedDate);
              $isToday = ($cellDate === $todayStr);
              $busy = $busyByDay[$cellDate] ?? 0;
              $href = 'slots.php?floor=' . $floor . '&date=' . urlencode($cellDate);
              ?>
            <a href="<?= htmlspecialchars($href) ?>"
               class="cal-cell<?= $isSel ? ' selected' : '' ?><?= $isToday ? ' today' : '' ?>">
              <?= $d ?>
              <?php if ($busy > 0): ?><span class="cal-dot" title="<?= (int)$busy ?> booking(s)"></span><?php endif; ?>
            </a>
          <?php endfor; ?>
        </div>
      </div>


      <div class="cal-quick" role="group" aria-label="Previous, today, next day">
        <div class="cal-quick-inner">
          <a class="cal-quick-btn" href="<?= htmlspecialchars('slots.php?floor=' . $floor . '&date=' . urlencode($dayBefore)) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            <span>Prev</span>
          </a>
          <a class="cal-quick-btn<?= ($selectedDate === $todayStr) ? ' is-today-pill' : '' ?>" href="<?= htmlspecialchars('slots.php?floor=' . $floor . '&date=' . urlencode($todayStr)) ?>">
            Today
          </a>
          <a class="cal-quick-btn" href="<?= htmlspecialchars('slots.php?floor=' . $floor . '&date=' . urlencode($dayAfter)) ?>">
            <span>Next</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
          </a>
        </div>
      </div>
    </div>


    <div class="map-panel">
      <div class="map-panel-header">
        <div class="map-panel-title">
          <svg viewBox="0 0 24 24">
            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
            <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
          </svg>
          Slot map
        </div>
        <div class="map-header-badges">
          <div class="map-date-badge"><?= htmlspecialchars($badgeLabel) ?></div>
          <div class="map-live-pill" id="slot_map_live_pill" title="Map refreshes automatically when slots or bookings change">
            <span class="map-live-dot" aria-hidden="true"></span>
            <span id="slot_map_live_label">Live</span>
          </div>
        </div>
      </div>


      <div class="legend">
        <div class="legend-item"><div class="legend-dot available"></div> Available</div>
        <div class="legend-item"><div class="legend-dot reserved"></div> Reserved</div>
        <div class="legend-item"><div class="legend-dot occupied"></div> Occupied</div>
        <div class="legend-item"><div class="legend-dot maintenance"></div> Maintenance</div>
      </div>


      <div class="floor-tabs">
        <?php for ($f = 1; $f <= 3; $f++): ?>
          <a href="<?= htmlspecialchars($qFloorDate($f)) ?>" class="floor-tab <?= $floor === $f ? 'active' : '' ?>">Floor <?= $f ?></a>
        <?php endfor; ?>
      </div>


      <?php foreach ([1, 2, 3] as $f): ?>
        <div class="floor-map <?= $floor === $f ? 'active' : '' ?>" id="floor-map-<?= $f ?>">
          <?php
          $rows = [];
          foreach ($slotsByFloor[$f] as $s) {
              preg_match('/^\d+([A-Z]+)/', $s['slot_code'], $m);
              $row = $m[1] ?? 'A';
              $rows[$row][] = $s;
          }
          ksort($rows);
          foreach ($rows as &$rowSlots) {
              parky_usort_row_slots($rowSlots);
          }
          unset($rowSlots);
          ?>
          <?php foreach ($rows as $rowLetter => $rowSlots): ?>
            <div class="row-label">Row <?= htmlspecialchars($rowLetter) ?></div>
            <div class="slots-row">
              <?php foreach ($rowSlots as $s):
                  $phys = $s['status'];
                  $disp = $s['display_status'];
                  $diffClass = ($phys !== $disp) ? ' display-diff' : '';
                  ?>
                <button
                  type="button"
                  class="slot-btn <?= htmlspecialchars($disp) ?><?= $diffClass ?>"
                  data-id="<?= (int)$s['id'] ?>"
                  data-physical="<?= htmlspecialchars($phys) ?>"
                  title="<?= htmlspecialchars($s['slot_code']) ?> — view: <?= htmlspecialchars($disp) ?>, DB: <?= htmlspecialchars($phys) ?> (click cycles DB status)"
                >
                  <?= htmlspecialchars($s['slot_code']) ?>
                </button>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>


      <div class="slot-summary" id="slot_map_summary">
        <div class="slot-count green">Available: <span id="slot_sum_avail"><?= (int) $dispAvail ?></span></div>
        <div class="slot-count yellow">Reserved: <span id="slot_sum_res"><?= (int) $dispRes ?></span></div>
        <div class="slot-count red">Occupied: <span id="slot_sum_occ"><?= (int) $dispOcc ?></span></div>
        <div class="slot-count gray">Maintenance: <span id="slot_sum_maint"><?= (int) $dispMaint ?></span></div>
      </div>
    </div>
  </div>
</div>


<script>
(function(){
  var order = ['available','occupied','reserved','maintenance'];
  var dateUrlPrefix = <?= json_encode($slotsDateUrlPrefix, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var mapDate = <?= json_encode($selectedDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var POLL_MS = 4000;
  var statusClasses = ['available','reserved','occupied','maintenance'];


  function goToMapDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return;
    window.location.href = dateUrlPrefix + encodeURIComponent(value);
  }


  function applySlotClasses(btn, display, physical) {
    statusClasses.forEach(function(c){ btn.classList.remove(c); });
    btn.classList.add(display);
    btn.classList.toggle('display-diff', physical !== display);
    btn.setAttribute('data-physical', physical);
    var code = (btn.textContent || '').trim();
    btn.setAttribute('title', code + ' — view: ' + display + ', DB: ' + physical + ' (click cycles DB status)');
  }


  function applyMapState(payload) {
    if (!payload || !payload.byId || !payload.counts) return;
    document.querySelectorAll('.admin-slot-page .slot-btn').forEach(function(btn){
      var id = btn.getAttribute('data-id');
      if (!id || !payload.byId[id]) return;
      var row = payload.byId[id];
      applySlotClasses(btn, row.d, row.p);
    });
    var c = payload.counts;
    var elA = document.getElementById('slot_sum_avail');
    var elR = document.getElementById('slot_sum_res');
    var elO = document.getElementById('slot_sum_occ');
    var elM = document.getElementById('slot_sum_maint');
    if (elA) elA.textContent = String(c.available);
    if (elR) elR.textContent = String(c.reserved);
    if (elO) elO.textContent = String(c.occupied);
    if (elM) elM.textContent = String(c.maintenance);
  }


  var pollTimer = null;
  var livePill = document.getElementById('slot_map_live_pill');
  var liveLabel = document.getElementById('slot_map_live_label');


  function setLiveStatus(text, paused) {
    if (liveLabel) liveLabel.textContent = text;
    if (livePill) livePill.classList.toggle('is-paused', !!paused);
  }


  function fetchMapState() {
    var url = 'slots-state.php?date=' + encodeURIComponent(mapDate);
    return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function(r){
        if (r.status === 401) throw new Error('session');
        return r.json();
      })
      .then(function(data){
        if (!data || !data.ok) return;
        applyMapState(data);
        var now = new Date();
        var t = now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        setLiveStatus('Live · ' + t, false);
      })
      .catch(function(){
        setLiveStatus('Live (retrying…)', true);
      });
  }


  function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }


  function startPolling() {
    stopPolling();
    if (document.hidden) return;
    pollTimer = setInterval(fetchMapState, POLL_MS);
  }


  document.addEventListener('visibilitychange', function(){
    if (document.hidden) {
      stopPolling();
      setLiveStatus('Paused', true);
    } else {
      fetchMapState();
      startPolling();
    }
  });


  document.querySelectorAll('.admin-slot-page .slot-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var cur = btn.getAttribute('data-physical');
      var i = order.indexOf(cur);
      var next = order[(i + 1) % order.length];
      document.getElementById('sf_slot_id').value = btn.getAttribute('data-id');
      document.getElementById('sf_status').value = next;
      document.getElementById('slotForm').submit();
    });
  });


  document.getElementById('ym_apply').addEventListener('click', function(){
    var y = document.getElementById('ym_y').value;
    var m = String(document.getElementById('ym_m').value).padStart(2,'0');
    goToMapDate(y + '-' + m + '-01');
  });


  var dateJumpForm = document.getElementById('form_date_jump');
  var dateJumpInput = dateJumpForm && dateJumpForm.querySelector('input[type="date"]');
  if (dateJumpInput) {
    dateJumpInput.addEventListener('change', function(){ goToMapDate(this.value); });
  }


  fetchMapState();
  startPolling();
})();
</script>


<?php require_once 'includes/footer.php'; ?>

