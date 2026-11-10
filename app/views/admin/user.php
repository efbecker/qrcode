<?php $t = $target; $btn = function (string $action, string $label, string $cls = 'btn-ghost', string $confirm = '') { ?>
  <form method="post"<?= $confirm ? ' data-confirm="' . e($confirm) . '"' : '' ?>>
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= e($action) ?>">
    <button type="submit" class="btn <?= e($cls) ?>"><?= e($label) ?></button>
  </form>
<?php }; ?>
<nav class="breadcrumb" aria-label="Você está em"><a href="<?= e(url('/admin/usuarios')) ?>">Contas</a> <span aria-hidden="true">/</span> <span><?= e($t['email']) ?></span></nav>
<div class="page-head">
  <div>
    <h1 class="page-title"><?= e($t['name']) ?></h1>
    <p class="qr-item-meta">
      <?= $t['is_active'] ? '<span class="badge badge-ok">ativa</span>' : '<span class="badge badge-error">desativada</span>' ?>
      <?= $t['is_admin'] ? '<span class="badge badge-accent">administrador</span>' : '' ?>
      <?= $t['email_verified_at'] ? '<span class="badge badge-ok">e-mail verificado</span>' : '<span class="badge badge-warn">e-mail não verificado</span>' ?>
      <?= $self ? '<span class="badge">você</span>' : '' ?>
    </p>
  </div>
</div>

<div class="settings-grid">
  <section class="panel">
    <h2 class="card-title">Dados</h2>
    <dl class="kv">
      <dt>E-mail</dt><dd><?= e($t['email']) ?></dd>
      <dt>Criada em</dt><dd><?= e(fmt_date((int) $t['created_at'])) ?></dd>
      <dt>Último acesso</dt><dd><?= e(fmt_date($t['last_login_at'] ? (int) $t['last_login_at'] : null)) ?></dd>
      <dt>Verificação</dt><dd><?= e($t['email_verified_at'] ? fmt_date((int) $t['email_verified_at']) : 'pendente') ?></dd>
      <dt>QR Codes</dt><dd><?= fmt_int(count($qrcodes)) ?></dd>
    </dl>
  </section>
  <section class="panel">
    <h2 class="card-title">Ações</h2>
    <div class="action-row">
      <?php if (!$self): ?>
        <?php $btn('toggle_active', $t['is_active'] ? 'Desativar conta' : 'Reativar conta', $t['is_active'] ? 'btn-ghost' : 'btn-primary', $t['is_active'] ? 'Desativar esta conta? A pessoa será desconectada e não poderá entrar.' : '') ?>
        <?php $btn('toggle_admin', $t['is_admin'] ? 'Remover admin' : 'Tornar admin', 'btn-ghost', $t['is_admin'] ? '' : 'Dar acesso total de administrador a esta conta?') ?>
      <?php endif ?>
      <?php if (!$t['email_verified_at']) $btn('verify', 'Marcar e-mail como verificado') ?>
      <?php $btn('send_reset', 'Enviar link de nova senha') ?>
      <?php $btn('logout_all', 'Encerrar sessões', 'btn-ghost', 'Desconectar esta conta de todos os dispositivos?') ?>
    </div>
    <?php if (!$self): ?>
      <details class="danger-zone">
        <summary>Excluir conta</summary>
        <form method="post" class="stack" data-confirm="Excluir definitivamente esta conta e todos os QR Codes?">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete">
          <div class="field"><label for="ce">Digite o e-mail da conta para confirmar</label><input type="text" id="ce" name="confirm_email" autocomplete="off" required></div>
          <button class="btn btn-danger" type="submit">Excluir conta</button>
        </form>
      </details>
    <?php endif ?>
  </section>
</div>

<section class="card-section">
  <h2 class="section-title">QR Codes da conta</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Nome</th><th>Tipo</th><th>Destino / conteúdo</th><th class="num">Aberturas</th><th>Situação</th><th>Criado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($qrcodes as $q): ?>
          <tr>
            <td><?= e($q['title']) ?></td>
            <td><?= e(qr_type_label($q['type'])) ?><?= $q['short_code'] ? ' · <code>/r/' . e($q['short_code']) . '</code>' : '' ?></td>
            <td class="truncate" title="<?= e($q['content']) ?>"><?= $q['short_code'] ? e(mb_strimwidth($q['content'], 0, 60, '…')) : '<span class="muted">(conteúdo privado)</span>' ?></td>
            <td class="num"><?= $q['short_code'] ? fmt_int((int) $q['clicks_total']) : '—' ?></td>
            <td><?= $q['blocked'] ? '<span class="badge badge-error">bloqueado</span>' : ($q['is_active'] ? '<span class="badge badge-ok">ativo</span>' : '<span class="badge badge-warn">pausado</span>') ?></td>
            <td><?= e(fmt_date((int) $q['created_at'], false)) ?></td>
            <td>
              <?php if ($q['short_code']): ?>
                <form method="post" data-confirm="<?= $q['blocked'] ? 'Desbloquear este link?' : 'Bloquear este link? Quem escanear verá que o link está indisponível.' ?>">
                  <?= csrf_field() ?><input type="hidden" name="action" value="block_qr"><input type="hidden" name="qr_id" value="<?= (int) $q['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"><?= $q['blocked'] ? 'Desbloquear' : 'Bloquear' ?></button>
                </form>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        <?php if (!$qrcodes): ?><tr><td colspan="7" class="muted">Nenhum QR Code salvo.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
  <p class="muted small">Por privacidade, o conteúdo de QR Codes estáticos (ex.: senha de Wi-Fi) não é exibido para administradores.</p>
</section>

<section class="card-section">
  <h2 class="section-title">Eventos recentes</h2>
  <?= view_fragment('partials/audit_table', ['events' => $events]) ?>
</section>
