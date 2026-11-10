<div class="auth-wrap">
  <section class="auth-card" aria-labelledby="t-verify">
    <h1 id="t-verify" class="page-title">Confirmar e-mail</h1>
    <p class="muted">Confirme que <strong><?= e($email) ?></strong> é o seu e-mail para liberar os links rastreados.</p>
    <form method="post" action="<?= e(url('/verificar-email')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <button type="submit" class="btn btn-accent btn-block btn-lg">Confirmar meu e-mail</button>
    </form>
  </section>
</div>
