<?php
// =====================================================
// sustituto.php - Propuesta de médico sustituto.
// El paciente (o su apoderado, o recepción) acepta o
// rechaza con sp_responder_sustitucion.
// =====================================================
require 'config.php';
requerir_rol('PACIENTE', 'APODERADO', 'RECEPCIONISTA');

$u = usuario();
$id_cita = (int) ($_GET['cita'] ?? $_POST['cita'] ?? 0);

// ---------- Responder ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acepta = (int) ($_POST['acepta'] ?? 0);
    try {
        llamar($pdo, 'CALL sp_responder_sustitucion(?, ?, ?)',
               [$u['id_usuario'], (int) $_POST['propuesta'], $acepta]);
        if ($acepta) {
            mensaje('exito', 'Aceptó al médico sustituto. Su cita sigue confirmada en la misma fecha y hora.');
            ir_a(tiene_rol('RECEPCIONISTA') ? 'recepcion_agenda.php' : 'mis_citas.php');
        }
        mensaje('info', 'Rechazó al médico sustituto. Elija un nuevo horario.');
        ir_a('alternativas.php?cita=' . $id_cita);
    } catch (PDOException $e) {
        mensaje('error', mensaje_error($e));
        ir_a('sustituto.php?cita=' . $id_cita);
    }
}

$c = obtener_cita($pdo, $id_cita);
if (!$c || !puede_gestionar($pdo, $id_cita)) {
    mensaje('error', 'No se encontró la cita.');
    ir_a('index.php');
}

// Propuesta pendiente con los datos del sustituto y de la ausencia.
$st = $pdo->prepare(
    "SELECT ps.id_propuesta, ps.fecha_emision, ps.fecha_limite,
            c.fecha, c.hora_inicio, c.hora_fin,
            CONCAT(pm.nombres, ' ', pm.apellidos) AS medico, e.nombre AS especialidad,
            co.codigo AS consultorio, co.ubicacion,
            am.motivo AS motivo_ausencia
     FROM propuesta_sustitucion ps
     JOIN cupo c         ON c.id_cupo = ps.id_cupo_sustituto
     JOIN medico m       ON m.id_medico = c.id_medico
     JOIN persona pm     ON pm.id_persona = m.id_persona
     JOIN especialidad e ON e.id_especialidad = m.id_especialidad
     JOIN consultorio co ON co.id_consultorio = c.id_consultorio
     JOIN ausencia_medico am ON am.id_ausencia = ps.id_ausencia
     WHERE ps.id_cita = ? AND ps.estado = 'PENDIENTE'");
$st->execute([$id_cita]);
$p = $st->fetch();

$titulo = 'Médico sustituto';
$activo = tiene_rol('RECEPCIONISTA') ? 'agenda' : 'mis_citas';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><?= etiqueta_estado($c['estado']) ?> &nbsp; Código #CIT-<?= str_pad($c['id_cita'], 5, '0', STR_PAD_LEFT) ?></p>
        <h1>Propuesta de médico sustituto</h1>
        <p class="sub">El médico asignado no podrá atender. Le proponemos un especialista de la misma
            especialidad para mantener su atención en la misma fecha y hora.</p>
    </div>
    <?php if ($p): ?>
        <div class="aviso aviso-error" style="margin:0"><span class="icono">hourglass_top</span>
            Responda hasta: <strong><?= date('d/m/Y H:i', strtotime($p['fecha_limite'])) ?></strong></div>
    <?php endif; ?>
</div>

<?php if (!$p): ?>
    <div class="tarjeta vacio"><span class="icono">info</span>Esta cita no tiene una propuesta pendiente.
        <a href="mis_citas.php">Volver a mis citas</a></div>
<?php else: ?>

    <div class="tarjeta tarjeta-azul">
        <h2 class="tarjeta-titulo"><span class="icono">info</span>Motivo</h2>
        <p style="margin:0">Ausencia del médico titular: <?= h($p['motivo_ausencia']) ?>.</p>
    </div>

    <div class="comparacion" style="margin-bottom:20px">
        <div class="tarjeta tachado" style="margin:0">
            <p style="margin:0 0 8px"><span class="etiqueta etiqueta-rojo">No disponible</span></p>
            <h3 style="margin-bottom:12px"><?= h($c['medico']) ?></h3>
            <div class="dato"><span>Fecha</span><strong><?= h(fecha_corta($c['fecha'])) ?></strong></div>
            <div class="dato"><span>Hora</span><strong><?= hora($c['hora_inicio']) ?></strong></div>
            <div class="dato"><span>Consultorio</span><strong><?= h($c['consultorio']) ?></strong></div>
        </div>
        <div class="flecha"><span class="icono" style="font-size:32px">arrow_forward</span></div>
        <div class="tarjeta tarjeta-sustituto" style="margin:0">
            <p style="margin:0 0 8px"><span class="etiqueta etiqueta-verde">Sustituto propuesto</span></p>
            <h3><?= h($p['medico']) ?></h3>
            <p style="margin:2px 0 12px;color:var(--primario);font-weight:600"><?= h($p['especialidad']) ?> (misma especialidad)</p>
            <div class="dato"><span>Fecha</span><strong><?= h(fecha_corta($p['fecha'])) ?></strong> <small>Misma fecha</small></div>
            <div class="dato"><span>Hora</span><strong><?= hora($p['hora_inicio']) ?></strong> <small>Misma hora</small></div>
            <div class="dato"><span>Nuevo consultorio</span><strong><?= h($p['consultorio']) ?></strong>
                <small><?= h($p['ubicacion']) ?></small></div>
        </div>
    </div>

    <div class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">alarm</span>Plazo de respuesta</h2>
        <div class="plazo">
            <div><span>Límite exacto</span><strong><?= date('d/m/Y H:i', strtotime($p['fecha_limite'])) ?></strong></div>
            <div><span>Regla</span><strong>24 horas</strong> o hasta el inicio de la cita, lo que ocurra primero</div>
            <div class="rojo"><span>Si no responde</span><strong>La cita se cancela</strong> con motivo «sin respuesta»</div>
        </div>
        <p style="margin:14px 0 0;color:var(--texto-2)">Aceptar o rechazar al sustituto <strong>no</strong> consume su
            reprogramación voluntaria.</p>
    </div>

    <div class="tarjeta">
        <form method="post" class="acciones">
            <input type="hidden" name="cita" value="<?= $id_cita ?>">
            <input type="hidden" name="propuesta" value="<?= $p['id_propuesta'] ?>">
            <button class="boton" type="submit" name="acepta" value="1"><span class="icono">check_circle</span>Aceptar sustituto y mantener turno</button>
            <button class="boton boton-secundario" type="submit" name="acepta" value="0"><span class="icono">event_repeat</span>Rechazar y buscar otro horario</button>
        </form>
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
