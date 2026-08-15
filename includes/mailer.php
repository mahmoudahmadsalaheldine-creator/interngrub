<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function _buildMailer() {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(SMTP_FROM ?: SMTP_USER, 'InternGrub');
    return $mail;
}

function sendEmail($toEmail, $subject, $body, $isHtml = true) {
    if (!defined('SMTP_USER') || SMTP_USER === '') return false;
    try {
        $mail = _buildMailer();
        $mail->addAddress($toEmail);
        $mail->Subject = $subject;
        if ($isHtml) {
            $mail->isHTML(true);
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);
        } else {
            $mail->isHTML(false);
            $mail->Body = $body;
        }
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function sendOtpEmail($toEmail, $otp) {
    $subject = 'InternGrub Password Reset OTP';
    $body    = "Your InternGrub password reset OTP is:\r\n\r\n    {$otp}\r\n\r\nThis code expires in 15 minutes. Do not share it with anyone.";
    return sendEmail($toEmail, $subject, $body, false);
}
