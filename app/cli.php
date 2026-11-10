<?php
/**
 * QR Code Fácil · administração pela linha de comando.
 * No servidor, use o atalho instalado pelo deploy.sh (roda como www-data):
 *
 *   sudo qrcode-admin create-admin voce@exemplo.com "Seu Nome"
 *   sudo qrcode-admin promote voce@exemplo.com
 *   sudo qrcode-admin reset-password voce@exemplo.com
 *   sudo qrcode-admin list-users
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

function out(string $msg): void { fwrite(STDOUT, $msg . PHP_EOL); }
function fail(string $msg): never { fwrite(STDERR, "ERRO: {$msg}" . PHP_EOL); exit(1); }

function ask_password(string $email, string $name): string
{
    if (!stream_isatty(STDIN)) {
        $pass = rtrim((string) fgets(STDIN), "\r\n");
        if ($pass === '') {
            $pass = random_token(12);
            out("Senha gerada: {$pass}");
            out('Guarde-a e troque em "Minha conta" após o primeiro login.');
        }
        return $pass;
    }
    for ($i = 0; $i < 3; $i++) {
        fwrite(STDOUT, 'Senha (mín. ' . PASSWORD_MIN . ' caracteres; Enter para gerar uma): ');
        shell_exec('stty -echo');
        $p1 = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        if ($p1 === '') {
            $p1 = random_token(12);
            out("Senha gerada: {$p1}");
            out('Guarde-a e troque em "Minha conta" após o primeiro login.');
            return $p1;
        }
        if ($err = password_problem($p1, $email, $name)) {
            out($err);
            continue;
        }
        fwrite(STDOUT, 'Repita a senha: ');
        shell_exec('stty -echo');
        $p2 = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        if (hash_equals($p1, $p2)) {
            return $p1;
        }
        out('As senhas não conferem.');
    }
    fail('Muitas tentativas.');
}

$cmd = $argv[1] ?? 'help';
$email = mb_strtolower(trim($argv[2] ?? ''));

switch ($cmd) {
    case 'create-admin':
        if (!valid_email($email)) {
            fail('Uso: create-admin <email> [nome]');
        }
        $name = trim($argv[3] ?? '') ?: 'Administrador';
        $user = q_one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user) {
            q('UPDATE users SET is_admin = 1, is_active = 1 WHERE id = ?', [$user['id']]);
            audit('cli', (int) $user['id'], $email, 'promovido a admin via CLI');
            out("A conta {$email} já existia e agora é administradora.");
            break;
        }
        $pass = ask_password($email, $name);
        q(
            'INSERT INTO users (email, name, password_hash, is_admin, email_verified_at, created_at) VALUES (?, ?, ?, 1, ?, ?)',
            [$email, $name, hash_password($pass), now(), now()]
        );
        audit('cli', (int) db()->lastInsertId(), $email, 'admin criado via CLI');
        out("Administrador {$email} criado. Entre em " . public_url('/entrar'));
        break;

    case 'promote':
    case 'demote':
        $n = q('UPDATE users SET is_admin = ? WHERE email = ?', [$cmd === 'promote' ? 1 : 0, $email])->rowCount();
        if (!$n) {
            fail("Conta não encontrada: {$email}");
        }
        $id = (int) q_val('SELECT id FROM users WHERE email = ?', [$email]);
        revoke_sessions($id);
        audit('cli', $id, $email, $cmd === 'promote' ? 'promovido a admin via CLI' : 'admin removido via CLI');
        out($cmd === 'promote' ? "{$email} agora é administrador." : "{$email} não é mais administrador.");
        break;

    case 'reset-password':
        $user = q_one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user) {
            fail("Conta não encontrada: {$email}");
        }
        $pass = ask_password($email, $user['name']);
        q('UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?', [hash_password($pass), $user['id']]);
        revoke_sessions((int) $user['id']);
        rl_clear('login-email:' . $email);
        audit('cli', (int) $user['id'], $email, 'senha redefinida via CLI');
        out("Senha de {$email} redefinida. Sessões anteriores encerradas.");
        break;

    case 'list-users':
        foreach (q_all('SELECT * FROM users ORDER BY created_at') as $u) {
            out(sprintf('%-5d %-40s %-6s %-9s %s', $u['id'], $u['email'], $u['is_admin'] ? 'admin' : '', $u['is_active'] ? 'ativa' : 'desativada', fmt_date((int) $u['created_at'])));
        }
        break;

    case 'migrate':
        db();
        maintenance(true);
        out('Banco de dados atualizado em ' . data_dir());
        break;

    default:
        out("Comandos:\n"
            . "  create-admin <email> [nome]   cria (ou promove) um administrador\n"
            . "  promote <email>               torna uma conta administradora\n"
            . "  demote <email>                remove o acesso de administrador\n"
            . "  reset-password <email>        define uma nova senha\n"
            . "  list-users                    lista as contas\n"
            . "  migrate                       cria/atualiza o banco de dados");
}
