<?php
// =====================================================
// index.php - Lleva a cada usuario a su página principal
// según su rol.
// =====================================================
require 'config.php';

if (!usuario()) {
    ir_a('login.php');
}

if (tiene_rol('ADMINISTRADOR')) {
    ir_a('admin_ausencias.php');
} elseif (tiene_rol('RECEPCIONISTA')) {
    ir_a('recepcion_agenda.php');
} elseif (tiene_rol('MEDICO')) {
    ir_a('medico_horario.php');
} else {
    ir_a('mis_citas.php');
}
