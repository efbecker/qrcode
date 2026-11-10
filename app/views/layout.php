<?php
/** @var string $title @var string $content @var string $__view */
$me = current_user();
$section = explode('/', $__view)[0];
$isAuth = $section === 'auth';
$needsQr = in_array($__view, ['panel/home', 'panel/qr'], true);
$flashes = session_status() === PHP_SESSION_ACTIVE ? take_flashes() : [];
$v = APP_VERSION;
?><!doctype html>
<html lang="pt-BR" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · QR Code Fácil</title>
  <meta name="robots" content="noindex">
  <meta name="theme-color" content="#0f1629">
  <link rel="icon" type="image/svg+xml" href="<?= e(url('/assets/img/favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/style.css?v=' . $v)) ?>">
  <link rel="stylesheet" href="<?= e(url('/assets/css/app.css?v=' . $v)) ?>">
</head>
<body class="page-<?= e($section) ?>">
  <a class="skip-link" href="#conteudo-principal">Pular para o conteúdo</a>
  <header class="site-header">
    <div class="container header-inner">
      <?= view_fragment('partials/brand') ?>
      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="menu-principal">
        <span class="sr-only">Abrir menu</span>
        <span class="nav-toggle-bar"></span>
      </button>
      <nav id="menu-principal" class="main-nav" aria-label="Menu principal">
        <ul>
          <li><a href="<?= e(url('/')) ?>">Gerador</a></li>
          <?php if ($me): ?>
            <li><a href="<?= e(url('/painel')) ?>"<?= $section === 'panel' && $__view !== 'panel/account' ? ' aria-current="page"' : '' ?>>Meu painel</a></li>
            <?php if ($me['is_admin']): ?>
              <li><a href="<?= e(url('/admin')) ?>"<?= $section === 'admin' ? ' aria-current="page"' : '' ?>>Administração</a></li>
            <?php endif ?>
          <?php else: ?>
            <li><a href="<?= e(url('/#vantagens')) ?>">Vantagens da conta</a></li>
          <?php endif ?>
        </ul>
        <div class="account-nav">
          <?php if ($me): ?>
            <a class="account-chip" href="<?= e(url('/painel/conta')) ?>" title="Minha conta">
              <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($me['name'], 0, 1))) ?></span>
              <span class="account-name"><?= e($me['name']) ?></span>
            </a>
            <form method="post" action="<?= e(url('/sair')) ?>" class="inline-form">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-ghost">Sair</button>
            </form>
          <?php else: ?>
            <a class="btn btn-ghost" href="<?= e(url('/entrar')) ?>">Entrar</a>
            <a class="btn btn-accent" href="<?= e(url('/cadastrar')) ?>">Criar conta grátis</a>
          <?php endif ?>
        </div>
      </nav>
    </div>
  </header>

  <main id="conteudo-principal" class="container<?= $isAuth ? ' main-narrow' : '' ?>">
    <?php foreach ($flashes as [$kind, $msg]): ?>
      <div class="alert alert-<?= e($kind) ?>" role="<?= $kind === 'error' ? 'alert' : 'status' ?>"><?= e($msg) ?></div>
    <?php endforeach ?>
    <?= $content ?>
  </main>

  <footer class="site-footer">
    <div class="container footer-inner">
      <p>QR Code Fácil · Licença MIT · <a href="https://github.com/efbecker/qrcode" target="_blank" rel="noopener">Código no GitHub</a></p>
      <p class="muted">Seus dados de acesso são protegidos e as estatísticas são anonimizadas (LGPD).</p>
    </div>
  </footer>

  <?php if ($needsQr): ?>
    <script src="<?= e(url('/assets/vendor/qr-code-styling/qr-code-styling.js?v=1.9.2')) ?>"></script>
  <?php endif ?>
  <script type="module" src="<?= e(url('/assets/js/site.js?v=' . $v)) ?>"></script>
</body>
</html>
