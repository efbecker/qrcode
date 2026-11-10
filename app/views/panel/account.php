<?= view_fragment('partials/verify_banner', ['user' => $user]) ?>
<h1 class="page-title">Minha conta</h1>

<div class="settings-grid">
  <section class="panel" aria-labelledby="t-profile">
    <h2 id="t-profile" class="card-title">Perfil</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">
      <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
        <label for="name">Nome</label>
        <input type="text" id="name" name="name" value="<?= e($user['name']) ?>" maxlength="80" required autocomplete="name">
        <?php if (isset($errors['name'])): ?><small class="field-error"><?= e($errors['name']) ?></small><?php endif ?>
      </div>
      <div class="field">
        <span class="label">E-mail</span>
        <p class="static-value"><?= e($user['email']) ?>
          <?= $user['email_verified_at'] ? '<span class="badge badge-ok">verificado</span>' : '<span class="badge badge-warn">não verificado</span>' ?></p>
      </div>
      <p class="muted small">Conta criada em <?= e(fmt_date((int) $user['created_at'], false)) ?> · último acesso <?= e(fmt_date($user['last_login_at'] ? (int) $user['last_login_at'] : null)) ?></p>
      <button type="submit" class="btn btn-primary">Salvar</button>
    </form>
  </section>

  <section class="panel" aria-labelledby="t-pass">
    <h2 id="t-pass" class="card-title">Alterar senha</h2>
    <?php if (isset($errors['password'])): ?><div class="alert alert-error" role="alert"><?= e($errors['password']) ?></div><?php endif ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <input type="text" name="username" value="<?= e($user['email']) ?>" autocomplete="username" hidden>
      <div class="field">
        <label for="cp">Senha atual</label>
        <input type="password" id="cp" name="current_password" autocomplete="current-password" required maxlength="1024">
      </div>
      <div class="field">
        <label for="np">Nova senha</label>
        <input type="password" id="np" name="password" autocomplete="new-password" required minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" data-strength>
        <div class="strength" aria-hidden="true"><span></span></div>
      </div>
      <div class="field">
        <label for="np2">Confirme a nova senha</label>
        <input type="password" id="np2" name="password_confirm" autocomplete="new-password" required maxlength="<?= PASSWORD_MAX ?>">
      </div>
      <button type="submit" class="btn btn-primary">Alterar senha</button>
      <p class="muted small">Ao alterar a senha, as sessões abertas em outros dispositivos são encerradas.</p>
    </form>
  </section>

  <section class="panel danger-zone" aria-labelledby="t-del">
    <h2 id="t-del" class="card-title">Excluir conta</h2>
    <p class="muted">Apaga sua conta, todos os QR Codes salvos e as estatísticas. Links rastreados deixam de funcionar. Não pode ser desfeito.</p>
    <?php if (isset($errors['delete'])): ?><div class="alert alert-error" role="alert"><?= e($errors['delete']) ?></div><?php endif ?>
    <form method="post" class="stack" data-confirm="Tem certeza? Sua conta e todos os dados serão apagados definitivamente.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <div class="field">
        <label for="dp">Confirme com sua senha</label>
        <input type="password" id="dp" name="current_password" autocomplete="current-password" required maxlength="1024">
      </div>
      <button type="submit" class="btn btn-danger">Excluir minha conta</button>
    </form>
  </section>
</div>
