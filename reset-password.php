<?php
session_start();
require_once 'config/db.php';


if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}


$error      = '';
$success    = '';
$token      = trim($_GET['token'] ?? '');
$validToken = false;
$resetEmail = '';
$expiresAt  = '';   // will hold the UTC expiry string for the JS countdown


// ── Validate token on page load ─────────────────────────────────────────────
// FIX: Compare against UTC_TIMESTAMP() so PHP (gmdate) and MySQL agree on time
if (empty($token)) {
    $error = 'Invalid or missing reset token.';
} else {
    $stmt = $pdo->prepare(
        "SELECT * FROM password_resets
         WHERE token = ? AND expires_at > UTC_TIMESTAMP()
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();


    if ($row) {
        $validToken = true;
        $resetEmail = $row['email'];
        $expiresAt  = $row['expires_at']; // stored as UTC, e.g. "2025-07-10 01:35:00"
    } else {
        $error = 'This reset link is invalid or has already expired.';
    }
}


// ── Handle form submission ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';


    if (empty($password) || empty($confirm)) {
        $error = 'Please fill in both fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($password, PASSWORD_BCRYPT);


        $pdo->prepare("UPDATE users SET password = ? WHERE email = ?")
            ->execute([$hashed, $resetEmail]);


        $pdo->prepare("DELETE FROM password_resets WHERE token = ?")
            ->execute([$token]);


        $success    = 'Password updated successfully! You can now log in.';
        $validToken = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky — Reset Password</title>
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
      --warningbg:  rgba(251,146,60,0.1);
      --warning:    #fb923c;
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
      padding: 2.5rem 2.25rem;
      width: 100%;
      max-width: 420px;
      position: relative;
      animation: fadeUp 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
    }


    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(24px); }
      to   { opacity: 1; transform: translateY(0); }
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
    .logo-icon span {
      font-size: 1.1rem; font-weight: 900;
      color: var(--emerald); line-height: 1;
    }
    .logo-text {
      font-size: 1.25rem; font-weight: 800;
      color: var(--emerald); letter-spacing: -0.02em;
    }


    .icon-banner {
      width: 52px; height: 52px;
      background: var(--emeraldbg2);
      border: 1.5px solid rgba(52,211,153,0.25);
      border-radius: 14px;
      display: flex; align-items: center; justify-content: center;
      margin-bottom: 1.1rem;
    }
    .icon-banner svg {
      width: 24px; height: 24px; fill: none; stroke: var(--emerald);
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
    }
    .icon-banner.danger {
      background: var(--dangerbg);
      border-color: rgba(248,113,113,0.25);
    }
    .icon-banner.danger svg { stroke: var(--danger); }


    .heading h1 { font-size: 1.4rem; font-weight: 800; color: var(--text); letter-spacing: -0.02em; line-height: 1.2; }
    .heading p  { font-size: 0.85rem; color: var(--muted); margin-top: 5px; font-weight: 500; line-height: 1.55; }


    .divider { height: 1px; background: var(--border); margin: 1.25rem 0; }


    /* ── Countdown Timer ── */
    .countdown-wrap {
      background: var(--surface);
      border: 1.5px solid var(--border2);
      border-radius: 14px;
      padding: 12px 16px;
      margin-bottom: 1.1rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .countdown-wrap svg {
      width: 18px; height: 18px; fill: none;
      stroke: var(--emerald); stroke-width: 2;
      stroke-linecap: round; stroke-linejoin: round;
      flex-shrink: 0; transition: stroke 0.4s;
    }
    .countdown-text {
      font-size: 0.82rem; font-weight: 700;
      color: var(--muted); flex: 1;
    }
    .countdown-timer {
      font-size: 1.05rem; font-weight: 900;
      color: var(--emerald);
      font-variant-numeric: tabular-nums;
      letter-spacing: 0.04em;
      min-width: 42px;
      text-align: right;
      transition: color 0.4s;
    }


    /* Danger state when <= 60s */
    .countdown-wrap.urgent {
      border-color: rgba(248,113,113,0.35);
      background: var(--dangerbg);
    }
    .countdown-wrap.urgent svg { stroke: var(--danger); }
    .countdown-wrap.urgent .countdown-timer { color: var(--danger); }
    .countdown-wrap.urgent .countdown-text  { color: var(--danger); }


    /* Warning state when <= 120s */
    .countdown-wrap.warning {
      border-color: rgba(251,146,60,0.35);
      background: var(--warningbg);
    }
    .countdown-wrap.warning svg { stroke: var(--warning); }
    .countdown-wrap.warning .countdown-timer { color: var(--warning); }
    .countdown-wrap.warning .countdown-text  { color: var(--warning); }


    /* Pulse animation when urgent */
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50%       { opacity: 0.55; }
    }
    .countdown-wrap.urgent .countdown-timer { animation: pulse 1s ease-in-out infinite; }


    /* ── Messages ── */
    .msg-box {
      border-radius: 12px; padding: 10px 14px;
      font-size: 0.83rem; font-weight: 600;
      margin-bottom: 1.1rem;
      display: flex; align-items: center; gap: 8px;
    }
    .msg-box::before { content: ''; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .msg-error   { background: var(--dangerbg); border: 1px solid rgba(248,113,113,0.25); color: var(--danger); }
    .msg-error::before { background: var(--danger); }
    .msg-success { background: var(--successbg); border: 1px solid rgba(52,211,153,0.25); color: var(--emerald); }
    .msg-success::before { background: var(--emerald); }


    /* ── Form ── */
    .form-group { margin-bottom: 1rem; }


    label {
      display: block; font-size: 0.75rem; font-weight: 700;
      color: var(--muted); margin-bottom: 6px;
      letter-spacing: 0.04em; text-transform: uppercase;
    }


    .input-wrap { position: relative; }


    .input-wrap svg.ico {
      position: absolute; left: 13px; top: 50%;
      transform: translateY(-50%);
      width: 16px; height: 16px;
      stroke: var(--muted2); fill: none;
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
      pointer-events: none; transition: stroke 0.2s;
    }
    .input-wrap:focus-within svg.ico { stroke: var(--emerald2); }


    input[type="password"],
    input[type="text"] {
      width: 100%;
      background: var(--surface);
      border: 1.5px solid var(--border2);
      border-radius: 12px;
      padding: 12px 42px 12px 42px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.92rem; font-weight: 600;
      color: var(--text); outline: none;
      transition: border-color 0.2s, background 0.2s;
    }
    input::placeholder { color: var(--muted2); font-weight: 500; }
    input:focus { border-color: var(--emerald2); background: var(--surface2); }


    input.match-ok    { border-color: var(--emerald2); }
    input.match-error { border-color: var(--danger); }


    .toggle-pass {
      position: absolute; right: 12px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      padding: 2px; display: flex; align-items: center;
    }
    .toggle-pass svg {
      width: 16px; height: 16px; fill: none;
      stroke: var(--muted2); stroke-width: 2;
      stroke-linecap: round; stroke-linejoin: round;
      transition: stroke 0.2s;
    }
    .toggle-pass:hover svg { stroke: var(--muted); }


    .strength-bar {
      height: 3px; border-radius: 2px;
      background: var(--surface); margin-top: 6px; overflow: hidden;
    }
    .strength-fill {
      height: 100%; border-radius: 2px; width: 0%;
      transition: width 0.3s, background 0.3s;
    }
    .strength-label { font-size: 0.72rem; font-weight: 600; margin-top: 3px; color: var(--muted2); }


    .match-label {
      font-size: 0.72rem; font-weight: 600;
      margin-top: 3px; min-height: 1em;
      transition: color 0.2s;
    }


    .btn-submit {
      width: 100%;
      background: var(--emerald2); color: #000000;
      border: none; border-radius: 13px; padding: 12px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.92rem; font-weight: 800;
      cursor: pointer; margin-top: 0.25rem;
      transition: background 0.2s, transform 0.1s;
    }
    .btn-submit:hover  { background: var(--emerald); }
    .btn-submit:active { transform: scale(0.98); }
    .btn-submit:disabled {
      background: var(--surface2); color: var(--muted2);
      cursor: not-allowed;
    }


    .btn-login {
      display: block; width: 100%; text-align: center;
      background: var(--emerald2); color: #000000;
      border: none; border-radius: 13px; padding: 12px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.92rem; font-weight: 800;
      text-decoration: none; margin-top: 1rem;
      transition: background 0.2s;
    }
    .btn-login:hover { background: var(--emerald); }


    .back-row {
      text-align: center; margin-top: 1.1rem;
      font-size: 0.82rem; color: var(--muted); font-weight: 600;
    }
    .back-row a { color: var(--emerald); text-decoration: none; font-weight: 700; }
    .back-row a:hover { opacity: 0.8; }
  </style>
</head>
<body>
<div class="card">


  <div class="logo">
    <div class="logo-icon">
      <span>P</span>
    </div>
    <span class="logo-text">Parky</span>
  </div>


  <?php if ($success): ?>
    <!-- ── SUCCESS STATE ── -->
    <div class="icon-banner">
      <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <div class="heading">
      <h1>Password updated!</h1>
      <p>Your password has been reset successfully. You can now log in with your new password.</p>
    </div>
    <div class="divider"></div>
    <div class="msg-box msg-success"><?= htmlspecialchars($success) ?></div>
    <a href="login.php" class="btn-login">Go to login</a>


  <?php elseif (!$validToken): ?>
    <!-- ── INVALID / EXPIRED TOKEN STATE ── -->
    <div class="icon-banner danger">
      <svg viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
    </div>
    <div class="heading">
      <h1>Link expired</h1>
      <p>This password reset link is invalid or has already expired. Please request a new one.</p>
    </div>
    <div class="divider"></div>
    <?php if ($error): ?>
      <div class="msg-box msg-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div class="back-row">
      <a href="forgot-password.php">← Request a new reset link</a>
    </div>


  <?php else: ?>
    <!-- ── RESET FORM STATE ── -->
    <div class="icon-banner">
      <svg viewBox="0 0 24 24">
        <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
      </svg>
    </div>
    <div class="heading">
      <h1>Set new password</h1>
      <p>Choose a strong password for your Parky account. It must be at least 8 characters.</p>
    </div>
    <div class="divider"></div>


    <!-- ── Countdown Timer ── -->
    <div class="countdown-wrap" id="countdownWrap">
      <svg viewBox="0 0 24 24" id="countdownIcon">
        <circle cx="12" cy="12" r="10"/>
        <polyline points="12 6 12 12 16 14"/>
      </svg>
      <span class="countdown-text" id="countdownText">Link expires in</span>
      <span class="countdown-timer" id="countdownDisplay">5:00</span>
    </div>


    <?php if ($error): ?>
      <div class="msg-box msg-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>


    <form method="POST" action="reset-password.php?token=<?= urlencode($token) ?>" id="resetForm">
      <div class="form-group">
        <label>New password</label>
        <div class="input-wrap">
          <input type="password" id="password" name="password"
            placeholder="Min. 8 characters" required
            oninput="checkStrength(this.value); checkMatch();"/>
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


      <div class="form-group">
        <label>Confirm new password</label>
        <div class="input-wrap">
          <input type="password" id="confirm" name="confirm"
            placeholder="Re-enter your new password" required
            oninput="checkMatch();"/>
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
        <div class="match-label" id="matchLabel"></div>
      </div>


      <button type="submit" class="btn-submit" id="submitBtn">Update password</button>
    </form>


    <div class="back-row">
      Remember your password? <a href="login.php">Back to login</a>
    </div>
  <?php endif; ?>


</div>


<?php if ($validToken && !$success): ?>
<script>
  // FIX: expires_at is stored as UTC. We append ' UTC' so the browser's
  // Date constructor parses it as UTC instead of local time, ensuring the
  // countdown matches the server's clock regardless of the user's timezone.
  var expiresAt = new Date('<?= $expiresAt ?> UTC');


  var wrap    = document.getElementById('countdownWrap');
  var display = document.getElementById('countdownDisplay');
  var text    = document.getElementById('countdownText');
  var btn     = document.getElementById('submitBtn');


  function updateCountdown() {
    var now       = new Date();
    var remaining = Math.floor((expiresAt - now) / 1000);


    if (remaining <= 0) {
      display.textContent = '0:00';
      wrap.classList.remove('warning');
      wrap.classList.add('urgent');
      text.textContent = 'Link has expired!';
      btn.disabled = true;
      // Redirect after 2 seconds to show the expired state from PHP
      setTimeout(function() {
        window.location.href = 'reset-password.php?token=<?= urlencode($token) ?>';
      }, 2000);
      return;
    }


    var mins = Math.floor(remaining / 60);
    var secs = remaining % 60;
    display.textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;


    // State transitions
    wrap.classList.remove('urgent', 'warning');
    if (remaining <= 60) {
      wrap.classList.add('urgent');
      text.textContent = 'Hurry! Link expires in';
    } else if (remaining <= 120) {
      wrap.classList.add('warning');
      text.textContent = 'Link expires in';
    } else {
      text.textContent = 'Link expires in';
    }


    setTimeout(updateCountdown, 1000);
  }


  updateCountdown();
</script>
<?php endif; ?>


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


    if (!val) {
      fill.style.width = '0%';
      label.textContent = '';
      return;
    }


    var score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;


    var levels = [
      { w: '20%', c: '#f87171', t: 'Too weak'  },
      { w: '40%', c: '#fb923c', t: 'Weak'      },
      { w: '60%', c: '#facc15', t: 'Fair'      },
      { w: '80%', c: '#34d399', t: 'Good'      },
      { w: '100%',c: '#10b981', t: 'Strong'    },
    ];
    var index = score > 0 ? Math.min(score - 1, 4) : 0;
    var l = levels[index];


    fill.style.width      = l.w;
    fill.style.background = l.c;
    label.style.color     = l.c;
    label.textContent     = l.t;
  }


  function checkMatch() {
    var pw    = document.getElementById('password').value;
    var conf  = document.getElementById('confirm').value;
    var input = document.getElementById('confirm');
    var label = document.getElementById('matchLabel');


    if (!conf) {
      input.classList.remove('match-ok', 'match-error');
      label.textContent = '';
      return;
    }


    if (pw === conf) {
      input.classList.add('match-ok');
      input.classList.remove('match-error');
      label.style.color = '#34d399';
      label.textContent = '✓ Passwords match';
    } else {
      input.classList.add('match-error');
      input.classList.remove('match-ok');
      label.style.color = '#f87171';
      label.textContent = '✗ Passwords do not match';
    }
  }
</script>
</body>
</html>

