<?php
/** Sessões, CSRF, autenticação, senhas e tokens de uso único. */
declare(strict_types=1);

const SESSION_IDLE_USER = 7 * 86400;    // inatividade máxima (usuário)
const SESSION_IDLE_ADMIN = 2 * 3600;    // inatividade máxima (administrador)
const SESSION_ABSOLUTE = 30 * 86400;    // vida máxima da sessão
const PASSWORD_MIN = 10;
const PASSWORD_MAX = 200;

function session_cookie_name(): string
{
    // O prefixo __Host- obriga Secure + Path=/ e sem Domain (não pode ser injetado por subdomínios)
    return is_https() ? '__Host-qrsess' : 'qrsess';
}

/**
 * Inicia a sessão. Com $create = false, só retoma uma sessão existente
 * (não cria cookie para visitantes anônimos de páginas que não precisam).
 */
function session_boot(bool $create = true): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $name = session_cookie_name();
    if (!$create && empty($_COOKIE[$name])) {
        return;
    }
    $dir = data_dir() . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    session_save_path($dir);
    session_name($name);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_USER);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $_SESSION['_created'] ??= now();
}

/* ------------------------------------------------------------------ CSRF */

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Valida token (campo _csrf ou cabeçalho X-CSRF-Token) e a origem da requisição. */
function csrf_verify(): void
{
    session_boot(false);
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $valid = is_string($sent) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $sent);

    // Defesa extra: se o navegador informar a origem, ela deve ser este mesmo host
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== 'null') {
        $host = parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ':' . $p : '');
        if (strcasecmp($host, $_SERVER['HTTP_HOST'] ?? '') !== 0) {
            $valid = false;
        }
    }
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') === 'cross-site') {
        $valid = false;
    }
    if (!$valid) {
        abort(419, 'Sua sessão expirou ou o formulário é inválido. Recarregue a página e tente novamente.');
    }
}

/* ------------------------------------------------------------------ usuário atual */

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    session_boot(false);
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if (!$uid) {
        return null;
    }
    $row = q_one('SELECT * FROM users WHERE id = ?', [$uid]);
    $t = now();
    $idle = ($row && $row['is_admin']) ? SESSION_IDLE_ADMIN : SESSION_IDLE_USER;
    $expired = !$row
        || !$row['is_active']
        || (int) $row['session_version'] !== (int) ($_SESSION['sv'] ?? -1)
        || $t - (int) ($_SESSION['last'] ?? 0) > $idle
        || $t - (int) ($_SESSION['login_at'] ?? 0) > SESSION_ABSOLUTE;
    if ($expired) {
        logout_session();
        return null;
    }
    if ($t - (int) $_SESSION['last'] > 60) {
        $_SESSION['last'] = $t;
    }
    return $user = $row;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        session_boot();
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? url('/painel');
        flash('info', 'Entre na sua conta para continuar.');
        redirect(url('/entrar'));
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!$u['is_admin']) {
        abort(403, 'Acesso restrito a administradores.');
    }
    return $u;
}

function login_user(array $user): void
{
    session_boot();
    session_regenerate_id(true); // impede fixação de sessão
    $keep = $_SESSION['after_login'] ?? null;
    $_SESSION = [
        'uid' => (int) $user['id'],
        'sv' => (int) $user['session_version'],
        'login_at' => now(),
        'last' => now(),
        '_created' => now(),
        '_csrf' => bin2hex(random_bytes(32)),
    ];
    if ($keep) {
        $_SESSION['after_login'] = $keep;
    }
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
}

function logout_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}

/** Invalida todas as sessões do usuário (troca de senha, reset, bloqueio). */
function revoke_sessions(int $userId): void
{
    q('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
}

/* ------------------------------------------------------------------ senhas */

function password_algo(): string|int
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function hash_password(string $plain): string
{
    return password_hash($plain, password_algo());
}

/** Devolve mensagem de erro ou null se a senha for aceitável. */
function password_problem(string $pass, string $email = '', string $name = ''): ?string
{
    $len = mb_strlen($pass);
    if ($len < PASSWORD_MIN) {
        return 'A senha deve ter pelo menos ' . PASSWORD_MIN . ' caracteres.';
    }
    if ($len > PASSWORD_MAX) {
        return 'A senha é longa demais.';
    }
    $lower = mb_strtolower($pass);
    $local = mb_strtolower((string) strstr($email, '@', true));
    if (($local !== '' && strlen($local) >= 4 && str_contains($lower, $local))
        || ($name !== '' && mb_strlen($name) >= 4 && str_contains($lower, mb_strtolower($name)))) {
        return 'A senha não pode conter seu nome ou e-mail.';
    }
    if (count(array_unique(mb_str_split($pass))) < 5) {
        return 'A senha é muito repetitiva. Misture letras, números e símbolos.';
    }
    $common = ['1234567890', '12345678910', '0123456789', 'qwertyuiop', 'senha12345', 'senha123456',
        'password123', 'password1234', 'abcdefghij', 'abc1234567', 'mudar12345', 'brasil1234', 'iloveyou12'];
    if (in_array($lower, $common, true)) {
        return 'Essa senha é comum demais. Escolha outra.';
    }
    return null;
}

/**
 * Verifica credenciais em tempo aproximadamente constante (mesmo quando o
 * e-mail não existe, para não revelar quais contas existem).
 */
function check_credentials(string $email, string $pass): ?array
{
    static $dummy = null;
    $user = q_one('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user) {
        $dummy ??= hash_password('dummy-password-for-timing');
        password_verify($pass, $dummy);
        return null;
    }
    if (!password_verify($pass, $user['password_hash'])) {
        return null;
    }
    if (password_needs_rehash($user['password_hash'], password_algo())) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($pass), $user['id']]);
    }
    return $user;
}

function valid_email(string $email): bool
{
    return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/* ------------------------------------------------------------------ tokens de uso único */

/** Cria token (guardamos só o hash SHA-256) e invalida tokens anteriores do mesmo tipo. */
function token_create(int $userId, string $purpose, int $ttl): string
{
    $token = random_token(32);
    q('DELETE FROM tokens WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
    q(
        'INSERT INTO tokens (user_id, purpose, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $purpose, hash('sha256', $token), now() + $ttl, now()]
    );
    return $token;
}

/** Devolve a linha do token se válido (sem consumir). */
function token_find(string $token, string $purpose): ?array
{
    if ($token === '' || strlen($token) > 100) {
        return null;
    }
    $row = q_one(
        'SELECT t.*, u.email, u.name, u.is_active FROM tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.purpose = ? AND t.used_at IS NULL AND t.expires_at > ?',
        [hash('sha256', $token), $purpose, now()]
    );
    return ($row && $row['is_active']) ? $row : null;
}

/** Consome o token de forma atômica (só um uso, mesmo com requisições simultâneas). */
function token_consume(int $tokenId): bool
{
    return q('UPDATE tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [now(), $tokenId])->rowCount() === 1;
}
