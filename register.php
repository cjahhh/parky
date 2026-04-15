<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    $first_name   = trim($_POST['first_name']   ?? '');
    $last_name    = trim($_POST['last_name']    ?? '');
    $username     = trim($_POST['username']     ?? '');
    $email        = trim($_POST['email']        ?? '');
    $phone        = trim($_POST['phone']        ?? '');
    $plate_number = strtoupper(trim($_POST['plate_number'] ?? ''));
    $password     = $_POST['password']          ?? '';
    $confirm      = $_POST['confirm']           ?? '';


    if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($plate_number) || empty($password) || empty($confirm)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($username) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (preg_match('/\s/', $username)) {
        $error = 'Username cannot contain spaces.';
    } elseif (!preg_match('/^[A-Z0-9\-]{2,15}$/', $plate_number)) {
        $error = 'Please enter a valid plate number (letters, numbers, hyphens only, no spaces).';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Check username, email, and plate uniqueness
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? OR plate_number = ? LIMIT 1");
        $stmt->execute([$username, $email, $plate_number]);
        $existing = $stmt->fetch();


        if ($existing) {
            $chkU = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chkU->execute([$username]);
            if ($chkU->fetch()) {
                $error = 'That username is already taken.';
            } else {
                $chkE = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $chkE->execute([$email]);
                $error = $chkE->fetch()
                    ? 'That email is already registered.'
                    : 'That plate number is already linked to another account.';
            }
        } else {
            $hashed      = password_hash($password, PASSWORD_BCRYPT);
            $token       = bin2hex(random_bytes(32));
            $expiresAt   = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $stmt        = $pdo->prepare(
                'INSERT INTO users (first_name, last_name, username, email, phone, plate_number, password, is_verified, verification_token, verification_expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
            );
            $stmt->execute([$first_name, $last_name, $username, $email, $phone, $plate_number, $hashed, $token, $expiresAt]);


            $displayName = trim($first_name . ' ' . $last_name);
            if ($displayName === '') {
                $displayName = $username;
            }
            $verifyUrl = app_public_base_url() . '/verify.php?token=' . rawurlencode($token);
            if (send_verification_email($email, $displayName, $verifyUrl)) {
                $success = 'Account created! Check your email for a verification link (valid for 1 hour), then log in.';
            } else {
                $success = 'Account created, but we could not send the email. Use “Resend verification” on the login page to get a link.';
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
  <title>Parky — Create Account</title>
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
      --emeraldbg2: rgba(52,211,153,0.15);
      --danger:     #f87171;
      --dangerbg:   rgba(248,113,113,0.1);
      --successbg:  rgba(52,211,153,0.1);
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
      top: -180px; right: -180px;
      width: 480px; height: 480px;
      background: radial-gradient(circle, rgba(52,211,153,0.06) 0%, transparent 70%);
      pointer-events: none;
    }


    body::after {
      content: '';
      position: absolute;
      bottom: -150px; left: -150px;
      width: 400px; height: 400px;
      background: radial-gradient(circle, rgba(52,211,153,0.04) 0%, transparent 70%);
      pointer-events: none;
    }


    .card {
      background: var(--bg2);
      border: 1px solid var(--border2);
      border-radius: 24px;
      padding: 2rem 2rem 1.6rem;
      width: 100%;
      max-width: 680px;
      position: relative;
      animation: fadeUp 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
    }


    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(20px); }
      to   { opacity: 1; transform: translateY(0); }
    }


    .card-top {
      display: flex;
      align-items: center;
      gap: 1.25rem;
      margin-bottom: 1.1rem;
    }


    .logo {
      display: flex;
      align-items: center;
      gap: 9px;
      flex-shrink: 0;
    }


    .logo-icon {
      width: 38px; height: 38px;
      background: var(--emeraldbg2);
      border: 1.5px solid rgba(52,211,153,0.3);
      border-radius: 11px;
      display: flex; align-items: center; justify-content: center;
    }


    .logo-icon svg {
      width: 18px; height: 18px;
      fill: none; stroke: var(--emerald);
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
    }


    .logo-text {
      font-size: 1.2rem; font-weight: 800;
      color: var(--emerald); letter-spacing: -0.02em;
    }


    .logo-divider {
      width: 1px; height: 32px;
      background: var(--border2);
      flex-shrink: 0;
    }


    .heading h1 {
      font-size: 1.15rem; font-weight: 800;
      color: var(--text); letter-spacing: -0.02em; line-height: 1.2;
    }


    .heading p {
      font-size: 0.8rem; color: var(--muted);
      margin-top: 2px; font-weight: 500;
    }


    .divider {
      height: 1px; background: var(--border); margin-bottom: 1rem;
    }


    .msg-box {
      border-radius: 10px; padding: 9px 13px;
      font-size: 0.81rem; font-weight: 600;
      margin-bottom: 0.9rem;
      display: flex; align-items: center; gap: 8px;
    }
    .msg-box::before {
      content: ''; width: 6px; height: 6px;
      border-radius: 50%; flex-shrink: 0;
    }
    .msg-error   { background: var(--dangerbg); border: 1px solid rgba(248,113,113,0.25); color: var(--danger); }
    .msg-error::before { background: var(--danger); }
    .msg-success { background: var(--successbg); border: 1px solid rgba(52,211,153,0.25); color: var(--emerald); }
    .msg-success::before { background: var(--emerald); }


    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.7rem 1rem;
    }


    .col-span-2 { grid-column: span 2; }


    .form-group { display: flex; flex-direction: column; }


    label {
      display: block;
      font-size: 0.7rem; font-weight: 700;
      color: var(--muted); margin-bottom: 5px;
      letter-spacing: 0.04em; text-transform: uppercase;
    }


    label span.req { color: var(--danger); margin-left: 2px; }


    .optional {
      font-size: 0.65rem; color: var(--muted2);
      font-weight: 600; text-transform: none;
      letter-spacing: 0; margin-left: 4px;
    }


    .input-wrap { position: relative; }


    .input-wrap svg.ico {
      position: absolute; left: 12px; top: 50%;
      transform: translateY(-50%);
      width: 15px; height: 15px;
      stroke: var(--muted2); fill: none;
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
      pointer-events: none; transition: stroke 0.2s;
    }


    .input-wrap:focus-within svg.ico { stroke: var(--emerald2); }


    input[type="text"],
    input[type="email"],
    input[type="tel"],
    input[type="password"] {
      width: 100%;
      background: var(--surface);
      border: 1.5px solid var(--border2);
      border-radius: 10px;
      padding: 9px 12px 9px 36px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.85rem; font-weight: 600;
      color: var(--text); outline: none;
      transition: border-color 0.2s, background 0.2s;
    }


    input::placeholder { color: var(--muted2); font-weight: 500; }
    input:focus { border-color: var(--emerald2); background: var(--surface2); }


    /* Plate number input — uppercase display */
    input#plate_number { text-transform: uppercase; letter-spacing: 0.08em; }


    .plate-hint {
      font-size: 0.67rem; color: var(--muted2);
      font-weight: 600; margin-top: 4px;
    }


    .strength-bar {
      height: 3px; border-radius: 2px;
      background: var(--surface); margin-top: 5px; overflow: hidden;
    }
    .strength-fill {
      height: 100%; border-radius: 2px; width: 0%;
      transition: width 0.3s, background 0.3s;
    }
    .strength-label {
      font-size: 0.68rem; font-weight: 600;
      margin-top: 2px; color: var(--muted2);
    }


    .toggle-pass {
      position: absolute; right: 10px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none;
      cursor: pointer; padding: 2px;
      display: flex; align-items: center;
    }
    .toggle-pass svg {
      width: 15px; height: 15px; fill: none;
      stroke: var(--muted2); stroke-width: 2;
      stroke-linecap: round; stroke-linejoin: round;
      transition: stroke 0.2s;
    }
    .toggle-pass:hover svg { stroke: var(--muted); }


    .card-bottom { margin-top: 1rem; }


    .btn-submit {
      width: 100%;
      background: var(--emerald2);
      color: #0a1a12;
      border: none; border-radius: 11px;
      padding: 11px 20px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.9rem; font-weight: 800;
      cursor: pointer;
      transition: background 0.2s, transform 0.1s;
    }
    .btn-submit:hover  { background: var(--emerald); }
    .btn-submit:active { transform: scale(0.98); }


    .login-row {
      text-align: center;
      margin-top: 0.75rem;
      font-size: 0.8rem; color: var(--muted); font-weight: 600;
    }
    .login-row a { color: var(--emerald); text-decoration: none; font-weight: 700; }


    .back-home {
      text-align: center;
      margin-top: 0.3rem;
    }
    .back-home a {
      font-size: 0.75rem; color: var(--muted2);
      text-decoration: none; font-weight: 600;
      transition: color 0.2s;
    }
    .back-home a:hover { color: var(--muted); }


    @media (max-width: 560px) {
      .form-grid { grid-template-columns: 1fr; }
      .col-span-2 { grid-column: span 1; }
      .card-top { flex-direction: column; align-items: flex-start; }
      .logo-divider { display: none; }
    }
  </style>
</head>
<body>


<div class="card">


  <div class="card-top">
    <div class="logo">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24">
          <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </div>
      <span class="logo-text">Parky</span>
    </div>


    <div class="logo-divider"></div>


    <div class="heading">
      <h1>Create your account</h1>
      <p>Join Parky and reserve your parking spot online.</p>
    </div>
  </div>


  <div class="divider"></div>


  <?php if ($error): ?>
    <div class="msg-box msg-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="msg-box msg-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>


  <form method="POST" action="register.php" autocomplete="off">
    <div class="form-grid">


      <!-- First name -->
      <div class="form-group">
        <label>First name <span class="req">*</span></label>
        <div class="input-wrap">
          <input type="text" name="first_name" placeholder="Juan" autocomplete="given-name"
            value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required maxlength="50"/>
          <svg class="ico" viewBox="0 0 24 24">
            <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </div>
      </div>


      <!-- Last name -->
      <div class="form-group">
        <label>Last name <span class="req">*</span></label>
        <div class="input-wrap">
          <input type="text" name="last_name" placeholder="dela Cruz" autocomplete="family-name"
            value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required maxlength="50"/>
          <svg class="ico" viewBox="0 0 24 24">
            <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </div>
      </div>


      <!-- Username -->
      <div class="form-group">
        <label>Username <span class="req">*</span></label>
        <div class="input-wrap">
          <input type="text" name="username" placeholder="juan123"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required/>
          <svg class="ico" viewBox="0 0 24 24">
            <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </div>
      </div>


      <!-- Email -->
      <div class="form-group col-span-2">
        <label>Email address <span class="req">*</span></label>
        <div class="input-wrap">
          <input type="email" name="email" placeholder="juan@email.com"
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required/>
          <svg class="ico" viewBox="0 0 24 24">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
        </div>
      </div>


      <!-- Phone -->
      <div class="form-group">
        <label>Phone number <span class="optional">(optional)</span></label>
        <div class="input-wrap">
          <input type="tel" name="phone" placeholder="09XXXXXXXX"
            value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
            oninput="this.value = this.value.replace(/\s+/g, '')"/>
          <svg class="ico" viewBox="0 0 24 24">
            <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/>
          </svg>
        </div>
      </div>


      <!-- Plate Number -->
      <div class="form-group">
        <label>Plate number <span class="req">*</span></label>
        <div class="input-wrap">
          <input type="text" id="plate_number" name="plate_number"
            placeholder="ABC123"
            value="<?= htmlspecialchars($_POST['plate_number'] ?? '') ?>"
            maxlength="15" required
            oninput="this.value = this.value.toUpperCase().replace(/\s+/g, '')"/>
          <svg class="ico" viewBox="0 0 24 24">
            <rect x="2" y="7" width="20" height="10" rx="2" ry="2"/>
            <path d="M6 11h.01M10 11h4M18 11h.01"/>
          </svg>
        </div>
        <div class="plate-hint">Used to identify your vehicle at the kiosk.</div>
      </div>


      <!-- Password -->
      <div class="form-group">
        <label>Password <span class="req">*</span></label>
        <div class="input-wrap">
          <input type="password" id="password" name="password"
            placeholder="Min. 6 characters" required
            oninput="checkStrength(this.value)"/>
          <svg class="ico" viewBox="0 0 24 24">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0110 0v4"/>
          </svg>
          <button type="button" class="toggle-pass" onclick="togglePass('password','eye1')" tabindex="-1">
            <svg id="eye1" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
        <div class="strength-label" id="strengthLabel"></div>
      </div>


      <!-- Confirm password -->
      <div class="form-group">
        <label>Confirm password <span class="req">*</span></label>
        <div class="input-wrap">
          <input type="password" id="confirm" name="confirm"
            placeholder="Re-enter your password" required/>
          <svg class="ico" viewBox="0 0 24 24">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0110 0v4"/>
          </svg>
          <button type="button" class="toggle-pass" onclick="togglePass('confirm','eye2')" tabindex="-1">
            <svg id="eye2" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>


    </div><!-- /form-grid -->


    <div class="card-bottom">
      <button type="submit" class="btn-submit">Create account</button>
      <div class="login-row">Already have an account? <a href="login.php">Log in</a></div>
      <div class="back-home"><a href="index.php">← Back to home</a></div>
    </div>


  </form>


</div>


<script>
  function togglePass(inputId, iconId) {
    var input = document.getElementById(inputId);
    var icon  = document.getElementById(iconId);
    if (input.type === 'password') {
      input.type = 'text';
      icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
      input.type = 'password';
      icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
  }


  function checkStrength(val) {
    var fill  = document.getElementById('strengthFill');
    var label = document.getElementById('strengthLabel');
    if (!val) { fill.style.width = '0%'; label.textContent = ''; return; }
    var score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    var levels = [
      { w: '20%', c: '#f87171', t: 'Too weak' },
      { w: '40%', c: '#fb923c', t: 'Weak' },
      { w: '60%', c: '#facc15', t: 'Fair' },
      { w: '80%', c: '#34d399', t: 'Good' },
      { w: '100%',c: '#10b981', t: 'Strong' },
    ];
    var l = levels[Math.min(score - 1, 4)] || levels[0];
    fill.style.width      = l.w;
    fill.style.background = l.c;
    label.style.color     = l.c;
    label.textContent     = l.t;
  }
</script>


</body>
</html>

