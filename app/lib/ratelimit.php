<?php
/** Limite de tentativas por janela de tempo (guardado no SQLite). */
declare(strict_types=1);

/** Registra uma tentativa e devolve o total na janela atual. */
function rl_hit(string $key, int $window): int
{
    $t = now();
    $k = hash('sha256', $key); // não guarda e-mails/IPs em claro
    return (int) q(
        'INSERT INTO rate_limits (k, window_start, hits) VALUES (:k, :t, 1)
         ON CONFLICT(k) DO UPDATE SET
            hits = CASE WHEN window_start <= :t - :w THEN 1 ELSE hits + 1 END,
            window_start = CASE WHEN window_start <= :t - :w THEN :t ELSE window_start END
         RETURNING hits',
        [':k' => $k, ':t' => $t, ':w' => $window]
    )->fetchColumn();
}

/** Total de tentativas na janela atual, sem registrar uma nova. */
function rl_count(string $key, int $window): int
{
    $row = q_one('SELECT window_start, hits FROM rate_limits WHERE k = ?', [hash('sha256', $key)]);
    if (!$row || (int) $row['window_start'] <= now() - $window) {
        return 0;
    }
    return (int) $row['hits'];
}

/** Segundos até a janela expirar. */
function rl_retry_after(string $key, int $window): int
{
    $start = (int) q_val('SELECT window_start FROM rate_limits WHERE k = ?', [hash('sha256', $key)]);
    return max(60, $start + $window - now());
}

function rl_clear(string $key): void
{
    q('DELETE FROM rate_limits WHERE k = ?', [hash('sha256', $key)]);
}

/** Atalho: registra e informa se ainda está dentro do limite. */
function rl_allow(string $key, int $max, int $window): bool
{
    return rl_hit($key, $window) <= $max;
}
