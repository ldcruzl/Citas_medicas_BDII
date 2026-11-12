<?php
// =====================================================
// login.php - Inicio de sesión
// Se puede entrar con el nombre de usuario o el correo.
// =====================================================
require 'config.php';

if (usuario()) {
    ir_a('index.php');
}

$error = '';
$dato = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dato  = trim($_POST['usuario'] ?? '');
    $clave = $_POST['clave'] ?? '';

    $st = $pdo->prepare(
        "SELECT u.id_usuario, u.id_persona, u.nombre_usuario, u.password_hash, u.estado,
                pe.nombres, pe.apellidos
         FROM usuario u
         JOIN persona pe ON pe.id_persona = u.id_persona
         WHERE u.nombre_usuario = ? OR pe.correo = ?
         LIMIT 1");
    $st->execute([$dato, $dato]);
    $fila = $st->fetch();

    if (!$fila || !password_verify($clave, $fila['password_hash'])) {
        $error = 'El usuario o la contraseña no son correctos.';
    } elseif ($fila['estado'] !== 'ACTIVO') {
        $error = 'La cuenta no está activa. Acérquese a recepción.';
    } else {
        // Roles de la cuenta.
        $st = $pdo->prepare(
            'SELECT r.nombre FROM usuario_rol ur JOIN rol r ON r.id_rol = ur.id_rol WHERE ur.id_usuario = ?');
        $st->execute([$fila['id_usuario']]);
        $roles = $st->fetchAll(PDO::FETCH_COLUMN);

        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id_usuario' => (int) $fila['id_usuario'],
            'id_persona' => (int) $fila['id_persona'],
            'nombre'     => $fila['nombres'] . ' ' . $fila['apellidos'],
            'roles'      => $roles,
        ];
        ir_a('index.php');
    }
}

$titulo = 'Iniciar sesión';
require 'includes/publico_inicio.php';
?>
<div class="login-caja">
    <div class="candado"><span class="icono">lock</span></div>
    <h1>Iniciar sesión</h1>
    <p class="sub">Acceso para pacientes, apoderados y personal de salud</p>

    <?php mostrar_mensaje(); ?>
    <?php if ($error): ?>
        <div class="aviso aviso-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="campo">
            <label for="usuario">Usuario o correo electrónico</label>
            <input type="text" id="usuario" name="usuario" value="<?= h($dato) ?>" required autofocus
                   placeholder="ej. carlos_demo">
        </div>
        <div class="campo">
            <label for="clave">Contraseña</label>
            <input type="password" id="clave" name="clave" required>
        </div>
        <button class="boton boton-bloque" type="submit"><span class="icono">login</span>Iniciar sesión</button>
    </form>

    <p style="text-align:center;margin:16px 0 0">¿No tiene una cuenta? <a href="registro.php">Crear cuenta</a></p>

    <div class="demo">
        <strong>Cuentas de prueba</strong> (contraseña <code>CitasDemo2026!</code>):<br>
        <code>carlos_demo</code> paciente · <code>elena_demo</code> paciente y apoderada ·
        <code>recepcion_demo</code> recepción · <code>javier_demo</code> médico ·
        <code>admin_demo</code> administrador
    </div>
</div>
<?php require 'includes/publico_fin.php'; ?>
