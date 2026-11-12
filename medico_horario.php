<?php
// =====================================================
// medico_horario.php - Mi horario (médico).
// Muestra los cupos del día y las citas; el médico
// registra el inicio y el fin de la atención con
// sp_atender_cita.
// =====================================================
require 'config.php';
requerir_rol('MEDICO');

$u = usuario();

// Datos del médico que inició sesión.
$st = $pdo->prepare("SELECT m.id_medico, e.nombre AS especialidad
                     FROM medico m JOIN especialidad e ON e.id_especialidad = m.id_especialidad
                     WHERE m.id_persona = ?");
$st->execute([$u['id_persona']]);
$medico = $st->fetch();
if (!$medico) {
    die('Esta cuenta no está registrada como médico.');
}

// ---------- Iniciar / finalizar atención ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        llamar($pdo, 'CALL sp_atender_cita(?, ?)', [$u['id_usuario'], (int) $_POST['cita']]);
        mensaje('exito', $_POST['accion'] === 'iniciar' ? 'Consulta iniciada.' : 'Consulta finalizada. La cita quedó como atendida.');
    } catch (PDOException $e) {
        mensaje('error', mensaje_error($e));
    }
    ir_a('medico_horario.php?fecha=' . urlencode($_POST['fecha'] ?? ''));
}

// ---------- Fecha ----------
$fecha = $_GET['fecha'] ?? '';
if (!$fecha) {
    // Hoy si tiene cupos; si no, su próxima fecha con cupos.
    $st = $pdo->prepare('SELECT MIN(fecha) FROM cupo WHERE id_medico = ? AND fecha >= CURDATE()');
    $st->execute([$medico['id_medico']]);
    $fecha = $st->fetchColumn() ?: date('Y-m-d');
}

// Cupos del día con su cita (si tiene).
$st = $pdo->prepare(
    "SELECT c.id_cupo, c.hora_inicio, c.hora_fin, co.codigo AS consultorio,
            ci.id_cita, ci.estado, ci.id_vinculo_apoderado,
            pp.nombres, pp.apellidos, pp.numero_documento, pp.fecha_nacimiento,
            fn_cupo_libre(c.id_cupo) AS libre,
            EXISTS (SELECT 1 FROM ausencia_medico am
                    WHERE am.id_medico = c.id_medico
                      AND am.inicio < TIMESTAMP(c.fecha, c.hora_fin)
                      AND am.fin > TIMESTAMP(c.fecha, c.hora_inicio)) AS ausente
     FROM cupo c
     JOIN consultorio co ON co.id_consultorio = c.id_consultorio
     LEFT JOIN asignacion_cita ac ON ac.id_cupo = c.id_cupo AND ac.estado = 'VIGENTE'
     LEFT JOIN cita ci ON ci.id_cita = ac.id_cita
     LEFT JOIN paciente pa ON pa.id_paciente = ci.id_paciente
     LEFT JOIN persona pp ON pp.id_persona = pa.id_persona
     WHERE c.id_medico = ? AND c.fecha = ?
     ORDER BY c.hora_inicio");
$st->execute([$medico['id_medico'], $fecha]);
$cupos = $st->fetchAll();

$citas = array_filter($cupos, fn($c) => $c['id_cita']);
$libres = count(array_filter($cupos, fn($c) => $c['libre']));

// Fechas con cupos para el selector.
$st = $pdo->prepare('SELECT DISTINCT fecha FROM cupo WHERE id_medico = ? AND fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY fecha LIMIT 30');
$st->execute([$medico['id_medico']]);
$fechas = $st->fetchAll(PDO::FETCH_COLUMN);

$titulo = 'Mi horario';
$activo = 'horario';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">stethoscope</span> <?= h($medico['especialidad']) ?></p>
        <h1>Mi horario de atención</h1>
        <p class="sub"><?= h($u['nombre']) ?> · <?= h(ucfirst(fecha_larga($fecha))) ?></p>
    </div>
    <form method="get" class="acciones">
        <select name="fecha" onchange="this.form.submit()" style="padding:9px;border-radius:8px;border:1px solid var(--borde-2)">
            <?php foreach ($fechas as $f): ?>
                <option value="<?= $f ?>" <?= $f === $fecha ? 'selected' : '' ?>><?= h(ucfirst(fecha_larga($f))) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="boton">Ver</button></noscript>
    </form>
</div>

<div class="grid-4" style="margin-bottom:20px">
    <div class="kpi"><span>Cupos del día</span><strong><?= count($cupos) ?></strong></div>
    <div class="kpi"><span>Citas programadas</span><strong><?= count($citas) ?></strong></div>
    <div class="kpi"><span>Cupos libres</span><strong><?= $libres ?></strong></div>
    <div class="kpi"><span>Consultorio</span><strong style="font-size:22px"><?= h($cupos[0]['consultorio'] ?? '—') ?></strong></div>
</div>

<section class="tarjeta">
    <h2 class="tarjeta-titulo"><span class="icono">groups</span>Pacientes del día</h2>
    <?php if (!$citas): ?>
        <p class="vacio">No tiene citas programadas para esta fecha.</p>
    <?php else: ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <tr><th>Hora</th><th>Paciente</th><th>Edad</th><th>Estado</th><th>Atención</th></tr>
                <?php foreach ($citas as $c): ?>
                    <tr>
                        <td><strong><?= hora($c['hora_inicio']) ?></strong></td>
                        <td><?= h($c['nombres'] . ' ' . $c['apellidos']) ?>
                            <span class="secundario">DNI <?= h($c['numero_documento']) ?>
                                <?= $c['id_vinculo_apoderado'] ? ' · acude con su apoderado' : '' ?></span></td>
                        <td><?= edad($c['fecha_nacimiento'], $fecha) ?> años</td>
                        <td><?= etiqueta_estado($c['estado']) ?></td>
                        <td>
                            <?php if (in_array($c['estado'], ['EN_ESPERA', 'EN_ATENCION'])): ?>
                                <form method="post">
                                    <input type="hidden" name="cita" value="<?= $c['id_cita'] ?>">
                                    <input type="hidden" name="fecha" value="<?= h($fecha) ?>">
                                    <?php if ($c['estado'] === 'EN_ESPERA'): ?>
                                        <button class="boton boton-chico" name="accion" value="iniciar"><span class="icono">play_arrow</span>Iniciar consulta</button>
                                    <?php else: ?>
                                        <button class="boton boton-exito boton-chico" name="accion" value="finalizar"><span class="icono">task_alt</span>Finalizar consulta</button>
                                    <?php endif; ?>
                                </form>
                            <?php elseif ($c['estado'] === 'CONFIRMADA'): ?>
                                <span class="secundario">Esperando llegada en recepción</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta">
    <h2 class="tarjeta-titulo"><span class="icono">grid_view</span>Cupos del día</h2>
    <div class="acciones" style="margin-bottom:12px;font-size:12px">
        <span class="celda libre" style="padding:3px 8px">Libre</span>
        <span class="celda ocupado" style="padding:3px 8px">Con cita</span>
        <span class="celda no-disponible" style="padding:3px 8px">No disponible (ausencia, pasado o bloqueado)</span>
    </div>
    <div class="matriz">
        <?php foreach ($cupos as $c):
            $clase = $c['id_cita'] ? 'ocupado' : ($c['libre'] ? 'libre' : 'no-disponible'); ?>
            <div class="celda <?= $clase ?>">
                <strong><?= hora($c['hora_inicio']) ?></strong>
                <?php if ($c['id_cita']): ?><?= h($c['apellidos']) ?>
                <?php elseif ($c['ausente']): ?>Ausencia
                <?php elseif ($c['libre']): ?>Libre
                <?php else: ?>—<?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php require 'includes/footer.php'; ?>
