<?php
/** Shared SMTP transport for RigPi e-mail notifications. */

function rigpiEnsureEmailSettings($db): void
{
    $db->rawQuery("CREATE TABLE IF NOT EXISTS EmailSettings (
        SettingID TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        Provider VARCHAR(20) NOT NULL DEFAULT 'custom',
        SMTPHost VARCHAR(255) NOT NULL DEFAULT '',
        SMTPPort INT UNSIGNED NOT NULL DEFAULT 587,
        SMTPEncryption VARCHAR(12) NOT NULL DEFAULT 'starttls',
        SMTPUsername VARCHAR(255) NOT NULL DEFAULT '',
        SMTPPassword TEXT NOT NULL,
        FromEmail VARCHAR(255) NOT NULL DEFAULT '',
        FromName VARCHAR(100) NOT NULL DEFAULT 'RigPi',
        UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function rigpiLoadEmailSettings($db): array
{
    rigpiEnsureEmailSettings($db);
    $db->where('SettingID', 1);
    return $db->getOne('EmailSettings') ?: [];
}

function rigpiMsmtpValue(string $value): string
{
    if (preg_match('/[\r\n]/', $value)) {
        throw new RuntimeException('Mail settings may not contain line breaks.');
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function rigpiSendEmail(array $settings, string $to, string $subject, string $body): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid destination email address in this user account.');
    }
    $host = trim((string) ($settings['SMTPHost'] ?? ''));
    $port = (int) ($settings['SMTPPort'] ?? 0);
    $security = strtolower(trim((string) ($settings['SMTPEncryption'] ?? '')));
    $username = trim((string) ($settings['SMTPUsername'] ?? ''));
    $password = (string) ($settings['SMTPPassword'] ?? '');
    $fromEmail = trim((string) ($settings['FromEmail'] ?? ''));
    $fromName = trim((string) ($settings['FromName'] ?? 'RigPi')) ?: 'RigPi';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $host)
        || $port < 1 || $port > 65535
        || !in_array($security, ['none', 'starttls', 'tls'], true)
        || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The saved SMTP configuration is incomplete or invalid.');
    }
    if (($username === '') !== ($password === '')) {
        throw new RuntimeException('SMTP username and password must either both be supplied or both be blank.');
    }
    if (!is_executable('/usr/bin/msmtp')) {
        throw new RuntimeException('The RigPi SMTP sender is not installed.');
    }

    $config = [
        'defaults',
        'timeout 15',
        'tls_trust_file /etc/ssl/certs/ca-certificates.crt',
        'account default',
        'host ' . rigpiMsmtpValue($host),
        'port ' . $port,
        'from ' . rigpiMsmtpValue($fromEmail),
    ];
    if ($security === 'none') {
        $config[] = 'tls off';
    } else {
        $config[] = 'tls on';
        $config[] = 'tls_starttls ' . ($security === 'starttls' ? 'on' : 'off');
    }
    if ($username !== '') {
        $config[] = 'auth on';
        $config[] = 'user ' . rigpiMsmtpValue($username);
        $config[] = 'password ' . rigpiMsmtpValue($password);
    } else {
        $config[] = 'auth off';
    }

    $configPath = tempnam('/tmp', 'rigpi-smtp-');
    if ($configPath === false) throw new RuntimeException('RigPi could not create a temporary mail configuration.');
    chmod($configPath, 0600);
    file_put_contents($configPath, implode("\n", $config) . "\n", LOCK_EX);

    $cleanSubject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    $cleanFromName = trim(preg_replace('/[\r\n]+/', ' ', $fromName));
    $encodedName = '=?UTF-8?B?' . base64_encode($cleanFromName) . '?=';
    $encodedSubject = '=?UTF-8?B?' . base64_encode($cleanSubject) . '?=';
    $message = "Date: " . date(DATE_RFC2822) . "\r\n"
        . "From: {$encodedName} <{$fromEmail}>\r\n"
        . "To: <{$to}>\r\n"
        . "Subject: {$encodedSubject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . str_replace(["\r\n", "\r"], "\n", $body) . "\n";

    $pipes = [];
    $process = proc_open(
        ['/usr/bin/msmtp', '--file=' . $configPath, '--account=default', '--', $to],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        @unlink($configPath);
        throw new RuntimeException('RigPi could not start the SMTP sender.');
    }
    fwrite($pipes[0], $message);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($configPath);
    if ($exitCode !== 0) {
        $detail = trim($stderr ?: $stdout);
        if (stripos($detail, 'timed out') !== false || stripos($detail, 'network read error') !== false) {
            $detail = 'The SMTP server did not respond before the connection timed out. Check the server name, port, encryption setting, and Internet access.';
        } else {
            $detail = preg_replace('/\s*\(account\s+[^)]*\)/i', '', $detail);
            $detail = str_replace($configPath, '[temporary configuration]', $detail);
        }
        throw new RuntimeException($detail !== '' ? $detail : 'The SMTP server rejected the message.');
    }
}
