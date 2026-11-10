<?php $cur = $__view ?? ''; $items = ['admin/home' => ['/admin', 'Visão geral'], 'admin/users' => ['/admin/usuarios', 'Contas'], 'admin/settings' => ['/admin/configuracoes', 'Configurações e SMTP'], 'admin/security' => ['/admin/seguranca', 'Segurança']]; ?>
<nav class="tabs admin-tabs" aria-label="Administração">
  <?php foreach ($items as $view => [$path, $label]): ?>
    <a class="tab" href="<?= e(url($path)) ?>"<?= ($cur === $view || ($view === 'admin/users' && $cur === 'admin/user')) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach ?>
</nav>
