<?php
declare(strict_types=1);

/**
 * Minimal SMTP client (AUTH LOGIN/PLAIN, STARTTLS or implicit TLS).
 * Transport "log" writes messages to data/mail.log instead (development/tests).
 */
final class Mailer
{
    private static ?Mailer $instance = null;
    /** @var resource|null */
    private $sock = null;
    /** Test hook: collects messages instead of sending. */
    public static ?array $outbox = null;

    public static function instance(): Mailer
    {
        return self::$instance ??= new Mailer();
    }

    /** Send and record in email_log. Returns true on success. */
    public function send(string $to, string $subject, string $body, string $kind = 'misc', ?int $volunteerId = null, ?int $coordinatorId = null): bool
    {
        $err = null;
        try {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid recipient address');
            }
            $this->deliver($to, $subject, $body);
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
            $err = $e->getMessage();
            $this->close();
        }
        Db::exec(
            'INSERT INTO email_log (kind, to_email, subject, status, error, volunteer_id, coordinator_id, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$kind, $to, mb_substr($subject, 0, 255), $ok ? 'sent' : 'failed', $err, $volunteerId, $coordinatorId, Util::now()]
        );
        return $ok;
    }

    public static function buildMessage(string $to, string $subject, string $body, string $fromEmail, string $fromName): string
    {
        $clean = static fn(string $s): string => trim(str_replace(["\r", "\n", "\0"], ' ', $s));
        $enc = static fn(string $s): string => preg_match('/^[\x20-\x7e]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $enc($clean($fromName)) . ' <' . $clean($fromEmail) . '>',
            'To: <' . $clean($to) . '>',
            'Subject: ' . $enc($clean($subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (preg_replace('/^.*@/', '', $fromEmail) ?: 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
    }

    private function deliver(string $to, string $subject, string $body): void
    {
        $cfg = (array) Config::get('mail', []);
        $from = (string) ($cfg['from_email'] ?? 'noreply@localhost');
        $msg = self::buildMessage($to, $subject, $body, $from, (string) ($cfg['from_name'] ?? ''));

        if (self::$outbox !== null) {
            self::$outbox[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
            return;
        }
        if (($cfg['transport'] ?? 'smtp') === 'log') {
            $file = $cfg['log_file'] ?? OBS_ROOT . '/data/mail.log';
            file_put_contents($file, "=== " . date('c') . "\nTo: $to\nSubject: $subject\n\n$body\n\n", FILE_APPEND | LOCK_EX);
            return;
        }

        $this->connect($cfg);
        $this->cmd('MAIL FROM:<' . $from . '>', [250]);
        $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $this->cmd('DATA', [354]);
        $data = preg_replace('/^\./m', '..', $msg);
        $this->write($data . "\r\n.");
        $this->expect([250]);
        $this->cmd('RSET', [250]);
    }

    private function connect(array $cfg): void
    {
        if ($this->sock) {
            return; // reuse connection (batches)
        }
        $host = (string) ($cfg['host'] ?? 'localhost');
        $port = (int) ($cfg['port'] ?? 587);
        $enc = (string) ($cfg['encryption'] ?? 'tls');
        $timeout = (int) ($cfg['timeout'] ?? 15);
        $target = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $this->sock = @stream_socket_client($target, $errno, $errstr, $timeout);
        if (!$this->sock) {
            throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
        }
        stream_set_timeout($this->sock, $timeout);
        $this->expect([220]);
        $helo = preg_replace('/[^a-z0-9.\-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
        $this->cmd('EHLO ' . $helo, [250]);
        if ($enc === 'tls') {
            $this->cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $this->cmd('EHLO ' . $helo, [250]);
        }
        if (!empty($cfg['username'])) {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode((string) $cfg['username']), [334]);
            $this->cmd(base64_encode((string) ($cfg['password'] ?? '')), [235]);
        }
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, "QUIT\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    private function write(string $line): void
    {
        if (fwrite($this->sock, $line . "\r\n") === false) {
            throw new RuntimeException('SMTP write failed');
        }
    }

    private function cmd(string $line, array $ok): string
    {
        $this->write($line);
        return $this->expect($ok);
    }

    private function expect(array $ok): string
    {
        $resp = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($resp, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException('SMTP error: ' . trim($resp));
        }
        return $resp;
    }
}
