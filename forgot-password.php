<?php
session_start();
require_once 'config/db.php';


require_once 'vendor/autoload.php';


use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;


if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}


$error   = '';
$success = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');


    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, username FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();


        if ($user) {
            // Delete any existing token for this email
            $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);


            // Generate a secure token
            $token = bin2hex(random_bytes(32));


            // FIX: Use UTC time for both PHP and store as UTC so MySQL NOW() comparison works.
            // expires in 5 minutes from now.
            $expiresAt = gmdate('Y-m-d H:i:s', time() + (5 * 60));


            // Store token with UTC expiry
            $stmt = $pdo->prepare(
                "INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)"
            );
            $stmt->execute([$email, $token, $expiresAt]);

			// new link to deployed
            $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
			$resetLink = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/reset-password.php?token=' . $token;


            $displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if ($displayName === '') {
                $displayName = $user['username'] ?? '';
            }


            $mail = new PHPMailer(true);


            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp-relay.brevo.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'a82655001@smtp-brevo.com';   // ← your Gmail address
                $mail->Password   = 'xsmtpsib-d2954e97674dc3b449af90494d32cc1be274e7229b862acfaa80741b333883e4-o3YLCMlYlaVGeckv';             // brevo key
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;


                $mail->setFrom('cascayok@gmail.com', 'Parky');
                $mail->addAddress($email, $displayName);


                $mail->isHTML(true);
                $mail->Subject = 'Reset your Parky password';
                $mail->Body    = '
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8"/>
  <style>
    body { margin:0; padding:0; background:#181c18; font-family: Arial, sans-serif; }
    .wrap { max-width:520px; margin:40px auto; background:#1f231f;
            border:1px solid rgba(255,255,255,0.12); border-radius:20px; overflow:hidden; }
    .header { background:#1f231f; padding:32px 36px 20px;
              border-bottom:1px solid rgba(255,255,255,0.07); text-align:center; }
    .logo { display:inline-flex; align-items:center; gap:10px; margin-bottom:4px; }
    .logo-box { width:36px; height:36px; background:rgba(52,211,153,0.15);
                border:1.5px solid rgba(52,211,153,0.3); border-radius:10px;
                display:inline-flex; align-items:center; justify-content:center; }
    .logo-letter { font-size:1.1rem; font-weight:900; color:#34d399;
                   font-family:Arial,sans-serif; line-height:1;
                   display:block; text-align:center; width:100%; }
    .logo-name { font-size:1.2rem; font-weight:800; color:#34d399; }
    .body { padding:28px 36px; text-align:center; }
    h2 { color:#eaf2ea; font-size:1.2rem; margin:0 0 10px; }
    p  { color:#7a907a; font-size:0.9rem; line-height:1.65; margin:0 0 20px; text-align:center; }
    .btn-wrap { text-align:center; margin:24px 0 8px; }
    .btn { display:inline-block; background:#10b981; color:#000000 !important;
           text-decoration:none; font-weight:800; font-size:0.95rem;
           padding:14px 36px; border-radius:12px; }
    .notice-box { margin:20px auto 0; background:#252a25; border-radius:10px;
                  padding:12px 16px; max-width:380px; }
    .notice-box p { color:#556655; font-size:0.8rem; margin:0; text-align:center; }
    .footer { padding:20px 36px; border-top:1px solid rgba(255,255,255,0.07); }
    .footer p { color:#556655; font-size:0.75rem; margin:0; text-align:center; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <div class="logo">
        <div class="logo-box">
          <span class="logo-letter">P</span>
        </div>
        <span class="logo-name">Parky</span>
      </div>
    </div>
    <div class="body">
      <h2>Reset your password</h2>
      <p>Hi ' . htmlspecialchars($displayName) . ',<br><br>
         We received a request to reset your Parky account password.
         Click the button below to set a new one.<br>
         <strong style="color:#eaf2ea">This link expires in 5 minutes.</strong></p>
      <div class="btn-wrap">
        <a href="' . $resetLink . '" class="btn">Reset my password</a>
      </div>
      <div class="notice-box">
        <p>⚠️ This link expires in <strong style="color:#eaf2ea">5 minutes</strong> for your security.<br>If you did not request this, ignore this email.</p>
      </div>
    </div>
    <div class="footer">
      <p>If you did not request a password reset, you can safely ignore this email.
         Your password will not change.</p>
    </div>
  </div>
</body>
</html>';


                $mail->AltBody = "Hi {$displayName},\n\nReset your Parky password using this link (expires in 5 minutes):\n\n{$resetLink}\n\nIf you did not request this, ignore this email.";


                $mail->send();
                $success = 'Password reset link sent! Check your email. It expires in 5 minutes.';


         		} catch (Exception $e) {
   					 $error = 'Mail error: ' . $mail->ErrorInfo;
				}
        } else {
            $success = 'Password reset link sent! Check your email. It expires in 5 minutes.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Parky — Forgot Password</title>
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


    .heading h1 { font-size: 1.4rem; font-weight: 800; color: var(--text); letter-spacing: -0.02em; line-height: 1.2; }
    .heading p  { font-size: 0.85rem; color: var(--muted); margin-top: 5px; font-weight: 500; line-height: 1.55; }


    .divider { height: 1px; background: var(--border); margin: 1.25rem 0; }


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


    input[type="email"] {
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
    input::placeholder { color: var(--muted2); font-weight: 500; }
    input:focus { border-color: var(--emerald2); background: var(--surface2); }


    .btn-submit {
      width: 100%;
      background: var(--emerald2); color: #000000;
      border: none; border-radius: 13px;
      padding: 12px;
      font-family: 'Nunito', sans-serif;
      font-size: 0.92rem; font-weight: 800;
      cursor: pointer; margin-top: 0.25rem;
      transition: background 0.2s, transform 0.1s;
    }
    .btn-submit:hover  { background: var(--emerald); }
    .btn-submit:active { transform: scale(0.98); }


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


  <div class="icon-banner">
    <svg viewBox="0 0 24 24">
      <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
      <path d="M7 11V7a5 5 0 0110 0v4"/>
    </svg>
  </div>


  <div class="heading">
    <h1>Forgot your password?</h1>
    <p>No worries. Enter your registered email and we'll send you a link to reset your password. The link expires in <strong style="color:var(--emerald)">5 minutes</strong>.</p>
  </div>


  <div class="divider"></div>


  <?php if ($error): ?>
    <div class="msg-box msg-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="msg-box msg-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>


  <?php if (!$success): ?>
  <form method="POST" action="forgot-password.php">
    <div class="form-group">
      <label>Email address</label>
      <div class="input-wrap">
        <input type="email" name="email" placeholder="juan@email.com"
          value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
          required autofocus/>
        <svg class="ico" viewBox="0 0 24 24">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
      </div>
    </div>
    <button type="submit" class="btn-submit">Send reset link</button>
  </form>
  <?php endif; ?>


  <div class="back-row">
    Remember your password? <a href="login.php">Back to login</a>
  </div>


</div>


</body>
</html>

