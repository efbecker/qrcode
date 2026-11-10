<?php $msg = [
  'missing' => ['Link não encontrado', 'Este QR Code não aponta para nenhum link ativo. Confira se ele foi digitado corretamente.'],
  'paused' => ['Link pausado', 'O dono deste QR Code pausou o link temporariamente. Tente novamente mais tarde.'],
  'blocked' => ['Link indisponível', 'Este link foi desativado pela administração do serviço.'],
][$kind]; ?>
<section class="empty-state">
  <h1 class="page-title"><?= e($msg[0]) ?></h1>
  <p class="muted"><?= e($msg[1]) ?></p>
  <a class="btn btn-accent" href="<?= e(url('/')) ?>">Crie seu próprio QR Code grátis</a>
</section>
