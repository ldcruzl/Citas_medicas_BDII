<?php
// =====================================================
// alternativas.php - Nuevo horario por ausencia del médico.
// Muestra las alternativas ofrecidas (48 horas de plazo)
// y otros horarios libres de la misma especialidad.
// Al elegir uno llama a sp_elegir_alternativa.
// =====================================================
require 'config.php';
requerir_rol('PACIENTE', 'APODERADO', 'RECEPCIONISTA');

$u = usuario();
$id_cita = (int) ($_GET['cita'] ?? $_POST['cita'] ?? 0);

// ---------- Elegir horario ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        llamar($pdo, 'CALL sp_elegir_alternativa(?, ?, ?)', [$u['id_usuario'], $id_cita, (int) $_POST['id_cupo']]);
        mensaje('exito', 'Su cita quedó confirmada con el nuevo horario.');
        ir_a(tiene_rol('RECEPCIONISTA') ? 'recepcion_agenda.php' : 'mis_citas.php');
    } catch (PDOException $e) {
        mensaje('error', mensaje_error($e));
        ir_a('alternativas.php?cita=' . $id_cita);
    }
}

$c = obtener_cita($pdo, $id_cita);
if (!$c || !puede_gestionar($pdo, $id_cita)) {
    mensaje('error', 'No se encontró la cita.');
    ir_a('index.php');
}

// Consulta común para mostrar un cupo.
$campos_cupo = "c.id_cupo, c.fecha, c.hora_inicio, CONCAT(pm.nombres, ' ', pm.apellidos) AS medico,
                co.codigo AS consultorio, fn_cupo_libre(c.id_cupo) AS libre
                FROM cupo c
                JOIN medico m       ON m.id_medico = c.id_medico
                JOIN persona pm     ON pm.id_persona = m.id_persona
                JOIN consultorio co ON co.id_consultorio = c.id_consultorio";

// Oferta pendiente y sus alternativas.
$st = $pdo->prepare("SELECT id_oferta, fecha_limite FROM oferta_reprogramacion WHERE id_cita = ? AND estado = 'PENDIENTE'");
$st->execute([$id_cita]);
$oferta = $st->fetch();

$alternativas = [];
if ($oferta) {
    $st = $pdo->prepare("SELECT ah.orden, $campos_cupo
                         JOIN alternativa_horario ah ON ah.id_cupo = c.id_cupo
                         WHERE ah.id_oferta = ? ORDER BY ah.orden");
    $st->execute([$oferta['id_oferta']]);
    $alternativas = $st->fetchAll();
}

// Otros horarios libres de la misma especialidad (próximos 30 días).
$st = $pdo->prepare("SELECT $campos_cupo
                     JOIN cita ci ON ci.id_cita = ?
                     WHERE m.id_especialidad = ci.id_especialidad
                       AND c.fecha BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                       AND fn_cupo_libre(c.id_cupo) = 1
                     ORDER BY c.fecha, c.hora_inicio
                     LIMIT 24");
$st->execute([$id_cita]);
$otros = $st->fetchAll();

$titulo = 'Alternativas de horario';
$activo = tiene_rol('RECEPCIONISTA') ? 'agenda' : 'mis_citas';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><?= etiqueta_estado($c['estado']) ?> &nbsp; Código #CIT-<?= str_pad($c['id_cita'], 5, '0', STR_PAD_LEFT) ?></p>
        <h1>Alternativas de horario</h1>
        <p class="sub">Por la ausencia del médico, elija un nuevo horario de <?= h($c['especialidad']) ?>
            para <?= h($c['nombres'] . ' ' . $c['apellidos']) ?>. Su cita se conserva y no gasta la reprogramación voluntaria.</p>
    </div>
    <?php if ($oferta): ?>
        <div class="aviso aviso-alerta" style="margin:0"><span class="icono">hourglass_top</span>
            Plazo para elegir: <strong><?= date('d/m/Y H:i', strtotime($oferta['fecha_limite'])) ?></strong> (48 horas)</div>
    <?php endif; ?>
</div>

<?php if ($c['estado'] !== 'PENDIENTE_REPROGRAMACION'): ?>
    <div class="tarjeta vacio"><span class="icono">info</span>Esta cita no está pendiente de reprogramación.
        <a href="mis_citas.php">Volver</a></div>
<?php else: ?>

    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">star</span>Horarios sugeridos (los más próximos)</h2>
        <?php if (!$alternativas): ?>
            <div class="vacio">
                <span class="icono">event_busy</span>
                <strong>Por ahora no hay horarios disponibles en <?= h($c['especialidad']) ?>.</strong><br>
                Su cita queda en seguimiento: le avisaremos cuando se habiliten nuevos cupos.
                El plazo de 48 horas empieza recién cuando se le ofrezcan opciones.
            </div>
        <?php else: ?>
            <div class="grid-3">
                <?php foreach ($alternativas as $a): ?>
                    <form method="post" class="medico-card" style="margin:0">
                        <input type="hidden" name="cita" value="<?= $id_cita ?>">
                        <span class="etiqueta etiqueta-azul">Opción <?= $a['orden'] ?></span>
                        <h3 style="margin:10px 0 4px"><?= h(ucfirst(fecha_larga($a['fecha']))) ?></h3>
                        <p style="margin:0 0 4px;font-size:20px;font-weight:700;color:var(--primario-oscuro)"><?= hora($a['hora_inicio']) ?> hrs</p>
                        <p style="margin:0 0 12px;color:var(--texto-2)"><?= h($a['medico']) ?> · <?= h($a['consultorio']) ?></p>
                        <?php if ($a['libre']): ?>
                            <button class="boton boton-bloque" name="id_cupo" value="<?= $a['id_cupo'] ?>">Elegir este horario</button>
                        <?php else: ?>
                            <span class="etiqueta etiqueta-gris">Ya no está disponible</span>
                        <?php endif; ?>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($otros): ?>
        <section class="tarjeta">
            <h2 class="tarjeta-titulo"><span class="icono">calendar_month</span>Otros horarios libres de <?= h($c['especialidad']) ?></h2>
            <form method="post">
                <input type="hidden" name="cita" value="<?= $id_cita ?>">
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <tr><th>Fecha</th><th>Hora</th><th>Médico</th><th>Consultorio</th><th></th></tr>
                        <?php foreach ($otros as $o): ?>
                            <tr>
                                <td><?= h(ucfirst(fecha_larga($o['fecha']))) ?></td>
                                <td><?= hora($o['hora_inicio']) ?></td>
                                <td><?= h($o['medico']) ?></td>
                                <td><?= h($o['consultorio']) ?></td>
                                <td class="num"><button class="boton boton-secundario boton-chico" name="id_cupo" value="<?= $o['id_cupo'] ?>">Elegir</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <div class="tarjeta">
        <p style="margin:0 0 10px">¿Prefiere no continuar? Puede cancelar la cita; luego podrá hacer una nueva reserva.</p>
        <a class="boton boton-peligro" href="cancelar.php?cita=<?= $id_cita ?>"><span class="icono">event_busy</span>Cancelar la cita</a>
    </div>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
