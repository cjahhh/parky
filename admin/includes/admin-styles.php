<?php
// Shared list/detail UI — include after header.php <head> base styles or inside main.
// Usage: require_once __DIR__ . '/admin-styles.php';  (outputs <style>)
if (!defined('ADMIN_STYLES_PRINTED')) {
    define('ADMIN_STYLES_PRINTED', true);
?>
<style id="admin-shared-styles">
  /* Page shell */
  .admin-page-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;
  }
  .admin-page-head h1 {
    font-size: 1.35rem; font-weight: 800; color: var(--text);
    letter-spacing: -0.02em; line-height: 1.2;
  }
  .admin-page-head .sub {
    font-size: 0.82rem; color: var(--muted); margin-top: 4px; font-weight: 500;
  }
  .admin-page-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }


  .admin-back {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.8rem; font-weight: 600; color: var(--muted);
    text-decoration: none; margin-bottom: 0.75rem;
  }
  .admin-back:hover { color: var(--emerald); }


  /* Toolbar */
  .admin-toolbar {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
    margin-bottom: 1.25rem;
  }
  .admin-search {
    flex: 1; min-width: 200px; max-width: 320px;
    padding: 10px 14px; border-radius: 10px;
    border: 1px solid var(--border2);
    background: var(--bg3); color: var(--text);
    font-family: 'Nunito', sans-serif; font-size: 0.85rem; font-weight: 600;
  }
  .admin-search::placeholder { color: var(--muted2); }
  .admin-search:focus {
    outline: none; border-color: rgba(52,211,153,0.35);
    box-shadow: 0 0 0 3px var(--emeraldbg);
  }


  .filter-pills { display: flex; flex-wrap: wrap; gap: 6px; }
  .filter-pill {
    padding: 7px 14px; border-radius: 20px;
    font-size: 0.78rem; font-weight: 700;
    text-decoration: none; color: var(--muted);
    border: 1px solid var(--border2); background: var(--bg3);
    transition: background 0.15s, color 0.15s, border-color 0.15s;
  }
  .filter-pill:hover { color: var(--text); border-color: var(--muted2); }
  .filter-pill.active {
    background: var(--emeraldbg); color: var(--emerald);
    border-color: rgba(52,211,153,0.35);
  }


  /* Buttons */
  .btn-admin {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 9px 16px; border-radius: 10px;
    font-family: 'Nunito', sans-serif; font-size: 0.82rem; font-weight: 700;
    cursor: pointer; text-decoration: none; border: 1px solid transparent;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
  }
  .btn-admin-primary {
    background: var(--text); color: var(--bg);
    border-color: var(--text);
  }
  .btn-admin-primary:hover { opacity: 0.92; }
  .btn-admin-outline {
    background: transparent; color: var(--text);
    border-color: var(--border2);
  }
  .btn-admin-outline:hover { border-color: var(--muted); background: var(--surface); }
  .btn-admin-danger-outline {
    background: transparent; color: var(--danger);
    border-color: rgba(248,113,113,0.4);
  }
  .btn-admin-danger-outline:hover { background: var(--dangerbg); }
  .btn-admin-sm { padding: 6px 12px; font-size: 0.75rem; border-radius: 8px; }


  /* Cards & panels */
  .admin-card {
    background: var(--bg3); border: 1px solid var(--border2);
    border-radius: 14px; padding: 1.25rem; margin-bottom: 1rem;
  }
  .admin-card-title {
    font-size: 0.82rem; font-weight: 700; color: var(--text);
    margin-bottom: 1rem;
  }


  /* Table */
  .admin-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--border2); border-radius: 14px;
    background: var(--bg3);
  }
  .admin-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.82rem;
  }
  .admin-table th {
    text-align: left; padding: 12px 14px;
    font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.06em; color: var(--muted2);
    border-bottom: 1px solid var(--border2); background: var(--surface);
  }
  .admin-table td {
    padding: 12px 14px; border-bottom: 1px solid var(--border);
    color: var(--text); font-weight: 600; vertical-align: middle;
  }
  .admin-table tr:last-child td { border-bottom: none; }
  .admin-table tr:hover td { background: rgba(255,255,255,0.02); }


  .cell-ellipsis { max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }


  /* Avatar */
  .admin-avatar-lg {
    width: 44px; height: 44px; border-radius: 50%;
    background: rgba(96,165,250,0.2); border: 1px solid rgba(96,165,250,0.35);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem; font-weight: 800; color: #93c5fd; flex-shrink: 0;
  }
  .admin-avatar-row { display: flex; align-items: center; gap: 12px; }


  /* Badges */
  .badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-size: 0.68rem; font-weight: 800; text-transform: capitalize;
  }
  .badge-pending   { background: rgba(96,165,250,0.15); color: #93c5fd; }
  .badge-confirmed { background: rgba(52,211,153,0.15); color: var(--emerald); }
  .badge-active    { background: rgba(96,165,250,0.15); color: #93c5fd; }
  .badge-completed { background: var(--surface2); color: var(--muted); }
  .badge-expired   { background: rgba(248,113,113,0.15); color: var(--danger); }
  .badge-cancelled { background: var(--surface2); color: var(--muted2); }
  .badge-paid      { background: rgba(52,211,153,0.15); color: var(--emerald); }
  .badge-unpaid    { background: rgba(248,113,113,0.15); color: var(--danger); }
  .badge-reserved-user { background: rgba(96,165,250,0.12); color: #93c5fd; border: 1px solid rgba(96,165,250,0.35); }
  .badge-guest         { background: rgba(251,191,36,0.12); color: #fcd34d; border: 1px solid rgba(251,191,36,0.3); }


  /* Stat mini grid */
  .admin-stat-row {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 1.25rem;
  }
  @media (max-width: 768px) { .admin-stat-row { grid-template-columns: 1fr; } }
  .admin-stat-card {
    background: var(--bg3); border: 1px solid var(--border2);
    border-radius: 12px; padding: 1rem;
  }
  .admin-stat-card .lbl {
    font-size: 0.7rem; font-weight: 700; color: var(--muted2);
    text-transform: uppercase; letter-spacing: 0.06em;
  }
  .admin-stat-card .val {
    font-size: 1.35rem; font-weight: 800; color: var(--text); margin-top: 6px;
  }
  .admin-stat-card .val.emerald { color: var(--emerald); }


  /* Two-column detail grid */
  .admin-detail-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
  }
  @media (max-width: 900px) { .admin-detail-grid { grid-template-columns: 1fr; } }


  .detail-list { display: flex; flex-direction: column; }
  .detail-row {
    display: flex; justify-content: space-between; gap: 12px;
    padding: 12px 0; border-bottom: 1px solid var(--border);
    font-size: 0.82rem;
  }
  .detail-row:last-child { border-bottom: none; }
  .detail-row .k { color: var(--muted2); font-weight: 600; }
  .detail-row .v { color: var(--text); font-weight: 700; text-align: right; }


  /* Timeline */
  .timeline { position: relative; padding-left: 8px; }
  .timeline-item {
    position: relative; padding-left: 28px; padding-bottom: 1.25rem;
  }
  .timeline-item:last-child { padding-bottom: 0; }
  .timeline-item::before {
    content: ''; position: absolute; left: 5px; top: 8px; bottom: -4px;
    width: 2px; background: var(--border2);
  }
  .timeline-item:last-child::before { display: none; }
  .timeline-dot {
    position: absolute; left: 0; top: 4px; width: 12px; height: 12px;
    border-radius: 50%; flex-shrink: 0;
  }
  .timeline-dot.green { background: var(--emerald2); box-shadow: 0 0 0 3px var(--emeraldbg); }
  .timeline-dot.blue  { background: #60a5fa; box-shadow: 0 0 0 3px rgba(96,165,250,0.2); }
  .timeline-dot.red   { background: var(--danger); box-shadow: 0 0 0 3px var(--dangerbg); }
  .timeline-dot.amber { background: var(--warning); box-shadow: 0 0 0 3px rgba(251,191,36,0.15); }
  .timeline-dot.gray  { background: var(--muted2); }
  .timeline-title { font-size: 0.85rem; font-weight: 800; color: var(--text); }
  .timeline-meta { font-size: 0.75rem; color: var(--muted); margin-top: 4px; line-height: 1.45; }


  /* Flash */
  .admin-flash {
    padding: 12px 16px; border-radius: 10px; margin-bottom: 1rem;
    font-size: 0.82rem; font-weight: 600;
  }
  .admin-flash.ok { background: var(--emeraldbg); color: var(--emerald); border: 1px solid rgba(52,211,153,0.25); }
  .admin-flash.err { background: var(--dangerbg); color: var(--danger); border: 1px solid rgba(248,113,113,0.25); }


  /* Slot grid (management) */
  .slot-floor-tabs { display: flex; gap: 8px; margin-bottom: 1rem; flex-wrap: wrap; }
  .slot-legend { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 1rem; font-size: 0.72rem; font-weight: 600; color: var(--muted); }
  .slot-legend span { display: inline-flex; align-items: center; gap: 6px; }
  .slot-legend i {
    width: 14px; height: 14px; border-radius: 4px; display: inline-block;
  }
  .slot-grid-admin {
    display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px;
  }
  @media (max-width: 900px) { .slot-grid-admin { grid-template-columns: repeat(3, 1fr); } }
  .slot-tile-admin {
    border: none; border-radius: 10px; padding: 10px 6px;
    font-family: 'Nunito', sans-serif; font-size: 0.72rem; font-weight: 800;
    cursor: pointer; transition: transform 0.1s, box-shadow 0.15s;
    text-align: center;
  }
  .slot-tile-admin:hover { transform: translateY(-1px); }
  .slot-tile-admin.available   { background: rgba(52,211,153,0.18); color: var(--emerald2); }
  .slot-tile-admin.occupied    { background: rgba(248,113,113,0.15); color: #fca5a5; }
  .slot-tile-admin.reserved    { background: rgba(251,191,36,0.15); color: #fcd34d; }
  .slot-tile-admin.maintenance {
    background: var(--surface); color: var(--muted);
    border: 2px dashed var(--border2);
  }


  /* Hourly chart bars */
  .hourly-chart { display: flex; align-items: flex-end; gap: 6px; height: 120px; padding-top: 8px; }
  .hourly-bar-wrap {
    flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; min-width: 0;
  }
  .hourly-bar-track {
    width: 100%; max-width: 36px; height: 90px; background: var(--surface2);
    border-radius: 6px; display: flex; flex-direction: column; justify-content: flex-end; margin: 0 auto;
  }
  .hourly-bar-fill {
    width: 100%; border-radius: 6px 6px 4px 4px;
    background: linear-gradient(180deg, #60a5fa, #3b82f6);
    min-height: 2px; transition: height 0.35s ease;
  }
  .hourly-bar-fill.peak { background: linear-gradient(180deg, #34d399, var(--emerald2)); }
  .hourly-label { font-size: 0.62rem; font-weight: 700; color: var(--muted2); text-align: center; }


  .sidebar-status-online {
    display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px;
    font-size: 0.65rem; font-weight: 800; margin-bottom: 8px;
    background: var(--emeraldbg); color: var(--emerald); border: 1px solid rgba(52,211,153,0.25);
  }


  .nav-item .nav-dot {
    width: 6px; height: 6px; border-radius: 50%; background: var(--muted2); flex-shrink: 0;
  }
  .nav-item.active .nav-dot { background: var(--emerald); }
</style>
<?php } ?>

