<?php
// =====================================================
// cancelar.php - Cancelar una cita (sp_cancelar_cita).
// Confirmada: solo con 24 horas o más de anticipación.
// Pendiente por ausencia del médico: sin esa regla.
// =====================================================
require 'config.php';
requerir_rol('PACIENTE', 'APODERADO', 'RECEPCIONISTA');

$u = usuario();
$id_cita = (int) ($_GET['cita'] ?? $_POST['cita'] ?? 0);
$volver = tiene_rol('RECEPCIONISTA') && !tiene_rol('PACIENTE') ? 'recepcion_agenda.php' : 'mis_citas.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        llamar($pdo, 'CALL sp_cancelar_cita(?, ?, ?)', [$u['id_usuario'], $id_cita, trim($_POST['motivo'] ?? '')]);
        mensaje('exito', 'La cita fue cancelada y el horario quedó libre para otro paciente.');
        ir_a($volver);
    } catch (PDOException $e) {
        mensaje('error', mensaje_error($e));
        ir_a('cancelar.php?cita=' . $id_cita);
    }
}

$c = obtener_cita($pdo, $id_cita);
if (!$c || !puede_gestionar($pdo, $id_cita)) {
    mensaje('error', 'No se encontró la cita.');
    ir_a($volver);
}

$es_pendiente = in_array($c['estado'], ['PENDIENTE_SUSTITUCION', 'PENDIENTE_REPROGRAMACION']);
$faltan_horas = $c['fecha'] ? (strtotime($c['fecha'] . ' ' . $c['hora_inicio']) - time()) / 3600 : 0;

$titulo = 'Cancelar cita';
$activo = 'mis_citas';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">event_busy</span> Cancelación de cita</p>
        <h1>Confirmar cancelación de cita médica</h1>
    </div>
</div>

<div class="aviso aviso-error">
    <strong>Atención:</strong> la cancelación es definitiva. La cita no se podrá reactivar y el horario
    quedará libre de inmediato. Para otra atención deberá hacer una nueva reserva.
</div>

<div class="dos-columnas">
    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">event</span>Cita a cancelar</h2>
        <div class="grid-2">
            <div class="dato"><span>Paciente</span><strong><?= h($c['nombres'] . ' ' . $c['apellidos']) ?></strong></div>
            <div class="dato"><span>Estado</span><?= etiqueta_estado($c['estado']) ?></div>
            <div class="dato"><span>Especialidad</span><strong><?= h($c['especialidad']) ?></strong></div>
            <div class="dato"><span>Médico</span><strong><?= h($c['medico'] ?? '—') ?></strong></div>
            <div class="dato"><span>Fecha</span><strong><?= h(ucfirst(fecha_larga($c['fecha']))) ?></strong></div>
            <div class="dato"><span>Hora</span><strong><?= hora($c['hora_inicio']) ?></strong></div>
        </div>
    </section>

    <aside class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">gavel</span>Regla de cancelación</h2>
        <?php if ($es_pendiente): ?>
            <p>Su cita está afectada por la ausencia del médico. Puede cancelarla en cualquier momento.</p>
        <?php elseif ($faltan_horas >= 24): ?>
            <p>Faltan <strong><?= floor($faltan_horas) ?> horas</strong> para su cita: puede cancelarla.</p>
        <?php else: ?>
            <p class="aviso aviso-alerta">Faltan menos de 24 horas para la cita. El sistema no permitirá cancelarla.</p>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="cita" value="<?= $id_cita ?>">
            <?php if (!$es_pendiente): ?>
                <div class="campo">
                    <label for="motivo">Motivo (opcional)</label>
                    <textarea id="motivo" name="motivo" rows="3" maxlength="200" placeholder="Ej.: viaje, mejoré de salud…"></textarea>
                </div>
            <?php endif; ?>
            <button class="boton boton-peligro-lleno boton-bloque" type="submit">
                <span class="icono">delete</span>Sí, cancelar definitivamente</button>
        </form>
        <p style="text-align:center;margin:12px 0 0"><a href="<?= $volver ?>">No, volver</a></p>
    </aside>
</div>

<?php require 'includes/footer.php'; ?>
