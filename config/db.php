<?php
// ============================================================
//  config/db.php — Parky Database Configuration (PDO)
//  Included via: require_once 'config/db.php';
// ============================================================


// ── Application timezone (PHP + MySQL session) ─────────────
// Change only APP_TIMEZONE; MySQL gets the matching offset automatically.
// Set FIRST before any date() or strtotime() call anywhere.
define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);


define('DB_HOST', 'sql300.infinityfree.com');
define('DB_NAME', 'if0_41662790_parky_db');
define('DB_USER', 'if0_41662790');   // ← change to your MySQL username
define('DB_PASS', 'k6F9namYWC8C34');       // ← change to your MySQL password
define('DB_CHARSET', 'utf8mb4');


$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;


$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];


try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);


    // Match MySQL session to PHP: NOW() / CURDATE() align with date() in this app.
    $dbSessionOffset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
    $pdo->exec('SET time_zone = ' . $pdo->quote($dbSessionOffset));
    define('APP_DB_TIMEZONE_OFFSET', $dbSessionOffset);


} catch (PDOException $e) {
    // Show a clean error in development; in production, log and show a generic message
    http_response_code(500);
    die(<<<HTML
    <!DOCTYPE html>
    <html><head><meta charset="UTF-8">
    <title>Parky — Connection Error</title>
    <style>
      body { font-family: sans-serif; background: #181c18; color: #eaf2ea;
             display: flex; align-items: center; justify-content: center; min-height: 100vh; }
      .box { background: #1f231f; border: 1px solid rgba(248,113,113,0.3); border-radius: 16px;
             padding: 2rem 2.5rem; max-width: 480px; text-align: center; }
      h2 { color: #f87171; margin-bottom: .5rem; }
      p  { color: #7a907a; font-size: .9rem; line-height: 1.6; }
      code { background: #252a25; padding: 2px 8px; border-radius: 5px;
             color: #34d399; font-size: .85rem; }
    </style></head><body>
    <div class="box">
      <h2>Database connection failed</h2>
      <p>Make sure MySQL is running and your credentials in
         <code>config/db.php</code> are correct.</p>
      <p style="margin-top:1rem; color:#556655; font-size:.8rem;">
        {$e->getMessage()}
      </p>
    </div>
    </body></html>
    HTML);
}
?>

