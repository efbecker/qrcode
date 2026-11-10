<?php
/** Painel do usuário: QR Codes salvos, estatísticas e conta. */
declare(strict_types=1);

function tracking_allowed(array $u): bool
{
    return !(smtp_configured() && setting('require_verified_email') === '1' && !$u['email_verified_at']);
}

function panel_home(): void
{
    $u = require_login();
    $uid = (int) $u['id'];
    $filter = query('f', 10);
    $search = query('q', 100);

    $where = 'user_id = ?';
    $params = [$uid];
    if ($filter === 'track') {
        $where .= ' AND short_code IS NOT NULL';
    } elseif ($filter === 'static') {
        $where .= ' AND short_code IS NULL';
    }
    if ($search !== '') {
        $where .= " AND (title LIKE ? ESCAPE '\\' OR content LIKE ? ESCAPE '\\')";
        $like = '%' . addcslashes($search, '%_\\') . '%';
        array_push($params, $like, $like);
    }
    $qrcodes = q_all("SELECT * FROM qrcodes WHERE {$where} ORDER BY created_at DESC LIMIT 500", $params);

    $stats = [
        'qrcodes' => (int) q_val('SELECT COUNT(*) FROM qrcodes WHERE user_id = ?', [$uid]),
        'tracked' => (int) q_val('SELECT COUNT(*) FROM qrcodes WHERE user_id = ? AND short_code IS NOT NULL', [$uid]),
        'total' => (int) q_val('SELECT COALESCE(SUM(clicks_total), 0) FROM qrcodes WHERE user_id = ?', [$uid]),
        'week' => (int) q_val(
            'SELECT COUNT(*) FROM clicks c JOIN qrcodes q ON q.id = c.qrcode_id WHERE q.user_id = ? AND c.created_at >= ?',
            [$uid, now() - 7 * 86400]
        ),
    ];
    $series = daily_series('qrcode_id IN (SELECT id FROM qrcodes WHERE user_id = ?)', [$uid], 30);

    render('panel/home', [
        'title' => 'Meu painel',
        'user' => $u,
        'qrcodes' => $qrcodes,
        'stats' => $stats,
        'series' => $series,
        'filter' => $filter,
        'search' => $search,
        'trackingAllowed' => tracking_allowed($u),
    ]);
}

function panel_qr(string $id): void
{
    $u = require_login();
    $qr = find_user_qr((int) $u['id'], (int) $id);

    if (is_post()) {
        csrf_verify();
        $action = input('action', 20);
        if ($action === 'update') {
            $title = input('title', 120);
            if ($title === '') {
                flash('error', 'Informe um nome para o QR Code.');
            } else {
                $content = $qr['content'];
                if ($qr['short_code']) {
                    $target = input('content', 2048);
                    if (!valid_target_url($target)) {
                        flash('error', 'Informe um link de destino válido (http:// ou https://).');
                        redirect(url('/painel/qr/' . $qr['id']));
                    }
                    $content = $target;
                }
                q('UPDATE qrcodes SET title = ?, content = ?, updated_at = ? WHERE id = ?', [$title, $content, now(), $qr['id']]);
                flash('ok', $qr['short_code'] && $content !== $qr['content']
                    ? 'Destino atualizado! O QR Code impresso já aponta para o novo link.'
                    : 'Alterações salvas.');
            }
        } elseif ($action === 'toggle') {
            q('UPDATE qrcodes SET is_active = 1 - is_active, updated_at = ? WHERE id = ?', [now(), $qr['id']]);
            flash('ok', $qr['is_active'] ? 'Link pausado: quem escanear verá um aviso de link desativado.' : 'Link reativado.');
        } elseif ($action === 'reset') {
            q('DELETE FROM clicks WHERE qrcode_id = ?', [$qr['id']]);
            q('UPDATE qrcodes SET clicks_total = 0, last_click_at = NULL WHERE id = ?', [$qr['id']]);
            flash('ok', 'Estatísticas zeradas.');
        } elseif ($action === 'delete') {
            q('DELETE FROM qrcodes WHERE id = ?', [$qr['id']]);
            flash('ok', 'QR Code excluído.');
            redirect(url('/painel'));
        }
        redirect(url('/painel/qr/' . $qr['id']));
    }

    $qid = (int) $qr['id'];
    $stats = null;
    if ($qr['short_code']) {
        $stats = [
            'total' => (int) $qr['clicks_total'],
            'unique' => (int) q_val('SELECT COUNT(DISTINCT visitor) FROM clicks WHERE qrcode_id = ?', [$qid]),
            'today' => (int) q_val('SELECT COUNT(*) FROM clicks WHERE qrcode_id = ? AND created_at >= ?', [$qid, strtotime('today')]),
            'week' => (int) q_val('SELECT COUNT(*) FROM clicks WHERE qrcode_id = ? AND created_at >= ?', [$qid, now() - 7 * 86400]),
            'series' => daily_series('qrcode_id = ?', [$qid], 30),
            'hours' => hours_breakdown($qid),
            'device' => breakdown($qid, 'device'),
            'os' => breakdown($qid, 'os'),
            'browser' => breakdown($qid, 'browser'),
            'lang' => breakdown($qid, 'lang'),
            'referer' => breakdown($qid, 'referer'),
            'recent' => q_all('SELECT * FROM clicks WHERE qrcode_id = ? ORDER BY created_at DESC LIMIT 50', [$qid]),
        ];
    }

    render('panel/qr', [
        'title' => $qr['title'],
        'user' => $u,
        'qr' => $qr,
        'stats' => $stats,
        'style' => json_decode((string) $qr['style_json'], true) ?: [],
    ]);
}

function panel_qr_csv(string $id): void
{
    $u = require_login();
    $qr = find_user_qr((int) $u['id'], (int) $id);
    $name = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $qr['title']) ?: 'qrcode'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cliques-' . trim($name, '-') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel reconhecer UTF-8
    fputcsv($out, ['data_hora', 'visitante_anonimo', 'rede_ip_anonimizada', 'dispositivo', 'sistema', 'navegador', 'idioma', 'origem'], ';', '"', '');
    $st = q('SELECT * FROM clicks WHERE qrcode_id = ? ORDER BY created_at DESC', [$qr['id']]);
    while ($c = $st->fetch()) {
        $row = [date('Y-m-d H:i:s', (int) $c['created_at']), $c['visitor'], $c['ip_prefix'], $c['device'], $c['os'], $c['browser'], $c['lang'], $c['referer']];
        // Neutraliza fórmulas (CSV injection) ao abrir no Excel/LibreOffice
        $row = array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v, $row);
        fputcsv($out, $row, ';', '"', '');
    }
    fclose($out);
    exit;
}

function panel_account(): void
{
    $u = require_login();
    $uid = (int) $u['id'];
    $errors = [];

    if (is_post()) {
        csrf_verify();
        $action = input('action', 20);
        $current = (string) ($_POST['current_password'] ?? '');

        if ($action === 'profile') {
            $name = input('name', 80);
            if (mb_strlen($name) < 2) {
                $errors['name'] = 'Informe seu nome.';
            } else {
                q('UPDATE users SET name = ? WHERE id = ?', [$name, $uid]);
                flash('ok', 'Nome atualizado.');
                redirect(url('/painel/conta'));
            }
        } elseif ($action === 'password' || $action === 'delete') {
            if (!rl_allow('reauth:' . $uid, 10, 900)) {
                $errors[$action] = 'Muitas tentativas. Aguarde alguns minutos.';
            } elseif (!password_verify($current, $u['password_hash'])) {
                $errors[$action] = 'Senha atual incorreta.';
                audit('reauth_failed', $uid, $u['email']);
            } elseif ($action === 'password') {
                $new = (string) ($_POST['password'] ?? '');
                if ($p = password_problem($new, $u['email'], $u['name'])) {
                    $errors['password'] = $p;
                } elseif (!hash_equals($new, (string) ($_POST['password_confirm'] ?? ''))) {
                    $errors['password'] = 'As senhas não conferem.';
                } else {
                    q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($new), $uid]);
                    revoke_sessions($uid); // encerra as outras sessões
                    audit('password_changed', $uid, $u['email']);
                    login_user(q_one('SELECT * FROM users WHERE id = ?', [$uid]));
                    flash('ok', 'Senha alterada. As sessões em outros dispositivos foram encerradas.');
                    redirect(url('/painel/conta'));
                }
            } else {
                if ($u['is_admin'] && (int) q_val('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1') <= 1) {
                    $errors['delete'] = 'Você é o único administrador. Promova outra conta antes de excluir a sua.';
                } else {
                    audit('account_deleted', $uid, $u['email']);
                    q('DELETE FROM users WHERE id = ?', [$uid]);
                    logout_session();
                    flash('ok', 'Sua conta e todos os dados foram excluídos.');
                    redirect(url('/entrar'));
                }
            }
        }
    }

    render('panel/account', ['title' => 'Minha conta', 'user' => $u, 'errors' => $errors]);
}
