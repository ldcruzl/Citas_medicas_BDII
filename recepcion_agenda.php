<?php
// =====================================================
// recepcion_agenda.php - Agenda de recepción.
// Lista las citas del día, registra la llegada
// (sp_registrar_llegada) y marca citas no atendidas.
// =====================================================
require 'config.php';
requerir_rol('RECEPCIONISTA');

$u = usuario();

// ---------- Acciones ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_cita = (int) $_POST['cita'];
    $volver  = 'recepcion_agenda.php?' . ($_POST['volver'] ?? '');

    try {
        if ($_POST['accion'] === 'llegada') {
            llamar($pdo, 'CALL sp_registrar_llegada(?, ?)', [$u['id_usuario'], $id_cita]);
            mensaje('exito', 'Llegada registrada. El paciente quedó en espera.');
        }

        if ($_POST['accion'] === 'no_atendida') {
            $motivo = $_POST['motivo'] ?? 'Inasistencia';
            $c = obtener_cita($pdo, $id_cita);
            $tolerancia = strtotime($c['fecha'] . ' ' . $c['hora_inicio'] . ' +1 minute');

            if ($c['estado'] === 'CONFIRMADA' && time() <= $tolerancia) {
                throw new Exception('Todavía está dentro de la tolerancia: espere a que pase 1 minuto de la hora.');
            }

            $pdo->beginTransaction();
            datos_historial($pdo, $u['id_usuario'], 'RECEPCION', $motivo);
            // El trigger valida que el cambio de estado esté permitido.
            $pdo->prepare("UPDATE cita SET estado = 'NO_ATENDIDA', motivo_cierre = ? WHERE id_cita = ?")
                ->execute([$motivo, $id_cita]);
            $pdo->prepare("UPDATE asignacion_cita SET estado = 'FINALIZADA', fecha_fin = NOW()
                           WHERE id_cita = ? AND estado = 'VIGENTE'")
                ->execute([$id_cita]);
            limpiar_historial($pdo);
            $pdo->commit();
            mensaje('exito', 'La cita quedó como no atendida (' . $motivo . ').');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        limpiar_historial($pdo);
        mensaje('error', $e instanceof PDOException ? mensaje_error($e) : $e->getMessage());
    }
    ir_a($volver);
}

// ---------- Filtros ----------
$fecha    = $_GET['fecha'] ?? date('Y-m-d');
$turno    = $_GET['turno'] ?? '';
$busqueda = trim($_GET['buscar'] ?? '');

$sql = sql_citas() . " WHERE cu.fecha = ? AND ci.estado NOT IN ('CANCELADA', 'PENDIENTE_REPROGRAMACION')";
$parametros = [$fecha];
if ($turno === 'MANANA') {
    $sql .= " AND cu.hora_inicio < '13:00:00'";
} elseif ($turno === 'TARDE') {
    $sql .= " AND cu.hora_inicio >= '14:00:00'";
}
if ($busqueda !== '') {
    $sql .= ' AND (pp.numero_documento = ? OR pp.apellidos LIKE ?)';
    $parametros[] = $busqueda;
    $parametros[] = $busqueda . '%';
}
$sql .= ' ORDER BY cu.hora_inicio, medico';

$st = $pdo->prepare($sql);
$st->execute($parametros);
$citas = $st->fetchAll();

// Apoderados de los menores (para mostrarlos en la lista).
$apoderados = [];
foreach ($pdo->query("SELECT va.id_vinculo, CONCAT(pe.nombres, ' ', pe.apellidos) AS nombre
                      FROM vinculo_apoderado va JOIN persona pe ON pe.id_persona = va.id_persona_apoderado") as $f) {
    $apoderados[$f['id_vinculo']] = $f['nombre'];
}

// Indicadores del día.
$cuenta = ['total' => count($citas), 'EN_ESPERA' => 0, 'EN_ATENCION' => 0, 'ATENDIDA' => 0, 'NO_ATENDIDA' => 0];
foreach ($citas as $c) {
    if (isset($cuenta[$c['estado']])) {
        $cuenta[$c['estado']]++;
    }
}

// Próxima fecha con citas (útil cuando hoy no hay ninguna).
$proxima = $pdo->query("SELECT MIN(cu.fecha) FROM asignacion_cita ac JOIN cupo cu ON cu.id_cupo = ac.id_cupo
                        WHERE ac.estado = 'VIGENTE' AND cu.fecha > CURDATE()")->fetchColumn();

$volver = http_build_query(['fecha' => $fecha, 'turno' => $turno, 'buscar' => $busqueda]);

$titulo = 'Agenda de recepción';
$activo = 'agenda';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">desk</span> Recepción · Consultorios externos</p>
        <h1>Agenda de recepción y admisión</h1>
        <p class="sub">Registro de llegada de pacientes y control de tolerancia (1 minuto después de la hora).</p>
    </div>
    <a class="boton" href="reservar.php"><span class="icono">edit_calendar</span>Registrar cita</a>
</div>

<div class="grid-4" style="margin-bottom:20px">
    <div class="kpi"><span>Citas del día</span><strong><?= $cuenta['total'] ?></strong></div>
    <div class="kpi"><span>En sala de espera</span><strong><?= $cuenta['EN_ESPERA'] ?></strong></div>
    <div class="kpi"><span>En consultorio</span><strong><?= $cuenta['EN_ATENCION'] ?></strong><small><?= $cuenta['ATENDIDA'] ?> atendidas</small></div>
    <div class="kpi"><span>No atendidas</span><strong style="color:var(--peligro-oscuro)"><?= $cuenta['NO_ATENDIDA'] ?></strong></div>
</div>

<form method="get" class="tarjeta">
    <div class="fila-campos">
        <div class="campo"><label>Fecha</label><input type="date" name="fecha" value="<?= h($fecha) ?>"></div>
        <div class="campo">
            <label>Turno</label>
            <select name="turno">
                <option value="">Todos</option>
                <option value="MANANA" <?= $turno === 'MANANA' ? 'selected' : '' ?>>Mañana (08:00 – 13:00)</option>
                <option value="TARDE" <?= $turno === 'TARDE' ? 'selected' : '' ?>>Tarde (14:00 – 19:00)</option>
            </select>
        </div>
        <div class="campo"><label>Buscar paciente</label><input name="buscar" value="<?= h($busqueda) ?>" placeholder="DNI o apellido"></div>
        <div class="campo"><button class="boton" type="submit"><span class="icono">search</span>Filtrar</button></div>
    </div>
</form>

<section class="tarjeta">
    <h2 class="tarjeta-titulo"><span class="icono">list</span>Citas programadas · <?= h(ucfirst(fecha_larga($fecha))) ?></h2>

    <?php if (!$citas): ?>
        <div class="vacio"><span class="icono">event_available</span>No hay citas para esta fecha.
            <?php if ($proxima): ?><br><a href="?fecha=<?= $proxima ?>">Ir a la próxima fecha con citas (<?= fecha_corta($proxima) ?>)</a><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <tr><th>Hora / tolerancia</th><th>Paciente</th><th>Médico</th><th>Consultorio</th><th>Llegada</th><th>Estado</th><th>Acciones</th></tr>
                <?php foreach ($citas as $c):
                    $tolerancia = strtotime($c['fecha'] . ' ' . $c['hora_inicio'] . ' +1 minute'); ?>
                    <tr>
                        <td><strong><?= hora($c['hora_inicio']) ?></strong>
                            <span class="secundario">hasta <?= date('H:i', $tolerancia) ?></span></td>
                        <td><strong><?= h($c['nombres'] . ' ' . $c['apellidos']) ?></strong>
                            <span class="secundario">DNI <?= h($c['numero_documento']) ?>
                                <?php if ($c['id_vinculo_apoderado']): ?> · Menor, apod.: <?= h($apoderados[$c['id_vinculo_apoderado']] ?? '') ?><?php endif; ?>
                            </span></td>
                        <td><?= h($c['medico']) ?><span class="secundario"><?= h($c['especialidad']) ?></span></td>
                        <td><?= h($c['consultorio']) ?><span class="secundario"><?= h($c['ubicacion']) ?></span></td>
                        <td><?= $c['hora_llegada'] ? date('H:i', strtotime($c['hora_llegada'])) : '<span class="secundario">Pendiente</span>' ?></td>
                        <td><?= etiqueta_estado($c['estado']) ?>
                            <?php if ($c['motivo_cierre']): ?><span class="secundario"><?= h($c['motivo_cierre']) ?></span><?php endif; ?></td>
                        <td>
                            <div class="acciones">
                                <?php if ($c['estado'] === 'CONFIRMADA'): ?>
                                    <form method="post" class="en-linea">
                                        <input type="hidden" name="cita" value="<?= $c['id_cita'] ?>">
                                        <input type="hidden" name="volver" value="<?= h($volver) ?>">
                                        <button class="boton boton-chico" name="accion" value="llegada"><span class="icono">how_to_reg</span>Registrar llegada</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (in_array($c['estado'], ['CONFIRMADA', 'EN_ESPERA'])): ?>
                                    <form method="post" class="en-linea acciones">
                                        <input type="hidden" name="cita" value="<?= $c['id_cita'] ?>">
                                        <input type="hidden" name="volver" value="<?= h($volver) ?>">
                                        <select name="motivo" style="padding:5px;border-radius:6px;border:1px solid var(--borde-2)">
                                            <option>Inasistencia</option>
                                            <option>Tardanza</option>
                                            <option>Retiro del paciente</option>
                                        </select>
                                        <button class="boton boton-peligro boton-chico" name="accion" value="no_atendida">No atendida</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($c['estado'] === 'PENDIENTE_SUSTITUCION'): ?>
                                    <a class="boton boton-secundario boton-chico" href="sustituto.php?cita=<?= $c['id_cita'] ?>">Registrar respuesta</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require 'includes/footer.php'; ?>
