<?php
/** Conexão SQLite (PDO) e migrações versionadas via PRAGMA user_version. */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $file = data_dir() . '/qrcode.sqlite';
    $old = umask(0027);
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    umask($old);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    migrate($pdo);
    return $pdo;
}

/** Executa uma consulta preparada e devolve o statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function migrate(PDO $pdo): void
{
    $migrations = [
        1 => <<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1,
                email_verified_at INTEGER,
                session_version INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL,
                last_login_at INTEGER
            );
            CREATE TABLE qrcodes (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title TEXT NOT NULL,
                type TEXT NOT NULL,
                content TEXT NOT NULL,
                short_code TEXT UNIQUE,
                is_active INTEGER NOT NULL DEFAULT 1,
                blocked INTEGER NOT NULL DEFAULT 0,
                style_json TEXT,
                clicks_total INTEGER NOT NULL DEFAULT 0,
                last_click_at INTEGER,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE INDEX qrcodes_user ON qrcodes(user_id, created_at);
            CREATE TABLE clicks (
                id INTEGER PRIMARY KEY,
                qrcode_id INTEGER NOT NULL REFERENCES qrcodes(id) ON DELETE CASCADE,
                created_at INTEGER NOT NULL,
                visitor TEXT NOT NULL,
                ip_prefix TEXT,
                device TEXT,
                os TEXT,
                browser TEXT,
                lang TEXT,
                referer TEXT
            );
            CREATE INDEX clicks_qr_time ON clicks(qrcode_id, created_at);
            CREATE INDEX clicks_time ON clicks(created_at);
            CREATE TABLE tokens (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                purpose TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at INTEGER NOT NULL,
                used_at INTEGER,
                created_at INTEGER NOT NULL
            );
            CREATE INDEX tokens_user ON tokens(user_id, purpose);
            CREATE TABLE settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
            CREATE TABLE rate_limits (
                k TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY,
                created_at INTEGER NOT NULL,
                user_id INTEGER,
                email TEXT,
                event TEXT NOT NULL,
                ip_prefix TEXT,
                detail TEXT
            );
            CREATE INDEX audit_time ON audit_log(created_at);
            SQL,
    ];

    $current = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    foreach ($migrations as $version => $sql) {
        if ($version <= $current) {
            continue;
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Reconfere dentro da transação (outro processo pode ter migrado)
            if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < $version) {
                $pdo->exec($sql);
                $pdo->exec('PRAGMA user_version = ' . (int) $version);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $t) {
            $pdo->exec('ROLLBACK');
            throw $t;
        }
    }
}

/** Limpeza periódica (executada ocasionalmente em requisições normais). */
function maintenance(bool $force = false): void
{
    if (!$force && random_int(1, 200) !== 1) {
        return;
    }
    $t = now();
    q('DELETE FROM tokens WHERE expires_at < ? OR used_at IS NOT NULL AND used_at < ?', [$t - 86400, $t - 86400]);
    q('DELETE FROM rate_limits WHERE window_start < ?', [$t - 86400 * 2]);
    $days = max(30, (int) (setting('click_retention_days') ?: 365));
    q('DELETE FROM clicks WHERE created_at < ?', [$t - $days * 86400]);
    q('DELETE FROM audit_log WHERE created_at < ?', [$t - 180 * 86400]);
}

function audit(string $event, ?int $userId = null, ?string $email = null, string $detail = ''): void
{
    q(
        'INSERT INTO audit_log (created_at, user_id, email, event, ip_prefix, detail) VALUES (?, ?, ?, ?, ?, ?)',
        [now(), $userId, $email !== null ? mb_substr($email, 0, 190) : null, $event, ip_prefix(), mb_substr($detail, 0, 500)]
    );
}
