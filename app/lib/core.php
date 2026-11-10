<?php
/** Funções utilitárias gerais: config, HTTP, escape, views, IP. */
declare(strict_types=1);

final class HttpError extends Exception {}

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['__config'][$key] ?? $default;
}

function data_dir(): string
{
    $dir = rtrim((string) config('data_dir'), '/');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("Não foi possível criar o diretório de dados: {$dir}");
    }
    return $dir;
}

/** Chave secreta do servidor (32 bytes), criada no primeiro uso, permissão 0600. */
function app_secret(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = data_dir() . '/secret.key';
    if (!is_file($file)) {
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        $old = umask(0077);
        file_put_contents($tmp, base64_encode(random_bytes(32)));
        umask($old);
        @chmod($tmp, 0600);
        @link($tmp, $file); // atômico; se outro processo criou antes, mantém a existente
        @unlink($tmp);
    }
    $raw = base64_decode(trim((string) file_get_contents($file)), true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('Chave secreta inválida em ' . $file);
    }
    return $key = $raw;
}

/** Escape para HTML. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (is_trusted_proxy() && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Prefixo de caminho onde a aplicação está publicada ("" na raiz, "/qrcode" no Alias). */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $dir = PHP_SAPI === 'cli' ? '' : str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $base = rtrim($dir, '/.');
    }
    return $base;
}

/** URL relativa ao site (para links e formulários). */
function url(string $path = '/'): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/** URL absoluta confiável (configurada pelo admin / deploy), para e-mails e links rastreados. */
function public_url(string $path = '/'): string
{
    $base = rtrim((string) (setting('base_url') ?: config('base_url')), '/');
    if ($base === '') {
        // Último recurso (ambiente de desenvolvimento): usa o host da requisição.
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = (is_https() ? 'https://' : 'http://') . $host . base_path();
    }
    return $base . '/' . ltrim($path, '/');
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return request_method() === 'POST';
}

/** Valor de formulário como string aparada, com limite de tamanho. */
function input(string $key, int $max = 500): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim($v);
    return mb_substr($v, 0, $max);
}

function query(string $key, int $max = 200): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

function redirect(string $to, int $code = 303): never
{
    // Só caminhos locais: evita open redirect
    if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
        $to = url('/');
    }
    header('Location: ' . $to, true, $code);
    exit;
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function abort(int $status, string $message = ''): never
{
    throw new HttpError($message, $status);
}

function now(): int
{
    return time();
}

function random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/* ------------------------------------------------------------------ IP / privacidade */

function is_trusted_proxy(): bool
{
    $proxies = (array) config('trusted_proxies', []);
    return $proxies && in_array($_SERVER['REMOTE_ADDR'] ?? '', $proxies, true);
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (is_trusted_proxy()) {
        $fwd = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $cand = trim(explode(',', $fwd)[0] ?? '');
        if (filter_var($cand, FILTER_VALIDATE_IP)) {
            $ip = $cand;
        }
    }
    return $ip;
}

/**
 * IP anonimizado (LGPD): IPv4 zera o último octeto (/24) e IPv6 mantém só o /48.
 * É isso que guardamos; o IP completo nunca vai para o banco.
 */
function ip_prefix(?string $ip = null): string
{
    $ip ??= client_ip();
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return '';
    }
    if (strlen($bin) === 4) {
        return inet_ntop(substr($bin, 0, 3) . "\0");
    }
    return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
}

/* ------------------------------------------------------------------ views */

/** Renderiza uma view dentro do layout padrão. */
function render(string $view, array $vars = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $vars['__view'] = $view;
    $content = view_fragment($view, $vars);
    $title = $vars['title'] ?? 'QR Code Fácil';
    echo view_fragment('layout', $vars + ['content' => $content, 'title' => $title]);
    exit;
}

function view_fragment(string $view, array $vars = []): string
{
    $file = APP_ROOT . '/views/' . $view . '.php';
    if (!preg_match('#^[a-z0-9_/\-]+$#', $view) || !is_file($file)) {
        throw new RuntimeException("View inexistente: {$view}");
    }
    extract($vars, EXTR_SKIP);
    ob_start();
    try {
        include $file;
        return (string) ob_get_clean();
    } catch (Throwable $t) {
        ob_end_clean();
        throw $t;
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = [$type, $message];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/* ------------------------------------------------------------------ formatação */

function fmt_date(?int $ts, bool $withTime = true): string
{
    if (!$ts) {
        return '—';
    }
    return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts);
}

function fmt_int(int|float $n): string
{
    return number_format((float) $n, 0, ',', '.');
}

function time_ago(?int $ts): string
{
    if (!$ts) {
        return 'nunca';
    }
    $d = max(0, now() - $ts);
    return match (true) {
        $d < 60 => 'agora há pouco',
        $d < 3600 => 'há ' . intdiv($d, 60) . ' min',
        $d < 86400 => 'há ' . intdiv($d, 3600) . ' h',
        $d < 86400 * 30 => 'há ' . intdiv($d, 86400) . ' dia' . (intdiv($d, 86400) > 1 ? 's' : ''),
        default => fmt_date($ts, false),
    };
}

/** Valida URL de destino: apenas http(s), com host, sem credenciais embutidas. */
function valid_target_url(string $u): bool
{
    if ($u === '' || strlen($u) > 2048 || preg_match('/[\x00-\x20\x7f]/', $u)) {
        return false;
    }
    $p = parse_url($u);
    if (!$p || !isset($p['scheme'], $p['host'])) {
        return false;
    }
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
        return false;
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return false;
    }
    return (bool) preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $p['host']);
}
