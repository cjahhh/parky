<?php
session_start();
require_once 'config/db.php';
require_once 'config/mailer.php';


if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}


$error   = '';
$success = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');


    if ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $last = $_SESSION['verify_resend_at'] ?? 0;
        if (time() - (int) $last < 60) {
            $error = 'Please wait a minute before requesting another email.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, first_name, last_name, username, is_verified FROM users WHERE email = ? LIMIT 1'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);


            if ($user && !(int) ($user['is_verified'] ?? 0)) {
                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $pdo->prepare(
                    'UPDATE users SET verification_token = ?, verification_expires_at = ? WHERE id = ?'
                )->execute([$token, $expiresAt, (int) $user['id']]);


                $displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                if ($displayName === '') {
                    $displayName = $user['username'] ?? '';
                }
                $verifyUrl = app_public_base_url() . '/verify.php?token=' . rawurlencode($token);
                if (send_verification_email($email, $displayName, $verifyUrl)) {
                    $_SESSION['verify_resend_at'] = time();
                    $success = 'Verification email sent. Check your inbox — the link expires in 1 hour.';
                } else {
                    $error = 'We could not send the email. Please try again later.';
                }
            } else {
                $success = 'If that email is registered and not yet verified, we sent a new verification link.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky — Resend verification</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #181c18; --bg2: #1f231f; --surface: #252a25; --surface2: #2c312c;
      --border: rgba(255,255,255,0.07); --border2: rgba(255,255,255,0.12);
      --text: #eaf2ea; --muted: #7a907a; --muted2: #556655;
      --emerald: #34d399; --emerald2: #10b981; --emeraldbg2: rgba(52,211,153,0.15);
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
      padding: 2.5rem 2.25rem;
      width: 100%;
      max-width: 420px;
    }
    .logo {
      display: flex; align-items: center; gap: 10px;
      margin-bottom: 1.75rem;
    }
    .logo-icon {
      width: 38px; height: 38px;
      background: var(--emeraldbg2);
      border: 1.5px solid rgba(52,211,153,0.3);
      border-radius: 11px;
      display: flex; align-items: center; justify-content: center;
    }
    .logo-icon span { font-size: 1.1rem; font-weight: 900; color: var(--emerald); }
    .logo-text { font-size: 1.25rem; font-weight: 800; color: var(--emerald); }
    h1 { font-size: 1.35rem; font-weight: 800; margin-bottom: 0.5rem; letter-spacing: -0.02em; }
    .sub { font-size: 0.85rem; color: var(--muted); font-weight: 500; line-height: 1.55; margin-bottom: 1.25rem; }
    .divider { height: 1px; background: var(--border); margin: 1.25rem 0; }
    .msg-box {
      border-radius: 12px; padding: 10px 14px;
      font-size: 0.83rem; font-weight: 600;
      margin-bottom: 1.1rem;
    }
    .msg-error   { background: var(--dangerbg); border: 1px solid rgba(248,113,113,0.25); color: var(--danger); }
    .msg-success { background: var(--successbg); border: 1px solid rgba(52,211,153,0.25); color: var(--emerald); }
    label {
      display: block; font-size: 0.75rem; font-weight: 700;
      color: var(--muted); margin-bottom: 6px;
      letter-spacing: 0.04em; text-transform: uppercase;
    }
    .input-wrap { position: relative; }
    .input-wrap svg {
      position: absolute; left: 13px; top: 50%;
      transform: translateY(-50%);
      width: 16px; height: 16px;
      stroke: var(--muted2); fill: none;
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
      pointer-events: none;
    }
    input[type="email"] {
      width: 100%;
      background: var(--surface);
      border: 1.5px solid var(--border2);
      border-radius: 12px;
      padding: 12px 14px 12px 42px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.92rem; font-weight: 600;
      color: var(--text); outline: none;
    }
    input:focus { border-color: var(--emerald2); background: var(--surface2); }
    .btn-submit {
      width: 100%;
      background: var(--emerald2); color: #000000;
      border: none; border-radius: 13px;
      padding: 12px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.92rem; font-weight: 800;
      cursor: pointer; margin-top: 0.75rem;
    }
    .btn-submit:hover { background: var(--emerald); }
    .back-row { text-align: center; margin-top: 1.1rem; font-size: 0.82rem; color: var(--muted); font-weight: 600; }
    .back-row a { color: var(--emerald); text-decoration: none; font-weight: 700; }
  </style>
</head>
<body>


<div class="card">
  <div class="logo">
    <div class="logo-icon"><span>P</span></div>
    <span class="logo-text">Parky</span>
  </div>
  <h1>Resend verification</h1>
  <p class="sub">Enter the email you used to register. We will send a new link valid for <strong style="color:var(--emerald)">1 hour</strong>.</p>
  <div class="divider"></div>


  <?php if ($error): ?>
    <div class="msg-box msg-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="msg-box msg-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>


  <?php if (!$success): ?>
  <form method="POST" action="resend-verification.php">
    <label>Email address</label>
    <div class="input-wrap">
      <input type="email" name="email" placeholder="you@email.com"
        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
        required autofocus/>
      <svg viewBox="0 0 24 24">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
        <polyline points="22,6 12,13 2,6"/>
      </svg>
    </div>
    <button type="submit" class="btn-submit">Send verification link</button>
  </form>
  <?php endif; ?>


  <div class="back-row">
    <a href="login.php">← Back to login</a>
  </div>
</div>


</body>
</html>



