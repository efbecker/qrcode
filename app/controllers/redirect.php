<?php
/** Link rastreado: registra a abertura e redireciona para o destino. */
declare(strict_types=1);

function redirect_short(string $code): void
{
    $qr = q_one(
        'SELECT q.*, u.is_active AS owner_active FROM qrcodes q JOIN users u ON u.id = q.user_id WHERE q.short_code = ?',
        [$code]
    );
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');

    if (!$qr) {
        render('link_status', ['title' => 'Link não encontrado', 'kind' => 'missing'], 404);
    }
    if ($qr['blocked']) {
        render('link_status', ['title' => 'Link bloqueado', 'kind' => 'blocked'], 410);
    }
    if (!$qr['is_active']) {
        render('link_status', ['title' => 'Link pausado', 'kind' => 'paused'], 410);
    }
    if (!valid_target_url($qr['content'])) {
        render('link_status', ['title' => 'Link inválido', 'kind' => 'missing'], 404);
    }

    if (request_method() === 'GET') {
        try {
            record_click($qr);
        } catch (Throwable $t) {
            error_log('[qrcode] falha ao registrar clique: ' . $t->getMessage()); // nunca impede o redirecionamento
        }
    }
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $qr['content'], true, 302);
    exit;
}
