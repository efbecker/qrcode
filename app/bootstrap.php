<?php
/**
 * QR Code Fácil · bootstrap
 * Carrega configuração, bibliotecas e prepara o ambiente.
 * Este diretório (app/) NUNCA é publicado pelo Apache: só public/ é servido.
 */
declare(strict_types=1);

const APP_ROOT = __DIR__;
const APP_VERSION = '2.0.0';

$__cfgFile = getenv('QRCODE_CONFIG') ?: '/etc/qrcode/config.php';
$__cfg = is_file($__cfgFile) ? (require $__cfgFile) : [];
// Ajustes locais que o deploy.sh nunca sobrescreve (ex.: trusted_proxies)
$__local = dirname($__cfgFile) . '/local.php';
if (is_file($__local) && is_array($__extra = require $__local)) {
    $__cfg = array_merge(is_array($__cfg) ? $__cfg : [], $__extra);
}

$GLOBALS['__config'] = array_merge([
    // Pasta gravável pelo PHP, fora da raiz web (banco SQLite, chave secreta, sessões).
    'data_dir' => dirname(__DIR__) . '/data',
    // URL pública do site (usada nos links rastreados e nos e-mails). Pode ser
    // sobrescrita em Admin > Configurações. Nunca derivamos isso do cabeçalho Host
    // nos e-mails, para evitar envenenamento de links de recuperação de senha.
    'base_url' => '',
    'timezone' => 'America/Sao_Paulo',
    // IPs de proxies reversos confiáveis (ex.: Cloudflare). Vazio = usa REMOTE_ADDR.
    'trusted_proxies' => [],
], is_array($__cfg) ? $__cfg : []);
unset($__cfg, $__cfgFile, $__local, $__extra);

date_default_timezone_set((string) $GLOBALS['__config']['timezone']);
mb_internal_encoding('UTF-8');

require APP_ROOT . '/lib/core.php';
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/settings.php';
require APP_ROOT . '/lib/ratelimit.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/mailer.php';
require APP_ROOT . '/lib/ua.php';
require APP_ROOT . '/lib/qr.php';
