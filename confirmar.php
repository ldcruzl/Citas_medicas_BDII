<?php
// =====================================================
// confirmar.php - Paso 2: revisar y confirmar la cita.
// Muestra el horario separado (bloqueo de 5 minutos) y
// al confirmar llama a sp_confirmar_cita.
// =====================================================
require 'config.php';
requerir_rol('PACIENTE', 'APODERADO', 'RECEPCIONISTA');

$u = usuario();
$id_bloqueo = (int) ($_GET['bloqueo'] ?? $_POST['bloqueo'] ?? 0);

// ---------- Confirmar ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        llamar($pdo, 'CALL sp_confirmar_cita(?, ?, @cita)', [$u['id_usuario'], $id_bloqueo]);
        $id_cita = variable_salida($pdo, 'cita');
        ir_a('cita_confirmada.php?id=' . (int) $id_cita);
    } catch (PDOException $e) {
        mensaje('error', mensaje_error($e));
        ir_a('confirmar.php?bloqueo=' . $id_bloqueo);
    }
}

// ---------- Datos del horario separado ----------
$st = $pdo->prepare(
    "SELECT br.id_bloqueo, br.estado, br.fecha_expiracion, br.fecha_expiracion > NOW() AS vigente,
            c.fecha, c.hora_inicio, c.hora_fin,
            co.codigo AS consultorio, co.ubicacion,
            e.nombre AS especialidad,
            CONCAT(pm.nombres, ' ', pm.apellidos) AS medico,
            pp.nombres, pp.apellidos, pp.tipo_documento, pp.numero_documento, pp.fecha_nacimiento
     FROM bloqueo_reserva br
     JOIN cupo c          ON c.id_cupo = br.id_cupo
     JOIN medico m        ON m.id_medico = c.id_medico
     JOIN persona pm      ON pm.id_persona = m.id_persona
     JOIN especialidad e  ON e.id_especialidad = m.id_especialidad
     JOIN consultorio co  ON co.id_consultorio = c.id_consultorio
     JOIN paciente pa     ON pa.id_paciente = br.id_paciente
     JOIN persona pp      ON pp.id_persona = pa.id_persona
     WHERE br.id_bloqueo = ? AND br.id_usuario = ?");
$st->execute([$id_bloqueo, $u['id_usuario']]);
$b = $st->fetch();

if (!$b) {
    mensaje('error', 'No se encontró el horario seleccionado.');
    ir_a('reservar.php');
}

$puede_confirmar = $b['estado'] === 'ACTIVO' && $b['vigente'];
$edad = edad($b['fecha_nacimiento'], $b['fecha']);

$titulo = 'Revisar y confirmar';
$activo = 'reservar';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">fact_check</span> Paso 2 de 2 · Confirmación</p>
        <h1>Revisar y confirmar cita médica</h1>
        <p class="sub">Revise los datos. La cita solo queda registrada al presionar «Confirmar cita».</p>
    </div>
    <?php if ($puede_confirmar): ?>
        <div class="aviso aviso-alerta" style="margin:0">
            <span class="icono">timer</span>
            Horario separado hasta las <strong><?= date('H:i', strtotime($b['fecha_expiracion'])) ?></strong>
            (<span id="cuenta" data-fin="<?= strtotime($b['fecha_expiracion']) ?>">5:00</span> restantes)
        </div>
    <?php endif; ?>
</div>

<?php if (!$puede_confirmar): ?>
    <div class="aviso aviso-error">
        Este horario ya no está separado (el tiempo de 5 minutos terminó o la selección se cambió).
        <a href="reservar.php">Elegir otro horario</a>.
    </div>
<?php endif; ?>

<div class="dos-columnas">
    <section>
        <div class="tarjeta">
            <h2 class="tarjeta-titulo"><span class="icono">person</span>Paciente</h2>
            <div class="grid-3">
                <div class="dato"><span>Nombre</span><strong><?= h($b['nombres'] . ' ' . $b['apellidos']) ?></strong></div>
                <div class="dato"><span><?= h($b['tipo_documento']) ?></span><strong><?= h($b['numero_documento']) ?></strong></div>
                <div class="dato"><span>Edad en la fecha de la cita</span><strong><?= $edad ?> años</strong>
                    <?php if ($edad < 18): ?><small>Menor: se atiende con su apoderado.</small><?php endif; ?></div>
            </div>
        </div>

        <div class="tarjeta">
            <h2 class="tarjeta-titulo"><span class="icono">event</span>Detalle del turno</h2>
            <div class="grid-2">
                <div class="dato"><span>Fecha</span><strong><?= h(ucfirst(fecha_larga($b['fecha']))) ?></strong></div>
                <div class="dato"><span>Hora</span><strong><?= hora($b['hora_inicio']) ?> – <?= hora($b['hora_fin']) ?></strong></div>
                <div class="dato"><span>Especialidad</span><strong><?= h($b['especialidad']) ?></strong></div>
                <div class="dato"><span>Médico</span><strong><?= h($b['medico']) ?></strong></div>
                <div class="dato"><span>Consultorio</span><strong><?= h($b['consultorio']) ?></strong>
                    <small><?= h($b['ubicacion']) ?></small></div>
                <div class="dato"><span>Sede</span><strong><?= h(SEDE_NOMBRE) ?></strong>
                    <small><?= h(SEDE_DIRECCION) ?></small></div>
            </div>
        </div>

        <div class="tarjeta tarjeta-azul">
            <h2 class="tarjeta-titulo"><span class="icono">rule</span>Al confirmar, el sistema vuelve a comprobar</h2>
            <ul style="margin:0;padding-left:18px">
                <li>Que el horario esté cargado y siga libre.</li>
                <li>Que el paciente no tenga otra cita activa en <?= h($b['especialidad']) ?>.</li>
                <li>Que no se cruce con otra cita del paciente.</li>
                <li>Que, si es menor, lo reserve su apoderado validado o recepción.</li>
            </ul>
        </div>
    </section>

    <aside>
        <div class="tarjeta">
            <h2 class="tarjeta-titulo">Confirmación</h2>
            <form method="post">
                <input type="hidden" name="bloqueo" value="<?= $id_bloqueo ?>">
                <button class="boton boton-bloque" type="submit" <?= $puede_confirmar ? '' : 'disabled' ?>>
                    <span class="icono">check_circle</span>Confirmar cita
                </button>
            </form>
            <p style="margin:12px 0 0;text-align:center"><a href="reservar.php">Volver y elegir otro horario</a></p>
        </div>
        <div class="tarjeta">
            <p style="margin:0;color:var(--texto-2)">Le enviaremos la confirmación al correo registrado.
                Preséntese con su documento de identidad.</p>
        </div>
    </aside>
</div>

<script>
// Cuenta regresiva de los 5 minutos del bloqueo.
var cuenta = document.getElementById('cuenta');
if (cuenta) {
    var fin = parseInt(cuenta.dataset.fin, 10) * 1000;
    setInterval(function () {
        var resto = Math.max(0, Math.floor((fin - Date.now()) / 1000));
        cuenta.textContent = Math.floor(resto / 60) + ':' + String(resto % 60).padStart(2, '0');
    }, 1000);
}
</script>

<?php require 'includes/footer.php'; ?>
