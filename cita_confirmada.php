<?php
// =====================================================
// cita_confirmada.php - Comprobante de la cita registrada.
// =====================================================
require 'config.php';
requerir_rol('PACIENTE', 'APODERADO', 'RECEPCIONISTA');

$id_cita = (int) ($_GET['id'] ?? 0);
$c = obtener_cita($pdo, $id_cita);

if (!$c || !puede_gestionar($pdo, $id_cita)) {
    mensaje('error', 'No se encontró la cita.');
    ir_a('index.php');
}

// Avisos preparados para esta cita.
$st = $pdo->prepare('SELECT canal, destinatario, estado FROM notificacion WHERE id_cita = ? ORDER BY id_notificacion');
$st->execute([$id_cita]);
$avisos = $st->fetchAll();

$titulo = 'Cita confirmada';
$activo = 'reservar';
require 'includes/header.php';
?>

<div class="tarjeta" style="text-align:center;padding:32px">
    <span class="icono" style="font-size:56px;color:var(--exito)">check_circle</span>
    <h1 style="font-size:28px;margin:8px 0 4px;color:var(--primario-oscuro)">¡Cita confirmada con éxito!</h1>
    <p style="color:var(--texto-2);margin:0">Código de cita <strong>#CIT-<?= str_pad($c['id_cita'], 5, '0', STR_PAD_LEFT) ?></strong>
        · <?= etiqueta_estado($c['estado']) ?></p>
</div>

<div class="dos-columnas">
    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">event_available</span>Datos de la cita</h2>
        <div class="grid-2">
            <div class="dato"><span>Paciente</span><strong><?= h($c['nombres'] . ' ' . $c['apellidos']) ?></strong>
                <small>Documento: <?= h($c['numero_documento']) ?></small></div>
            <div class="dato"><span>Especialidad</span><strong><?= h($c['especialidad']) ?></strong></div>
            <div class="dato"><span>Fecha</span><strong><?= h(ucfirst(fecha_larga($c['fecha']))) ?></strong></div>
            <div class="dato"><span>Hora</span><strong><?= hora($c['hora_inicio']) ?> – <?= hora($c['hora_fin']) ?></strong>
                <small>Registre su llegada hasta las <?= date('H:i', strtotime($c['hora_inicio'] . ' +1 minute')) ?></small></div>
            <div class="dato"><span>Médico</span><strong><?= h($c['medico']) ?></strong></div>
            <div class="dato"><span>Consultorio</span><strong><?= h($c['consultorio']) ?></strong>
                <small><?= h($c['ubicacion']) ?> · <?= h(SEDE_NOMBRE) ?></small></div>
        </div>
    </section>

    <aside>
        <div class="tarjeta">
            <h2 class="tarjeta-titulo"><span class="icono">mail</span>Avisos enviados</h2>
            <?php foreach ($avisos as $a): ?>
                <p style="margin:0 0 6px"><strong><?= h($a['canal']) ?>:</strong> <?= h($a['destinatario']) ?>
                    <?= etiqueta_estado($a['estado']) ?></p>
            <?php endforeach; ?>
            <?php if (!$avisos): ?><p style="margin:0;color:var(--texto-2)">No hay correo registrado para avisos.</p><?php endif; ?>
        </div>
        <div class="tarjeta tarjeta-azul">
            <p style="margin:0 0 8px"><strong>Recuerde:</strong> traiga su documento de identidad.</p>
            <p style="margin:0">Puede cancelar con 24 horas o más de anticipación desde «Mis citas».</p>
        </div>
        <div class="acciones">
            <a class="boton" href="<?= tiene_rol('RECEPCIONISTA') ? 'recepcion_agenda.php' : 'mis_citas.php' ?>">
                <span class="icono">list</span><?= tiene_rol('RECEPCIONISTA') ? 'Ir a la agenda' : 'Ver mis citas' ?></a>
            <a class="boton boton-secundario" href="reservar.php">Nueva reserva</a>
        </div>
    </aside>
</div>

<?php require 'includes/footer.php'; ?>
