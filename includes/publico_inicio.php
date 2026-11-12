<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($titulo ?? SISTEMA_NOMBRE) ?> – <?= h(SISTEMA_NOMBRE) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0&display=block" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<div class="publico">
    <div class="publico-franja">
        <span><span class="gratis">Servicio Público Gratuito</span> &nbsp;Portal de citas médicas · <?= h(SEDE_CIUDAD) ?></span>
        <span><span class="icono" style="font-size:16px">call</span> Línea Salud: <?= h(LINEA_SALUD) ?></span>
    </div>
    <header class="publico-cabecera">
        <a href="login.php" class="marca" style="border:0;padding:0">
            <span class="marca-icono"><span class="icono">local_hospital</span></span>
            <span>
                <strong><?= h(SISTEMA_NOMBRE) ?></strong>
                <small>SISTEMA PÚBLICO DE CITAS · TACNA</small>
            </span>
        </a>
        <span style="color:var(--texto-2);font-size:13px"><?= h(hoy_en_tacna()) ?></span>
    </header>
    <div class="publico-cuerpo">
