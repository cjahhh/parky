<?php
$url = getenv('PARKY_TF_SERVICE_URL');
echo "URL: " . $url . "\n";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$res = curl_exec($ch);
$err = curl_error($ch);
echo "Result: " . $res . "\n";
echo "Error: " . $err . "\n";
