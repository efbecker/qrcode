<h1 class="page-title">Segurança</h1>
<?= view_fragment('partials/admin_nav', ['__view' => $__view]) ?>
<div class="panel card-section">
  <h2 class="card-title">Proteções ativas</h2>
  <ul class="check-list">
    <li>Senhas com hash <strong><?= defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt' ?></strong>, mínimo de <?= PASSWORD_MIN ?> caracteres.</li>
    <li>Bloqueio após <?= LOGIN_MAX_PER_EMAIL ?> tentativas erradas por conta ou <?= LOGIN_MAX_PER_IP ?> por rede, em 15 minutos.</li>
    <li>Links de nova senha de uso único, válidos por 1 hora; guardamos só o hash do token.</li>
    <li>Sessões com cookie HttpOnly<?= is_https() ? ', Secure e prefixo __Host-' : '' ?>, SameSite=Lax, renovadas no login e encerradas ao trocar a senha.</li>
    <li>Proteção CSRF em todos os formulários e verificação de origem.</li>
    <li>Senha do SMTP cifrada no banco (libsodium) com chave fora da pasta pública.</li>
    <li>IPs anonimizados (LGPD) em estatísticas e neste registro.</li>
  </ul>
  <?php if (!is_https()): ?><div class="alert alert-warn">O site está sendo acessado <strong>sem HTTPS</strong>. Ative o certificado (certbot) para proteger senhas e sessões.</div><?php endif ?>
</div>
<form method="get" class="filters">
  <select name="event" aria-label="Tipo de evento">
    <option value="">Todos os eventos</option>
    <?php foreach ($eventTypes as $et): ?>
      <option value="<?= e($et['event']) ?>"<?= $event === $et['event'] ? ' selected' : '' ?>><?= e($et['event']) ?> (<?= (int) $et['n'] ?>)</option>
    <?php endforeach ?>
  </select>
  <button class="btn btn-primary" type="submit">Filtrar</button>
</form>
<?= view_fragment('partials/audit_table', ['events' => $events]) ?>
