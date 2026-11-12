<?php
// =====================================================
// registro.php - Crear cuenta de paciente
// Guarda la persona, la cuenta, el rol PACIENTE y el
// registro de paciente en una sola transacción.
// =====================================================
require 'config.php';

$d = [
    'tipo_documento' => 'DNI', 'numero_documento' => '', 'nombres' => '', 'apellidos' => '',
    'fecha_nacimiento' => '', 'correo' => '', 'telefono' => '', 'nombre_usuario' => '',
];
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($d as $campo => $v) {
        $d[$campo] = trim($_POST[$campo] ?? '');
    }
    $clave     = $_POST['clave'] ?? '';
    $clave2    = $_POST['clave2'] ?? '';
    $whatsapp  = isset($_POST['whatsapp']) ? 1 : 0;

    // Validaciones básicas.
    if ($d['tipo_documento'] === 'DNI' && !preg_match('/^\d{8}$/', $d['numero_documento'])) {
        $errores[] = 'El DNI debe tener 8 dígitos.';
    }
    if ($d['numero_documento'] === '' || $d['nombres'] === '' || $d['apellidos'] === '') {
        $errores[] = 'Complete documento, nombres y apellidos.';
    }
    if (!$d['fecha_nacimiento'] || (int) date_diff(date_create($d['fecha_nacimiento']), date_create('today'))->y < 18) {
        $errores[] = 'Debe ser mayor de edad. Los menores se registran desde la cuenta de su apoderado.';
    }
    if (!filter_var($d['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'Ingrese un correo electrónico válido.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.]{4,50}$/', $d['nombre_usuario'])) {
        $errores[] = 'El usuario debe tener entre 4 y 50 letras, números, punto o guion bajo.';
    }
    if (strlen($clave) < 8 || $clave !== $clave2) {
        $errores[] = 'La contraseña debe tener al menos 8 caracteres y coincidir en ambos campos.';
    }

    if (!$errores) {
        try {
            $pdo->beginTransaction();

            // ¿La persona ya existe? (por ejemplo, la registró recepción antes)
            $st = $pdo->prepare('SELECT id_persona FROM persona WHERE tipo_documento = ? AND numero_documento = ?');
            $st->execute([$d['tipo_documento'], $d['numero_documento']]);
            $id_persona = $st->fetchColumn();

            if ($id_persona) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM usuario WHERE id_persona = ?');
                $st->execute([$id_persona]);
                if ($st->fetchColumn() > 0) {
                    throw new Exception('Ya existe una cuenta para ese documento.');
                }
                $pdo->prepare('UPDATE persona SET correo = ?, telefono = ? WHERE id_persona = ?')
                    ->execute([$d['correo'], $d['telefono'] ?: null, $id_persona]);
            } else {
                $pdo->prepare(
                    'INSERT INTO persona (tipo_documento, numero_documento, nombres, apellidos, fecha_nacimiento, correo, telefono)
                     VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$d['tipo_documento'], $d['numero_documento'], $d['nombres'], $d['apellidos'],
                               $d['fecha_nacimiento'], $d['correo'], $d['telefono'] ?: null]);
                $id_persona = $pdo->lastInsertId();
            }

            // Cuenta con contraseña cifrada (hash).
            $pdo->prepare(
                'INSERT INTO usuario (id_persona, nombre_usuario, password_hash, consentimiento_whatsapp, fecha_consentimiento_whatsapp)
                 VALUES (?, ?, ?, ?, ?)')
                ->execute([$id_persona, $d['nombre_usuario'], password_hash($clave, PASSWORD_DEFAULT),
                           $whatsapp, $whatsapp ? date('Y-m-d H:i:s') : null]);
            $id_usuario = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO usuario_rol (id_usuario, id_rol) SELECT ?, id_rol FROM rol WHERE nombre = 'PACIENTE'")
                ->execute([$id_usuario]);

            $pdo->prepare('INSERT IGNORE INTO paciente (id_persona) VALUES (?)')
                ->execute([$id_persona]);

            $pdo->commit();
            mensaje('exito', 'Cuenta creada. Ya puede iniciar sesión.');
            ir_a('login.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $errores[] = $e instanceof PDOException ? mensaje_error($e) : $e->getMessage();
        }
    }
}

$titulo = 'Crear cuenta';
require 'includes/publico_inicio.php';
?>
<div class="login-caja ancha">
    <div class="candado"><span class="icono">person_add</span></div>
    <h1>Registro de cuenta de paciente</h1>
    <p class="sub">Sus datos se registran una sola vez y se usan en todas sus reservas.</p>

    <?php foreach ($errores as $err): ?>
        <div class="aviso aviso-error"><?= h($err) ?></div>
    <?php endforeach; ?>

    <form method="post">
        <div class="fila-campos">
            <div class="campo">
                <label>Tipo de documento</label>
                <select name="tipo_documento">
                    <?php foreach (['DNI' => 'DNI', 'CE' => 'Carné de extranjería', 'PASAPORTE' => 'Pasaporte'] as $v => $t): ?>
                        <option value="<?= $v ?>" <?= $d['tipo_documento'] === $v ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="campo">
                <label>Número de documento</label>
                <input name="numero_documento" value="<?= h($d['numero_documento']) ?>" required maxlength="20">
            </div>
            <div class="campo">
                <label>Fecha de nacimiento</label>
                <input type="date" name="fecha_nacimiento" value="<?= h($d['fecha_nacimiento']) ?>" required>
            </div>
        </div>
        <div class="fila-campos">
            <div class="campo">
                <label>Nombres</label>
                <input name="nombres" value="<?= h($d['nombres']) ?>" required maxlength="100">
            </div>
            <div class="campo">
                <label>Apellidos</label>
                <input name="apellidos" value="<?= h($d['apellidos']) ?>" required maxlength="100">
            </div>
        </div>
        <div class="fila-campos">
            <div class="campo">
                <label>Correo electrónico</label>
                <input type="email" name="correo" value="<?= h($d['correo']) ?>" required maxlength="150">
            </div>
            <div class="campo">
                <label>Celular</label>
                <input name="telefono" value="<?= h($d['telefono']) ?>" maxlength="20" placeholder="9XX XXX XXX">
            </div>
        </div>
        <div class="fila-campos">
            <div class="campo">
                <label>Nombre de usuario</label>
                <input name="nombre_usuario" value="<?= h($d['nombre_usuario']) ?>" required>
            </div>
            <div class="campo">
                <label>Contraseña</label>
                <input type="password" name="clave" required minlength="8">
            </div>
            <div class="campo">
                <label>Repetir contraseña</label>
                <input type="password" name="clave2" required minlength="8">
            </div>
        </div>
        <label class="casilla">
            <input type="checkbox" name="whatsapp">
            Acepto recibir avisos de mis citas por WhatsApp (opcional; rechazarlo no impide reservar).
        </label>
        <button class="boton boton-bloque" type="submit"><span class="icono">how_to_reg</span>Crear cuenta</button>
    </form>
    <p style="text-align:center;margin:16px 0 0">¿Ya tiene cuenta? <a href="login.php">Iniciar sesión</a></p>
</div>
<?php require 'includes/publico_fin.php'; ?>
