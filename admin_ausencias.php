<?php
// =====================================================
// admin_ausencias.php - Ausencias y sustitución.
// 1. Ver las citas que afectaría una ausencia.
// 2. Registrarla con sp_registrar_ausencia (propone
//    sustitutos o envía a reprogramación).
// 3. Seguir las citas pendientes de respuesta.
// =====================================================
require 'config.php';
requerir_rol('ADMINISTRADOR');

$u = usuario();

// ---------- Acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($_POST['accion'] === 'registrar') {
            $inicio = $_POST['fecha'] . ' ' . $_POST['hora_inicio'] . ':00';
            $fin    = $_POST['fecha'] . ' ' . $_POST['hora_fin'] . ':00';
            $filas = llamar($pdo, 'CALL sp_registrar_ausencia(?, ?, ?, ?, ?, @ausencia)',
                            [$u['id_usuario'], (int) $_POST['medico'], $inicio, $fin, trim($_POST['motivo'])]);
            $_SESSION['resultado_ausencia'] = $filas;
            mensaje('exito', 'Ausencia registrada. Citas afectadas: ' . count($filas) . '.');
        }

        if ($_POST['accion'] === 'vencimientos') {
            $r = llamar($pdo, 'CALL sp_procesar_vencimientos()')[0];
            mensaje('info', 'Revisión de plazos: ' . $r['bloqueos_vencidos'] . ' bloqueos vencidos, '
                . $r['canceladas_sin_respuesta_sustituto'] . ' citas canceladas sin respuesta al sustituto y '
                . $r['canceladas_sin_respuesta_alternativas'] . ' sin respuesta a las alternativas.');
        }
    } catch (PDOException $e) {
        mensaje('error', mensaje_error($e));
    }
    ir_a('admin_ausencias.php');
}

$resultado = $_SESSION['resultado_ausencia'] ?? null;
unset($_SESSION['resultado_ausencia']);

$medicos = $pdo->query("SELECT m.id_medico, CONCAT(p.nombres, ' ', p.apellidos) AS nombre, e.nombre AS especialidad
                        FROM medico m JOIN persona p ON p.id_persona = m.id_persona
                        JOIN especialidad e ON e.id_especialidad = m.id_especialidad
                        WHERE m.estado = 'ACTIVO' ORDER BY e.nombre, p.apellidos")->fetchAll();

// ---------- Vista previa del impacto ----------
$f = [
    'medico'      => (int) ($_GET['medico'] ?? 0),
    'fecha'       => $_GET['fecha'] ?? date('Y-m-d', strtotime('+1 day')),
    'hora_inicio' => $_GET['hora_inicio'] ?? '08:00',
    'hora_fin'    => $_GET['hora_fin'] ?? '13:00',
    'motivo'      => $_GET['motivo'] ?? '',
];
$afectadas = null;
if ($f['medico']) {
    $st = $pdo->prepare(sql_citas() . "
        WHERE cu.id_medico = ? AND ac.estado = 'VIGENTE' AND ci.estado IN ('CONFIRMADA', 'EN_ESPERA')
          AND TIMESTAMP(cu.fecha, cu.hora_inicio) < ? AND TIMESTAMP(cu.fecha, cu.hora_fin) > ?
        ORDER BY cu.fecha, cu.hora_inicio");
    $st->execute([$f['medico'], $f['fecha'] . ' ' . $f['hora_fin'], $f['fecha'] . ' ' . $f['hora_inicio']]);
    $afectadas = $st->fetchAll();
}

// ---------- Bandeja de seguimiento ----------
$propuestas = $pdo->query(
    "SELECT ps.id_cita, ps.fecha_limite, CONCAT(pp.nombres, ' ', pp.apellidos) AS paciente,
            CONCAT(pm.nombres, ' ', pm.apellidos) AS sustituto, c.fecha, c.hora_inicio, co.codigo AS consultorio
     FROM propuesta_sustitucion ps
     JOIN cita ci ON ci.id_cita = ps.id_cita
     JOIN paciente pa ON pa.id_paciente = ci.id_paciente
     JOIN persona pp ON pp.id_persona = pa.id_persona
     JOIN cupo c ON c.id_cupo = ps.id_cupo_sustituto
     JOIN medico m ON m.id_medico = c.id_medico
     JOIN persona pm ON pm.id_persona = m.id_persona
     JOIN consultorio co ON co.id_consultorio = c.id_consultorio
     WHERE ps.estado = 'PENDIENTE'
     ORDER BY ps.fecha_limite")->fetchAll();

$reprogramar = $pdo->query(
    "SELECT ci.id_cita, CONCAT(pp.nombres, ' ', pp.apellidos) AS paciente, e.nombre AS especialidad, o.fecha_limite
     FROM cita ci
     JOIN paciente pa ON pa.id_paciente = ci.id_paciente
     JOIN persona pp ON pp.id_persona = pa.id_persona
     JOIN especialidad e ON e.id_especialidad = ci.id_especialidad
     LEFT JOIN oferta_reprogramacion o ON o.id_cita = ci.id_cita AND o.estado = 'PENDIENTE'
     WHERE ci.estado = 'PENDIENTE_REPROGRAMACION'
     ORDER BY o.fecha_limite IS NULL, o.fecha_limite")->fetchAll();

$ausencias = $pdo->query(
    "SELECT am.inicio, am.fin, am.motivo, CONCAT(p.nombres, ' ', p.apellidos) AS medico
     FROM ausencia_medico am JOIN medico m ON m.id_medico = am.id_medico JOIN persona p ON p.id_persona = m.id_persona
     ORDER BY am.id_ausencia DESC LIMIT 8")->fetchAll();

$titulo = 'Ausencias y sustitución';
$activo = 'ausencias';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">admin_panel_settings</span> Administración</p>
        <h1>Ausencias médicas y sustitución</h1>
        <p class="sub">Al registrar una ausencia, el sistema propone un médico sustituto de la misma especialidad
            a la misma hora. Si no hay, ofrece al paciente otros horarios (48 horas para elegir).</p>
    </div>
    <form method="post">
        <button class="boton boton-secundario" name="accion" value="vencimientos"><span class="icono">update</span>Procesar plazos vencidos</button>
    </form>
</div>

<?php if ($resultado !== null): ?>
    <section class="tarjeta" style="border-color:#a7f3d0">
        <h2 class="tarjeta-titulo"><span class="icono">task_alt</span>Resultado de la ausencia registrada</h2>
        <?php if (!$resultado): ?>
            <p style="margin:0">No había citas en ese horario. Los cupos quedaron bloqueados para nuevas reservas.</p>
        <?php else: ?>
            <table class="tabla">
                <tr><th>Cita</th><th>Paciente</th><th>Estado</th><th>Resultado</th></tr>
                <?php foreach ($resultado as $r): ?>
                    <tr><td>#<?= $r['id_cita'] ?></td><td><?= h($r['paciente']) ?></td>
                        <td><?= etiqueta_estado($r['estado']) ?></td><td><?= h($r['resultado']) ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="dos-columnas" style="grid-template-columns:360px 1fr">
    <section class="tarjeta" style="border-top:3px solid var(--primario)">
        <h2 class="tarjeta-titulo"><span class="icono">event_busy</span>Declaración de ausencia</h2>
        <form method="get">
            <div class="campo">
                <label>Médico</label>
                <select name="medico" required>
                    <option value="">Seleccione…</option>
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= $m['id_medico'] ?>" <?= $f['medico'] == $m['id_medico'] ? 'selected' : '' ?>>
                            <?= h($m['nombre']) ?> – <?= h($m['especialidad']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo"><label>Fecha</label><input type="date" name="fecha" value="<?= h($f['fecha']) ?>" required></div>
            <div class="fila-campos">
                <div class="campo"><label>Desde</label><input type="time" name="hora_inicio" value="<?= h($f['hora_inicio']) ?>" required></div>
                <div class="campo"><label>Hasta</label><input type="time" name="hora_fin" value="<?= h($f['hora_fin']) ?>" required></div>
            </div>
            <div class="campo"><label>Motivo</label>
                <input name="motivo" value="<?= h($f['motivo']) ?>" placeholder="Ej.: licencia médica, capacitación" maxlength="200"></div>
            <button class="boton boton-secundario boton-bloque" type="submit"><span class="icono">search</span>Ver citas afectadas</button>
        </form>
    </section>

    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">group</span>Citas afectadas por la ausencia</h2>
        <?php if ($afectadas === null): ?>
            <p class="vacio">Elija el médico y el horario, y presione «Ver citas afectadas».</p>
        <?php else: ?>
            <?php if (!$afectadas): ?>
                <p>No hay citas en ese horario. Igual puede registrar la ausencia para bloquear los cupos.</p>
            <?php else: ?>
                <table class="tabla" style="margin-bottom:16px">
                    <tr><th>Cita</th><th>Paciente</th><th>Hora</th><th>Estado</th></tr>
                    <?php foreach ($afectadas as $c): ?>
                        <tr><td>#<?= $c['id_cita'] ?></td><td><?= h($c['nombres'] . ' ' . $c['apellidos']) ?>
                                <span class="secundario">DNI <?= h($c['numero_documento']) ?></span></td>
                            <td><?= hora($c['hora_inicio']) ?></td><td><?= etiqueta_estado($c['estado']) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
            <form method="post">
                <?php foreach ($f as $campo => $valor): ?>
                    <input type="hidden" name="<?= $campo ?>" value="<?= h($valor) ?>">
                <?php endforeach; ?>
                <?php if (trim($f['motivo']) === ''): ?>
                    <div class="aviso aviso-alerta">Escriba el motivo de la ausencia antes de registrarla.</div>
                <?php else: ?>
                    <button class="boton" name="accion" value="registrar"><span class="icono">send</span>Registrar ausencia y buscar sustitutos</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="tarjeta">
    <h2 class="tarjeta-titulo"><span class="icono">monitor_heart</span>Bandeja de seguimiento</h2>
    <h3 style="font-size:15px;margin:4px 0 10px">Propuestas de sustituto esperando respuesta (<?= count($propuestas) ?>)</h3>
    <?php if (!$propuestas): ?>
        <p class="secundario" style="color:var(--texto-2)">Ninguna.</p>
    <?php else: ?>
        <table class="tabla" style="margin-bottom:18px">
            <tr><th>Cita</th><th>Paciente</th><th>Sustituto propuesto</th><th>Turno</th><th>Vence</th></tr>
            <?php foreach ($propuestas as $p): ?>
                <tr><td>#<?= $p['id_cita'] ?></td><td><?= h($p['paciente']) ?></td>
                    <td><?= h($p['sustituto']) ?><span class="secundario"><?= h($p['consultorio']) ?></span></td>
                    <td><?= fecha_corta($p['fecha']) ?> <?= hora($p['hora_inicio']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($p['fecha_limite'])) ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <h3 style="font-size:15px;margin:4px 0 10px">Citas pendientes de reprogramación (<?= count($reprogramar) ?>)</h3>
    <?php if (!$reprogramar): ?>
        <p style="color:var(--texto-2)">Ninguna.</p>
    <?php else: ?>
        <table class="tabla">
            <tr><th>Cita</th><th>Paciente</th><th>Especialidad</th><th>Situación</th></tr>
            <?php foreach ($reprogramar as $r): ?>
                <tr><td>#<?= $r['id_cita'] ?></td><td><?= h($r['paciente']) ?></td><td><?= h($r['especialidad']) ?></td>
                    <td><?= $r['fecha_limite']
                            ? 'Alternativas enviadas · vence ' . date('d/m/Y H:i', strtotime($r['fecha_limite']))
                            : '<span class="etiqueta etiqueta-ambar">En seguimiento: sin cupos</span>' ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</section>

<section class="tarjeta">
    <h2 class="tarjeta-titulo"><span class="icono">history</span>Últimas ausencias registradas</h2>
    <?php if (!$ausencias): ?>
        <p style="color:var(--texto-2)">Todavía no hay ausencias.</p>
    <?php else: ?>
        <table class="tabla">
            <tr><th>Médico</th><th>Desde</th><th>Hasta</th><th>Motivo</th></tr>
            <?php foreach ($ausencias as $a): ?>
                <tr><td><?= h($a['medico']) ?></td><td><?= date('d/m/Y H:i', strtotime($a['inicio'])) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($a['fin'])) ?></td><td><?= h($a['motivo']) ?></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</section>

<?php require 'includes/footer.php'; ?>
