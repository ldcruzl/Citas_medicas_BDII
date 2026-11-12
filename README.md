# Citas Médicas Tacna – Sistema web

Sistema de citas médicas basado en los mockups del proyecto.
Esta carpeta es solo la web: no contiene SQL. La base de datos
`hospital_citas` se crea aparte, en MySQL Workbench, con los archivos .sql
del proyecto.

## Cómo se conectan

MySQL Workbench y la web no se conectan entre sí: los dos se conectan
al mismo servidor MySQL.

```
MySQL Workbench ──┐
                  ├──> Servidor MySQL (localhost:3306) ──> base hospital_citas
Web en PHP ───────┘         config.php dice a cuál conectarse
```

## Requisitos

- XAMPP con PHP 8 (solo se usa Apache; la base sigue siendo la de Workbench).
- Internet para cargar la letra Inter y los íconos (Google Fonts).

## Pasos

1. En MySQL Workbench, crear la base ejecutando los archivos .sql del proyecto.
2. Copiar la carpeta `citas_tacna` dentro de `C:\xampp\htdocs\`.
3. Abrir `config.php` y poner los mismos datos con los que entra a Workbench
   (Workbench › Database › Manage Connections):
   ```php
   define('DB_HOST', 'localhost');      // Hostname
   define('DB_NAME', 'hospital_citas'); // la base creada
   define('DB_USER', 'root');           // Username
   define('DB_PASS', 'su_contraseña');  // la contraseña de MySQL
   ```
   Si el puerto no es 3306, escribir `localhost;port=3307`.
4. En el panel de XAMPP, iniciar solo **Apache**. No iniciar el MySQL de
   XAMPP: usamos el servidor MySQL que ya usa Workbench (si los dos usan el
   puerto 3306, chocan).
5. Abrir http://localhost/citas_tacna/probar_conexion.php
   Debe decir «Conexión correcta» y mostrar 20 tablas.
6. Entrar al sistema: http://localhost/citas_tacna/

## Cuentas de prueba

Contraseña de todas: `CitasDemo2026!`

| Usuario          | Rol                    | Página inicial          |
|------------------|------------------------|-------------------------|
| `carlos_demo`    | Paciente               | Mis citas               |
| `elena_demo`     | Paciente y apoderada   | Mis citas               |
| `ana_demo`       | Paciente               | Mis citas               |
| `recepcion_demo` | Recepcionista          | Agenda del día          |
| `javier_demo`    | Médico                 | Mi horario              |
| `admin_demo`     | Administrador          | Ausencias y sustitución |

Los cupos de prueba se crean para el lunes y martes siguientes a la
ejecución de `03_datos.sql`. Para la exposición, el administrador puede
generar cupos de cualquier fecha en **Horarios y cupos**.

## Pantallas y lo que usan de la base de datos

| Página                    | Mockup                              | Base de datos                                       |
|---------------------------|-------------------------------------|-----------------------------------------------------|
| `login.php`               | Inicio de sesión                    | `usuario`, `usuario_rol` (contraseña con hash)      |
| `registro.php`            | Registro de cuenta                  | INSERT en `persona`, `usuario`, `paciente` (transacción) |
| `reservar.php`            | Consultar disponibilidad            | `sp_consultar_disponibilidad`, `sp_bloquear_cupo`   |
| `confirmar.php`           | Revisar y confirmar                 | `sp_confirmar_cita`                                 |
| `cita_confirmada.php`     | Cita confirmada                     | `cita`, `notificacion`                              |
| `mis_citas.php`           | Mis citas                           | `cita`, `asignacion_cita`, `cupo`                   |
| `cancelar.php`            | Cancelar cita                       | `sp_cancelar_cita`                                  |
| `sustituto.php`           | Propuesta de médico sustituto       | `sp_responder_sustitucion`                          |
| `alternativas.php`        | Alternativas / Sin alternativas     | `sp_elegir_alternativa`, `fn_cupo_libre`            |
| `menores.php`             | Menores a mi cargo / Registrar menor| INSERT en `vinculo_apoderado` (PENDIENTE)           |
| `recepcion_agenda.php`    | Agenda de recepción                 | `sp_registrar_llegada`, UPDATE a NO_ATENDIDA        |
| `validar_apoderados.php`  | Validar apoderados                  | UPDATE de `vinculo_apoderado`                       |
| `medico_horario.php`      | Mi horario / Detalle de atención    | `sp_atender_cita`, `fn_cupo_libre`                  |
| `admin_ausencias.php`     | Ausencias y sustituciones           | `sp_registrar_ausencia` (cursor), `sp_procesar_vencimientos` |
| `admin_horarios.php`      | Horarios y cupos                    | INSERT de cupos de 15 minutos                       |
| `admin_medicos.php`       | Gestión de médicos                  | vista `vw_reporte_resumen_medicos`                  |
| `admin_reportes.php`      | Reportes                            | `sp_reporte_ocupacion` (cursor), vistas, historial  |

Los cambios de estado de las citas pasan por los triggers de
`04_triggers.sql`, que validan el cambio y guardan el historial y los avisos.

## Estructura

```
citas_tacna/
├── config.php            conexión a MySQL y datos de la sede (Tacna)
├── includes/
│   ├── funciones.php     ayudas: fechas, mensajes, llamadas a procedimientos, roles
│   ├── header.php        menú lateral según el rol
│   ├── footer.php
│   ├── publico_inicio.php / publico_fin.php   (login y registro)
├── assets/css/estilos.css  colores y tipografía de los mockups
└── páginas .php (una por pantalla)
```

## Pendiente (fuera del alcance de esta versión)

- Reprogramación voluntaria y "nueva cita por tardanza o inasistencia".
- Registro de pacientes nuevos desde recepción (hoy recepción busca pacientes ya registrados).
- Envío real de correos y WhatsApp: el sistema solo los registra en `notificacion`.
