<?php
// =====================================================
// admin_reportes.php - Reportes del administrador.
// Usa las vistas, el procedimiento con cursor
// sp_reporte_ocupacion y el historial de citas.
// =====================================================
require 'config.php';
requerir_rol('ADMINISTRADOR');

// Fecha para el reporte de ocupación.
$fecha = $_GET['fecha'] ?? '';
if (!$fecha) {
    $fecha = $pdo->query('SELECT MIN(fecha) FROM cupo WHERE fecha >= CURDATE()')->fetchColumn() ?: date('Y-m-d');
}

// Citas por estado.
$por_estado = [];
foreach ($pdo->query('SELECT estado, COUNT(*) AS total FROM cita GROUP BY estado') as $f) {
    $por_estado[$f['estado']] = $f['total'];
}
$activas = ($por_estado['CONFIRMADA'] ?? 0) + ($por_estado['EN_ESPERA'] ?? 0) + ($por_estado['EN_ATENCION'] ?? 0);
$pendientes = ($por_estado['PENDIENTE_SUSTITUCION'] ?? 0) + ($por_estado['PENDIENTE_REPROGRAMACION'] ?? 0);

// Ocupación por médico (procedimiento con cursor).
$ocupacion = llamar($pdo, 'CALL sp_reporte_ocupacion(?)', [$fecha]);

// Cancelaciones y no atenciones por origen y motivo (desde el historial).
$cierres = $pdo->query(
    "SELECT evento, origen, IFNULL(motivo, '—') AS motivo, COUNT(*) AS total
     FROM historial_cita
     WHERE evento IN ('CANCELACION', 'NO_ATENDIDA')
     GROUP BY evento, origen, motivo
     ORDER BY total DESC")->fetchAll();

// Eventos por ausencia del médico.
$eventos = [];
foreach ($pdo->query(
    "SELECT evento, COUNT(*) AS total FROM historial_cita
     WHERE evento IN ('PROPUESTA_SUSTITUTO', 'SUSTITUTO_ACEPTADO', 'SUSTITUTO_RECHAZADO', 'SIN_SUSTITUTO', 'REPROGRAMACION_AUSENCIA')
     GROUP BY evento") as $f) {
    $eventos[$f['evento']] = $f['total'];
}

// Notificaciones por estado.
$avisos = $pdo->query('SELECT canal, estado, COUNT(*) AS total FROM notificacion GROUP BY canal, estado ORDER BY canal, estado')->fetchAll();

// Próximas citas (vista).
$proximas = $pdo->query('SELECT * FROM vw_reporte_proximas_citas LIMIT 15')->fetchAll();

$titulo = 'Reportes';
$activo = 'reportes';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">admin_panel_settings</span> Administración</p>
        <h1>Reportes operativos</h1>
        <p class="sub">Ocupación de cupos, cierres de citas, sustituciones y avisos.</p>
    </div>
</div>

<div class="grid-4" style="margin-bottom:20px">
    <div class="kpi"><span>Citas activas</span><strong><?= $activas ?></strong></div>
    <div class="kpi"><span>Atendidas</span><strong><?= $por_estado['ATENDIDA'] ?? 0 ?></strong>
        <small><?= $por_estado['NO_ATENDIDA'] ?? 0 ?> no atendidas</small></div>
    <div class="kpi"><span>Canceladas</span><strong><?= $por_estado['CANCELADA'] ?? 0 ?></strong></div>
    <div class="kpi"><span>Pendientes por ausencia</span><strong style="color:var(--alerta)"><?= $pendientes ?></strong></div>
</div>

<section class="tarjeta">
    <div class="medico-cabecera">
        <h2 class="tarjeta-titulo" style="margin:0"><span class="icono">donut_large</span>Ocupación de cupos por médico</h2>
        <form method="get"><input type="date" name="fecha" value="<?= h($fecha) ?>" onchange="this.form.submit()"
                                  style="padding:7px;border:1px solid var(--borde-2);border-radius:8px"></form>
    </div>
    <p style="margin:0 0 12px;color:var(--texto-2)"><?= h(ucfirst(fecha_larga($fecha))) ?> · calculado con el cursor de sp_reporte_ocupacion.</p>
    <div class="tabla-contenedor">
        <table class="tabla">
            <tr><th>Médico</th><th>Especialidad</th><th class="num">Cupos</th><th class="num">Ocupados</th>
                <th class="num">Libres</th><th class="num">No disponibles</th><th>Ocupación</th></tr>
            <?php foreach ($ocupacion as $o): ?>
                <tr>
                    <td><?= h($o['medico']) ?></td>
                    <td><?= h($o['especialidad']) ?></td>
                    <td class="num"><?= $o['total_cupos'] ?></td>
                    <td class="num"><?= $o['ocupados'] ?></td>
                    <td class="num"><?= $o['libres'] ?></td>
                    <td class="num"><?= $o['no_disponibles'] ?></td>
                    <td style="min-width:140px">
                        <div style="background:var(--superficie-azul);border-radius:4px;height:8px;margin-top:6px">
                            <div style="background:var(--primario);height:8px;border-radius:4px;width:<?= (float) $o['porcentaje_ocupacion'] ?>%"></div>
                        </div>
                        <small><?= $o['porcentaje_ocupacion'] ?> %</small>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</section>

<div class="grid-2">
    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">event_busy</span>Cancelaciones y no atenciones</h2>
        <?php if (!$cierres): ?>
            <p style="color:var(--texto-2)">Todavía no hay citas cerradas.</p>
        <?php else: ?>
            <table class="tabla">
                <tr><th>Tipo</th><th>Origen</th><th>Motivo</th><th class="num">Total</th></tr>
                <?php foreach ($cierres as $c): ?>
                    <tr><td><?= $c['evento'] === 'CANCELACION' ? 'Cancelación' : 'No atendida' ?></td>
                        <td><?= h(ucfirst(strtolower(str_replace('_', ' ', $c['origen'])))) ?></td>
                        <td><?= h($c['motivo']) ?></td><td class="num"><?= $c['total'] ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </section>

    <section class="tarjeta">
        <h2 class="tarjeta-titulo"><span class="icono">swap_horiz</span>Ausencias del médico</h2>
        <table class="tabla">
            <tr><td>Propuestas de sustituto enviadas</td><td class="num"><?= $eventos['PROPUESTA_SUSTITUTO'] ?? 0 ?></td></tr>
            <tr><td>Sustitutos aceptados</td><td class="num"><?= $eventos['SUSTITUTO_ACEPTADO'] ?? 0 ?></td></tr>
            <tr><td>Sustitutos rechazados</td><td class="num"><?= $eventos['SUSTITUTO_RECHAZADO'] ?? 0 ?></td></tr>
            <tr><td>Citas sin sustituto disponible</td><td class="num"><?= $eventos['SIN_SUSTITUTO'] ?? 0 ?></td></tr>
            <tr><td>Reprogramadas por ausencia</td><td class="num"><?= $eventos['REPROGRAMACION_AUSENCIA'] ?? 0 ?></td></tr>
        </table>

        <h2 class="tarjeta-titulo" style="margin-top:20px"><span class="icono">mail</span>Avisos</h2>
        <?php if (!$avisos): ?>
            <p style="color:var(--texto-2)">Sin avisos registrados.</p>
        <?php else: ?>
            <table class="tabla">
                <tr><th>Canal</th><th>Estado</th><th class="num">Total</th></tr>
                <?php foreach ($avisos as $a): ?>
                    <tr><td><?= h($a['canal']) ?></td><td><?= h($a['estado']) ?></td><td class="num"><?= $a['total'] ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </section>
</div>

<section class="tarjeta">
    <h2 class="tarjeta-titulo"><span class="icono">upcoming</span>Próximas citas (vista vw_reporte_proximas_citas)</h2>
    <?php if (!$proximas): ?>
        <p style="color:var(--texto-2)">No hay citas próximas.</p>
    <?php else: ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <tr><th>Fecha</th><th>Hora</th><th>Paciente</th><th>Especialidad</th><th>Médico</th><th>Consultorio</th><th>Estado</th></tr>
                <?php foreach ($proximas as $p): ?>
                    <tr><td><?= fecha_corta($p['fecha_cita']) ?></td><td><?= hora($p['hora_inicio']) ?></td>
                        <td><?= h($p['paciente_nombre_completo']) ?></td><td><?= h($p['especialidad']) ?></td>
                        <td><?= h($p['medico_nombre_completo']) ?></td><td><?= h($p['consultorio']) ?></td>
                        <td><?= etiqueta_estado($p['estado_cita']) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require 'includes/footer.php'; ?>
