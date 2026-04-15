<?php
session_start();
require_once 'config/db.php';


// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}


$error       = '';
$show_resend = false;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';


    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();


        if ($user && password_verify($password, $user['password'])) {
            $isVerified = (int) ($user['is_verified'] ?? 0);
            if (!$isVerified && empty($user['verification_token']) && empty($user['verification_expires_at'])) {
                $pdo->prepare('UPDATE users SET is_verified = 1 WHERE id = ?')->execute([(int) $user['id']]);
                $isVerified = 1;
            }
            if (!$isVerified) {
                $error       = 'Please verify your email before logging in. Check your inbox or request a new link below.';
                $show_resend = true;
            } else {
                $displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                if ($displayName === '') {
                    $displayName = $user['username'] ?? '';
                }
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['user_name']     = $displayName;
                $_SESSION['plate_number']  = $user['plate_number']; // ← added
                header('Location: dashboard.php');
                exit;
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky — Log in</title>
          <link rel="icon" href="favicon.ico" type="image/x-icon">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }


    :root {
      --bg:         #181c18;
      --bg2:        #1f231f;
      --surface:    #252a25;
      --surface2:   #2c312c;
      --border:     rgba(255,255,255,0.07);
      --border2:    rgba(255,255,255,0.12);
      --text:       #eaf2ea;
      --muted:      #7a907a;
      --muted2:     #556655;
      --emerald:    #34d399;
      --emerald2:   #10b981;
      --emerald3:   #059669;
      --emeraldbg:  rgba(52,211,153,0.08);
      --emeraldbg2: rgba(52,211,153,0.15);
      --danger:     #f87171;
      --dangerbg:   rgba(248,113,113,0.1);
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
      position: relative;
      overflow: hidden;
    }


    body::before {
      content: '';
      position: absolute;
      top: -180px;
      right: -180px;
      width: 480px;
      height: 480px;
      background: radial-gradient(circle, rgba(52,211,153,0.06) 0%, transparent 70%);
      pointer-events: none;
    }


    body::after {
      content: '';
      position: absolute;
      bottom: -150px;
      left: -150px;
      width: 400px;
      height: 400px;
      background: radial-gradient(circle, rgba(52,211,153,0.04) 0%, transparent 70%);
      pointer-events: none;
    }


    .card {
      background: var(--bg2);
      border: 1px solid var(--border2);
      border-radius: 24px;
      padding: 2.5rem 2.25rem;
      width: 100%;
      max-width: 400px;
      position: relative;
      animation: fadeUp 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
    }


    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(24px); }
      to   { opacity: 1; transform: translateY(0); }
    }


    .logo {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 2rem;
    }


    .logo-icon {
      width: 40px;
      height: 40px;
      background: var(--emeraldbg2);
      border: 1.5px solid rgba(52,211,153,0.3);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
    }


    .logo-icon svg {
      width: 20px;
      height: 20px;
      fill: none;
      stroke: var(--emerald);
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }


    .logo-text {
      font-size: 1.3rem;
      font-weight: 800;
      color: var(--emerald);
      letter-spacing: -0.02em;
    }


    .heading { margin-bottom: 0.4rem; }


    .heading h1 {
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--text);
      letter-spacing: -0.02em;
      line-height: 1.2;
    }


    .heading p {
      font-size: 0.88rem;
      color: var(--muted);
      margin-top: 5px;
      font-weight: 500;
    }


    .divider {
      height: 1px;
      background: var(--border);
      margin: 1.5rem 0;
    }


    .error-box {
      background: var(--dangerbg);
      border: 1px solid rgba(248,113,113,0.25);
      border-radius: 12px;
      padding: 10px 14px;
      font-size: 0.83rem;
      color: var(--danger);
      font-weight: 600;
      margin-bottom: 1.25rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }


    .error-box::before {
      content: '';
      width: 6px;
      height: 6px;
      background: var(--danger);
      border-radius: 50%;
      flex-shrink: 0;
    }


    .form-group { margin-bottom: 1rem; }


    label {
      display: block;
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--muted);
      margin-bottom: 7px;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }


    .input-wrap { position: relative; }


    .input-wrap svg {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      width: 17px;
      height: 17px;
      stroke: var(--muted2);
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
      pointer-events: none;
      transition: stroke 0.2s;
    }


    input[type="text"],
    input[type="password"] {
      width: 100%;
      background: var(--surface);
      border: 1.5px solid var(--border2);
      border-radius: 12px;
      padding: 12px 14px 12px 42px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.92rem;
      font-weight: 600;
      color: var(--text);
      outline: none;
      transition: border-color 0.2s, background 0.2s;
    }


    input[type="text"]::placeholder,
    input[type="password"]::placeholder {
      color: var(--muted2);
      font-weight: 500;
    }


    input[type="text"]:focus,
    input[type="password"]:focus {
      border-color: var(--emerald2);
      background: var(--surface2);
    }


    .input-wrap:focus-within svg { stroke: var(--emerald); }


    .toggle-pass {
      position: absolute;
      right: 14px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      padding: 2px;
      display: flex;
      align-items: center;
    }


    .toggle-pass svg {
      position: static;
      transform: none;
      width: 17px;
      height: 17px;
      stroke: var(--muted2);
      transition: stroke 0.2s;
    }


    .toggle-pass:hover svg { stroke: var(--muted); }


    .forgot-row {
      text-align: right;
      margin-top: 6px;
    }


    .forgot-row a {
      font-size: 0.8rem;
      font-weight: 700;
      color: var(--emerald);
      text-decoration: none;
      opacity: 0.8;
      transition: opacity 0.2s;
    }


    .forgot-row a:hover { opacity: 1; }


    .btn-submit {
      width: 100%;
      background: var(--emerald2);
      color: #0a1a12;
      border: none;
      border-radius: 14px;
      padding: 13px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.95rem;
      font-weight: 800;
      cursor: pointer;
      margin-top: 1.4rem;
      letter-spacing: 0.01em;
      transition: background 0.2s, transform 0.1s;
    }


    .btn-submit:hover  { background: var(--emerald); }
    .btn-submit:active { transform: scale(0.98); }


    .register-row {
      text-align: center;
      margin-top: 1.4rem;
      font-size: 0.85rem;
      color: var(--muted);
      font-weight: 600;
    }


    .register-row a {
      color: var(--emerald);
      text-decoration: none;
      font-weight: 700;
      transition: opacity 0.2s;
    }


    .register-row a:hover { opacity: 0.8; }


    .back-home {
      text-align: center;
      margin-top: 1rem;
    }


    .back-home a {
      font-size: 0.8rem;
      color: var(--muted2);
      text-decoration: none;
      font-weight: 600;
      transition: color 0.2s;
    }


    .back-home a:hover { color: var(--muted); }
  </style>
</head>
<body>


  <div class="card">


    <div class="logo">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24">
          <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </div>
      <span class="logo-text">Parky</span>
    </div>


    <div class="heading">
      <h1>Welcome back!</h1>
      <p>Log in to manage your parking reservation.</p>
    </div>


    <div class="divider"></div>


    <?php if ($error): ?>
      <div class="error-box"><?= htmlspecialchars($error) ?></div>
      <?php if (!empty($show_resend)): ?>
        <p style="text-align:center;font-size:0.82rem;font-weight:700;margin:-0.5rem 0 1rem;">
          <a href="resend-verification.php" style="color:var(--emerald);text-decoration:none;">Resend verification email</a>
        </p>
      <?php endif; ?>
    <?php endif; ?>


    <form method="POST" action="login.php" autocomplete="off">


      <div class="form-group">
        <label for="username">Username</label>
        <div class="input-wrap">
          <input
            type="text"
            id="username"
            name="username"
            placeholder="Enter your username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            required
            autofocus
          />
          <svg viewBox="0 0 24 24">
            <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </div>
      </div>


      <div class="form-group">
        <label for="password">Password</label>
        <div class="input-wrap">
          <input
            type="password"
            id="password"
            name="password"
            placeholder="Enter your password"
            required
          />
          <svg viewBox="0 0 24 24">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0110 0v4"/>
          </svg>
          <button type="button" class="toggle-pass" onclick="togglePassword()" tabindex="-1">
            <svg id="eyeIcon" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <div class="forgot-row">
          <a href="forgot-password.php">Forgot password?</a>
        </div>
      </div>


      <button type="submit" class="btn-submit">Log in</button>


    </form>


    <div class="register-row">
      Don't have an account? <a href="register.php">Sign up</a>
    </div>


    <div class="back-home">
      <a href="index.php">← Back to home</a>
    </div>


  </div>


  <script>
    function togglePassword() {
      var input = document.getElementById('password');
      var icon  = document.getElementById('eyeIcon');
      if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
      } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
      }
    }
  </script>


</body>
</html>

