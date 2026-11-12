<?php
// =====================================================
// validar_apoderados.php - Recepción valida, rechaza o
// revoca vínculos entre un menor y su apoderado.
// Un menor solo puede tener un apoderado validado a la vez
// (lo asegura uq_vinculo_vigente).
// =====================================================
require 'config.php';
requerir_rol('RECEPCIONISTA', 'ADMINISTRADOR');

$u = usuario();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_vinculo = (int) $_POST['vinculo'];
    $motivo = trim($_POST['motivo'] ?? '');

    try {
        if ($_POST['accion'] === 'validar') {
            $pdo->prepare("UPDATE vinculo_apoderado
                           SET estado = 'VALIDADO', fecha_validacion = NOW(), id_usuario_validador = ?
                           WHERE id_vinculo = ? AND estado = 'PENDIENTE'")
                ->execute([$u['id_usuario'], $id_vinculo]);
            mensaje('exito', 'Vínculo validado. El apoderado ya puede reservar citas para el menor.');
        } else {
            // Rechazar una solicitud o revocar un vínculo validado: queda INACTIVO con su motivo.
            if ($motivo === '') {
                throw new Exception('Indique el motivo.');
            }
            $pdo->prepare("UPDATE vinculo_apoderado
                           SET estado = 'INACTIVO', fecha_fin = NOW(), id_usuario_cierre = ?, motivo_fin = ?
                           WHERE id_vinculo = ? AND estado IN ('PENDIENTE', 'VALIDADO')")
                ->execute([$u['id_usuario'], $motivo, $id_vinculo]);
            mensaje('exito', 'El vínculo quedó inactivo. Se conserva en el historial.');
        }
    } catch (PDOException $e) {
        mensaje('error', ($e->errorInfo[1] ?? 0) == 1062
            ? 'El menor ya tiene otro apoderado validado. Revoque primero el vínculo anterior.'
            : mensaje_error($e));
    } catch (Exception $e) {
        mensaje('error', $e->getMessage());
    }
    ir_a('validar_apoderados.php');
}

$sql = "SELECT va.id_vinculo, va.estado, va.parentesco, va.fecha_registro, va.fecha_validacion,
               men.nombres AS menor_nombres, men.apellidos AS menor_apellidos, men.numero_documento AS menor_dni,
               men.fecha_nacimiento,
               apo.nombres AS apo_nombres, apo.apellidos AS apo_apellidos, apo.numero_documento AS apo_dni
        FROM vinculo_apoderado va
        JOIN paciente pa  ON pa.id_paciente = va.id_paciente
        JOIN persona men  ON men.id_persona = pa.id_persona
        JOIN persona apo  ON apo.id_persona = va.id_persona_apoderado
        WHERE va.estado = ?
        ORDER BY va.fecha_registro";
$st = $pdo->prepare($sql);
$st->execute(['PENDIENTE']);
$pendientes = $st->fetchAll();
$st->execute(['VALIDADO']);
$validados = $st->fetchAll();

$titulo = 'Validar apoderados';
$activo = 'apoderados';
require 'includes/header.php';
?>

<div class="encabezado">
    <div>
        <p class="paso"><span class="icono">verified_user</span> Recepción · Representación de menores</p>
        <h1>Validación de apoderados</h1>
        <p class="sub">Compruebe en ventanilla el DNI del menor, el DNI del apoderado y la partida de nacimiento
            (u otro documento que acredite el parentesco).</p>
    </div>
</div>

<section class="tarjeta">
    <h2 class="tarjeta-titulo"><span class="icono">pending_actions</span>Solicitudes pendientes (<?= count($pendientes) ?>)</h2>
    <?php if (!$pendientes): ?>
        <p class="vacio">No hay solicitudes pendientes.</p>
    <?php else: ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <tr><th>Menor</th><th>Apoderado</th><th>Parentesco</th><th>Registrado</th><th>Acciones</th></tr>
                <?php foreach ($pendientes as $v): ?>
                    <tr>
                        <td><strong><?= h($v['menor_nombres'] . ' ' . $v['menor_apellidos']) ?></strong>
                            <span class="secundario">DNI <?= h($v['menor_dni']) ?> · <?= edad($v['fecha_nacimiento']) ?> años</span></td>
                        <td><?= h($v['apo_nombres'] . ' ' . $v['apo_apellidos']) ?><span class="secundario">DNI <?= h($v['apo_dni']) ?></span></td>
                        <td><?= h($v['parentesco']) ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($v['fecha_registro'])) ?></td>
                        <td>
                            <form method="post" class="acciones">
                                <input type="hidden" name="vinculo" value="<?= $v['id_vinculo'] ?>">
                                <button class="boton boton-exito boton-chico" name="accion" value="validar"><span class="icono">check</span>Validar</button>
                                <input name="motivo" placeholder="Motivo si rechaza" style="padding:5px 8px;border:1px solid var(--borde-2);border-radius:6px">
                                <button class="boton boton-peligro boton-chico" name="accion" value="inactivar">Rechazar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta">
    <h2 class="tarjeta-titulo"><span class="icono">groups</span>Apoderados vigentes (<?= count($validados) ?>)</h2>
    <?php if (!$validados): ?>
        <p class="vacio">No hay apoderados validados.</p>
    <?php else: ?>
        <div class="tabla-contenedor">
            <table class="tabla">
                <tr><th>Menor</th><th>Apoderado</th><th>Parentesco</th><th>Validado</th><th>Revocar</th></tr>
                <?php foreach ($validados as $v): ?>
                    <tr>
                        <td><strong><?= h($v['menor_nombres'] . ' ' . $v['menor_apellidos']) ?></strong>
                            <span class="secundario">DNI <?= h($v['menor_dni']) ?></span></td>
                        <td><?= h($v['apo_nombres'] . ' ' . $v['apo_apellidos']) ?><span class="secundario">DNI <?= h($v['apo_dni']) ?></span></td>
                        <td><?= h($v['parentesco']) ?></td>
                        <td><?= date('d/m/Y', strtotime($v['fecha_validacion'])) ?></td>
                        <td>
                            <form method="post" class="acciones">
                                <input type="hidden" name="vinculo" value="<?= $v['id_vinculo'] ?>">
                                <input name="motivo" placeholder="Motivo de la revocación" required style="padding:5px 8px;border:1px solid var(--borde-2);border-radius:6px">
                                <button class="boton boton-peligro boton-chico" name="accion" value="inactivar">Revocar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require 'includes/footer.php'; ?>
