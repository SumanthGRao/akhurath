<?php

declare(strict_types=1);

/**
 * Minimal SMTP client for Hostinger and similar hosts (AUTH LOGIN, SSL or STARTTLS).
 *
 * @param list<array{filename: string, mimetype: string, body: string}>|null $attachments
 * @return array{ok: bool, error: string}
 */
function akh_smtp_send(string $to, string $subject, string $plainBody, ?array $attachments = null): array
{
    if (!AKH_SMTP_ENABLED) {
        return ['ok' => false, 'error' => 'SMTP is disabled in includes/config.php.'];
    }
    $from = trim(AKH_SMTP_FROM_EMAIL);
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Set AKH_SMTP_FROM_EMAIL to a valid address.'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient address.'];
    }
    $user = AKH_SMTP_USER;
    $pass = AKH_SMTP_PASS;
    if ($user === '' || $pass === '') {
        return ['ok' => false, 'error' => 'Set AKH_SMTP_USER and AKH_SMTP_PASS.'];
    }

    $host = AKH_SMTP_HOST;
    $port = AKH_SMTP_PORT;
    $enc = strtolower(AKH_SMTP_ENCRYPTION);

    $errno = 0;
    $errstr = '';
    if ($enc === 'ssl') {
        $remote = 'ssl://' . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 25, STREAM_CLIENT_CONNECT);
    } else {
        $remote = 'tcp://' . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 25, STREAM_CLIENT_CONNECT);
    }
    if ($fp === false) {
        return ['ok' => false, 'error' => "Could not connect ({$errno}): {$errstr}"];
    }
    stream_set_timeout($fp, 25);

    $read = static function () use ($fp): ?string {
        $buf = '';
        while (true) {
            $line = fgets($fp, 8192);
            if ($line === false) {
                return $buf === '' ? null : $buf;
            }
            $buf .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                return $buf;
            }
        }
    };

    $expect = static function (array $okCodes) use ($read): ?string {
        $line = $read();
        if ($line === null || $line === '') {
            return 'Empty SMTP response';
        }
        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            return trim($line);
        }

        return null;
    };

    $send = static function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };

    if (($e = $expect([220])) !== null) {
        fclose($fp);

        return ['ok' => false, 'error' => $e];
    }

    $ehlo = 'EHLO akhurath-site';
    $send($ehlo);
    if (($e = $expect([250])) !== null) {
        fclose($fp);

        return ['ok' => false, 'error' => $e];
    }

    if ($enc === 'tls') {
        $send('STARTTLS');
        if (($e = $expect([220])) !== null) {
            fclose($fp);

            return ['ok' => false, 'error' => $e];
        }
        $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoOk !== true) {
            fclose($fp);

            return ['ok' => false, 'error' => 'STARTTLS negotiation failed.'];
        }
        $send($ehlo);
        if (($e = $expect([250])) !== null) {
            fclose($fp);

            return ['ok' => false, 'error' => $e];
        }
    }

    $send('AUTH LOGIN');
    if (($e = $expect([334])) !== null) {
        fclose($fp);

        return ['ok' => false, 'error' => $e];
    }
    $send(base64_encode($user));
    if (($e = $expect([334])) !== null) {
        fclose($fp);

        return ['ok' => false, 'error' => $e];
    }
    $send(base64_encode($pass));
    if (($e = $expect([235])) !== null) {
        fclose($fp);

        return ['ok' => false, 'error' => $e];
    }

    $send('MAIL FROM:<' . $from . '>');
    if (($e = $expect([250])) !== null) {
        fclose($fp);

        return ['ok' => false, 'error' => $e];
    }
    $send('RCPT TO:<' . $to . '>');
    if (($e = $expect([250, 251])) !== null) {
        fclose($fp);

        return ['ok' => false, 'error' => $e];
    }
    $send('DATA');
    if (($e = $expect([354])) !== null) {
        fclose($fp);

        return ['ok' => false, 'error' => $e];
    }

    $fromName = trim((string) AKH_SMTP_FROM_NAME);
    $fromHeader = $fromName !== '' ? sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $from) : $from;
    $subEnc = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
        : $subject;
    $body = str_replace(["\r\n", "\r"], "\n", $plainBody);
    $body = str_replace("\n", "\r\n", $body);
    $body = preg_replace('/^\./m', '..', $body) ?? $body;

    $attachList = [];
    if (is_array($attachments)) {
        foreach ($attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            $fn = trim((string) ($att['filename'] ?? ''));
            $mime = trim((string) ($att['mimetype'] ?? 'application/octet-stream'));
            $bin = (string) ($att['body'] ?? '');
            if ($fn === '' || $bin === '') {
                continue;
            }
            $attachList[] = [
                'filename' => $fn,
                'mimetype' => $mime !== '' ? $mime : 'application/octet-stream',
                'body' => $bin,
            ];
        }
    }

    if ($attachList === []) {
        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: ' . $subEnc,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    } else {
        $boundary = 'akh_' . bin2hex(random_bytes(12));
        $headers = [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: ' . $subEnc,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];
        $parts = [];
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/plain; charset=UTF-8';
        $parts[] = 'Content-Transfer-Encoding: 8bit';
        $parts[] = '';
        $parts[] = $body;
        foreach ($attachList as $att) {
            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . $att['mimetype'] . '; name="' . addcslashes($att['filename'], '"\\') . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-Disposition: attachment; filename="' . addcslashes($att['filename'], '"\\') . '"';
            $parts[] = '';
            $parts[] = rtrim(chunk_split(base64_encode($att['body']), 76, "\r\n"));
        }
        $parts[] = '--' . $boundary . '--';
        $message = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts) . "\r\n.";
    }
    fwrite($fp, $message . "\r\n");

    if (($e = $expect([250])) !== null) {
        fclose($fp);

        return ['ok' => false, 'error' => $e];
    }
    $send('QUIT');
    fclose($fp);

    return ['ok' => true, 'error' => ''];
}
