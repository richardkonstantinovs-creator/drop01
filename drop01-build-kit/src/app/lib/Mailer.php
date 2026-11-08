<?php
// SMTP email through the vendored PHPMailer. Settings come from Settings → Email.
defined('DROP01') || exit;

use PHPMailer\PHPMailer\PHPMailer;

final class Mailer {
    public static function configured(): bool {
        $s = Settings::get('smtp');
        return !empty($s['host']) && !empty($s['from']);
    }

    /** Sends one email. $headers: extra headers such as In-Reply-To for Inbox replies. Returns [true, ''] or [false, 'reason']. */
    public static function send(string $to, string $subject, string $html, string $text, array $attachments = [], array $headers = []): array {
        if (!self::configured()) return [false, 'Email is not set up yet (Settings → Email).'];
        require_once ROOT . '/vendor/phpmailer/Exception.php';
        require_once ROOT . '/vendor/phpmailer/PHPMailer.php';
        require_once ROOT . '/vendor/phpmailer/SMTP.php';
        $s = Settings::get('smtp');
        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host = $s['host'];
            $m->Port = (int) ($s['port'] ?: 587);
            $m->Timeout = 12;
            if (!empty($s['user'])) { $m->SMTPAuth = true; $m->Username = $s['user']; $m->Password = Settings::secret('smtp_pass'); }
            $sec = $s['secure'] ?? 'tls';
            $m->SMTPSecure = $sec === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($sec === 'none' ? '' : PHPMailer::ENCRYPTION_STARTTLS);
            if ($sec === 'none') $m->SMTPAutoTLS = false;
            $m->CharSet = 'UTF-8';
            $m->setFrom($s['from'], $s['from_name'] ?: Settings::get('store_name'));
            $m->addAddress($to);
            $m->Subject = $subject;
            $m->isHTML(true);
            $m->Body = $html;
            $m->AltBody = $text;
            foreach ($attachments as $name => $path) $m->addAttachment($path, $name);
            foreach ($headers as $k => $hv) if (preg_match('/^[A-Za-z-]+$/', $k) && !preg_match('/[\r\n]/', $hv)) $m->addCustomHeader($k, $hv);
            $m->send();
            return [true, ''];
        } catch (Throwable $e) {
            return [false, $m->ErrorInfo ?: $e->getMessage()];
        }
    }
}
