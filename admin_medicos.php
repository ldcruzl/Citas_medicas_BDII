<?php
// =====================================================
// admin_medicos.php - Gestión de médicos.
// Lista los médicos (vista vw_reporte_resumen_medicos),
// registra nuevos y los activa o desactiva.
// Desactivar impide nuevas reservas, pero no cancela
// las citas que ya tiene.
// =====================================================
require 'config.php';
requerir_rol('ADMINISTRADOR');

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($_POST['accion'] === 'estado') {
            $pdo->prepare("UPDATE medico SET estado = IF(estado = 'ACTIVO', 'INACTIVO', 'ACTIVO') WHERE id_medico = ?")
                ->execute([(int) $_POST['medico']]);
            mensaje('exito', 'Se actualizó el estado del médico.');
            ir_a('admin_medicos.php');
        }

        if ($_POST['accion'] === 'nuevo') {
            $p = array_map('trim', $_POST);
            if (strlen($p['clave']) < 8) {
                throw new Exception('La contraseña inicial debe tener al menos 8 caracteres.');
            }

            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO persona (tipo_documento, numero_documento, nombres, apellidos, fecha_nacimiento, correo, telefono)
                           VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$p['tipo_documento'], $p['numero_documento'], $p['nombres'], $p['apellidos'],
                           $p['fecha_nacimiento'], $p['correo'] ?: null, $p['telefono'] ?: null]);
            $id_persona = $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO usuario (id_persona, nombre_usuario, password_hash) VALUES (?, ?, ?)')
                ->execute([$id_persona, $p['nombre_usuario'], password_hash($p['clave'], PASSWORD_DEFAULT)]);
            $id_usuario = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO usuario_rol (id_usuario, id_rol) SELECT ?, id_rol FROM rol WHERE nombre = 'MEDICO'")
                ->execute([$id_usuario]);

            $pdo->prepare('INSERT INTO medico (id_persona, id_especialidad) VALUES (?, ?)')
                ->execute([$id_persona, (int) $p['especialidad']]);

            $pdo->commit();
            mensaje('exito', 'Médico registrado. Ahora genere sus cupos en «Horarios y cupos».');
            ir_a('admin_medicos.php');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errores[] = $e instanceof PDOException ? mensaje_error($e) : $e->getMessage();
    }
}

$medicos = $pdo->query('SELECT * FROM vw_reporte_resumen_medicos ORDER BY especialidad, medico_nombre_completo')->fetchAll();
$especialidades = $pdo->query("SELECT id_especialidad, nombre FROM especialidad WHERE estado = 'ACTIVO' ORDER BY nombre")->fetchAll();

$titulo = 'Médicos';
$activo = 'medicos';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">admin_panel_settings</span> Administración</p>
        <h1>Gestión de médicos</h1>
        <p class="sub">Cada médico tiene una especialidad. Desactivarlo impide nuevas reservas,
            pero no cancela sus citas existentes.</p>
    </div>
</div>

<div class="dos-columnas">
    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">medical_services</span>Médicos registrados (<?= count($medicos) ?>)</h2>
        <div class="tabla-contenedor">
            <table class="tabla">
                <tr><th>Médico</th><th>Estado</th><th class="num">Cupos</th><th class="num">Disponibles</th><th class="num">Citas activas</th><th></th></tr>
                <?php foreach ($medicos as $m): ?>
                    <tr>
                        <td><strong><?= h($m['medico_nombre_completo']) ?></strong><span class="secundario"><?= h($m['especialidad']) ?></span></td>
                        <td><?= etiqueta_estado($m['estado_medico']) ?></td>
                        <td class="num"><?= $m['total_cupos_generados'] ?></td>
                        <td class="num"><?= $m['cupos_disponibles'] ?></td>
                        <td class="num"><?= $m['citas_activas_ocupadas'] ?></td>
                        <td>
                            <form method="post"
                                  onsubmit="return <?= $m['estado_medico'] === 'ACTIVO' && $m['citas_activas_ocupadas'] > 0
                                      ? "confirm('Este médico tiene " . (int) $m['citas_activas_ocupadas'] . " citas activas. Se conservarán. ¿Desactivar?')"
                                      : 'true' ?>">
                                <input type="hidden" name="medico" value="<?= $m['id_medico'] ?>">
                                <button class="boton boton-chico <?= $m['estado_medico'] === 'ACTIVO' ? 'boton-peligro' : 'boton-exito' ?>"
                                        name="accion" value="estado"><?= $m['estado_medico'] === 'ACTIVO' ? 'Desactivar' : 'Activar' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </section>

    <aside class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">person_add</span>Registrar médico</h2>
        <?php foreach ($errores as $err): ?>
            <div class="aviso aviso-error"><?= h($err) ?></div>
        <?php endforeach; ?>
        <form method="post">
            <input type="hidden" name="tipo_documento" value="DNI">
            <div class="campo"><label>DNI</label><input name="numero_documento" required pattern="\d{8}" maxlength="8"></div>
            <div class="campo"><label>Nombres</label><input name="nombres" required></div>
            <div class="campo"><label>Apellidos</label><input name="apellidos" required></div>
            <div class="campo"><label>Fecha de nacimiento</label><input type="date" name="fecha_nacimiento" required></div>
            <div class="campo"><label>Correo</label><input type="email" name="correo"></div>
            <div class="campo"><label>Celular</label><input name="telefono"></div>
            <div class="campo"><label>Especialidad</label>
                <select name="especialidad">
                    <?php foreach ($especialidades as $e): ?>
                        <option value="<?= $e['id_especialidad'] ?>"><?= h($e['nombre']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="campo"><label>Usuario</label><input name="nombre_usuario" required></div>
            <div class="campo"><label>Contraseña inicial</label><input type="password" name="clave" required minlength="8"></div>
            <button class="boton boton-bloque" name="accion" value="nuevo">Registrar médico</button>
        </form>
    </aside>
</div>

<?php require 'includes/footer.php'; ?>
