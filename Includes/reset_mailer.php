<?php

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

function mbus_reset_escape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Send a reset code. Returns null on success, otherwise a user-safe error. */
function mbus_send_reset_code(string $email, string $code): ?string
{
    try {
        $username = trim((string)getenv('SMTP_USERNAME'));
        $password = trim((string)getenv('SMTP_PASSWORD'));
        $from = trim((string)(getenv('SMTP_FROM') ?: $username));
        if ($username === '' || $password === '' || $from === '') {
            throw new RuntimeException('SMTP credentials are not configured.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com');
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = (string)(getenv('SMTP_SECURE') ?: 'ssl');
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 465);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($from, 'MBUS System');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Your MBUS password reset code';
        $mail->Body = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#14263f">'
            . '<h2 style="margin-bottom:8px">Reset your MBUS password</h2>'
            . '<p>Use this 6-digit code to continue:</p>'
            . '<p style="font-size:28px;letter-spacing:8px;font-weight:700;color:#086FCF">' . mbus_reset_escape($code) . '</p>'
            . '<p>This code expires in 15 minutes. If you did not request a reset, you can safely ignore this email.</p>'
            . '</div>';
        $mail->AltBody = "Your MBUS password reset code is {$code}. It expires in 15 minutes. If you did not request this, ignore this email.";
        $mail->send();
        return null;
    } catch (PHPMailerException|RuntimeException|Throwable $exception) {
        error_log('Password reset email failed: ' . $exception->getMessage());
        return 'We could not send the verification code right now. Please check your email address and try again.';
    }
}

function mbus_reset_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($local === '' || $domain === '') return $email;
    $visible = strlen($local) <= 2 ? substr($local, 0, 1) : substr($local, 0, 2);
    return $visible . str_repeat('•', max(1, strlen($local) - strlen($visible))) . '@' . $domain;
}
