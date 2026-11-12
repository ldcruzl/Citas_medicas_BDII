<?php
// =====================================================
// config.php
// Datos de conexión a MySQL y datos de la sede.
// Poner los MISMOS datos con los que se entra a MySQL Workbench:
// así la web usa la base hospital_citas creada allí.
// =====================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'hospital_citas');
define('DB_USER', 'root');
define('DB_PASS', '');

// Datos de la sede (Tacna, Perú).
define('SISTEMA_NOMBRE', 'Citas Médicas Tacna');
define('SEDE_NOMBRE', 'Centro de Salud – Sede Tacna');
define('SEDE_DIRECCION', 'Av. Bolognesi s/n, Cercado de Tacna');
define('SEDE_CIUDAD', 'Tacna, Perú');
define('LINEA_SALUD', '(052) 000-123');

// Hora de Perú (Tacna está en la zona horaria de Lima, UTC-5).
date_default_timezone_set('America/Lima');

session_start();

// Conexión única con PDO.
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-05:00'",
        ]
    );
} catch (PDOException $e) {
    die('<p style="font-family:sans-serif;padding:2rem">No se pudo conectar a la base de datos '
        . DB_NAME . '. Revise los datos de config.php.<br><small>'
        . htmlspecialchars($e->getMessage()) . '</small></p>');
}

require_once __DIR__ . '/includes/funciones.php';
