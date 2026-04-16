<?php

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

function send_verification_email(string $toEmail, string $displayName, string $verifyUrl): bool {
    $apiKey = getenv('BREVO_API_KEY');
    $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    $safeUrl  = htmlspecialchars($verifyUrl,   ENT_QUOTES, 'UTF-8');

    $htmlBody = '
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
    .logo-box { width:36px; height:36px; background:rgba(52,211,153,0.15);
                border:1.5px solid rgba(52,211,153,0.3); border-radius:10px;
                display:inline-flex; align-items:center; justify-content:center; }
    .logo-letter { font-size:1.1rem; font-weight:900; color:#34d399; }
    .logo-name { font-size:1.2rem; font-weight:800; color:#34d399; }
    .body { padding:28px 36px; text-align:center; }
    h2 { color:#eaf2ea; font-size:1.2rem; margin:0 0 10px; }
    p  { color:#7a907a; font-size:0.9rem; line-height:1.65; margin:0 0 20px; }
    .btn { display:inline-block; background:#10b981; color:#000000 !important;
           text-decoration:none; font-weight:800; font-size:0.95rem;
           padding:14px 36px; border-radius:12px; }
    .notice-box { margin:20px auto 0; background:#252a25; border-radius:10px;
                  padding:12px 16px; max-width:380px; }
    .notice-box p { color:#556655; font-size:0.8rem; margin:0; }
    .footer { padding:20px 36px; border-top:1px solid rgba(255,255,255,0.07); }
    .footer p { color:#556655; font-size:0.75rem; margin:0; text-align:center; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <div style="display:inline-flex;align-items:center;gap:10px;">
        <div class="logo-box"><span class="logo-letter">P</span></div>
        <span class="logo-name">Parky</span>
      </div>
    </div>
    <div class="body">
      <h2>Confirm your email</h2>
      <p>Hi ' . $safeName . ',<br><br>
         Thanks for signing up. Please verify your email address to activate your account.<br>
         <strong style="color:#eaf2ea">This link expires in 1 hour.</strong></p>
      <div style="text-align:center;margin:24px 0 8px;">
        <a href="' . $safeUrl . '" class="btn">Verify my email</a>
      </div>
      <div class="notice-box">
        <p>If you did not create a Parky account, you can ignore this email.</p>
      </div>
    </div>
    <div class="footer">
      <p>If the button does not work, copy and paste this link:<br>
         <span style="word-break:break-all;color:#7a907a;">' . $safeUrl . '</span></p>
    </div>
  </div>
</body>
</html>';

    $payload = json_encode([
        'sender'      => ['name' => 'Parky', 'email' => 'cj1009garcia@gmail.com'],
        'to'          => [['email' => $toEmail, 'name' => $displayName]],
        'subject'     => 'Verify your Parky account',
        'htmlContent' => $htmlBody,
        'textContent' => "Hi {$displayName},\n\nVerify your Parky account (expires in 1 hour):\n\n{$verifyUrl}\n\nIf you did not sign up, ignore this email.",
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 201) {
        return true;
    }

    error_log('Verification email error: HTTP ' . $httpCode . ' — ' . $response . ' ' . $curlErr);
    return false;
}
