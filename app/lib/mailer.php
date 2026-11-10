<?php
/**
 * Cliente SMTP mínimo (sem dependências): STARTTLS / SSL implícito,
 * AUTH PLAIN/LOGIN, verificação de certificado TLS sempre ativa.
 */
declare(strict_types=1);

final class MailError extends RuntimeException {}

/** Remove quebras de linha (impede injeção de cabeçalhos). */
function mail_clean_header(string $v): string
{
    return trim(preg_replace('/[\r\n\x00]+/', ' ', $v));
}

function mail_encode_header(string $v): string
{
    $v = mail_clean_header($v);
    return preg_match('/[^\x20-\x7e]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
}

/**
 * Envia um e-mail em texto puro. Lança MailError em caso de falha.
 * $override permite testar configurações ainda não salvas.
 */
function send_mail(string $to, string $subject, string $body, array $override = []): void
{
    $cfg = array_merge([
        'host' => setting('smtp_host'),
        'port' => (int) setting('smtp_port'),
        'security' => setting('smtp_security'),
        'user' => setting('smtp_user'),
        'pass' => decrypt_secret(setting('smtp_pass')),
        'from_email' => setting('smtp_from_email'),
        'from_name' => setting('smtp_from_name'),
    ], $override);

    if ($cfg['host'] === '' || $cfg['from_email'] === '') {
        throw new MailError('Servidor SMTP não configurado.');
    }
    if (!valid_email($to) || !valid_email($cfg['from_email'])) {
        throw new MailError('Endereço de e-mail inválido.');
    }

    $host = $cfg['host'];
    $port = $cfg['port'] ?: ($cfg['security'] === 'ssl' ? 465 : 587);
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
        'SNI_enabled' => true,
    ]]);
    $remote = ($cfg['security'] === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new MailError("Não foi possível conectar em {$host}:{$port} ({$errstr}).");
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): array {
        $lines = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $lines .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if ($lines === '') {
            throw new MailError('O servidor SMTP encerrou a conexão.');
        }
        return [(int) substr($lines, 0, 3), trim($lines)];
    };
    $cmd = function (string $c, array $expect, string $logAs = '') use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $expect, true)) {
            throw new MailError('SMTP: ' . ($logAs ?: strtok($c, ' ')) . ' recusado: ' . mb_substr($text, 0, 200));
        }
        return $text;
    };

    try {
        [$code, $greet] = $read();
        if ($code !== 220) {
            throw new MailError('Saudação SMTP inesperada: ' . mb_substr($greet, 0, 200));
        }
        $helo = preg_replace('/[^A-Za-z0-9.\-]/', '', parse_url(public_url(), PHP_URL_HOST) ?: 'localhost');
        $ehlo = $cmd('EHLO ' . $helo, [250]);

        if ($cfg['security'] === 'starttls') {
            if (!preg_match('/STARTTLS/i', $ehlo)) {
                throw new MailError('O servidor não oferece STARTTLS. Use SSL ou verifique a porta.');
            }
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new MailError('Falha na negociação TLS (certificado inválido?).');
            }
            $ehlo = $cmd('EHLO ' . $helo, [250]);
        }

        if ($cfg['user'] !== '') {
            if ($cfg['security'] === 'none') {
                throw new MailError('Por segurança, autenticação SMTP só é feita com TLS (STARTTLS ou SSL).');
            }
            if (preg_match('/AUTH[ =][^\n]*PLAIN/i', $ehlo)) {
                $cmd('AUTH PLAIN ' . base64_encode("\0" . $cfg['user'] . "\0" . $cfg['pass']), [235], 'AUTH');
            } else {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($cfg['user']), [334], 'AUTH');
                $cmd(base64_encode($cfg['pass']), [235], 'AUTH');
            }
        }

        $from = mail_clean_header($cfg['from_email']);
        $to = mail_clean_header($to);
        $cmd("MAIL FROM:<{$from}>", [250]);
        $cmd("RCPT TO:<{$to}>", [250, 251]);
        $cmd('DATA', [354]);

        $domain = substr(strrchr($from, '@'), 1);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . mail_encode_header($cfg['from_name'] ?: 'QR Code Fácil') . " <{$from}>",
            "To: <{$to}>",
            'Subject: ' . mail_encode_header($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        fwrite($fp, $data . ".\r\n");
        [$code, $text] = $read();
        if ($code !== 250) {
            throw new MailError('Mensagem recusada: ' . mb_substr($text, 0, 200));
        }
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

/** Envia e-mail sem interromper o fluxo; registra falha no log de auditoria. */
function try_send_mail(string $to, string $subject, string $body): bool
{
    try {
        send_mail($to, $subject, $body);
        return true;
    } catch (Throwable $t) {
        error_log('[qrcode] falha ao enviar e-mail: ' . $t->getMessage());
        audit('mail_error', null, $to, $t->getMessage());
        return false;
    }
}

function mail_template(string $name, string $intro, string $link, string $outro): string
{
    $site = public_url('/');
    return "Olá, {$name}!\n\n{$intro}\n\n{$link}\n\n{$outro}\n\n"
        . "Se você não fez essa solicitação, ignore esta mensagem; nada será alterado.\n\n"
        . "— QR Code Fácil\n{$site}\n";
}
