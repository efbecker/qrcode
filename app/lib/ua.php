<?php
/** Leitura simples do User-Agent (sem guardar o UA completo). */
declare(strict_types=1);

function ua_is_bot(string $ua): bool
{
    if ($ua === '') {
        return false;
    }
    return (bool) preg_match(
        '/bot\b|bot\/|crawl|spider|slurp|facebookexternalhit|facebookcatalog|whatsapp\/|telegrambot|twitterbot|'
        . 'linkedinbot|discordbot|slackbot|skypeuripreview|bingpreview|embedly|vkshare|pinterest|'
        . 'google-inspectiontool|googleother|lighthouse|headlesschrome|curl\/|wget\/|python-requests|'
        . 'python-urllib|go-http-client|okhttp|java\/|libwww|httpclient|preview|scanner|monitor/i',
        $ua
    );
}

/** @return array{device:string, os:string, browser:string} */
function ua_parse(string $ua): array
{
    if ($ua === '') {
        return ['device' => 'Desconhecido', 'os' => 'Desconhecido', 'browser' => 'Desconhecido'];
    }

    $device = match (true) {
        (bool) preg_match('/iPad|Tablet|PlayBook|Silk|Kindle|(Android(?!.*Mobile))/i', $ua) => 'Tablet',
        (bool) preg_match('/Mobi|iPhone|iPod|Android|Windows Phone|BlackBerry|Opera Mini/i', $ua) => 'Celular',
        (bool) preg_match('/SmartTV|SMART-TV|HbbTV|AppleTV|CrKey|Tizen.*TV|Web0S/i', $ua) => 'TV',
        default => 'Computador',
    };

    $os = match (true) {
        (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
        (bool) preg_match('/Android/i', $ua) => 'Android',
        (bool) preg_match('/Windows/i', $ua) => 'Windows',
        (bool) preg_match('/CrOS/i', $ua) => 'ChromeOS',
        (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'macOS',
        (bool) preg_match('/Linux/i', $ua) => 'Linux',
        default => 'Outro',
    };

    $browser = match (true) {
        (bool) preg_match('/Instagram/i', $ua) => 'Instagram (app)',
        (bool) preg_match('/FBAN|FBAV|FB_IAB/i', $ua) => 'Facebook (app)',
        (bool) preg_match('/\bLine\//i', $ua) => 'LINE (app)',
        (bool) preg_match('/Edg(e|A|iOS)?\//i', $ua) => 'Edge',
        (bool) preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
        (bool) preg_match('/SamsungBrowser/i', $ua) => 'Samsung Internet',
        (bool) preg_match('/Firefox|FxiOS/i', $ua) => 'Firefox',
        (bool) preg_match('/CriOS|Chrome/i', $ua) => 'Chrome',
        (bool) preg_match('/Safari/i', $ua) => 'Safari',
        default => 'Outro',
    };

    return compact('device', 'os', 'browser');
}

/** Idioma principal do navegador (ex.: pt-BR). */
function ua_lang(): string
{
    $al = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if (preg_match('/^\s*([A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,4})?)/', $al, $m)) {
        return $m[1];
    }
    return '';
}

function referer_host(): string
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $ref !== '' ? (string) parse_url($ref, PHP_URL_HOST) : '';
    return mb_substr(preg_replace('/[^A-Za-z0-9.\-]/', '', $host), 0, 100);
}
