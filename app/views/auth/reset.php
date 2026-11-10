<div class="auth-wrap">
  <section class="auth-card" aria-labelledby="t-reset">
    <h1 id="t-reset" class="page-title">Nova senha</h1>
    <?php if ($invalid): ?>
      <div class="alert alert-error" role="alert">Este link é inválido, já foi usado ou expirou.</div>
      <a class="btn btn-primary btn-block" href="<?= e(url('/esqueci-senha')) ?>">Pedir um novo link</a>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif ?>
      <form method="post" action="<?= e(url('/redefinir-senha')) ?>" class="stack" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field">
          <label for="password">Nova senha</label>
          <input type="password" id="password" name="password" autocomplete="new-password" required minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" autofocus data-strength>
          <small class="hint">Mínimo de <?= PASSWORD_MIN ?> caracteres.</small>
          <div class="strength" aria-hidden="true"><span></span></div>
        </div>
        <div class="field">
          <label for="password_confirm">Confirme a nova senha</label>
          <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required maxlength="<?= PASSWORD_MAX ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Salvar nova senha</button>
        <p class="muted small">Por segurança, todas as sessões abertas da conta serão encerradas.</p>
      </form>
    <?php endif ?>
  </section>
</div>
