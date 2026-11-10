<?php
/** QR Codes salvos, links rastreados e estatísticas de cliques. */
declare(strict_types=1);

const QR_TYPE_LABELS = [
    'url' => 'Link', 'text' => 'Texto', 'location' => 'Localização', 'call' => 'Chamada',
    'email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'wifi' => 'Wi-Fi',
    'vcard' => 'V-Card', 'pix' => 'Pix', 'bitcoin' => 'Bitcoin', 'paypal' => 'PayPal',
];

function qr_type_label(string $type): string
{
    return QR_TYPE_LABELS[$type] ?? 'Outro';
}

/** Código curto aleatório (base62, 7 caracteres ≈ 3,5 trilhões de combinações). */
function new_short_code(): string
{
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem 0/O/1/l/I
    for ($try = 0; $try < 10; $try++) {
        $code = '';
        for ($i = 0; $i < 7; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (!q_val('SELECT 1 FROM qrcodes WHERE short_code = ?', [$code])) {
            return $code;
        }
    }
    throw new RuntimeException('Não foi possível gerar um código único.');
}

function short_url(string $code): string
{
    return public_url('/r/' . $code);
}

/** O conteúdo que vai dentro do QR Code (link curto se rastreado). */
function qr_data(array $qr): string
{
    return $qr['short_code'] ? short_url($qr['short_code']) : $qr['content'];
}

/** Mantém apenas opções de estilo conhecidas e com valores válidos. */
function sanitize_style(mixed $style): array
{
    $style = is_array($style) ? $style : [];
    $out = [];
    $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
    foreach (['bgColor', 'dotsColor', 'eyeFrameColor', 'eyeCenterColor'] as $k) {
        if ($v = $hex($style[$k] ?? null)) {
            $out[$k] = $v;
        }
    }
    $enum = [
        'dotsType' => ['square', 'rounded', 'dots', 'classy', 'classy-rounded', 'extra-rounded'],
        'eyeFrameType' => ['square', 'extra-rounded', 'dot'],
        'eyeCenterType' => ['square', 'dot'],
        'ecLevel' => ['L', 'M', 'Q', 'H'],
    ];
    foreach ($enum as $k => $allowed) {
        if (in_array($style[$k] ?? null, $allowed, true)) {
            $out[$k] = $style[$k];
        }
    }
    $out['bgTransparent'] = !empty($style['bgTransparent']);
    $out['margin'] = max(0, min(40, (int) ($style['margin'] ?? 10)));
    return $out;
}

function find_user_qr(int $userId, int $id): array
{
    $qr = q_one('SELECT * FROM qrcodes WHERE id = ? AND user_id = ?', [$id, $userId]);
    if (!$qr) {
        abort(404, 'QR Code não encontrado.');
    }
    return $qr;
}

/** Registra uma abertura do link rastreado (dados minimizados, LGPD). */
function record_click(array $qr): void
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (ua_is_bot($ua)) {
        return; // pré-visualizações de apps e robôs não contam
    }
    $ip = client_ip();
    // Limite por IP: evita inflar contagens com scripts
    if (!rl_allow('click:' . $ip, 120, 60) || !rl_allow('click:' . $ip . ':' . $qr['id'], 20, 60)) {
        return;
    }
    // Identificador anônimo de visitante, que muda todo dia (não permite rastrear a pessoa)
    $visitor = substr(hash_hmac('sha256', $ip . '|' . $ua, subkey('visitor:' . date('Y-m-d'))), 0, 16);
    $info = ua_parse($ua);
    $t = now();
    q(
        'INSERT INTO clicks (qrcode_id, created_at, visitor, ip_prefix, device, os, browser, lang, referer)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$qr['id'], $t, $visitor, ip_prefix($ip), $info['device'], $info['os'], $info['browser'], ua_lang(), referer_host()]
    );
    q('UPDATE qrcodes SET clicks_total = clicks_total + 1, last_click_at = ? WHERE id = ?', [$t, $qr['id']]);
}

/** Série diária de aberturas dos últimos $days dias (inclui dias zerados). */
function daily_series(string $where, array $params, int $days = 30): array
{
    $start = strtotime('today') - ($days - 1) * 86400;
    $rows = q_all(
        "SELECT date(created_at, 'unixepoch', 'localtime') AS d, COUNT(*) AS n
         FROM clicks WHERE created_at >= ? AND {$where} GROUP BY d",
        array_merge([$start], $params)
    );
    $map = array_column($rows, 'n', 'd');
    $series = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', $start + $i * 86400 + 3600);
        $series[$d] = (int) ($map[$d] ?? 0);
    }
    return $series;
}

function breakdown(int $qrId, string $col, int $limit = 6): array
{
    $allowed = ['device', 'os', 'browser', 'lang', 'referer'];
    if (!in_array($col, $allowed, true)) {
        return [];
    }
    return q_all(
        "SELECT COALESCE(NULLIF({$col}, ''), '—') AS label, COUNT(*) AS n FROM clicks
         WHERE qrcode_id = ? GROUP BY label ORDER BY n DESC LIMIT {$limit}",
        [$qrId]
    );
}

function hours_breakdown(int $qrId): array
{
    $rows = q_all(
        "SELECT CAST(strftime('%H', created_at, 'unixepoch', 'localtime') AS INTEGER) AS h, COUNT(*) AS n
         FROM clicks WHERE qrcode_id = ? GROUP BY h",
        [$qrId]
    );
    $out = array_fill(0, 24, 0);
    foreach ($rows as $r) {
        $out[(int) $r['h']] = (int) $r['n'];
    }
    return $out;
}

/** Gráfico de barras em SVG (renderizado no servidor; sem JavaScript). */
function svg_bars(array $series, string $label = 'aberturas', bool $dateLabels = true): string
{
    $n = count($series);
    if ($n === 0) {
        return '';
    }
    $max = max(1, max($series));
    $w = 1000;
    $h = 150;
    $pad = 18;
    $bw = ($w - 2) / $n;
    $out = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ($h + $pad) . '" role="img" aria-label="Gráfico de ' . e($label) . '">';
    foreach ([0.5, 1] as $g) {
        $y = $h - $h * $g + 2;
        $out .= '<line x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" class="chart-grid"/>';
    }
    $i = 0;
    foreach ($series as $key => $v) {
        $bh = $v > 0 ? max(2, ($v / $max) * ($h - 6)) : 0;
        $x = $i * $bw + 1;
        $tip = ($dateLabels ? date('d/m', strtotime((string) $key)) : $key . 'h') . ': ' . fmt_int($v) . ' ' . $label;
        $out .= '<g><title>' . e($tip) . '</title>'
            . '<rect class="chart-hit" x="' . round($x, 2) . '" y="0" width="' . round($bw, 2) . '" height="' . $h . '"/>'
            . '<rect class="chart-bar" x="' . round($x + $bw * 0.15, 2) . '" y="' . round($h - $bh, 2) . '" width="' . round($bw * 0.7, 2) . '" height="' . round($bh, 2) . '" rx="2"/></g>';
        $every = $dateLabels ? max(1, (int) ceil($n / 6)) : 3;
        if ($i % $every === 0) {
            $text = $dateLabels ? date('d/m', strtotime((string) $key)) : $key . 'h';
            $out .= '<text x="' . round($x + $bw / 2, 2) . '" y="' . ($h + 14) . '" class="chart-label">' . e($text) . '</text>';
        }
        $i++;
    }
    return $out . '<text x="' . $w . '" y="12" class="chart-label chart-max">máx. ' . fmt_int($max) . '</text></svg>';
}
