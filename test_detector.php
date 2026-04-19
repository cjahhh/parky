<?php
$url = getenv('PARKY_TF_SERVICE_URL')
    ?: ($_ENV['PARKY_TF_SERVICE_URL'] ?? '')
    ?: ($_SERVER['PARKY_TF_SERVICE_URL'] ?? '');

echo "URL: [" . $url . "]\n\n";

// Test a dummy POST to the detector
$payload = json_encode([
    'image_b64' => 'test',
    'request_id' => 'test123',
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_VERBOSE        => true,
]);
$raw  = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $code . "\n";
echo "Error: " . $err . "\n";
echo "Response: " . $raw . "\n";
