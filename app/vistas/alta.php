<?php declare(strict_types=1); /** @var string $error */ ?>
<div class="centrado">
  <form method="post" action="<?= h(url('alta')) ?>" class="tarjeta angosta">
    <h1>Primer acceso</h1>
    <p class="ayuda">No hay usuarios todavía. Crea el tuyo para empezar.</p>
    <?php if ($error !== ''): ?><div class="aviso error"><?= h($error) ?></div><?php endif; ?>
    <label>Usuario <input name="usuario" required autofocus></label>
    <label>Nombre <input name="nombre"></label>
    <label>Contraseña <input type="password" name="clave" required minlength="8">
      <span class="ayuda">Mínimo 8 caracteres.</span></label>
    <button class="btn primario">Crear y entrar</button>
  </form>
</div>
