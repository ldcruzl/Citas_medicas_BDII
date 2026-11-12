<?php
// =====================================================
// funciones.php
// Funciones de ayuda usadas en todas las páginas.
// =====================================================

// ---------- Texto y fechas ----------

// Escapar texto antes de mostrarlo en HTML.
function h($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

// "2026-11-16" -> "lunes, 16 de noviembre de 2026"
function fecha_larga($fecha)
{
    if (!$fecha) {
        return '—';
    }
    $dias  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
              'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($fecha);
    return $dias[date('w', $t)] . ', ' . date('j', $t) . ' de ' . $meses[date('n', $t) - 1] . ' de ' . date('Y', $t);
}

// Edad en años cumplidos a una fecha (por defecto, hoy).
function edad($fecha_nacimiento, $fecha = 'today')
{
    return date_diff(date_create($fecha_nacimiento), date_create($fecha))->y;
}

function es_menor($fecha_nacimiento)
{
    return edad($fecha_nacimiento) < 18;
}

// "2026-11-16" -> "16/11/2026"
function fecha_corta($fecha)
{
    return $fecha ? date('d/m/Y', strtotime($fecha)) : '—';
}

// "08:15:00" -> "08:15"
function hora($hora)
{
    return $hora ? substr($hora, 0, 5) : '—';
}

// Etiqueta de color para cada estado de la cita.
function etiqueta_estado($estado)
{
    $textos = [
        'CONFIRMADA'               => ['Confirmada', 'azul'],
        'EN_ESPERA'                => ['En espera', 'celeste'],
        'EN_ATENCION'              => ['En atención', 'verde'],
        'ATENDIDA'                 => ['Atendida', 'verde'],
        'NO_ATENDIDA'              => ['No atendida', 'rojo'],
        'CANCELADA'                => ['Cancelada', 'gris'],
        'PENDIENTE_SUSTITUCION'    => ['Pendiente de sustitución', 'ambar'],
        'PENDIENTE_REPROGRAMACION' => ['Pendiente de reprogramación', 'ambar'],
        'PENDIENTE'                => ['Pendiente', 'ambar'],
        'VALIDADO'                 => ['Validado', 'verde'],
        'INACTIVO'                 => ['Inactivo', 'gris'],
        'ACTIVO'                   => ['Activo', 'verde'],
    ];
    $dato = $textos[$estado] ?? [$estado, 'gris'];
    return '<span class="etiqueta etiqueta-' . $dato[1] . '">' . h($dato[0]) . '</span>';
}

// ---------- Mensajes entre páginas ----------

function mensaje($tipo, $texto)
{
    $_SESSION['mensaje'] = ['tipo' => $tipo, 'texto' => $texto];
}

function mostrar_mensaje()
{
    if (empty($_SESSION['mensaje'])) {
        return;
    }
    $m = $_SESSION['mensaje'];
    unset($_SESSION['mensaje']);
    echo '<div class="aviso aviso-' . h($m['tipo']) . '">' . h($m['texto']) . '</div>';
}

function ir_a($pagina)
{
    header('Location: ' . $pagina);
    exit;
}

// ---------- Base de datos ----------

// Ejecuta una consulta o un CALL y devuelve las filas del primer resultado.
// Recorre todos los resultados para dejar libre la conexión
// (los procedimientos pueden devolver más de uno).
function llamar($pdo, $sql, $parametros = [])
{
    $st = $pdo->prepare($sql);
    $st->execute($parametros);
    $filas = null;
    do {
        if ($st->columnCount() > 0) {
            $resultado = $st->fetchAll();
            if ($filas === null) {
                $filas = $resultado;
            }
        }
    } while ($st->nextRowset());
    $st->closeCursor();
    return $filas ?? [];
}

// Lee una variable de salida de un procedimiento: SELECT @variable
function variable_salida($pdo, $nombre)
{
    return $pdo->query('SELECT @' . $nombre)->fetchColumn();
}

// Mensaje entendible a partir de un error de MySQL.
function mensaje_error(PDOException $e)
{
    $codigo = $e->errorInfo[1] ?? 0;
    $texto  = $e->errorInfo[2] ?? $e->getMessage();

    if ($codigo == 1644) {      // SIGNAL de nuestros procedimientos y triggers
        return $texto;
    }
    if ($codigo == 1062) {      // UNIQUE repetido
        return 'El registro ya existe o se repite un dato que debe ser único.';
    }
    if ($codigo == 3819) {      // CHECK no cumplido
        return 'Los datos no cumplen las reglas de la base de datos.';
    }
    return 'Error de base de datos: ' . $texto;
}

// Variables que usan los triggers para el historial (ver 04_triggers.sql).
function datos_historial($pdo, $id_usuario, $origen, $motivo)
{
    $st = $pdo->prepare('SET @usuario_actual = ?, @origen_actual = ?, @motivo_actual = ?');
    $st->execute([$id_usuario, $origen, $motivo]);
}

function limpiar_historial($pdo)
{
    $pdo->exec('SET @usuario_actual = NULL, @origen_actual = NULL, @motivo_actual = NULL');
}

// ---------- Sesión y roles ----------

function usuario()
{
    return $_SESSION['usuario'] ?? null;
}

function tiene_rol($rol)
{
    $u = usuario();
    return $u && in_array($rol, $u['roles']);
}

// Exige haber iniciado sesión y tener alguno de los roles indicados.
function requerir_rol(...$roles)
{
    if (!usuario()) {
        ir_a('login.php');
    }
    foreach ($roles as $rol) {
        if (tiene_rol($rol)) {
            return;
        }
    }
    mensaje('error', 'No tiene permiso para entrar a esa página.');
    ir_a('index.php');
}

// Pacientes que el usuario puede gestionar: él mismo y los menores
// con vínculo VALIDADO. Devuelve [id_paciente => datos].
function pacientes_gestionables($pdo)
{
    $u = usuario();
    $lista = [];

    if (tiene_rol('PACIENTE')) {
        $st = $pdo->prepare(
            "SELECT pa.id_paciente, pe.nombres, pe.apellidos, pe.numero_documento, 'TITULAR' AS tipo
             FROM paciente pa JOIN persona pe ON pe.id_persona = pa.id_persona
             WHERE pa.id_persona = ? AND pa.estado = 'ACTIVO'");
        $st->execute([$u['id_persona']]);
        foreach ($st as $f) {
            $lista[$f['id_paciente']] = $f;
        }
    }

    if (tiene_rol('APODERADO')) {
        $st = $pdo->prepare(
            "SELECT pa.id_paciente, pe.nombres, pe.apellidos, pe.numero_documento, 'MENOR' AS tipo
             FROM vinculo_apoderado va
             JOIN paciente pa ON pa.id_paciente = va.id_paciente
             JOIN persona pe ON pe.id_persona = pa.id_persona
             WHERE va.id_persona_apoderado = ? AND va.estado = 'VALIDADO'
             ORDER BY pe.nombres");
        $st->execute([$u['id_persona']]);
        foreach ($st as $f) {
            $lista[$f['id_paciente']] = $f;
        }
    }

    return $lista;
}

// Fecha y hora de hoy para el encabezado: "Tacna, jueves 12 de noviembre de 2026"
function hoy_en_tacna()
{
    return 'Tacna, ' . fecha_larga(date('Y-m-d'));
}

// ---------- Datos de una cita ----------

// Consulta base de citas con su último horario asignado, paciente,
// médico, especialidad y consultorio. Se completa con un WHERE.
function sql_citas()
{
    return "SELECT ci.id_cita, ci.id_paciente, ci.estado, ci.origen_creacion, ci.motivo_cierre,
                   ci.id_vinculo_apoderado, ci.reprogramaciones_voluntarias, ci.fecha_creacion,
                   ci.hora_llegada, ci.inicio_atencion, ci.fin_atencion,
                   pp.nombres, pp.apellidos, pp.numero_documento, pp.fecha_nacimiento,
                   e.nombre AS especialidad,
                   ac.estado AS estado_asignacion,
                   cu.id_cupo, cu.fecha, cu.hora_inicio, cu.hora_fin,
                   CONCAT(pm.nombres, ' ', pm.apellidos) AS medico,
                   co.codigo AS consultorio, co.ubicacion
            FROM cita ci
            JOIN paciente pa     ON pa.id_paciente = ci.id_paciente
            JOIN persona pp      ON pp.id_persona = pa.id_persona
            JOIN especialidad e  ON e.id_especialidad = ci.id_especialidad
            LEFT JOIN asignacion_cita ac ON ac.id_asignacion = (
                SELECT MAX(a2.id_asignacion) FROM asignacion_cita a2 WHERE a2.id_cita = ci.id_cita)
            LEFT JOIN cupo cu        ON cu.id_cupo = ac.id_cupo
            LEFT JOIN medico m       ON m.id_medico = cu.id_medico
            LEFT JOIN persona pm     ON pm.id_persona = m.id_persona
            LEFT JOIN consultorio co ON co.id_consultorio = cu.id_consultorio ";
}

function obtener_cita($pdo, $id_cita)
{
    $st = $pdo->prepare(sql_citas() . ' WHERE ci.id_cita = ?');
    $st->execute([$id_cita]);
    return $st->fetch();
}

// ¿El usuario puede ver o gestionar esta cita? (misma regla que fn_puede_gestionar_cita)
function puede_gestionar($pdo, $id_cita)
{
    $st = $pdo->prepare('SELECT fn_puede_gestionar_cita(?, ?)');
    $st->execute([usuario()['id_usuario'], $id_cita]);
    return (int) $st->fetchColumn() === 1;
}
