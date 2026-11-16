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

/** Send one SMTP message. Returns null on success, otherwise a user-safe error. */
function mbus_send_smtp_message(string $email, string $subject, string $html_body, string $text_body, string $recipient_name = ''): ?string
{
    $username = trim((string)getenv('SMTP_USERNAME'));
    $password = trim((string)getenv('SMTP_PASSWORD'));
    $from = trim((string)(getenv('SMTP_FROM') ?: $username));
    if ($username === '' || $password === '' || $from === '') {
        error_log('MBUS SMTP is not configured: SMTP_USERNAME, SMTP_PASSWORD, and SMTP_FROM are required.');
        return 'Email delivery is not configured yet. Please contact the administrator.';
    }

    $configured_host = trim((string)(getenv('SMTP_HOST') ?: 'smtp.gmail.com'));
    $configured_port = (int)(getenv('SMTP_PORT') ?: 465);
    $configured_secure = strtolower(trim((string)(getenv('SMTP_SECURE') ?: 'ssl')));
    $attempts = [[$configured_port, $configured_secure]];
    // Gmail supports both. 587/TLS is often more reliable from hosted services than 465/SSL.
    if ($configured_port === 465 && $configured_secure === 'ssl') $attempts[] = [587, 'tls'];
    if ($configured_port === 587 && $configured_secure === 'tls') $attempts[] = [465, 'ssl'];

    $last_message = '';
    foreach ($attempts as [$port, $secure]) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $configured_host;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->SMTPSecure = $secure;
            $mail->Port = $port;
            $mail->Timeout = 8;
            $mail->SMTPKeepAlive = false;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from, 'MBUS System');
            $mail->addAddress($email, $recipient_name);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html_body;
            $mail->AltBody = $text_body;
            $mail->send();
            return null;
        } catch (PHPMailerException|RuntimeException|Throwable $exception) {
            $last_message = $exception->getMessage();
            error_log("MBUS SMTP attempt failed on {$configured_host}:{$port}/{$secure}: {$last_message}");
        }
    }

    return 'We could not send the verification code right now. Please try again in a few moments or contact the administrator.';
}

function mbus_send_reset_code(string $email, string $code): ?string
{
    $safe_code = mbus_reset_escape($code);
    return mbus_send_smtp_message(
        $email,
        'Your MBUS password reset code',
        '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#14263f">'
        . '<h2 style="margin-bottom:8px">Reset your MBUS password</h2>'
        . '<p>Use this 6-digit code to continue:</p>'
        . '<p style="font-size:28px;letter-spacing:8px;font-weight:700;color:#086FCF">' . $safe_code . '</p>'
        . '<p>This code expires in 15 minutes. If you did not request a reset, you can safely ignore this email.</p></div>',
        "Your MBUS password reset code is {$code}. It expires in 15 minutes. If you did not request this, ignore this email."
    );
}

function mbus_send_registration_code(string $email, string $code, string $full_name = ''): ?string
{
    $safe_code = mbus_reset_escape($code);
    return mbus_send_smtp_message(
        $email,
        'Verify your MBUS account',
        '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#14263f">'
        . '<h2 style="margin-bottom:8px">Verify your MBUS account</h2>'
        . '<p>Use this 6-digit code to finish creating your MBUS account:</p>'
        . '<p style="font-size:28px;letter-spacing:8px;font-weight:700;color:#086FCF">' . $safe_code . '</p>'
        . '<p>This code expires in 15 minutes.</p></div>',
        "Your MBUS verification code is {$code}. It expires in 15 minutes.",
        $full_name
    );
}

function mbus_reset_mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($local === '' || $domain === '') return $email;
    $visible = strlen($local) <= 2 ? substr($local, 0, 1) : substr($local, 0, 2);
    return $visible . str_repeat('•', max(1, strlen($local) - strlen($visible))) . '@' . $domain;
}
