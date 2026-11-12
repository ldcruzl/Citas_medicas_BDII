<?php
// =====================================================
// header.php
// Parte superior de las páginas internas: barra lateral
// con el menú según los roles del usuario y barra superior.
// Antes de incluirlo, cada página define:
//   $titulo  título de la pestaña
//   $activo  opción del menú que se marca
// =====================================================

$u = usuario();
$activo = $activo ?? '';

// Opciones del menú: [archivo, texto, icono, clave]
$menu = [];

if (tiene_rol('PACIENTE') || tiene_rol('APODERADO')) {
    $menu['Mis trámites'] = [
        ['reservar.php',  'Reservar cita',      'calendar_add_on', 'reservar'],
        ['mis_citas.php', 'Mis citas',          'event_note',      'mis_citas'],
        ['menores.php',   'Menores a mi cargo', 'family_restroom', 'menores'],
    ];
}
if (tiene_rol('RECEPCIONISTA')) {
    $menu['Recepción'] = [
        ['recepcion_agenda.php',   'Agenda del día',     'view_agenda',    'agenda'],
        ['reservar.php',           'Registrar cita',     'edit_calendar',  'reservar'],
        ['validar_apoderados.php', 'Validar apoderados', 'verified_user',  'apoderados'],
    ];
}
if (tiene_rol('MEDICO')) {
    $menu['Consultorio'] = [
        ['medico_horario.php', 'Mi horario', 'stethoscope', 'horario'],
    ];
}
if (tiene_rol('ADMINISTRADOR')) {
    $menu['Administración'] = [
        ['admin_ausencias.php', 'Ausencias y sustitución', 'event_busy',    'ausencias'],
        ['admin_horarios.php',  'Horarios y cupos',        'calendar_month', 'horarios'],
        ['admin_medicos.php',   'Médicos',                 'medical_services', 'medicos'],
        ['admin_reportes.php',  'Reportes',                'bar_chart',     'reportes'],
    ];
}
?>
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
<div class="app">

    <!-- Barra lateral -->
    <aside class="lateral">
        <a href="index.php" class="marca">
            <span class="marca-icono"><span class="icono">local_hospital</span></span>
            <span>
                <strong><?= h(SISTEMA_NOMBRE) ?></strong>
                <small>SALUD PÚBLICA · TACNA</small>
            </span>
        </a>

        <nav class="menu">
            <?php foreach ($menu as $grupo => $opciones): ?>
                <p class="menu-grupo"><?= h($grupo) ?></p>
                <?php foreach ($opciones as $op): ?>
                    <a href="<?= $op[0] ?>" class="<?= $activo === $op[3] ? 'activo' : '' ?>">
                        <span class="icono"><?= $op[2] ?></span><?= h($op[1]) ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="usuario-caja">
            <span class="avatar"><span class="icono">person</span></span>
            <div>
                <strong><?= h($u['nombre']) ?></strong>
                <small><?= h(implode(' · ', array_map('ucfirst', array_map('strtolower', $u['roles'])))) ?></small>
            </div>
            <a href="logout.php" class="salir"><span class="icono">logout</span>Cerrar sesión</a>
        </div>
    </aside>

    <!-- Contenido -->
    <div class="contenido">
        <header class="superior">
            <span><span class="icono">verified</span>Servicio público gratuito</span>
            <span><span class="icono">call</span>Línea Salud: <?= h(LINEA_SALUD) ?></span>
            <span><span class="icono">location_on</span><?= h(hoy_en_tacna()) ?></span>
        </header>

        <main class="principal">
            <?php mostrar_mensaje(); ?>
