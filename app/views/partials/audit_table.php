<?php $labels = [
  'login' => 'Login', 'logout' => 'Logout', 'login_failed' => 'Login falhou', 'login_blocked' => 'Login bloqueado (excesso de tentativas)',
  'login_disabled' => 'Login em conta desativada', 'register' => 'Cadastro', 'register_duplicate' => 'Cadastro com e-mail já existente',
  'reset_requested' => 'Pedido de nova senha', 'password_reset' => 'Senha redefinida', 'password_changed' => 'Senha alterada',
  'reauth_failed' => 'Senha atual incorreta', 'email_verified' => 'E-mail verificado', 'account_deleted' => 'Conta excluída pelo usuário',
  'admin_user_disabled' => 'Admin desativou conta', 'admin_user_enabled' => 'Admin reativou conta', 'admin_granted' => 'Admin concedido',
  'admin_revoked' => 'Admin removido', 'admin_email_verified' => 'Admin verificou e-mail', 'admin_reset_sent' => 'Admin enviou nova senha',
  'admin_sessions_revoked' => 'Admin encerrou sessões', 'admin_qr_block_toggled' => 'Admin bloqueou/desbloqueou link',
  'admin_user_deleted' => 'Admin excluiu conta', 'settings_changed' => 'Configurações alteradas', 'mail_error' => 'Falha no envio de e-mail',
  'cli' => 'Comando no servidor',
]; ?>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Quando</th><th>Evento</th><th>Conta</th><th>Rede (anonimizada)</th><th>Detalhe</th></tr></thead>
    <tbody>
      <?php foreach ($events as $ev): ?>
        <tr class="<?= in_array($ev['event'], ['login_failed', 'login_blocked', 'reauth_failed', 'mail_error'], true) ? 'row-warn' : '' ?>">
          <td><?= e(fmt_date((int) $ev['created_at'])) ?></td>
          <td><?= e($labels[$ev['event']] ?? $ev['event']) ?></td>
          <td><?= e($ev['email'] ?? '—') ?></td>
          <td><code><?= e($ev['ip_prefix'] ?: '—') ?></code></td>
          <td class="truncate" title="<?= e($ev['detail']) ?>"><?= e($ev['detail']) ?></td>
        </tr>
      <?php endforeach ?>
      <?php if (!$events): ?><tr><td colspan="5" class="muted">Nenhum evento.</td></tr><?php endif ?>
    </tbody>
  </table>
</div>
