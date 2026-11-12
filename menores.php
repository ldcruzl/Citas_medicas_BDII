<?php
// =====================================================
// menores.php - Menores a mi cargo.
// El apoderado registra al menor; el vínculo queda
// PENDIENTE hasta que recepción lo valide en ventanilla.
// =====================================================
require 'config.php';
requerir_rol('PACIENTE', 'APODERADO');

$u = usuario();
$errores = [];
$d = ['tipo_documento' => 'DNI', 'numero_documento' => '', 'nombres' => '', 'apellidos' => '',
      'fecha_nacimiento' => '', 'parentesco' => 'MADRE'];

// ---------- Registrar menor ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($d as $campo => $v) {
        $d[$campo] = trim($_POST[$campo] ?? '');
    }

    if ($d['numero_documento'] === '' || $d['nombres'] === '' || $d['apellidos'] === '') {
        $errores[] = 'Complete el documento, los nombres y los apellidos del menor.';
    }
    if (!$d['fecha_nacimiento'] || !es_menor($d['fecha_nacimiento'])) {
        $errores[] = 'La fecha de nacimiento debe corresponder a un menor de 18 años.';
    }

    if (!$errores) {
        try {
            $pdo->beginTransaction();

            // Persona del menor (si ya existe se reutiliza).
            $st = $pdo->prepare('SELECT id_persona FROM persona WHERE tipo_documento = ? AND numero_documento = ?');
            $st->execute([$d['tipo_documento'], $d['numero_documento']]);
            $id_persona = $st->fetchColumn();
            if (!$id_persona) {
                $pdo->prepare('INSERT INTO persona (tipo_documento, numero_documento, nombres, apellidos, fecha_nacimiento)
                               VALUES (?, ?, ?, ?, ?)')
                    ->execute([$d['tipo_documento'], $d['numero_documento'], $d['nombres'], $d['apellidos'], $d['fecha_nacimiento']]);
                $id_persona = $pdo->lastInsertId();
            }

            // Registro de paciente.
            $pdo->prepare('INSERT IGNORE INTO paciente (id_persona) VALUES (?)')->execute([$id_persona]);
            $st = $pdo->prepare('SELECT id_paciente FROM paciente WHERE id_persona = ?');
            $st->execute([$id_persona]);
            $id_paciente = $st->fetchColumn();

            // No repetir una solicitud que ya existe.
            $st = $pdo->prepare("SELECT COUNT(*) FROM vinculo_apoderado
                                 WHERE id_paciente = ? AND id_persona_apoderado = ? AND estado IN ('PENDIENTE', 'VALIDADO')");
            $st->execute([$id_paciente, $u['id_persona']]);
            if ($st->fetchColumn() > 0) {
                throw new Exception('Ya registró a este menor.');
            }

            $pdo->prepare("INSERT INTO vinculo_apoderado (id_paciente, id_persona_apoderado, parentesco, estado)
                           VALUES (?, ?, ?, 'PENDIENTE')")
                ->execute([$id_paciente, $u['id_persona'], $d['parentesco']]);

            // La cuenta pasa a tener también el rol de apoderado.
            $pdo->prepare("INSERT IGNORE INTO usuario_rol (id_usuario, id_rol)
                           SELECT ?, id_rol FROM rol WHERE nombre = 'APODERADO'")
                ->execute([$u['id_usuario']]);

            $pdo->commit();

            if (!in_array('APODERADO', $_SESSION['usuario']['roles'])) {
                $_SESSION['usuario']['roles'][] = 'APODERADO';
            }
            mensaje('exito', 'Solicitud registrada. Acérquese a ventanilla para validar el vínculo.');
            ir_a('menores.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $errores[] = $e instanceof PDOException ? mensaje_error($e) : $e->getMessage();
        }
    }
}

// ---------- Menores registrados ----------
$st = $pdo->prepare(
    "SELECT va.id_vinculo, va.estado, va.parentesco, va.fecha_registro, va.fecha_validacion, va.motivo_fin,
            pe.nombres, pe.apellidos, pe.tipo_documento, pe.numero_documento, pe.fecha_nacimiento
     FROM vinculo_apoderado va
     JOIN paciente pa ON pa.id_paciente = va.id_paciente
     JOIN persona pe ON pe.id_persona = pa.id_persona
     WHERE va.id_persona_apoderado = ?
     ORDER BY va.estado = 'INACTIVO', pe.nombres");
$st->execute([$u['id_persona']]);
$menores = $st->fetchAll();

$titulo = 'Menores a mi cargo';
$activo = 'menores';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">family_restroom</span> Representación de menores</p>
        <h1>Menores a mi cargo</h1>
        <p class="sub">Solo puede reservar citas para un menor cuando su vínculo está validado en ventanilla.</p>
    </div>
</div>

<div class="dos-columnas">
    <section>
        <?php if (!$menores): ?>
            <div class="tarjeta vacio"><span class="icono">child_care</span>Todavía no registró menores.</div>
        <?php endif; ?>
        <div class="grid-2">
            <?php foreach ($menores as $m): ?>
                <div class="tarjeta">
                    <div class="medico-cabecera">
                        <div>
                            <h3><?= h($m['nombres'] . ' ' . $m['apellidos']) ?></h3>
                            <small><?= h($m['tipo_documento']) ?> <?= h($m['numero_documento']) ?> ·
                                <?= edad($m['fecha_nacimiento']) ?> años</small>
                        </div>
                        <?= etiqueta_estado($m['estado']) ?>
                    </div>
                    <div class="dato"><span>Parentesco</span><strong><?= h(ucfirst(strtolower($m['parentesco']))) ?></strong></div>
                    <?php if ($m['estado'] === 'VALIDADO'): ?>
                        <p style="margin:0 0 10px;color:var(--texto-2)">Validado el <?= date('d/m/Y', strtotime($m['fecha_validacion'])) ?>.</p>
                        <a class="boton boton-chico" href="reservar.php">Reservar cita</a>
                    <?php elseif ($m['estado'] === 'PENDIENTE'): ?>
                        <div class="aviso aviso-alerta" style="margin:0">Pendiente de validación presencial. Lleve el DNI del menor,
                            su DNI y la partida de nacimiento.</div>
                    <?php else: ?>
                        <p style="margin:0;color:var(--texto-2)">Vínculo inactivo: <?= h($m['motivo_fin']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">person_add</span>Registrar menor a cargo</h2>
        <?php foreach ($errores as $err): ?>
            <div class="aviso aviso-error"><?= h($err) ?></div>
        <?php endforeach; ?>
        <form method="post">
            <div class="campo">
                <label>Tipo de documento</label>
                <select name="tipo_documento">
                    <option value="DNI">DNI</option>
                    <option value="CE" <?= $d['tipo_documento'] === 'CE' ? 'selected' : '' ?>>Carné de extranjería</option>
                    <option value="PASAPORTE" <?= $d['tipo_documento'] === 'PASAPORTE' ? 'selected' : '' ?>>Pasaporte</option>
                </select>
            </div>
            <div class="campo"><label>Número de documento</label>
                <input name="numero_documento" value="<?= h($d['numero_documento']) ?>" required maxlength="20"></div>
            <div class="campo"><label>Nombres</label>
                <input name="nombres" value="<?= h($d['nombres']) ?>" required maxlength="100"></div>
            <div class="campo"><label>Apellidos</label>
                <input name="apellidos" value="<?= h($d['apellidos']) ?>" required maxlength="100"></div>
            <div class="campo"><label>Fecha de nacimiento</label>
                <input type="date" name="fecha_nacimiento" value="<?= h($d['fecha_nacimiento']) ?>" required></div>
            <div class="campo">
                <label>Parentesco</label>
                <select name="parentesco">
                    <?php foreach (['MADRE', 'PADRE', 'TUTOR LEGAL', 'ABUELO(A)', 'OTRO'] as $p): ?>
                        <option <?= $d['parentesco'] === $p ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="boton boton-bloque" type="submit">Enviar solicitud</button>
        </form>
    </aside>
</div>

<?php require 'includes/footer.php'; ?>
