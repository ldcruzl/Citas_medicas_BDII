<?php
// =====================================================
// reservar.php - Paso 1: elegir paciente, especialidad,
// fecha, turno y horario.
// Usa sp_consultar_disponibilidad para listar los cupos
// y sp_bloquear_cupo para separar el elegido 5 minutos.
// La usan el paciente, el apoderado y recepción.
// =====================================================
require 'config.php';
requerir_rol('PACIENTE', 'APODERADO', 'RECEPCIONISTA');

$u = usuario();
$es_recepcion = tiene_rol('RECEPCIONISTA');

// ---------- Separar el horario elegido ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_paciente = (int) ($_POST['paciente'] ?? 0);
    $id_cupo     = (int) ($_POST['id_cupo'] ?? 0);
    $volver      = 'reservar.php?' . ($_POST['volver'] ?? '');

    try {
        llamar($pdo, 'CALL sp_bloquear_cupo(?, ?, ?, @bloqueo)', [$u['id_usuario'], $id_paciente, $id_cupo]);
        $id_bloqueo = variable_salida($pdo, 'bloqueo');
        ir_a('confirmar.php?bloqueo=' . (int) $id_bloqueo);
    } catch (PDOException $e) {
        mensaje('error', mensaje_error($e));
        ir_a($volver);
    }
}

// ---------- ¿Para quién es la cita? ----------
$pacientes = [];
$pendientes = [];
$dni = trim($_GET['dni'] ?? '');

if ($es_recepcion) {
    // Recepción busca al paciente por su documento.
    if ($dni !== '') {
        $st = $pdo->prepare(
            "SELECT pa.id_paciente, pe.nombres, pe.apellidos, pe.numero_documento, pe.fecha_nacimiento
             FROM paciente pa JOIN persona pe ON pe.id_persona = pa.id_persona
             WHERE pe.numero_documento = ? AND pa.estado = 'ACTIVO'");
        $st->execute([$dni]);
        foreach ($st as $f) {
            $f['tipo'] = es_menor($f['fecha_nacimiento']) ? 'MENOR' : 'TITULAR';
            $pacientes[$f['id_paciente']] = $f;
        }
    }
} else {
    $pacientes = pacientes_gestionables($pdo);

    // Menores con vínculo todavía sin validar (se muestran bloqueados).
    if (tiene_rol('APODERADO')) {
        $st = $pdo->prepare(
            "SELECT pe.nombres, pe.apellidos, pe.numero_documento
             FROM vinculo_apoderado va
             JOIN paciente pa ON pa.id_paciente = va.id_paciente
             JOIN persona pe ON pe.id_persona = pa.id_persona
             WHERE va.id_persona_apoderado = ? AND va.estado = 'PENDIENTE'");
        $st->execute([$u['id_persona']]);
        $pendientes = $st->fetchAll();
    }
}

$id_paciente = (int) ($_GET['paciente'] ?? 0);
if (!isset($pacientes[$id_paciente])) {
    $id_paciente = $pacientes ? array_key_first($pacientes) : 0;
}

// ---------- Especialidad, fecha y turno ----------
$especialidades = $pdo->query(
    "SELECT id_especialidad, nombre FROM especialidad WHERE estado = 'ACTIVO' ORDER BY nombre")->fetchAll();

$id_especialidad = (int) ($_GET['especialidad'] ?? 0);
if (!$id_especialidad) {
    // Por defecto, la especialidad con el cupo más próximo.
    $id_especialidad = (int) $pdo->query(
        "SELECT m.id_especialidad FROM cupo c JOIN medico m ON m.id_medico = c.id_medico
         WHERE TIMESTAMP(c.fecha, c.hora_inicio) > NOW()
         ORDER BY c.fecha, c.hora_inicio LIMIT 1")->fetchColumn();
    if (!$id_especialidad && $especialidades) {
        $id_especialidad = (int) $especialidades[0]['id_especialidad'];
    }
}

// Fechas con cupos en los próximos 30 días para esa especialidad.
$st = $pdo->prepare(
    "SELECT DISTINCT c.fecha FROM cupo c JOIN medico m ON m.id_medico = c.id_medico
     WHERE m.id_especialidad = ? AND c.fecha BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY c.fecha");
$st->execute([$id_especialidad]);
$fechas = $st->fetchAll(PDO::FETCH_COLUMN);

$fecha = $_GET['fecha'] ?? '';
if (!in_array($fecha, $fechas)) {
    $fecha = $fechas[0] ?? date('Y-m-d');
}

$turnos = $pdo->query('SELECT id_turno, nombre, hora_inicio, hora_fin FROM turno ORDER BY hora_inicio')->fetchAll();
$id_turno = $_GET['turno'] ?? '';

// ---------- Consultar disponibilidad ----------
$medicos = [];
$error_busqueda = '';
if ($id_paciente && $fechas) {
    try {
        $filas = llamar($pdo, 'CALL sp_consultar_disponibilidad(?, ?, ?, NULL)',
                        [$id_especialidad, $fecha, $id_turno === '' ? null : (int) $id_turno]);
        // Agrupar los cupos por médico.
        foreach ($filas as $f) {
            $medicos[$f['id_medico']]['datos'] = $f;
            $medicos[$f['id_medico']]['cupos'][] = $f;
        }
    } catch (PDOException $e) {
        $error_busqueda = mensaje_error($e);
    }
}

$nombre_especialidad = '';
foreach ($especialidades as $e) {
    if ($e['id_especialidad'] == $id_especialidad) {
        $nombre_especialidad = $e['nombre'];
    }
}

// Para volver a esta misma búsqueda si hay un error.
$volver = http_build_query(['dni' => $dni, 'paciente' => $id_paciente, 'especialidad' => $id_especialidad,
                            'fecha' => $fecha, 'turno' => $id_turno]);

$titulo = 'Reservar cita';
$activo = 'reservar';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">calendar_add_on</span> Paso 1 de 2 · Selección de horario</p>
        <h1><?= $es_recepcion ? 'Registrar cita en ventanilla' : 'Reservar cita' ?></h1>
        <p class="sub">Elija al paciente, la especialidad y un horario libre. El horario quedará separado
            5 minutos mientras confirma.</p>
    </div>
</div>

<form method="get">
    <!-- ¿Para quién es la cita? -->
    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">badge</span>¿Para quién es la cita?</h2>

        <?php if ($es_recepcion): ?>
            <div class="fila-campos" style="max-width:520px">
                <div class="campo">
                    <label for="dni">Documento del paciente</label>
                    <input id="dni" name="dni" value="<?= h($dni) ?>" placeholder="DNI, CE o pasaporte">
                </div>
                <div class="campo"><button class="boton boton-secundario" type="submit"><span class="icono">search</span>Buscar</button></div>
            </div>
            <?php if ($dni !== '' && !$pacientes): ?>
                <div class="aviso aviso-alerta">No hay un paciente activo con el documento <?= h($dni) ?>.</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="opciones-paciente">
            <?php foreach ($pacientes as $p): ?>
                <label class="opcion-paciente <?= $p['id_paciente'] == $id_paciente ? 'marcada' : '' ?>">
                    <input type="radio" name="paciente" value="<?= $p['id_paciente'] ?>"
                           <?= $p['id_paciente'] == $id_paciente ? 'checked' : '' ?> onchange="this.form.submit()">
                    <span>
                        <strong><?= $p['tipo'] === 'TITULAR' ? 'Titular' : 'Menor a cargo' ?></strong>
                        <?= h($p['nombres'] . ' ' . $p['apellidos']) ?><br>
                        <small>Documento: <?= h($p['numero_documento']) ?></small>
                    </span>
                </label>
            <?php endforeach; ?>

            <?php foreach ($pendientes as $p): ?>
                <div class="opcion-paciente bloqueada">
                    <span class="icono">lock</span>
                    <span>
                        <strong><?= h($p['nombres'] . ' ' . $p['apellidos']) ?></strong>
                        <?= etiqueta_estado('PENDIENTE') ?><br>
                        <small>Falta validar el vínculo en ventanilla.</small>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (!$es_recepcion && !$pacientes): ?>
            <p class="vacio">No tiene pacientes habilitados para reservar.</p>
        <?php endif; ?>
    </section>

    <!-- Criterios de atención -->
    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">tune</span>Criterios de atención</h2>
        <div class="fila-campos">
            <div class="campo">
                <label for="especialidad">Especialidad médica</label>
                <select id="especialidad" name="especialidad" onchange="this.form.fecha.value='';this.form.submit()">
                    <?php foreach ($especialidades as $e): ?>
                        <option value="<?= $e['id_especialidad'] ?>" <?= $e['id_especialidad'] == $id_especialidad ? 'selected' : '' ?>>
                            <?= h($e['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label for="fecha">Fecha de atención</label>
                <select id="fecha" name="fecha" onchange="this.form.submit()">
                    <?php foreach ($fechas as $f): ?>
                        <option value="<?= $f ?>" <?= $f === $fecha ? 'selected' : '' ?>><?= h(ucfirst(fecha_larga($f))) ?></option>
                    <?php endforeach; ?>
                    <?php if (!$fechas): ?><option value="">Sin fechas programadas</option><?php endif; ?>
                </select>
            </div>
            <div class="campo">
                <label>Turno</label>
                <div class="segmentado">
                    <label><input type="radio" name="turno" value="" <?= $id_turno === '' ? 'checked' : '' ?> onchange="this.form.submit()">Todos</label>
                    <?php foreach ($turnos as $t): ?>
                        <label><input type="radio" name="turno" value="<?= $t['id_turno'] ?>"
                                      <?= $id_turno == $t['id_turno'] ? 'checked' : '' ?> onchange="this.form.submit()">
                            <?= $t['nombre'] === 'MANANA' ? 'Mañana' : 'Tarde' ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <noscript><button class="boton" type="submit">Buscar horarios</button></noscript>
    </section>
</form>

<div class="dos-columnas">
    <!-- Médicos y horarios -->
    <section>
        <h2 class="tarjeta-titulo">Médicos y cupos disponibles (<?= count($medicos) ?>)</h2>

        <?php if ($error_busqueda): ?>
            <div class="aviso aviso-error"><?= h($error_busqueda) ?></div>
        <?php elseif (!$fechas): ?>
            <div class="tarjeta vacio"><span class="icono">event_busy</span>
                No hay horarios programados para <?= h($nombre_especialidad) ?> en los próximos 30 días.</div>
        <?php elseif (!$id_paciente): ?>
            <div class="tarjeta vacio"><span class="icono">person_search</span>Primero elija al paciente.</div>
        <?php elseif (!$medicos): ?>
            <div class="tarjeta vacio"><span class="icono">event_busy</span><strong>Cupos agotados</strong><br>
                Pruebe con otro turno u otra fecha.</div>
        <?php endif; ?>

        <?php foreach ($medicos as $m): $d = $m['datos']; ?>
            <div class="medico-card">
                <div class="medico-cabecera">
                    <div style="display:flex;gap:12px">
                        <span class="medico-foto"><span class="icono">stethoscope</span></span>
                        <div>
                            <h3><?= h($d['medico']) ?></h3>
                            <span class="esp"><?= h($d['especialidad']) ?></span><br>
                            <small><span class="icono" style="font-size:15px">meeting_room</span>
                                <?= h($d['consultorio']) ?> · <?= h($d['ubicacion']) ?></small>
                        </div>
                    </div>
                    <span class="etiqueta etiqueta-verde"><?= count($m['cupos']) ?> horarios libres</span>
                </div>
                <p style="margin:0 0 8px;color:var(--texto-2)">Seleccione un horario de 15 minutos:</p>
                <form method="post" class="horas">
                    <input type="hidden" name="paciente" value="<?= $id_paciente ?>">
                    <input type="hidden" name="volver" value="<?= h($volver) ?>">
                    <?php foreach ($m['cupos'] as $c): ?>
                        <button class="hora-boton" type="submit" name="id_cupo" value="<?= $c['id_cupo'] ?>"
                                title="<?= hora($c['hora_inicio']) ?> a <?= hora($c['hora_fin']) ?>">
                            <?= hora($c['hora_inicio']) ?>
                        </button>
                    <?php endforeach; ?>
                </form>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- Resumen y avisos -->
    <aside>
        <div class="tarjeta">
            <h2 class="tarjeta-titulo">Resumen de selección</h2>
            <div class="dato"><span>Paciente</span>
                <strong><?= $id_paciente ? h($pacientes[$id_paciente]['nombres'] . ' ' . $pacientes[$id_paciente]['apellidos']) : '—' ?></strong></div>
            <div class="dato"><span>Especialidad</span><strong><?= h($nombre_especialidad) ?></strong></div>
            <div class="dato"><span>Fecha</span><strong><?= h(ucfirst(fecha_larga($fecha))) ?></strong></div>
        </div>
        <div class="tarjeta tarjeta-azul">
            <h2 class="tarjeta-titulo"><span class="icono">info</span>Avisos del servicio</h2>
            <p style="margin:0 0 8px"><strong>Gratuito:</strong> la reserva no tiene ningún costo.</p>
            <p style="margin:0 0 8px"><strong>Tolerancia:</strong> registre su llegada en recepción hasta 1 minuto después de la hora.</p>
            <p style="margin:0 0 8px"><strong>Cancelaciones:</strong> con 24 horas o más de anticipación.</p>
            <p style="margin:0"><strong>Una cita activa</strong> por especialidad.</p>
        </div>
    </aside>
</div>

<?php require 'includes/footer.php'; ?>
