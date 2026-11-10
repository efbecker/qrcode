<div class="auth-wrap auth-wide">
  <section class="auth-card" aria-labelledby="t-reg">
    <h1 id="t-reg" class="page-title">Criar conta grátis</h1>
    <?php if (!$open): ?>
      <div class="alert alert-info">Novos cadastros estão temporariamente fechados. Você ainda pode <a href="<?= e(url('/')) ?>">gerar QR Codes sem conta</a>.</div>
    <?php else: ?>
      <p class="muted">Leva menos de um minuto. Sem cartão de crédito.</p>
      <?php if (!empty($errors['form'])): ?><div class="alert alert-error" role="alert"><?= e($errors['form']) ?></div><?php endif ?>
      <form method="post" action="<?= e(url('/cadastrar')) ?>" class="stack" novalidate>
        <?= csrf_field() ?>
        <div class="hp" aria-hidden="true"><label for="website">Não preencha</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
          <label for="name">Nome</label>
          <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" autocomplete="name" required maxlength="80" autofocus>
          <?php if (isset($errors['name'])): ?><small class="field-error"><?= e($errors['name']) ?></small><?php endif ?>
        </div>
        <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
          <label for="email">E-mail</label>
          <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" autocomplete="email" required maxlength="190">
          <?php if (isset($errors['email'])): ?><small class="field-error"><?= e($errors['email']) ?></small><?php endif ?>
        </div>
        <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
          <label for="password">Senha</label>
          <input type="password" id="password" name="password" autocomplete="new-password" required minlength="<?= PASSWORD_MIN ?>" maxlength="<?= PASSWORD_MAX ?>" aria-describedby="pw-hint" data-strength>
          <small id="pw-hint" class="hint">Mínimo de <?= PASSWORD_MIN ?> caracteres. Uma frase longa é ótima, ex.: "café-com-pão-às-7h".</small>
          <div class="strength" aria-hidden="true"><span></span></div>
          <?php if (isset($errors['password'])): ?><small class="field-error"><?= e($errors['password']) ?></small><?php endif ?>
        </div>
        <div class="field<?= isset($errors['password_confirm']) ? ' has-error' : '' ?>">
          <label for="password_confirm">Confirme a senha</label>
          <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required maxlength="<?= PASSWORD_MAX ?>">
          <?php if (isset($errors['password_confirm'])): ?><small class="field-error"><?= e($errors['password_confirm']) ?></small><?php endif ?>
        </div>
        <label class="check<?= isset($errors['terms']) ? ' has-error' : '' ?>">
          <input type="checkbox" name="terms" value="1" required>
          <span>Concordo que meus QR Codes salvos e as estatísticas anônimas de abertura fiquem guardados na minha conta. Posso excluir tudo a qualquer momento.</span>
        </label>
        <?php if (isset($errors['terms'])): ?><small class="field-error"><?= e($errors['terms']) ?></small><?php endif ?>
        <button type="submit" class="btn btn-accent btn-block btn-lg">Criar minha conta</button>
      </form>
      <p class="auth-switch">Já tem conta? <a href="<?= e(url('/entrar')) ?>">Entrar</a></p>
    <?php endif ?>
  </section>

  <aside class="auth-benefits" aria-labelledby="t-benef">
    <h2 id="t-benef">Com a conta você ganha</h2>
    <ul class="benefit-list">
      <li><strong>Contagem de aberturas</strong> de cada QR Code, em tempo real.</li>
      <li><strong>Quem abriu e como:</strong> dia e horário, celular ou computador, sistema, navegador e idioma.</li>
      <li><strong>Links dinâmicos:</strong> troque o destino depois de impresso, sem gerar outro QR Code.</li>
      <li><strong>Pausar e reativar</strong> um link quando a campanha acabar.</li>
      <li><strong>Histórico</strong> de todos os QR Codes, prontos para baixar de novo em PNG ou SVG.</li>
      <li><strong>Exportação CSV</strong> das aberturas para planilhas.</li>
    </ul>
    <p class="muted small">Só precisa de um QR Code rápido? <a href="<?= e(url('/')) ?>">Use o gerador sem conta</a>: continua grátis e sem rastreamento.</p>
  </aside>
</div>
