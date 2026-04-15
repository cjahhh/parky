<?php
require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->SMTPDebug  = 2; // Show full debug output
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'smoochzaki@gmail.com';
    $mail->Password   = 'ayrt uipd fbrm zrmb';
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;
    $mail->SMTPOptions = array(
    'ssl' => array(
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true
    )
);
    $mail->setFrom('smoochzaki@gmail.com', 'Parky');
    $mail->addAddress('smoochzaki@gmail.com'); // send to yourself
    $mail->Subject = 'Parky Test';
    $mail->Body    = 'Test email working!';
    $mail->send();
    echo 'Email sent successfully!';
} catch (Exception $e) {
    echo 'Error: ' . $mail->ErrorInfo;
}