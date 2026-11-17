<?php
declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class Mailer
{
    public static function configured(): bool
    {
        $config = require dirname(__DIR__, 2) . '/config/mail.php';
        return class_exists(PHPMailer::class) && $config['username'] !== '' && $config['password'] !== ''
            && (bool) filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)
            && in_array($config['encryption'], ['tls', 'ssl'], true);
    }

    public function sendPasswordResetCode(string $recipient, string $code): void
    {
        $this->sendNotification($recipient, 'GreenPulse | Password reset code',
            "Your GreenPulse password reset code is: $code\n\nThis code expires in 10 minutes and can be used only once. Do not share it.\n\nIf you did not request this, you can ignore this email.");
    }

    public function sendNotification(string $recipient, string $subject, string $message): void
    {
        if (!self::configured()) throw new RuntimeException('SMTP configuration is incomplete or PHPMailer is not installed.');
        $config = require dirname(__DIR__, 2) . '/config/mail.php';
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $config['port'];
            $mail->Timeout = 10;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = '<html><body style="font-family:Arial,sans-serif;color:#14261c"><h1>GreenPulse</h1><p>'
                . nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p><p>This is an automated message.</p></body></html>';
            $mail->AltBody = $message . "\n\nThis is an automated message.";
            $mail->send();
        } catch (\PHPMailer\PHPMailer\Exception $error) {
            throw new RuntimeException('SMTP email delivery failed.', 0, $error);
        }
    }
}
