<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo '<pre>';
echo "PHPMailer installed: " . (class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'YES' : 'NO (falling back to mail(), which fails on XAMPP)') . "\n";
echo "MAIL_FROM: " . (defined('MAIL_FROM') ? MAIL_FROM : 'not defined') . "\n\n";

$result = sendOtpEmail('david.abokunwa220591011@st.lasu.edu.ng', '123456', 'registration');
var_dump($result);