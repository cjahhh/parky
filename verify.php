<?php
session_start();
require_once 'config/db.php';


if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}


$token = trim($_GET['token'] ?? '');
$message = '';
$ok = false;


if ($token === '') {
    $message = 'Invalid or missing verification link.';
} else {
    $stmt = $pdo->prepare(
        'SELECT id FROM users
         WHERE verification_token = ? AND verification_expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);


    if ($row) {
        $pdo->prepare(
            'UPDATE users SET is_verified = 1, verification_token = NULL, verification_expires_at = NULL WHERE id = ?'
        )->execute([(int) $row['id']]);
        $ok      = true;
        $message = 'Your email is verified! You can now log in to your account.';
    } else {
        $message = 'This verification link is invalid or has expired. You can request a new one from the login page.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky — Email verification</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #181c18; --bg2: #1f231f; --border2: rgba(255,255,255,0.12);
      --text: #eaf2ea; --muted: #7a907a; --emerald: #34d399; --emerald2: #10b981;
      --danger: #f87171; --dangerbg: rgba(248,113,113,0.1);
      --successbg: rgba(52,211,153,0.1);
    }
    body {
      font-family: 'Nunito', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }
    .card {
      background: var(--bg2);
      border: 1px solid var(--border2);
      border-radius: 24px;
      padding: 2rem 2.25rem;
      max-width: 440px;
      width: 100%;
      text-align: center;
    }
    h1 { font-size: 1.25rem; font-weight: 800; margin-bottom: 0.75rem; letter-spacing: -0.02em; }
    p { font-size: 0.88rem; color: var(--muted); line-height: 1.6; font-weight: 600; }
    .msg-ok { color: var(--emerald); margin-bottom: 1.25rem; }
    .msg-bad { color: var(--danger); background: var(--dangerbg); padding: 12px 14px; border-radius: 12px; margin-bottom: 1.25rem; }
    .links { margin-top: 1.25rem; display: flex; flex-direction: column; gap: 10px; }
    .links a {
      display: inline-block;
      font-size: 0.85rem; font-weight: 700; color: var(--emerald);
      text-decoration: none;
    }
    .links a:hover { text-decoration: underline; }
    .btn {
      display: inline-block;
      background: var(--emerald2);
      color: #0a1a12;
      font-weight: 800;
      font-size: 0.9rem;
      padding: 11px 24px;
      border-radius: 12px;
      text-decoration: none;
      margin-top: 0.5rem;
    }
    .btn:hover { background: var(--emerald); }
    .countdown { font-size: 0.8rem; color: var(--muted); margin-top: 1rem; font-weight: 600; }
  </style>
</head>
<body>
  <div class="card">
    <h1><?= $ok ? 'You are verified' : 'Verification' ?></h1>
    <?php if ($ok): ?>
      <p class="msg-ok"><?= htmlspecialchars($message) ?></p>
      <a class="btn" href="login.php">Log in</a>
      <p class="countdown">Redirecting in <span id="timer">5</span> seconds...</p>
    <?php else: ?>
      <p class="msg-bad"><?= htmlspecialchars($message) ?></p>
      <div class="links">
        <a href="resend-verification.php">Resend verification email</a>
        <a href="login.php">Back to login</a>
        <a href="index.php">Home</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($ok): ?>
  <script>
    let countdown = 5;
    const timerEl = document.getElementById('timer');
    
    const interval = setInterval(() => {
      countdown--;
      timerEl.textContent = countdown;
      if (countdown <= 0) {
        clearInterval(interval);
        window.location.href = 'login.php';
      }
    }, 1000);
  </script>
  <?php endif; ?>
</body>
</html>



