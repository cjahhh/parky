<?php
session_start();
require_once '../config/db.php';


// Redirect if already logged in as admin
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}


$error = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';


    if (empty($username) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();


        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];


            // Update last login timestamp
            $upd = $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
            $upd->execute([$admin['id']]);


            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky Admin — Log in</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }


    :root {
      /* Slightly darker than login.php to feel more serious */
      --bg:         #111411;
      --bg2:        #161a16;
      --surface:    #1d221d;
      --surface2:   #232923;
      --border:     rgba(255,255,255,0.06);
      --border2:    rgba(255,255,255,0.10);
      --text:       #eaf2ea;
      --muted:      #7a907a;
      --muted2:     #4a5a4a;
      --emerald:    #34d399;
      --emerald2:   #10b981;
      --emerald3:   #059669;
      --emeraldbg:  rgba(52,211,153,0.08);
      --emeraldbg2: rgba(52,211,153,0.13);
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


    /* Same subtle glow as login.php */
    body::before {
      content: '';
      position: absolute;
      top: -180px; right: -180px;
      width: 480px; height: 480px;
      background: radial-gradient(circle, rgba(52,211,153,0.05) 0%, transparent 70%);
      pointer-events: none;
    }


    body::after {
      content: '';
      position: absolute;
      bottom: -150px; left: -150px;
      width: 400px; height: 400px;
      background: radial-gradient(circle, rgba(52,211,153,0.03) 0%, transparent 70%);
      pointer-events: none;
    }


    /* Card — same as login.php */
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


    /* Logo — same structure, shield icon instead of house */
    .logo {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 1.5rem;
    }


    .logo-icon {
      width: 40px; height: 40px;
      background: var(--emeraldbg2);
      border: 1.5px solid rgba(52,211,153,0.3);
      border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
    }


    .logo-icon svg {
      width: 20px; height: 20px;
      fill: none; stroke: var(--emerald);
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
    }


    .logo-text {
      font-size: 1.3rem; font-weight: 800;
      color: var(--emerald); letter-spacing: -0.02em;
    }


    /* Admin badge — unique distinction from login.php */
    .admin-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--emeraldbg2);
      border: 1px solid rgba(52,211,153,0.25);
      border-radius: 20px;
      padding: 4px 12px;
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--emerald);
      letter-spacing: 0.06em;
      text-transform: uppercase;
      margin-bottom: 1rem;
    }


    .admin-badge::before {
      content: '';
      width: 6px; height: 6px;
      background: var(--emerald);
      border-radius: 50%;
    }


    /* Heading */
    .heading { margin-bottom: 0.4rem; }


    .heading h1 {
      font-size: 1.5rem; font-weight: 800;
      color: var(--text); letter-spacing: -0.02em; line-height: 1.2;
    }


    .heading p {
      font-size: 0.88rem; color: var(--muted);
      margin-top: 5px; font-weight: 500;
    }


    .divider { height: 1px; background: var(--border); margin: 1.5rem 0; }


    /* Error — same as login.php */
    .error-box {
      background: var(--dangerbg);
      border: 1px solid rgba(248,113,113,0.25);
      border-radius: 12px;
      padding: 10px 14px;
      font-size: 0.83rem;
      color: var(--danger); font-weight: 600;
      margin-bottom: 1.25rem;
      display: flex; align-items: center; gap: 8px;
    }


    .error-box::before {
      content: '';
      width: 6px; height: 6px;
      background: var(--danger);
      border-radius: 50%; flex-shrink: 0;
    }


    /* Form — identical to login.php */
    .form-group { margin-bottom: 1rem; }


    label {
      display: block;
      font-size: 0.82rem; font-weight: 700;
      color: var(--muted); margin-bottom: 7px;
      letter-spacing: 0.03em; text-transform: uppercase;
    }


    .input-wrap { position: relative; }


    .input-wrap svg {
      position: absolute; left: 14px; top: 50%;
      transform: translateY(-50%);
      width: 17px; height: 17px;
      stroke: var(--muted2); fill: none;
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
      pointer-events: none; transition: stroke 0.2s;
    }


    input[type="text"],
    input[type="password"] {
      width: 100%;
      background: var(--surface);
      border: 1.5px solid var(--border2);
      border-radius: 12px;
      padding: 12px 14px 12px 42px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.92rem; font-weight: 600;
      color: var(--text); outline: none;
      transition: border-color 0.2s, background 0.2s;
    }


    input[type="text"]::placeholder,
    input[type="password"]::placeholder { color: var(--muted2); font-weight: 500; }


    input[type="text"]:focus,
    input[type="password"]:focus {
      border-color: var(--emerald2);
      background: var(--surface2);
    }


    .input-wrap:focus-within svg { stroke: var(--emerald); }


    .toggle-pass {
      position: absolute; right: 14px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none;
      cursor: pointer; padding: 2px;
      display: flex; align-items: center;
    }


    .toggle-pass svg {
      position: static; transform: none;
      width: 17px; height: 17px;
      stroke: var(--muted2); transition: stroke 0.2s;
    }


    .toggle-pass:hover svg { stroke: var(--muted); }


    /* Submit — same as login.php */
    .btn-submit {
      width: 100%;
      background: var(--emerald2); color: #0a1a12;
      border: none; border-radius: 14px; padding: 13px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.95rem; font-weight: 800;
      cursor: pointer; margin-top: 1.4rem;
      letter-spacing: 0.01em;
      transition: background 0.2s, transform 0.1s;
    }


    .btn-submit:hover  { background: var(--emerald); }
    .btn-submit:active { transform: scale(0.98); }


    /* Back to website — replaces register + forgot links */
    .back-home {
      text-align: center; margin-top: 1.25rem;
    }


    .back-home a {
      font-size: 0.8rem; color: var(--muted2);
      text-decoration: none; font-weight: 600;
      transition: color 0.2s;
    }


    .back-home a:hover { color: var(--muted); }


    /* Security notice — unique to admin */
    .security-note {
      display: flex; align-items: center; gap: 8px;
      background: var(--surface);
      border: 1px solid var(--border2);
      border-radius: 10px;
      padding: 10px 14px;
      margin-top: 1.25rem;
    }


    .security-note svg {
      width: 14px; height: 14px;
      stroke: var(--muted2); fill: none;
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
      flex-shrink: 0;
    }


    .security-note span {
      font-size: 0.75rem; color: var(--muted2); font-weight: 600;
      line-height: 1.4;
    }
  </style>
</head>
<body>


  <div class="card">


    <!-- Logo -->
    <div class="logo">
      <div class="logo-icon">
        <!-- Shield icon — distinct from user login house icon -->
        <svg viewBox="0 0 24 24">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
      </div>
      <span class="logo-text">Parky</span>
    </div>


    <!-- Admin badge — unique distinction -->
    <div class="admin-badge">Admin Panel</div>


    <!-- Heading -->
    <div class="heading">
      <h1>Administrator login</h1>
      <p>Sign in to access the Parky management dashboard.</p>
    </div>


    <div class="divider"></div>


    <!-- Error message -->
    <?php if ($error): ?>
      <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>


    <!-- Form -->
    <form method="POST" action="admin-login.php" autocomplete="off">


      <div class="form-group">
        <label for="username">Username</label>
        <div class="input-wrap">
          <input
            type="text"
            id="username"
            name="username"
            placeholder="Enter admin username"
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
            placeholder="Enter admin password"
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
        <!-- No forgot password link for admin -->
      </div>


      <button type="submit" class="btn-submit">Sign in to dashboard</button>


    </form>


    <!-- Security note — unique to admin login -->
    <div class="security-note">
      <svg viewBox="0 0 24 24">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0110 0v4"/>
      </svg>
      <span>Restricted access. Authorized personnel only.</span>
    </div>


    <!-- Back to website — no register link for admin -->
    <div class="back-home">
      <a href="../index.php">← Back to website</a>
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

