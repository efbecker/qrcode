<?php
/** Painel do administrador: visão geral, contas, configurações (SMTP) e segurança. */
declare(strict_types=1);

function admin_home(): void
{
    $u = require_admin();
    $t = now();
    $stats = [
        'users' => (int) q_val('SELECT COUNT(*) FROM users'),
        'users_week' => (int) q_val('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$t - 7 * 86400]),
        'active_month' => (int) q_val('SELECT COUNT(*) FROM users WHERE last_login_at >= ?', [$t - 30 * 86400]),
        'qrcodes' => (int) q_val('SELECT COUNT(*) FROM qrcodes'),
        'tracked' => (int) q_val('SELECT COUNT(*) FROM qrcodes WHERE short_code IS NOT NULL'),
        'clicks' => (int) q_val('SELECT COALESCE(SUM(clicks_total), 0) FROM qrcodes'),
        'clicks_week' => (int) q_val('SELECT COUNT(*) FROM clicks WHERE created_at >= ?', [$t - 7 * 86400]),
        'failed_logins' => (int) q_val("SELECT COUNT(*) FROM audit_log WHERE event IN ('login_failed', 'login_blocked') AND created_at >= ?", [$t - 86400]),
    ];
    render('admin/home', [
        'title' => 'Administração',
        'user' => $u,
        'stats' => $stats,
        'series' => daily_series('1 = 1', [], 30),
        'recentUsers' => q_all('SELECT * FROM users ORDER BY created_at DESC LIMIT 8'),
        'topQr' => q_all(
            'SELECT q.*, u.email FROM qrcodes q JOIN users u ON u.id = q.user_id
             WHERE q.short_code IS NOT NULL ORDER BY q.clicks_total DESC LIMIT 8'
        ),
        'smtpOk' => smtp_configured(),
    ]);
}

function admin_users(): void
{
    $u = require_admin();
    $search = query('q', 100);
    $status = query('status', 20);
    $where = ['1 = 1'];
    $params = [];
    if ($search !== '') {
        $where[] = "(u.email LIKE ? ESCAPE '\\' OR u.name LIKE ? ESCAPE '\\')";
        $like = '%' . addcslashes($search, '%_\\') . '%';
        array_push($params, $like, $like);
    }
    match ($status) {
        'admin' => $where[] = 'u.is_admin = 1',
        'inactive' => $where[] = 'u.is_active = 0',
        'unverified' => $where[] = 'u.email_verified_at IS NULL',
        default => null,
    };
    $users = q_all(
        'SELECT u.*, (SELECT COUNT(*) FROM qrcodes q WHERE q.user_id = u.id) AS qr_count,
                (SELECT COALESCE(SUM(clicks_total), 0) FROM qrcodes q WHERE q.user_id = u.id) AS clicks
         FROM users u WHERE ' . implode(' AND ', $where) . ' ORDER BY u.created_at DESC LIMIT 500',
        $params
    );
    render('admin/users', ['title' => 'Contas', 'user' => $u, 'users' => $users, 'search' => $search, 'status' => $status]);
}

function admin_user(string $id): void
{
    $admin = require_admin();
    $target = q_one('SELECT * FROM users WHERE id = ?', [(int) $id]);
    if (!$target) {
        abort(404, 'Conta não encontrada.');
    }
    $tid = (int) $target['id'];
    $self = $tid === (int) $admin['id'];

    if (is_post()) {
        csrf_verify();
        $action = input('action', 30);
        $activeAdmins = (int) q_val('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1');
        $lastAdmin = $target['is_admin'] && $target['is_active'] && $activeAdmins <= 1;

        switch ($action) {
            case 'toggle_active':
                if ($self) {
                    flash('error', 'Você não pode desativar a própria conta.');
                    break;
                }
                q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$tid]);
                revoke_sessions($tid);
                audit($target['is_active'] ? 'admin_user_disabled' : 'admin_user_enabled', (int) $admin['id'], $target['email']);
                flash('ok', $target['is_active'] ? 'Conta desativada e sessões encerradas. Os links dela continuam funcionando; bloqueie-os abaixo se necessário.' : 'Conta reativada.');
                break;
            case 'toggle_admin':
                if ($self || $lastAdmin) {
                    flash('error', 'Não é possível remover o seu próprio acesso de administrador.');
                    break;
                }
                q('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?', [$tid]);
                revoke_sessions($tid);
                audit($target['is_admin'] ? 'admin_revoked' : 'admin_granted', (int) $admin['id'], $target['email']);
                flash('ok', $target['is_admin'] ? 'Acesso de administrador removido.' : 'Conta promovida a administrador.');
                break;
            case 'verify':
                q('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $tid]);
                audit('admin_email_verified', (int) $admin['id'], $target['email']);
                flash('ok', 'E-mail marcado como verificado.');
                break;
            case 'send_reset':
                if (!smtp_configured()) {
                    flash('error', 'Configure o SMTP antes de enviar e-mails.');
                } elseif (send_password_reset($target)) {
                    audit('admin_reset_sent', (int) $admin['id'], $target['email']);
                    flash('ok', 'Link de redefinição enviado para ' . $target['email'] . '.');
                } else {
                    flash('error', 'Falha ao enviar o e-mail. Veja Configurações > testar SMTP.');
                }
                break;
            case 'logout_all':
                revoke_sessions($tid);
                audit('admin_sessions_revoked', (int) $admin['id'], $target['email']);
                flash('ok', 'Todas as sessões desta conta foram encerradas.');
                break;
            case 'block_qr':
                $qid = (int) ($_POST['qr_id'] ?? 0);
                $n = q('UPDATE qrcodes SET blocked = 1 - blocked WHERE id = ? AND user_id = ?', [$qid, $tid])->rowCount();
                if ($n) {
                    audit('admin_qr_block_toggled', (int) $admin['id'], $target['email'], 'qr #' . $qid);
                    flash('ok', 'Situação do link alterada.');
                }
                break;
            case 'delete':
                if ($self || $lastAdmin) {
                    flash('error', 'Não é possível excluir esta conta por aqui.');
                    break;
                }
                if (input('confirm_email', 190) !== mb_strtolower($target['email'])) {
                    flash('error', 'Para excluir, digite o e-mail da conta exatamente.');
                    break;
                }
                q('DELETE FROM users WHERE id = ?', [$tid]);
                audit('admin_user_deleted', (int) $admin['id'], $target['email']);
                flash('ok', 'Conta ' . $target['email'] . ' excluída com todos os QR Codes.');
                redirect(url('/admin/usuarios'));
        }
        redirect(url('/admin/usuarios/' . $tid));
    }

    render('admin/user', [
        'title' => $target['name'],
        'user' => $admin,
        'target' => $target,
        'self' => $self,
        'qrcodes' => q_all('SELECT * FROM qrcodes WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [$tid]),
        'events' => q_all('SELECT * FROM audit_log WHERE user_id = ? OR email = ? ORDER BY created_at DESC LIMIT 20', [$tid, $target['email']]),
    ]);
}

function admin_settings(): void
{
    $admin = require_admin();
    $errors = [];

    if (is_post()) {
        csrf_verify();
        $action = input('action', 20);

        if ($action === 'general') {
            $base = rtrim(input('base_url', 200), '/');
            if ($base !== '' && !valid_target_url($base)) {
                $errors['base_url'] = 'Informe a URL completa, ex.: https://qrcode.seudominio.com.br';
            }
            $days = (int) input('click_retention_days', 5);
            $max = (int) input('max_qrcodes_per_user', 6);
            if (!$errors) {
                setting_set('base_url', $base);
                setting_set('allow_registration', empty($_POST['allow_registration']) ? '0' : '1');
                setting_set('require_verified_email', empty($_POST['require_verified_email']) ? '0' : '1');
                setting_set('click_retention_days', (string) max(30, min(3650, $days ?: 365)));
                setting_set('max_qrcodes_per_user', (string) max(1, min(100000, $max ?: 500)));
                audit('settings_changed', (int) $admin['id'], $admin['email'], 'geral');
                flash('ok', 'Configurações gerais salvas.');
                redirect(url('/admin/configuracoes'));
            }
        } elseif ($action === 'smtp' || $action === 'smtp_test') {
            $smtp = [
                'host' => input('smtp_host', 190),
                'port' => (int) input('smtp_port', 5),
                'security' => in_array($_POST['smtp_security'] ?? '', ['starttls', 'ssl', 'none'], true) ? $_POST['smtp_security'] : 'starttls',
                'user' => input('smtp_user', 190),
                'from_email' => mb_strtolower(input('smtp_from_email', 190)),
                'from_name' => input('smtp_from_name', 80),
            ];
            $newPass = (string) ($_POST['smtp_pass'] ?? '');
            $clearPass = !empty($_POST['smtp_pass_clear']);

            if ($smtp['host'] !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $smtp['host'])) {
                $errors['smtp_host'] = 'Servidor inválido.';
            }
            if ($smtp['port'] < 1 || $smtp['port'] > 65535) {
                $errors['smtp_port'] = 'Porta inválida.';
            }
            if ($smtp['from_email'] !== '' && !valid_email($smtp['from_email'])) {
                $errors['smtp_from_email'] = 'E-mail do remetente inválido.';
            }
            if ($smtp['user'] !== '' && $smtp['security'] === 'none') {
                $errors['smtp_security'] = 'Com usuário e senha, use STARTTLS ou SSL (a senha não pode trafegar sem criptografia).';
            }

            if (!$errors && $action === 'smtp') {
                setting_set('smtp_host', $smtp['host']);
                setting_set('smtp_port', (string) $smtp['port']);
                setting_set('smtp_security', $smtp['security']);
                setting_set('smtp_user', $smtp['user']);
                setting_set('smtp_from_email', $smtp['from_email']);
                setting_set('smtp_from_name', $smtp['from_name'] ?: 'QR Code Fácil');
                if ($clearPass) {
                    setting_set('smtp_pass', '');
                } elseif ($newPass !== '') {
                    setting_set('smtp_pass', encrypt_secret($newPass));
                }
                audit('settings_changed', (int) $admin['id'], $admin['email'], 'smtp');
                flash('ok', 'Configuração de SMTP salva.');
                redirect(url('/admin/configuracoes#smtp'));
            }

            if (!$errors && $action === 'smtp_test') {
                if (!rl_allow('smtp-test:' . $admin['id'], 10, 600)) {
                    flash('error', 'Muitos testes seguidos. Aguarde alguns minutos.');
                    redirect(url('/admin/configuracoes#smtp'));
                }
                $smtp['pass'] = $clearPass ? '' : ($newPass !== '' ? $newPass : decrypt_secret(setting('smtp_pass')));
                try {
                    send_mail(
                        $admin['email'],
                        'Teste de SMTP · QR Code Fácil',
                        "Olá, {$admin['name']}!\n\nSe você recebeu esta mensagem, o envio de e-mails do QR Code Fácil está funcionando.\n\n"
                            . 'Servidor: ' . $smtp['host'] . ':' . $smtp['port'] . ' (' . $smtp['security'] . ")\n",
                        $smtp
                    );
                    flash('ok', 'E-mail de teste enviado para ' . $admin['email'] . '. Lembre-se de salvar a configuração.');
                } catch (Throwable $t) {
                    flash('error', 'Falha no teste: ' . $t->getMessage());
                }
                redirect(url('/admin/configuracoes#smtp'));
            }
        }
    }

    render('admin/settings', [
        'title' => 'Configurações',
        'user' => $admin,
        'errors' => $errors,
        'hasPass' => setting('smtp_pass') !== '',
        'configBase' => (string) config('base_url'),
    ]);
}

function admin_security(): void
{
    $u = require_admin();
    $event = query('event', 40);
    $params = [];
    $where = '1 = 1';
    if ($event !== '') {
        $where = 'event = ?';
        $params[] = $event;
    }
    render('admin/security', [
        'title' => 'Segurança',
        'user' => $u,
        'events' => q_all("SELECT * FROM audit_log WHERE {$where} ORDER BY created_at DESC LIMIT 300", $params),
        'eventTypes' => q_all('SELECT event, COUNT(*) AS n FROM audit_log GROUP BY event ORDER BY n DESC'),
        'event' => $event,
    ]);
}
