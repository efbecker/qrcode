<h1 class="page-title">Contas</h1>
<?= view_fragment('partials/admin_nav', ['__view' => $__view]) ?>
<form method="get" class="filters" role="search">
  <input type="search" name="q" value="<?= e($search) ?>" placeholder="Buscar por nome ou e-mail" aria-label="Buscar contas">
  <select name="status" aria-label="Filtrar">
    <?php foreach (['' => 'Todas', 'admin' => 'Administradores', 'inactive' => 'Desativadas', 'unverified' => 'E-mail não verificado'] as $k => $l): ?>
      <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option>
    <?php endforeach ?>
  </select>
  <button class="btn btn-primary" type="submit">Filtrar</button>
</form>
<p class="muted small"><?= fmt_int(count($users)) ?> conta(s)</p>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Nome</th><th>E-mail</th><th>Situação</th><th class="num">QR Codes</th><th class="num">Aberturas</th><th>Criada</th><th>Último acesso</th></tr></thead>
    <tbody>
      <?php foreach ($users as $r): ?>
        <tr>
          <td><a href="<?= e(url('/admin/usuarios/' . $r['id'])) ?>"><?= e($r['name']) ?></a></td>
          <td><?= e($r['email']) ?></td>
          <td>
            <?= $r['is_active'] ? '<span class="badge badge-ok">ativa</span>' : '<span class="badge badge-error">desativada</span>' ?>
            <?= $r['is_admin'] ? '<span class="badge badge-accent">admin</span>' : '' ?>
            <?= $r['email_verified_at'] ? '' : '<span class="badge badge-warn">não verificado</span>' ?>
          </td>
          <td class="num"><?= fmt_int((int) $r['qr_count']) ?></td>
          <td class="num"><?= fmt_int((int) $r['clicks']) ?></td>
          <td><?= e(fmt_date((int) $r['created_at'], false)) ?></td>
          <td><?= e(time_ago($r['last_login_at'] ? (int) $r['last_login_at'] : null)) ?></td>
        </tr>
      <?php endforeach ?>
      <?php if (!$users): ?><tr><td colspan="7" class="muted">Nenhuma conta encontrada.</td></tr><?php endif ?>
    </tbody>
  </table>
</div>
