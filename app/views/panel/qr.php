<?php
$data = qr_data($qr);
$qrJson = json_encode(['data' => $data, 'style' => $style], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$fname = 'qrcode-' . preg_replace('/[^a-z0-9]+/i', '-', $qr['short_code'] ?: (string) $qr['id']);
$bd = function (array $rows, string $title) use ($stats) {
    $total = max(1, (int) $stats['total']);
    $html = '<div class="breakdown"><h3 class="card-title">' . e($title) . '</h3>';
    if (!$rows) {
        return $html . '<p class="muted small">Sem dados ainda.</p></div>';
    }
    $max = max(array_column($rows, 'n'));
    foreach ($rows as $r) {
        $pct = round(((int) $r['n'] / $total) * 100);
        $w = round(((int) $r['n'] / max(1, $max)) * 100);
        $html .= '<div class="bd-row"><span class="bd-label">' . e($r['label']) . '</span><span class="bd-bar"><span style="width:' . $w . '%"></span></span><span class="bd-val">' . fmt_int((int) $r['n']) . ' <small>(' . $pct . '%)</small></span></div>';
    }
    return $html . '</div>';
};
?>
<nav class="breadcrumb" aria-label="Você está em"><a href="<?= e(url('/painel')) ?>">Meu painel</a> <span aria-hidden="true">/</span> <span><?= e($qr['title']) ?></span></nav>

<div class="page-head">
  <div>
    <h1 class="page-title"><?= e($qr['title']) ?></h1>
    <p class="qr-item-meta">
      <span class="badge"><?= e(qr_type_label($qr['type'])) ?></span>
      <?php if ($qr['short_code']): ?>
        <span class="badge badge-accent">Rastreado</span>
        <?= $qr['is_active'] ? '<span class="badge badge-ok">Ativo</span>' : '<span class="badge badge-warn">Pausado</span>' ?>
        <?php if ($qr['blocked']): ?><span class="badge badge-error">Bloqueado pela administração</span><?php endif ?>
      <?php else: ?>
        <span class="badge badge-muted">Estático (sem rastreio)</span>
      <?php endif ?>
      <span class="muted small">criado em <?= e(fmt_date((int) $qr['created_at'])) ?></span>
    </p>
  </div>
</div>

<div class="detail-grid">
  <section class="panel qr-detail-card" aria-label="QR Code">
    <div class="qr-big" data-qr="<?= e($qrJson) ?>" data-qr-size="260" id="qr-big"></div>
    <div class="download-row">
      <button type="button" class="btn btn-outline" data-download="png" data-target="qr-big" data-name="<?= e($fname) ?>">Baixar PNG</button>
      <button type="button" class="btn btn-outline" data-download="svg" data-target="qr-big" data-name="<?= e($fname) ?>">Baixar SVG</button>
    </div>
    <?php if ($qr['short_code']): ?>
      <div class="field">
        <label for="short">Link curto (é o que está no QR Code)</label>
        <div class="copy-field">
          <input type="text" id="short" value="<?= e($data) ?>" readonly>
          <button type="button" class="btn btn-ghost btn-sm" data-copy="short">Copiar</button>
        </div>
      </div>
    <?php endif ?>
  </section>

  <section class="panel" aria-labelledby="t-edit">
    <h2 id="t-edit" class="card-title">Editar</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <div class="field">
        <label for="title">Nome</label>
        <input type="text" id="title" name="title" value="<?= e($qr['title']) ?>" maxlength="120" required>
      </div>
      <?php if ($qr['short_code']): ?>
        <div class="field">
          <label for="content">Link de destino</label>
          <input type="url" id="content" name="content" value="<?= e($qr['content']) ?>" maxlength="2048" required>
          <small class="hint">Mude o destino a qualquer momento: o QR Code impresso continua o mesmo.</small>
        </div>
      <?php else: ?>
        <div class="field">
          <span class="label">Conteúdo</span>
          <pre class="payload-view"><?= e($qr['content']) ?></pre>
          <small class="hint">QR Codes estáticos guardam o conteúdo dentro da imagem e não podem ser editados nem rastreados.</small>
        </div>
      <?php endif ?>
      <button type="submit" class="btn btn-primary">Salvar alterações</button>
    </form>

    <div class="action-row">
      <?php if ($qr['short_code']): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle">
          <button type="submit" class="btn btn-ghost"><?= $qr['is_active'] ? 'Pausar link' : 'Reativar link' ?></button>
        </form>
        <form method="post" data-confirm="Zerar todas as estatísticas deste QR Code? Isso não pode ser desfeito.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reset">
          <button type="submit" class="btn btn-ghost">Zerar estatísticas</button>
        </form>
      <?php endif ?>
      <form method="post" data-confirm="Excluir este QR Code? <?= $qr['short_code'] ? 'Quem escanear o QR Code impresso verá uma página de link não encontrado.' : '' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-danger">Excluir</button>
      </form>
    </div>
  </section>
</div>

<?php if ($stats): ?>
  <div class="stat-grid">
    <div class="stat"><span class="stat-label">Aberturas (total)</span><span class="stat-value accent"><?= fmt_int($stats['total']) ?></span></div>
    <div class="stat"><span class="stat-label">Visitantes únicos*</span><span class="stat-value"><?= fmt_int($stats['unique']) ?></span></div>
    <div class="stat"><span class="stat-label">Hoje</span><span class="stat-value"><?= fmt_int($stats['today']) ?></span></div>
    <div class="stat"><span class="stat-label">Últimos 7 dias</span><span class="stat-value"><?= fmt_int($stats['week']) ?></span></div>
  </div>

  <section class="panel card-section">
    <h2 class="card-title">Aberturas por dia (30 dias)</h2>
    <?= svg_bars($stats['series']) ?>
  </section>

  <div class="bd-grid">
    <section class="panel"><?= $bd($stats['device'], 'Dispositivo') ?></section>
    <section class="panel"><?= $bd($stats['os'], 'Sistema') ?></section>
    <section class="panel"><?= $bd($stats['browser'], 'Navegador / app') ?></section>
    <section class="panel"><?= $bd($stats['lang'], 'Idioma') ?></section>
  </div>

  <section class="panel card-section">
    <h2 class="card-title">Horário das aberturas</h2>
    <?= svg_bars($stats['hours'], 'aberturas', false) ?>
  </section>

  <section class="card-section" aria-labelledby="t-recent">
    <div class="list-head">
      <h2 id="t-recent" class="section-title">Últimas aberturas</h2>
      <?php if ($stats['total']): ?><a class="btn btn-outline btn-sm" href="<?= e(url('/painel/qr/' . $qr['id'] . '/cliques.csv')) ?>">Exportar CSV</a><?php endif ?>
    </div>
    <?php if (!$stats['recent']): ?>
      <div class="panel empty-state">
        <p class="muted">Ninguém abriu este QR Code ainda. Assim que alguém escanear, a abertura aparece aqui.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Data e hora</th><th>Visitante*</th><th>Dispositivo</th><th>Sistema</th><th>Navegador</th><th>Idioma</th><th>Rede (anonimizada)</th></tr></thead>
          <tbody>
            <?php foreach ($stats['recent'] as $c): ?>
              <tr>
                <td><?= e(fmt_date((int) $c['created_at'])) ?></td>
                <td><code><?= e(substr($c['visitor'], 0, 6)) ?></code></td>
                <td><?= e($c['device']) ?></td>
                <td><?= e($c['os']) ?></td>
                <td><?= e($c['browser']) ?></td>
                <td><?= e($c['lang'] ?: '—') ?></td>
                <td><code><?= e($c['ip_prefix'] ?: '—') ?></code></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
    <p class="muted small">* Para respeitar a LGPD, não guardamos o IP completo nem dados pessoais de quem escaneia. O "visitante" é um código anônimo que muda a cada dia, então "únicos" conta pessoas diferentes por dia. Robôs e pré-visualizações de apps (WhatsApp, Facebook etc.) não são contados.</p>
  </section>
<?php endif ?>
