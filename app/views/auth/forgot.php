<div class="auth-wrap">
  <section class="auth-card" aria-labelledby="t-forgot">
    <h1 id="t-forgot" class="page-title">Recuperar senha</h1>
    <?php if ($sent): ?>
      <div class="alert alert-ok" role="status">Se existir uma conta com <strong><?= e($email) ?></strong>, você receberá em instantes um link para criar uma nova senha. O link vale por 1 hora e só pode ser usado uma vez.</div>
      <p class="muted small">Não chegou? Confira a caixa de spam ou aguarde alguns minutos antes de pedir de novo.</p>
    <?php else: ?>
      <p class="muted">Informe o e-mail da sua conta. Enviaremos um link seguro para você criar uma nova senha.</p>
      <?php if (!$smtp): ?><div class="alert alert-info">O envio de e-mails ainda não foi configurado. Se precisar, peça ao administrador para redefinir seu acesso.</div><?php endif ?>
      <form method="post" action="<?= e(url('/esqueci-senha')) ?>" class="stack" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="email">E-mail</label>
          <input type="email" id="email" name="email" autocomplete="email" required maxlength="190" autofocus>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Enviar link</button>
      </form>
    <?php endif ?>
    <p class="auth-switch"><a href="<?= e(url('/entrar')) ?>">Voltar para o login</a></p>
  </section>
</div>
