<?php
/** Cadastro, login, logout, recuperação de senha e verificação de e-mail. */
declare(strict_types=1);

const LOGIN_WINDOW = 900;          // 15 minutos
const LOGIN_MAX_PER_EMAIL = 5;
const LOGIN_MAX_PER_IP = 20;
const RESET_TTL = 3600;            // link de redefinição vale 1 hora
const VERIFY_TTL = 2 * 86400;

function after_login_target(array $user): string
{
    $to = $_SESSION['after_login'] ?? '';
    unset($_SESSION['after_login']);
    if (is_string($to) && str_starts_with($to, '/') && !str_starts_with($to, '//')) {
        return $to;
    }
    return url('/painel');
}

function auth_login(): void
{
    session_boot();
    if (current_user()) {
        redirect(url('/painel'));
    }
    $email = '';
    $error = null;

    if (is_post()) {
        csrf_verify();
        $email = mb_strtolower(input('email', 190));
        $pass = (string) ($_POST['password'] ?? '');
        $ipKey = 'login-ip:' . client_ip();
        $emailKey = 'login-email:' . $email;

        if (rl_count($ipKey, LOGIN_WINDOW) >= LOGIN_MAX_PER_IP || rl_count($emailKey, LOGIN_WINDOW) >= LOGIN_MAX_PER_EMAIL) {
            $wait = (int) ceil(max(rl_retry_after($ipKey, LOGIN_WINDOW), rl_retry_after($emailKey, LOGIN_WINDOW)) / 60);
            audit('login_blocked', null, $email);
            $error = "Muitas tentativas. Por segurança, aguarde cerca de {$wait} minuto(s) e tente novamente.";
        } elseif ($email === '' || $pass === '' || strlen($pass) > 1024) {
            $error = 'Informe e-mail e senha.';
        } else {
            $user = check_credentials($email, $pass);
            if (!$user) {
                rl_hit($ipKey, LOGIN_WINDOW);
                rl_hit($emailKey, LOGIN_WINDOW);
                audit('login_failed', null, $email);
                $error = 'E-mail ou senha incorretos.';
            } elseif (!$user['is_active']) {
                audit('login_disabled', (int) $user['id'], $email);
                $error = 'Esta conta está desativada. Fale com o administrador.';
            } else {
                rl_clear($emailKey);
                login_user($user);
                audit('login', (int) $user['id'], $user['email']);
                redirect(after_login_target($user));
            }
        }
    }

    render('auth/login', ['title' => 'Entrar', 'email' => $email, 'error' => $error]);
}

function auth_register(): void
{
    session_boot();
    if (current_user()) {
        redirect(url('/painel'));
    }
    $open = setting('allow_registration') === '1';
    $form = ['name' => '', 'email' => ''];
    $errors = [];

    if (is_post() && $open) {
        csrf_verify();
        $form['name'] = input('name', 80);
        $form['email'] = mb_strtolower(input('email', 190));
        $pass = (string) ($_POST['password'] ?? '');
        $pass2 = (string) ($_POST['password_confirm'] ?? '');

        // Honeypot invisível contra robôs
        if (input('website') !== '') {
            abort(400, 'Requisição inválida.');
        }
        if (!rl_allow('register-ip:' . client_ip(), 5, 3600)) {
            $errors['form'] = 'Muitos cadastros a partir da sua rede. Tente novamente mais tarde.';
        }
        if (mb_strlen($form['name']) < 2) {
            $errors['name'] = 'Informe seu nome.';
        }
        if (!valid_email($form['email'])) {
            $errors['email'] = 'Informe um e-mail válido.';
        }
        if ($p = password_problem($pass, $form['email'], $form['name'])) {
            $errors['password'] = $p;
        } elseif (!hash_equals($pass, $pass2)) {
            $errors['password_confirm'] = 'As senhas não conferem.';
        }
        if (empty($_POST['terms'])) {
            $errors['terms'] = 'É preciso aceitar o uso dos dados descrito abaixo.';
        }

        if (!$errors) {
            if (q_val('SELECT 1 FROM users WHERE email = ?', [$form['email']])) {
                // Não revela se o e-mail existe: orienta a recuperar a senha
                $errors['email'] = 'Não foi possível criar a conta com este e-mail. Se ele já é seu, use "Esqueci minha senha".';
                audit('register_duplicate', null, $form['email']);
            } else {
                q(
                    'INSERT INTO users (email, name, password_hash, created_at) VALUES (?, ?, ?, ?)',
                    [$form['email'], $form['name'], hash_password($pass), now()]
                );
                $user = q_one('SELECT * FROM users WHERE id = ?', [(int) db()->lastInsertId()]);
                audit('register', (int) $user['id'], $user['email']);
                login_user($user);
                if (smtp_configured()) {
                    send_verification($user);
                    flash('ok', 'Conta criada! Enviamos um link de confirmação para ' . $user['email'] . '.');
                } else {
                    flash('ok', 'Conta criada! Bem-vindo(a) ao QR Code Fácil.');
                }
                redirect(url('/painel'));
            }
        }
    }

    render('auth/register', ['title' => 'Criar conta', 'form' => $form, 'errors' => $errors, 'open' => $open]);
}

function auth_logout(): void
{
    csrf_verify();
    if ($u = current_user()) {
        audit('logout', (int) $u['id'], $u['email']);
    }
    logout_session();
    flash('ok', 'Você saiu da sua conta.');
    redirect(url('/entrar'));
}

function auth_forgot(): void
{
    session_boot();
    $sent = false;
    $email = '';
    if (is_post()) {
        csrf_verify();
        $email = mb_strtolower(input('email', 190));
        $allowed = rl_allow('forgot-ip:' . client_ip(), 5, 3600);
        if (!$allowed) {
            flash('error', 'Muitas solicitações. Aguarde um pouco e tente novamente.');
            redirect(url('/esqueci-senha'));
        }
        // Sempre a mesma resposta, exista ou não a conta (não revela cadastros)
        $sent = true;
        if (valid_email($email) && rl_allow('forgot-email:' . $email, 3, 3600)) {
            $user = q_one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
            if ($user) {
                send_password_reset($user);
                audit('reset_requested', (int) $user['id'], $user['email']);
            }
        }
    }
    render('auth/forgot', ['title' => 'Recuperar senha', 'sent' => $sent, 'email' => $email, 'smtp' => smtp_configured()]);
}

function send_password_reset(array $user): bool
{
    $token = token_create((int) $user['id'], 'reset', RESET_TTL);
    $link = public_url('/redefinir-senha?token=' . $token);
    return try_send_mail(
        $user['email'],
        'Redefinição de senha · QR Code Fácil',
        mail_template(
            $user['name'],
            'Recebemos um pedido para redefinir a senha da sua conta. Para criar uma nova senha, acesse o link abaixo (válido por 1 hora e de uso único):',
            $link,
            'Depois de redefinir, todas as sessões abertas da sua conta serão encerradas.'
        )
    );
}

function send_verification(array $user): bool
{
    $token = token_create((int) $user['id'], 'verify', VERIFY_TTL);
    return try_send_mail(
        $user['email'],
        'Confirme seu e-mail · QR Code Fácil',
        mail_template(
            $user['name'],
            'Falta pouco! Confirme seu e-mail para ativar os links rastreados da sua conta:',
            public_url('/verificar-email?token=' . $token),
            'O link vale por 48 horas.'
        )
    );
}

function auth_reset(): void
{
    session_boot();
    $token = is_post() ? input('token', 100) : query('token', 100);
    $row = token_find($token, 'reset');
    $error = null;

    if (!$row) {
        render('auth/reset', ['title' => 'Redefinir senha', 'invalid' => true, 'token' => '', 'error' => null]);
    }

    if (is_post()) {
        csrf_verify();
        if (!rl_allow('reset-ip:' . client_ip(), 10, 3600)) {
            abort(429, 'Muitas tentativas. Aguarde e tente novamente.');
        }
        $pass = (string) ($_POST['password'] ?? '');
        $pass2 = (string) ($_POST['password_confirm'] ?? '');
        if ($p = password_problem($pass, $row['email'], $row['name'])) {
            $error = $p;
        } elseif (!hash_equals($pass, $pass2)) {
            $error = 'As senhas não conferem.';
        } elseif (!token_consume((int) $row['id'])) {
            render('auth/reset', ['title' => 'Redefinir senha', 'invalid' => true, 'token' => '', 'error' => null]);
        } else {
            $uid = (int) $row['user_id'];
            q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($pass), $uid]);
            // Quem recebeu o link provou ter acesso ao e-mail
            q('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $uid]);
            q('DELETE FROM tokens WHERE user_id = ? AND purpose = ?', [$uid, 'reset']);
            revoke_sessions($uid);
            rl_clear('login-email:' . mb_strtolower($row['email']));
            audit('password_reset', $uid, $row['email']);
            try_send_mail(
                $row['email'],
                'Sua senha foi alterada · QR Code Fácil',
                "Olá, {$row['name']}!\n\nA senha da sua conta no QR Code Fácil acabou de ser redefinida.\n"
                . "Se não foi você, redefina a senha imediatamente em " . public_url('/esqueci-senha') . " e avise o administrador.\n"
            );
            logout_session();
            flash('ok', 'Senha redefinida! Entre com a nova senha.');
            redirect(url('/entrar'));
        }
    }

    render('auth/reset', ['title' => 'Redefinir senha', 'invalid' => false, 'token' => $token, 'error' => $error]);
}

function auth_verify_email(): void
{
    session_boot();
    $token = is_post() ? input('token', 100) : query('token', 100);
    $row = token_find($token, 'verify');
    if (!$row) {
        flash('error', 'Link de confirmação inválido ou expirado. Peça um novo no seu painel.');
        redirect(url(current_user() ? '/painel' : '/entrar'));
    }
    // GET só mostra o botão: alguns antivírus/apps de e-mail abrem links automaticamente
    if (is_post()) {
        csrf_verify();
        if (token_consume((int) $row['id'])) {
            q('UPDATE users SET email_verified_at = ? WHERE id = ?', [now(), $row['user_id']]);
            audit('email_verified', (int) $row['user_id'], $row['email']);
            flash('ok', 'E-mail confirmado! Seus links rastreados estão liberados.');
        }
        redirect(url(current_user() ? '/painel' : '/entrar'));
    }
    render('auth/verify', ['title' => 'Confirmar e-mail', 'token' => $token, 'email' => $row['email']]);
}

function auth_resend_verification(): void
{
    $u = require_login();
    csrf_verify();
    if ($u['email_verified_at']) {
        redirect(url('/painel'));
    }
    if (!smtp_configured()) {
        flash('error', 'O envio de e-mails ainda não foi configurado pelo administrador.');
    } elseif (!rl_allow('verify-resend:' . $u['id'], 3, 3600)) {
        flash('error', 'Você já pediu vários e-mails. Aguarde um pouco antes de tentar de novo.');
    } elseif (send_verification($u)) {
        flash('ok', 'Enviamos um novo link de confirmação para ' . $u['email'] . '.');
    } else {
        flash('error', 'Não foi possível enviar o e-mail agora. Tente mais tarde.');
    }
    redirect(url('/painel'));
}
