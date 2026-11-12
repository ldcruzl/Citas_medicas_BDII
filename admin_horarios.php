<?php
// =====================================================
// admin_horarios.php - Horarios y cupos.
// Genera los cupos de 15 minutos de un médico (20 por
// turno) y muestra la matriz de cupos de un día.
// =====================================================
require 'config.php';
requerir_rol('ADMINISTRADOR');

// ---------- Acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($_POST['accion'] === 'generar') {
            $desde = $_POST['desde'];
            $hasta = $_POST['hasta'];
            $turnos = $_POST['turno'] === 'AMBOS' ? ['MANANA', 'TARDE'] : [$_POST['turno']];

            if ($hasta < $desde || (strtotime($hasta) - strtotime($desde)) / 86400 > 60) {
                throw new Exception('Revise las fechas: el rango debe ser de 60 días como máximo.');
            }

            // 20 cupos de 15 minutos por turno. INSERT IGNORE salta los cupos que
            // chocan (mismo médico o mismo consultorio a la misma hora).
            $sql = "INSERT IGNORE INTO cupo (id_medico, id_consultorio, id_turno, fecha, hora_inicio, hora_fin)
                    SELECT ?, ?, t.id_turno, ?,
                           SEC_TO_TIME(TIME_TO_SEC(t.hora_inicio) + n.numero * 900),
                           SEC_TO_TIME(TIME_TO_SEC(t.hora_inicio) + (n.numero + 1) * 900)
                    FROM turno t
                    CROSS JOIN (SELECT 0 AS numero UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                                UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
                                UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
                                UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
                                UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19) n
                    WHERE t.nombre = ?";
            $st = $pdo->prepare($sql);

            $creados = 0;
            $esperados = 0;
            $pdo->beginTransaction();
            for ($dia = strtotime($desde); $dia <= strtotime($hasta); $dia += 86400) {
                if (date('w', $dia) == 0) {
                    continue;   // Domingo: no hay atención (lunes a sábado).
                }
                foreach ($turnos as $t) {
                    $st->execute([(int) $_POST['medico'], (int) $_POST['consultorio'], date('Y-m-d', $dia), $t]);
                    $creados += $st->rowCount();
                    $esperados += 20;
                }
            }
            $pdo->commit();

            $omitidos = $esperados - $creados;
            mensaje('exito', "Se generaron $creados cupos." . ($omitidos ? " Se omitieron $omitidos porque ya existían o el consultorio estaba ocupado." : ''));
            ir_a('admin_horarios.php?medico=' . (int) $_POST['medico'] . '&fecha=' . $desde);
        }

        if ($_POST['accion'] === 'cambiar') {
            // Solo se bloquean o habilitan cupos sin cita.
            $pdo->prepare("UPDATE cupo SET estado_habilitacion = IF(estado_habilitacion = 'HABILITADO', 'BLOQUEADO', 'HABILITADO')
                           WHERE id_cupo = ?
                             AND NOT EXISTS (SELECT 1 FROM asignacion_cita ac WHERE ac.id_cupo = cupo.id_cupo AND ac.estado = 'VIGENTE')")
                ->execute([(int) $_POST['cupo']]);
            mensaje('exito', 'Se actualizó el cupo.');
            ir_a('admin_horarios.php?' . $_POST['volver']);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mensaje('error', $e instanceof PDOException ? mensaje_error($e) : $e->getMessage());
        ir_a('admin_horarios.php');
    }
}

$medicos = $pdo->query("SELECT m.id_medico, CONCAT(p.nombres, ' ', p.apellidos) AS nombre, e.nombre AS especialidad
                        FROM medico m JOIN persona p ON p.id_persona = m.id_persona
                        JOIN especialidad e ON e.id_especialidad = m.id_especialidad
                        WHERE m.estado = 'ACTIVO' ORDER BY e.nombre, p.apellidos")->fetchAll();
$consultorios = $pdo->query("SELECT id_consultorio, codigo, ubicacion FROM consultorio WHERE estado = 'ACTIVO' ORDER BY codigo")->fetchAll();

// ---------- Matriz de un médico en una fecha ----------
$id_medico = (int) ($_GET['medico'] ?? ($medicos[0]['id_medico'] ?? 0));
$fecha = $_GET['fecha'] ?? date('Y-m-d');

$st = $pdo->prepare(
    "SELECT c.id_cupo, c.hora_inicio, c.estado_habilitacion, co.codigo,
            ac.id_cita, pp.apellidos, fn_cupo_libre(c.id_cupo) AS libre
     FROM cupo c
     JOIN consultorio co ON co.id_consultorio = c.id_consultorio
     LEFT JOIN asignacion_cita ac ON ac.id_cupo = c.id_cupo AND ac.estado = 'VIGENTE'
     LEFT JOIN cita ci ON ci.id_cita = ac.id_cita
     LEFT JOIN paciente pa ON pa.id_paciente = ci.id_paciente
     LEFT JOIN persona pp ON pp.id_persona = pa.id_persona
     WHERE c.id_medico = ? AND c.fecha = ?
     ORDER BY c.hora_inicio");
$st->execute([$id_medico, $fecha]);
$cupos = $st->fetchAll();

$volver = http_build_query(['medico' => $id_medico, 'fecha' => $fecha]);

$titulo = 'Horarios y cupos';
$activo = 'horarios';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">admin_panel_settings</span> Administración</p>
        <h1>Programación de horarios y cupos</h1>
        <p class="sub">Cada cupo dura 15 minutos: 20 por turno y 40 por día como máximo. Atención de lunes a sábado.</p>
    </div>
</div>

<div class="dos-columnas" style="grid-template-columns:360px 1fr">
    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">add_box</span>Generar cupos</h2>
        <form method="post">
            <div class="campo"><label>Médico</label>
                <select name="medico" required>
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= $m['id_medico'] ?>" <?= $m['id_medico'] == $id_medico ? 'selected' : '' ?>>
                            <?= h($m['nombre']) ?> – <?= h($m['especialidad']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="campo"><label>Consultorio</label>
                <select name="consultorio" required>
                    <?php foreach ($consultorios as $co): ?>
                        <option value="<?= $co['id_consultorio'] ?>"><?= h($co['codigo']) ?> – <?= h($co['ubicacion']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="fila-campos">
                <div class="campo"><label>Desde</label><input type="date" name="desde" value="<?= date('Y-m-d') ?>" required></div>
                <div class="campo"><label>Hasta</label><input type="date" name="hasta" value="<?= date('Y-m-d', strtotime('+6 days')) ?>" required></div>
            </div>
            <div class="campo"><label>Turno</label>
                <select name="turno">
                    <option value="AMBOS">Mañana y tarde</option>
                    <option value="MANANA">Mañana (08:00 – 13:00)</option>
                    <option value="TARDE">Tarde (14:00 – 19:00)</option>
                </select></div>
            <button class="boton boton-bloque" name="accion" value="generar"><span class="icono">calendar_add_on</span>Generar cupos</button>
        </form>
    </section>

    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">grid_view</span>Matriz de cupos</h2>
        <form method="get" class="fila-campos">
            <div class="campo"><label>Médico</label>
                <select name="medico" onchange="this.form.submit()">
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= $m['id_medico'] ?>" <?= $m['id_medico'] == $id_medico ? 'selected' : '' ?>><?= h($m['nombre']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="campo"><label>Fecha</label><input type="date" name="fecha" value="<?= h($fecha) ?>" onchange="this.form.submit()"></div>
        </form>

        <?php if (!$cupos): ?>
            <p class="vacio"><span class="icono">event_busy</span>Este médico no tiene cupos el <?= h(fecha_larga($fecha)) ?>.</p>
        <?php else: ?>
            <p style="margin:0 0 10px;color:var(--texto-2)">Consultorio <?= h($cupos[0]['codigo']) ?> · <?= count($cupos) ?> cupos.
                Pulse un cupo libre para bloquearlo, o uno bloqueado para habilitarlo.</p>
            <div class="matriz">
                <?php foreach ($cupos as $c):
                    $clase = $c['id_cita'] ? 'ocupado' : ($c['libre'] ? 'libre' : 'no-disponible'); ?>
                    <form method="post" class="celda <?= $clase ?>">
                        <input type="hidden" name="cupo" value="<?= $c['id_cupo'] ?>">
                        <input type="hidden" name="volver" value="<?= h($volver) ?>">
                        <strong><?= hora($c['hora_inicio']) ?></strong>
                        <?php if ($c['id_cita']): ?>
                            <?= h($c['apellidos']) ?>
                        <?php else: ?>
                            <button name="accion" value="cambiar" style="all:unset;cursor:pointer;text-decoration:underline">
                                <?= $c['estado_habilitacion'] === 'BLOQUEADO' ? 'Bloqueado' : ($c['libre'] ? 'Libre' : 'No disp.') ?>
                            </button>
                        <?php endif; ?>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require 'includes/footer.php'; ?>
