<div class="auth-wrap">
  <section class="auth-card" aria-labelledby="t-login">
    <h1 id="t-login" class="page-title">Entrar</h1>
    <p class="muted">Acesse seus QR Codes e acompanhe quantas pessoas abriram seus links.</p>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif ?>
    <form method="post" action="<?= e(url('/entrar')) ?>" class="stack" novalidate>
      <?= csrf_field() ?>
      <div class="field">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus maxlength="190">
      </div>
      <div class="field">
        <div class="label-row"><label for="password">Senha</label><a href="<?= e(url('/esqueci-senha')) ?>" class="small-link">Esqueci minha senha</a></div>
        <input type="password" id="password" name="password" autocomplete="current-password" required maxlength="1024">
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">Entrar</button>
    </form>
    <p class="auth-switch">Ainda não tem conta? <a href="<?= e(url('/cadastrar')) ?>">Crie grátis em 30 segundos</a></p>
  </section>
</div>
