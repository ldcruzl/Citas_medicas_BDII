<?php
// =====================================================
// mis_citas.php - Citas del paciente y de sus menores.
// Separa: citas que necesitan respuesta (ausencia del
// médico), citas activas e historial.
// =====================================================
require 'config.php';
requerir_rol('PACIENTE', 'APODERADO');

$pacientes = pacientes_gestionables($pdo);
$filtro = (int) ($_GET['paciente'] ?? 0);

$ids = $filtro && isset($pacientes[$filtro]) ? [$filtro] : array_keys($pacientes);

$citas = [];
if ($ids) {
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(sql_citas() . " WHERE ci.id_paciente IN ($marcas) ORDER BY cu.fecha, cu.hora_inicio");
    $st->execute($ids);
    $citas = $st->fetchAll();
}

$pendientes = array_filter($citas, fn($c) => in_array($c['estado'], ['PENDIENTE_SUSTITUCION', 'PENDIENTE_REPROGRAMACION']));
$activas    = array_filter($citas, fn($c) => in_array($c['estado'], ['CONFIRMADA', 'EN_ESPERA', 'EN_ATENCION']));
$historial  = array_reverse(array_filter($citas, fn($c) => in_array($c['estado'], ['ATENDIDA', 'NO_ATENDIDA', 'CANCELADA'])));

$titulo = 'Mis citas';
$activo = 'mis_citas';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">folder_shared</span> Carpeta ciudadana de salud</p>
        <h1>Mis citas médicas</h1>
        <p class="sub">Seguimiento de sus citas y de las de los menores a su cargo.</p>
    </div>
    <a class="boton" href="reservar.php"><span class="icono">add_circle</span>Solicitar nueva cita</a>
</div>

<div class="aviso aviso-info">
    <strong>Recuerde:</strong> tolerancia de ingreso de 1 minuto después de la hora.
    Las cancelaciones se permiten hasta 24 horas antes de la cita.
</div>

<?php if (count($pacientes) > 1): ?>
    <div class="acciones" style="margin-bottom:18px">
        <span style="font-weight:600">Filtrar por paciente:</span>
        <a class="boton boton-chico <?= $filtro ? 'boton-secundario' : '' ?>" href="mis_citas.php">Todos</a>
        <?php foreach ($pacientes as $p): ?>
            <a class="boton boton-chico <?= $filtro == $p['id_paciente'] ? '' : 'boton-secundario' ?>"
               href="mis_citas.php?paciente=<?= $p['id_paciente'] ?>">
                <?= h($p['nombres']) ?> (<?= $p['tipo'] === 'TITULAR' ? 'titular' : 'menor' ?>)</a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Citas que esperan una respuesta por ausencia del médico -->
<?php if ($pendientes): ?>
    <h2 class="tarjeta-titulo"><span class="icono">warning</span>Citas que requieren su respuesta</h2>
    <div class="grid-2">
        <?php foreach ($pendientes as $c): ?>
            <div class="tarjeta" style="border-color:#fde68a">
                <div class="medico-cabecera">
                    <div>
                        <span class="esp" style="color:var(--primario);font-weight:600"><?= h(mb_strtoupper($c['especialidad'])) ?></span>
                        <h3><?= h($c['nombres'] . ' ' . $c['apellidos']) ?></h3>
                    </div>
                    <?= etiqueta_estado($c['estado']) ?>
                </div>
                <p style="margin:0 0 10px;color:var(--texto-2)">
                    El médico asignado no podrá atender
                    (<?= h(fecha_corta($c['fecha'])) ?>, <?= hora($c['hora_inicio']) ?>).
                    <?= $c['estado'] === 'PENDIENTE_SUSTITUCION'
                        ? 'Le proponemos un médico sustituto en la misma fecha y hora.'
                        : 'Elija un nuevo horario entre las alternativas ofrecidas.' ?>
                </p>
                <?php if ($c['estado'] === 'PENDIENTE_SUSTITUCION'): ?>
                    <a class="boton" href="sustituto.php?cita=<?= $c['id_cita'] ?>"><span class="icono">swap_horiz</span>Ver propuesta</a>
                <?php else: ?>
                    <a class="boton" href="alternativas.php?cita=<?= $c['id_cita'] ?>"><span class="icono">event_repeat</span>Elegir nuevo horario</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Citas activas -->
<h2 class="tarjeta-titulo" style="margin-top:6px"><span class="icono">event_available</span>Citas activas (<?= count($activas) ?>)</h2>
<?php if (!$activas): ?>
    <div class="tarjeta vacio"><span class="icono">event</span>No tiene citas activas.
        <a href="reservar.php">Reservar una cita</a></div>
<?php endif; ?>
<div class="grid-3">
    <?php foreach ($activas as $c):
        $faltan_horas = (strtotime($c['fecha'] . ' ' . $c['hora_inicio']) - time()) / 3600; ?>
        <div class="tarjeta">
            <div class="medico-cabecera">
                <span style="color:var(--primario);font-weight:600;font-size:12px"><?= h(mb_strtoupper($c['especialidad'])) ?></span>
                <?= etiqueta_estado($c['estado']) ?>
            </div>
            <h3 style="font-size:17px;margin-bottom:10px"><?= h($c['medico']) ?></h3>
            <div class="dato">
                <span>Fecha y hora</span>
                <strong><?= h(ucfirst(fecha_larga($c['fecha']))) ?></strong>
                <small><?= hora($c['hora_inicio']) ?> hrs · llegar hasta las <?= date('H:i', strtotime($c['hora_inicio'] . ' +1 minute')) ?></small>
            </div>
            <div class="dato"><span>Consultorio</span><strong><?= h($c['consultorio']) ?></strong>
                <small><?= h($c['ubicacion']) ?></small></div>
            <p style="margin:0 0 12px;font-size:13px">Paciente: <strong><?= h($c['nombres'] . ' ' . $c['apellidos']) ?></strong></p>
            <?php if ($c['estado'] === 'CONFIRMADA'): ?>
                <?php if ($faltan_horas >= 24): ?>
                    <a class="boton boton-peligro boton-chico" href="cancelar.php?cita=<?= $c['id_cita'] ?>"><span class="icono">event_busy</span>Cancelar</a>
                <?php else: ?>
                    <small style="color:var(--texto-2)">Faltan menos de 24 horas: ya no se puede cancelar en línea.</small>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Historial -->
<section class="tarjeta">
    <h2 class="tarjeta-titulo"><span class="icono">history</span>Historial de atenciones</h2>
    <?php if (!$historial): ?>
        <p class="vacio">Todavía no hay citas cerradas.</p>
    <?php else: ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <tr><th>Paciente</th><th>Especialidad</th><th>Médico</th><th>Fecha</th><th>Estado</th><th>Motivo</th></tr>
                <?php foreach ($historial as $c): ?>
                    <tr>
                        <td><?= h($c['nombres'] . ' ' . $c['apellidos']) ?></td>
                        <td><?= h($c['especialidad']) ?></td>
                        <td><?= h($c['medico'] ?? '—') ?></td>
                        <td><?= h(fecha_corta($c['fecha'])) ?><span class="secundario"><?= hora($c['hora_inicio']) ?></span></td>
                        <td><?= etiqueta_estado($c['estado']) ?></td>
                        <td><?= h($c['motivo_cierre'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require 'includes/footer.php'; ?>
