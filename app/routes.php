<?php
/** Tabela de rotas e tratamento de erros. */
declare(strict_types=1);

require APP_ROOT . '/controllers/auth.php';
require APP_ROOT . '/controllers/panel.php';
require APP_ROOT . '/controllers/admin.php';
require APP_ROOT . '/controllers/api.php';
require APP_ROOT . '/controllers/redirect.php';

/** [método(s), padrão, handler]. Parâmetros nomeados viram argumentos. */
const ROUTES = [
    ['GET', '#^/r/(?<code>[A-Za-z0-9]{4,16})$#', 'redirect_short'],

    ['GET|POST', '#^/entrar$#', 'auth_login'],
    ['GET|POST', '#^/cadastrar$#', 'auth_register'],
    ['POST', '#^/sair$#', 'auth_logout'],
    ['GET|POST', '#^/esqueci-senha$#', 'auth_forgot'],
    ['GET|POST', '#^/redefinir-senha$#', 'auth_reset'],
    ['GET|POST', '#^/verificar-email$#', 'auth_verify_email'],
    ['POST', '#^/verificar-email/reenviar$#', 'auth_resend_verification'],

    ['GET', '#^/painel$#', 'panel_home'],
    ['GET|POST', '#^/painel/qr/(?<id>\d+)$#', 'panel_qr'],
    ['GET', '#^/painel/qr/(?<id>\d+)/cliques\.csv$#', 'panel_qr_csv'],
    ['GET|POST', '#^/painel/conta$#', 'panel_account'],

    ['GET', '#^/admin$#', 'admin_home'],
    ['GET', '#^/admin/usuarios$#', 'admin_users'],
    ['GET|POST', '#^/admin/usuarios/(?<id>\d+)$#', 'admin_user'],
    ['GET|POST', '#^/admin/configuracoes$#', 'admin_settings'],
    ['GET', '#^/admin/seguranca$#', 'admin_security'],

    ['GET', '#^/api/me$#', 'api_me'],
    ['POST', '#^/api/qrcodes$#', 'api_create_qr'],
];

function dispatch(): void
{
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base = base_path();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim($path, '/');

    foreach (ROUTES as [$methods, $pattern, $handler]) {
        if (!preg_match($pattern, $path, $m)) {
            continue;
        }
        $allowed = explode('|', $methods);
        $method = request_method() === 'HEAD' ? 'GET' : request_method();
        if (!in_array($method, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            abort(405, 'Método não permitido.');
        }
        $args = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
        $handler(...$args);
        return;
    }
    abort(404, 'Página não encontrada.');
}

try {
    header_remove('X-Powered-By');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    maintenance();
    dispatch();
} catch (HttpError $err) {
    $status = $err->getCode() ?: 500;
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (str_contains($path, '/api/')) {
        json_response(['error' => $err->getMessage() ?: 'Erro'], $status);
    }
    render('error', ['title' => 'Erro ' . $status, 'status' => $status, 'message' => $err->getMessage()], $status);
} catch (Throwable $t) {
    error_log('[qrcode] ' . $t);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    try {
        render('error', ['title' => 'Erro interno', 'status' => 500, 'message' => 'Ocorreu um erro inesperado. Tente novamente em instantes.'], 500);
    } catch (Throwable) {
        echo 'Erro interno.';
    }
}
