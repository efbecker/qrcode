<?php
/** API JSON usada pelo gerador (index.html). */
declare(strict_types=1);

function api_me(): void
{
    $u = current_user();
    if (!$u) {
        json_response(['authenticated' => false, 'registration' => setting('allow_registration') === '1']);
    }
    json_response([
        'authenticated' => true,
        'name' => $u['name'],
        'isAdmin' => (bool) $u['is_admin'],
        'canTrack' => tracking_allowed($u),
        'csrf' => csrf_token(),
    ]);
}

function api_create_qr(): void
{
    $u = current_user();
    if (!$u) {
        abort(401, 'Entre na sua conta para salvar QR Codes.');
    }
    csrf_verify();
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        abort(415, 'Envie JSON.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 64 * 1024);
    $in = json_decode((string) $raw, true);
    if (!is_array($in)) {
        abort(400, 'JSON inválido.');
    }
    $uid = (int) $u['id'];
    if (!rl_allow('create-qr:' . $uid, 60, 3600)) {
        abort(429, 'Você criou muitos QR Codes na última hora. Aguarde um pouco.');
    }
    $max = (int) setting('max_qrcodes_per_user');
    if ((int) q_val('SELECT COUNT(*) FROM qrcodes WHERE user_id = ?', [$uid]) >= $max) {
        abort(403, "Limite de {$max} QR Codes por conta atingido. Exclua alguns no painel.");
    }

    $type = is_string($in['type'] ?? null) && isset(QR_TYPE_LABELS[$in['type']]) ? $in['type'] : 'text';
    $content = is_string($in['content'] ?? null) ? trim($in['content']) : '';
    $title = is_string($in['title'] ?? null) ? mb_substr(trim($in['title']), 0, 120) : '';
    $track = !empty($in['track']);
    $style = sanitize_style($in['style'] ?? []);

    if ($content === '' || strlen($content) > 4000) {
        abort(422, 'Conteúdo vazio ou longo demais.');
    }
    if ($track) {
        if (!tracking_allowed($u)) {
            abort(403, 'Confirme seu e-mail para criar links rastreados.');
        }
        if (!valid_target_url($content)) {
            abort(422, 'Só links http(s) podem ser rastreados.');
        }
    }
    if ($title === '') {
        $title = $track ? (string) (parse_url($content, PHP_URL_HOST) ?: 'Meu link') : qr_type_label($type);
    }

    $code = $track ? new_short_code() : null;
    $t = now();
    q(
        'INSERT INTO qrcodes (user_id, title, type, content, short_code, style_json, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$uid, $title, $type, $content, $code, json_encode($style), $t, $t]
    );
    $id = (int) db()->lastInsertId();
    json_response([
        'id' => $id,
        'data' => $code ? short_url($code) : $content,
        'tracked' => $track,
        'panelUrl' => url('/painel/qr/' . $id),
    ], 201);
}
