<h1 class="page-title">Administração</h1>
<?= view_fragment('partials/admin_nav', ['__view' => $__view]) ?>

<?php if (!setting('base_url') && !config('base_url')): ?>
  <div class="alert alert-warn banner-row">
    <span><strong>Endereço público não definido.</strong> Defina a URL do site para os links curtos e os e-mails de recuperação de senha usarem sempre o domínio correto.</span>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/configuracoes')) ?>">Definir</a>
  </div>
<?php endif ?>
<?php if (!$smtpOk): ?>
  <div class="alert alert-warn banner-row">
    <span><strong>SMTP não configurado.</strong> Sem ele não há e-mails de recuperação de senha nem de confirmação de conta.</span>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/configuracoes#smtp')) ?>">Configurar agora</a>
  </div>
<?php endif ?>

<div class="stat-grid">
  <div class="stat"><span class="stat-label">Contas</span><span class="stat-value"><?= fmt_int($stats['users']) ?></span><span class="stat-sub">+<?= fmt_int($stats['users_week']) ?> em 7 dias</span></div>
  <div class="stat"><span class="stat-label">Ativas (30 dias)</span><span class="stat-value"><?= fmt_int($stats['active_month']) ?></span></div>
  <div class="stat"><span class="stat-label">QR Codes salvos</span><span class="stat-value"><?= fmt_int($stats['qrcodes']) ?></span><span class="stat-sub"><?= fmt_int($stats['tracked']) ?> rastreados</span></div>
  <div class="stat"><span class="stat-label">Aberturas</span><span class="stat-value accent"><?= fmt_int($stats['clicks']) ?></span><span class="stat-sub"><?= fmt_int($stats['clicks_week']) ?> em 7 dias</span></div>
</div>

<section class="panel card-section">
  <h2 class="card-title">Aberturas em toda a plataforma (30 dias)</h2>
  <?= svg_bars($series) ?>
</section>

<div class="settings-grid">
  <section class="panel">
    <div class="list-head"><h2 class="card-title">Cadastros recentes</h2><a class="small-link" href="<?= e(url('/admin/usuarios')) ?>">Ver todas</a></div>
    <?php if (!$recentUsers): ?><p class="muted">Nenhuma conta ainda.</p><?php endif ?>
    <ul class="simple-list">
      <?php foreach ($recentUsers as $r): ?>
        <li><a href="<?= e(url('/admin/usuarios/' . $r['id'])) ?>"><?= e($r['name']) ?></a> <span class="muted small"><?= e($r['email']) ?> · <?= e(time_ago((int) $r['created_at'])) ?></span></li>
      <?php endforeach ?>
    </ul>
  </section>
  <section class="panel">
    <h2 class="card-title">Links mais abertos</h2>
    <?php if (!$topQr): ?><p class="muted">Nenhum link rastreado ainda.</p><?php endif ?>
    <ul class="simple-list">
      <?php foreach ($topQr as $q): ?>
        <li><a href="<?= e(url('/admin/usuarios/' . $q['user_id'])) ?>"><?= e($q['title']) ?></a> <span class="muted small"><?= fmt_int((int) $q['clicks_total']) ?> aberturas · <?= e($q['email']) ?></span></li>
      <?php endforeach ?>
    </ul>
  </section>
  <section class="panel">
    <h2 class="card-title">Segurança (24 h)</h2>
    <p><span class="stat-value<?= $stats['failed_logins'] > 20 ? ' warn' : '' ?>"><?= fmt_int($stats['failed_logins']) ?></span> tentativas de login falhas ou bloqueadas.</p>
    <a class="small-link" href="<?= e(url('/admin/seguranca')) ?>">Ver registro de segurança</a>
  </section>
</div>
