<?php declare(strict_types=1); /** @var string $error */ ?>
<div class="centrado">
  <form method="post" action="<?= h(url('login')) ?>" class="tarjeta angosta">
    <h1>Entrar</h1>
    <?php if ($error !== ''): ?><div class="aviso error"><?= h($error) ?></div><?php endif; ?>
    <label>Usuario <input name="usuario" required autofocus></label>
    <label>Contraseña <input type="password" name="clave" required></label>
    <button class="btn primario">Entrar</button>
  </form>
</div>
