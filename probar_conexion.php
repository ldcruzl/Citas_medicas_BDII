<?php
// =====================================================
// probar_conexion.php
// Comprueba que la web llega a la base de datos que se
// creó en MySQL Workbench. Abrir:
//   http://localhost/citas_tacna/probar_conexion.php
// =====================================================
require 'config.php';   // si no conecta, config.php muestra el error

function contar($pdo, $sql)
{
    return (int) $pdo->query($sql)->fetchColumn();
}

$tablas   = contar($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
$vistas   = contar($pdo, "SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE()");
$rutinas  = contar($pdo, "SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()");
$triggers = contar($pdo, "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()");
$usuarios = contar($pdo, "SELECT COUNT(*) FROM usuario");

$titulo = 'Prueba de conexión';
require 'includes/publico_inicio.php';
?>
<div class="login-caja">
    <h1>Conexión correcta</h1>
    <p class="sub">La web está conectada a la base <strong><?= h(DB_NAME) ?></strong> en <?= h(DB_HOST) ?>.</p>
    <table class="tabla">
        <tr><td>Tablas</td><td class="num"><?= $tablas ?> <small>(deben ser 20)</small></td></tr>
        <tr><td>Vistas</td><td class="num"><?= $vistas ?></td></tr>
        <tr><td>Funciones y procedimientos</td><td class="num"><?= $rutinas ?></td></tr>
        <tr><td>Triggers</td><td class="num"><?= $triggers ?></td></tr>
        <tr><td>Usuarios registrados</td><td class="num"><?= $usuarios ?></td></tr>
    </table>
    <p style="text-align:center;margin-top:18px"><a class="boton" href="login.php">Ir al inicio de sesión</a></p>
</div>
<?php require 'includes/publico_fin.php'; ?>
