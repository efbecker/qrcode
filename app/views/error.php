<section class="empty-state">
  <p class="big-code"><?= (int) $status ?></p>
  <h1 class="page-title"><?= e($status === 404 ? 'Página não encontrada' : ($status === 403 ? 'Acesso negado' : 'Algo deu errado')) ?></h1>
  <p class="muted"><?= e($message ?: 'Tente novamente.') ?></p>
  <a class="btn btn-primary" href="<?= e(url('/')) ?>">Ir para o gerador</a>
</section>
