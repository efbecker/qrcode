<?php
$qrJson = fn (array $q) => json_encode(['data' => qr_data($q), 'style' => json_decode((string) $q['style_json'], true) ?: []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$tabs = ['' => 'Todos', 'track' => 'Rastreados', 'static' => 'Estáticos'];
?>
<?= view_fragment('partials/verify_banner', ['user' => $user]) ?>

<div class="page-head">
  <div>
    <h1 class="page-title">Olá, <?= e(explode(' ', $user['name'])[0]) ?>!</h1>
    <p class="muted">Acompanhe as aberturas dos seus QR Codes e gerencie seus links.</p>
  </div>
  <a class="btn btn-accent" href="<?= e(url('/')) ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
    Novo QR Code
  </a>
</div>

<div class="stat-grid">
  <div class="stat"><span class="stat-label">QR Codes</span><span class="stat-value"><?= fmt_int($stats['qrcodes']) ?></span></div>
  <div class="stat"><span class="stat-label">Links rastreados</span><span class="stat-value"><?= fmt_int($stats['tracked']) ?></span></div>
  <div class="stat"><span class="stat-label">Aberturas (total)</span><span class="stat-value accent"><?= fmt_int($stats['total']) ?></span></div>
  <div class="stat"><span class="stat-label">Últimos 7 dias</span><span class="stat-value"><?= fmt_int($stats['week']) ?></span></div>
</div>

<?php if ($stats['tracked']): ?>
  <section class="panel card-section" aria-labelledby="t-chart">
    <h2 id="t-chart" class="card-title">Aberturas nos últimos 30 dias</h2>
    <?= svg_bars($series) ?>
  </section>
<?php endif ?>

<section class="card-section" aria-labelledby="t-list">
  <div class="list-head">
    <h2 id="t-list" class="section-title">Meus QR Codes</h2>
    <form method="get" action="<?= e(url('/painel')) ?>" class="list-tools" role="search">
      <nav class="tabs" aria-label="Filtrar">
        <?php foreach ($tabs as $k => $label): ?>
          <a href="<?= e(url('/painel' . ($k ? '?f=' . $k : ''))) ?>" class="tab"<?= $filter === $k ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
        <?php endforeach ?>
      </nav>
      <?php if ($filter): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif ?>
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Buscar por nome ou link" aria-label="Buscar QR Codes">
    </form>
  </div>

  <?php if (!$qrcodes): ?>
    <div class="empty-state panel">
      <?php if ($search || $filter): ?>
        <p class="muted">Nenhum QR Code encontrado com esse filtro.</p>
      <?php else: ?>
        <h3>Você ainda não salvou nenhum QR Code</h3>
        <p class="muted">No gerador, preencha um link e clique em <strong>Salvar e rastrear</strong>. O QR Code aparece aqui com a contagem de aberturas.</p>
        <a class="btn btn-accent" href="<?= e(url('/')) ?>">Criar meu primeiro QR Code</a>
      <?php endif ?>
    </div>
  <?php else: ?>
    <ul class="qr-list">
      <?php foreach ($qrcodes as $q): ?>
        <li class="qr-item">
          <a class="qr-item-link" href="<?= e(url('/painel/qr/' . $q['id'])) ?>">
            <span class="qr-thumb" data-qr="<?= e($qrJson($q)) ?>" data-qr-size="120" aria-hidden="true"></span>
            <span class="qr-item-body">
              <span class="qr-item-title"><?= e($q['title']) ?></span>
              <span class="qr-item-meta">
                <span class="badge"><?= e(qr_type_label($q['type'])) ?></span>
                <?php if ($q['short_code']): ?>
                  <span class="badge badge-accent">Rastreado</span>
                  <?php if (!$q['is_active']): ?><span class="badge badge-warn">Pausado</span><?php endif ?>
                  <?php if ($q['blocked']): ?><span class="badge badge-error">Bloqueado</span><?php endif ?>
                <?php else: ?>
                  <span class="badge badge-muted">Estático</span>
                <?php endif ?>
              </span>
              <span class="qr-item-dest" title="<?= e($q['short_code'] ? $q['content'] : qr_type_label($q['type'])) ?>"><?= e(mb_strimwidth(preg_replace('/(^WIFI:.*?P:)((?:\\\\.|[^;])*)/', '$1••••••', str_replace("\n", ' ', $q['content'])), 0, 70, '…')) ?></span>
            </span>
            <span class="qr-item-stats">
              <?php if ($q['short_code']): ?>
                <span class="qr-clicks"><?= fmt_int((int) $q['clicks_total']) ?></span>
                <span class="muted small"><?= (int) $q['clicks_total'] === 1 ? 'abertura' : 'aberturas' ?></span>
                <span class="muted small">última: <?= e(time_ago($q['last_click_at'] ? (int) $q['last_click_at'] : null)) ?></span>
              <?php else: ?>
                <span class="muted small">sem rastreio</span>
              <?php endif ?>
            </span>
          </a>
        </li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
</section>
