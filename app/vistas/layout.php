<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cotizador GNP · Equinox</title>
<link rel="stylesheet" href="<?= h(BASE_URL) ?>/assets/estilo.css">
</head>
<body>

<header class="barra">
  <a class="marca" href="<?= h(url('cotizar')) ?>">
    <span class="logo">GNP Cotizador</span>
  </a>
  <?php if (Auth::dentro()): ?>
    <nav>
      <a href="<?= h(url('historial')) ?>"<?= ($ruta ?? '') === 'historial' ? ' class="activo"' : '' ?>>Historial</a>
      <?php if (Auth::esAdmin()): ?>
        <a href="<?= h(url('usuarios')) ?>"<?= ($ruta ?? '') === 'usuarios' ? ' class="activo"' : '' ?>>Usuarios</a>
      <?php endif; ?>
    </nav>
    <div class="sesion">
      <span><?= h(Auth::nombre()) ?></span>
      <a href="<?= h(url('salir')) ?>">Salir</a>
    </div>
  <?php endif; ?>
</header>

<?php if (Auth::dentro() && !Env::esProduccion()): ?>
  <div class="cinta">
    Ambiente de trabajo · las llamadas van a <strong>producción</strong> de GNP. Cotizar e imprimir no generan póliza.
  </div>
<?php endif; ?>

<main class="contenido">
  <?php require $contenido; ?>
</main>

<footer class="pie">
  Cotizador GNP · Equinox Agente de Seguros y de Fianzas ·
  una cotización de GNP vale <strong>15 días naturales</strong>
</footer>

</body>
</html>
