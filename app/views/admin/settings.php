<?php
$posted = is_post();
$val = fn (string $k) => $posted && isset($_POST[$k]) && $k !== 'smtp_pass' ? (string) $_POST[$k] : setting($k);
$err = fn (string $k) => isset($errors[$k]) ? '<small class="field-error">' . e($errors[$k]) . '</small>' : '';
$sec = $val('smtp_security');
?>
<h1 class="page-title">Configurações</h1>
<?= view_fragment('partials/admin_nav', ['__view' => $__view]) ?>

<div class="settings-grid settings-2">
  <section class="panel" aria-labelledby="t-general">
    <h2 id="t-general" class="card-title">Geral</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="general">
      <div class="field">
        <label for="base_url">Endereço público do site</label>
        <input type="url" id="base_url" name="base_url" value="<?= e($val('base_url')) ?>" placeholder="<?= e($configBase ?: 'https://qrcode.seudominio.com.br') ?>">
        <small class="hint">Usado nos links curtos dos QR Codes e nos e-mails. Vazio = <?= e($configBase ?: 'detecta automaticamente') ?>. Atenção: alterar muda o endereço dos novos downloads; QR Codes já impressos continuam com o endereço antigo.</small>
        <?= $err('base_url') ?>
      </div>
      <label class="switch"><input type="checkbox" name="allow_registration" value="1"<?= setting('allow_registration') === '1' ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span class="switch-label">Permitir novos cadastros</span></label>
      <label class="switch"><input type="checkbox" name="require_verified_email" value="1"<?= setting('require_verified_email') === '1' ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span class="switch-label">Exigir e-mail confirmado para criar links rastreados (só vale com SMTP configurado; reduz abuso de spam/phishing)</span></label>
      <div class="form-grid">
        <div class="field">
          <label for="crd">Guardar aberturas por (dias)</label>
          <input type="number" id="crd" name="click_retention_days" min="30" max="3650" value="<?= e($val('click_retention_days')) ?>">
        </div>
        <div class="field">
          <label for="mq">Máximo de QR Codes por conta</label>
          <input type="number" id="mq" name="max_qrcodes_per_user" min="1" max="100000" value="<?= e($val('max_qrcodes_per_user')) ?>">
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Salvar</button>
    </form>
  </section>

  <section class="panel" id="smtp" aria-labelledby="t-smtp">
    <h2 id="t-smtp" class="card-title">Servidor de e-mail (SMTP)</h2>
    <p class="muted small">Usado para recuperação de senha, confirmação de cadastro e avisos de segurança. Funciona com Gmail/Google Workspace (senha de app), Microsoft 365, Amazon SES, Brevo, Mailgun, Zoho, servidor próprio etc.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="field field-full">
          <label for="smtp_host">Servidor</label>
          <input type="text" id="smtp_host" name="smtp_host" value="<?= e($val('smtp_host')) ?>" placeholder="smtp.gmail.com" autocomplete="off">
          <?= $err('smtp_host') ?>
        </div>
        <div class="field">
          <label for="smtp_port">Porta</label>
          <input type="number" id="smtp_port" name="smtp_port" value="<?= e($val('smtp_port')) ?>" min="1" max="65535">
          <?= $err('smtp_port') ?>
        </div>
        <div class="field">
          <label for="smtp_security">Segurança</label>
          <select id="smtp_security" name="smtp_security">
            <option value="starttls"<?= $sec === 'starttls' ? ' selected' : '' ?>>STARTTLS (porta 587)</option>
            <option value="ssl"<?= $sec === 'ssl' ? ' selected' : '' ?>>SSL/TLS (porta 465)</option>
            <option value="none"<?= $sec === 'none' ? ' selected' : '' ?>>Nenhuma (só relay local, sem senha)</option>
          </select>
          <?= $err('smtp_security') ?>
        </div>
        <div class="field">
          <label for="smtp_user">Usuário</label>
          <input type="text" id="smtp_user" name="smtp_user" value="<?= e($val('smtp_user')) ?>" autocomplete="off">
        </div>
        <div class="field">
          <label for="smtp_pass">Senha</label>
          <input type="password" id="smtp_pass" name="smtp_pass" autocomplete="new-password" placeholder="<?= $hasPass ? '•••••••• (guardada; deixe vazio para manter)' : '' ?>">
          <?php if ($hasPass): ?><label class="check small"><input type="checkbox" name="smtp_pass_clear" value="1"> <span>Apagar senha salva</span></label><?php endif ?>
        </div>
        <div class="field">
          <label for="smtp_from_email">E-mail do remetente</label>
          <input type="email" id="smtp_from_email" name="smtp_from_email" value="<?= e($val('smtp_from_email')) ?>" placeholder="nao-responda@seudominio.com.br">
          <?= $err('smtp_from_email') ?>
        </div>
        <div class="field">
          <label for="smtp_from_name">Nome do remetente</label>
          <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= e($val('smtp_from_name')) ?>">
        </div>
      </div>
      <p class="muted small">A senha é guardada cifrada (libsodium) e nunca é exibida de volta. O certificado TLS do servidor é sempre verificado.</p>
      <div class="action-row">
        <button type="submit" name="action" value="smtp" class="btn btn-primary">Salvar SMTP</button>
        <button type="submit" name="action" value="smtp_test" class="btn btn-outline">Enviar e-mail de teste para <?= e($user['email']) ?></button>
      </div>
    </form>
  </section>
</div>
