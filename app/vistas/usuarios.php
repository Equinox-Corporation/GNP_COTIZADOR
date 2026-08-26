<?php declare(strict_types=1); /** @var array $filas @var string $error @var string $ok */ ?>

<h1>Usuarios</h1>

<?php if ($error !== ''): ?>
  <div class="aviso error"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($ok !== ''): ?>
  <div class="aviso ok"><?= h($ok) ?></div>
<?php endif; ?>

<section class="tarjeta">
  <h2>Nuevo usuario</h2>
  <form method="post" action="<?= h(url('usuarios/crear')) ?>" autocomplete="off">
    <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
    <div class="rejilla">
      <label>Usuario
        <input name="usuario" required maxlength="40">
      </label>
      <label>Nombre para mostrar
        <input name="nombre" maxlength="60">
      </label>
      <label>Contraseña
        <input type="password" name="clave" required minlength="8">
        <span class="ayuda">Mínimo 8 caracteres.</span>
      </label>
      <label class="linea admin-check">
        <input type="checkbox" name="es_admin" value="1"> Administrador
        <span class="ayuda">Puede entrar a este panel y dar de alta a otros.</span>
      </label>
    </div>
    <div class="acciones">
      <button class="btn primario">Crear usuario</button>
    </div>
  </form>
</section>

<h2>Cuentas existentes</h2>
<div class="tabla-envoltura">
<table class="listado">
  <thead>
    <tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Estado</th><th>Alta</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($filas as $f): ?>
    <tr<?= (int) $f['activo'] === 0 ? ' class="vencida"' : '' ?>>
      <td>
        <strong><?= h($f['usuario']) ?></strong>
        <?php if ((int) $f['id'] === Auth::id()): ?><small>eres tú</small><?php endif; ?>
      </td>
      <td><?= h($f['nombre'] ?: '—') ?></td>
      <td>
        <?php if ((int) $f['es_admin'] === 1): ?>
          <span class="marca-estado ok">Administrador</span>
        <?php else: ?>
          <span class="marca-estado">Vendedor</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ((int) $f['activo'] === 1): ?>
          <span class="marca-estado ok">activo</span>
        <?php else: ?>
          <span class="marca-estado err">apagado</span>
        <?php endif; ?>
      </td>
      <td><small><?= h($f['creado_en']) ?></small></td>
      <td>
        <div class="acciones-fila">
          <form method="post" action="<?= h(url('usuarios/estado')) ?>">
            <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button class="btn plano"><?= (int) $f['activo'] === 1 ? 'Apagar' : 'Prender' ?></button>
          </form>
          <form method="post" action="<?= h(url('usuarios/admin')) ?>">
            <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button class="btn plano"><?= (int) $f['es_admin'] === 1 ? 'Quitar admin' : 'Hacer admin' ?></button>
          </form>
          <details>
            <summary>Editar</summary>
            <form method="post" action="<?= h(url('usuarios/editar')) ?>" class="edicion" autocomplete="off">
              <input type="hidden" name="_t" value="<?= h(Auth::token()) ?>">
              <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <label>Nombre
                <input name="nombre" value="<?= h($f['nombre']) ?>" maxlength="60">
              </label>
              <label>Nueva contraseña
                <input type="password" name="clave" minlength="8" placeholder="dejar en blanco para no cambiarla">
              </label>
              <button class="btn">Guardar</button>
            </form>
          </details>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
