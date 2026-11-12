<?php
// logout.php - Cerrar sesión
require 'config.php';

$_SESSION = [];
session_destroy();

session_start();
mensaje('info', 'Sesión cerrada correctamente.');
ir_a('login.php');
