<?php if (!$user['email_verified_at'] && smtp_configured()): ?>
  <div class="alert alert-warn banner-row">
    <span><strong>Confirme seu e-mail</strong> (<?= e($user['email']) ?>)<?= setting('require_verified_email') === '1' ? ' para liberar os links rastreados' : '' ?>. Não recebeu?</span>
    <form method="post" action="<?= e(url('/verificar-email/reenviar')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-outline btn-sm">Reenviar link</button>
    </form>
  </div>
<?php endif ?>
