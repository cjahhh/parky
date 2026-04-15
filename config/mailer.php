<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;


require_once __DIR__ . '/../vendor/autoload.php';


function createMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp-relay.brevo.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'a82655001@smtp-brevo.com';    // ← brevo sender
    $mail->Password   = 'xsmtpsib-d2954e97674dc3b449af90494d32cc1be274e7229b862acfaa80741b333883e4-o3YLCMlYlaVGeckv';  // brevo
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->SMTPOptions = array(
    'ssl' => array(
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true
    )
);
    $mail->setFrom('cascayok@gmail.com', 'Parky');
    $mail->isHTML(true);
    return $mail;
}


/** Base URL for links in outbound mail (same folder as register.php / verify.php). */
function app_public_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $path   = ($dir === '' || $dir === '/') ? '' : $dir;
    return $scheme . '://' . $host . $path;
}


/**
 * Sends the email verification message. Returns true on success.
 */
function send_verification_email(string $toEmail, string $displayName, string $verifyUrl): bool {
    try {
        $mail = createMailer();
        $mail->addAddress($toEmail, $displayName);
        $mail->Subject = 'Verify your Parky account';
        $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
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
      <h2>Confirm your email</h2>
      <p>Hi ' . $safeName . ',<br><br>
         Thanks for signing up. Please verify your email address to activate your account.<br>
         <strong style="color:#eaf2ea">This link expires in 1 hour.</strong></p>
      <div class="btn-wrap">
        <a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '" class="btn">Verify my email</a>
      </div>
      <div class="notice-box">
        <p>If you did not create a Parky account, you can ignore this email.</p>
      </div>
    </div>
    <div class="footer">
      <p>If the button does not work, copy and paste this link into your browser:<br>
         <span style="word-break:break-all;color:#7a907a;">' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '</span></p>
    </div>
  </div>
</body>
</html>';
        $mail->AltBody = "Hi {$displayName},\n\nVerify your Parky account (link expires in 1 hour):\n\n{$verifyUrl}\n\nIf you did not sign up, ignore this email.";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Verification email error: ' . $e->getMessage());
        return false;
    }
}

