# Tareas realizadas del proyecto Sistema UGDA FIA UES

## Alcance del documento

Este documento consolida las tareas registradas en el historial Git disponible de la rama `Bryan-dev`, desde el primer commit del proyecto hasta el último commit registrado al **13 de agosto de 2026**.

- Inicio del historial: **26 de enero de 2026**.
- Último cambio registrado: **13 de agosto de 2026**.
- Commits revisados: **71**.
- Commits de trabajo: **63**.
- Commits de integración de ramas: **8**.
- Los commits de integración se relacionan al final para evitar duplicar tareas ya descritas en los commits de desarrollo.
- Cuando un mensaje de commit era muy breve, la descripción se amplió usando los archivos modificados y la funcionalidad actualmente presente en el proyecto.

## Resumen de funcionalidades construidas

Durante el desarrollo se implementaron o ampliaron las siguientes áreas:

1. Aplicación SPA con Laravel 12, Vue 3, Vite y PrimeVue.
2. Inicio de sesión con código de verificación 2FA por correo electrónico.
3. Dashboard, layout administrativo, sidebar, logo y navegación principal.
4. Gestión de unidades organizativas y jerarquía de unidades.
5. Gestión de usuarios, roles, perfiles, permisos y unidades asociadas.
6. Solicitudes de transferencia documental y solicitudes de préstamo.
7. Flujo de autorización, despacho, devolución, revisión física y correcciones.
8. Gestión de series y subseries documentales.
9. Archivo de gestión, archivo general, cajas y documentos.
10. Ubicaciones físicas, reasignación, documentos reservados y etiquetas.
11. Vista previa y descarga segura de documentos PDF.
12. Notificaciones del sistema, correos transaccionales y actividad de usuarios.
13. Restablecimiento de contraseña, bloqueo de cuentas y sanitización de entradas.
14. Reportes de transferencias, préstamos, clasificación, organización y consultas de acceso.
15. Configuración Docker para la aplicación, Vite, PostgreSQL y Mailpit.
16. Pruebas funcionales, pruebas de reportes, pruebas del catálogo de solicitudes y prueba E2E de login.
17. Documentación técnica, instrucciones de instalación y reglas de protección de la base de datos.

## Detalle técnico de lo implementado

Esta sección especifica qué hace actualmente cada módulo y qué piezas del sistema participan en cada tarea.

### 1. Autenticación, seguridad y perfil

- `POST /login-auth` valida correo y contraseña, genera el código 2FA y envía el correo mediante `TwoFactorCodeMail`.
- `POST /login-verify` valida el código 2FA e inicia la sesión.
- `POST /login-change-password` permite completar el cambio de una contraseña temporal.
- Se agregó bloqueo de cuentas después de varios intentos fallidos.
- Se agregó sanitización global de texto mediante `SanitizeTextInput` y el helper frontend `inputSanitizer.js`.
- Se implementó cambio de contraseña y configuración de 2FA desde `MiPerfil.vue`.
- Se agregaron notificaciones internas, marcado individual o masivo como leído y servicios de registro de actividad.
- Archivos principales: `app/Http/Controllers/AuthController.php`, `app/Http/Controllers/UserProfileController.php`, `app/Services/AuthenticatorCodeService.php`, `app/Services/UserActivityLogger.php`, `app/Services/SystemNotificationService.php`, `resources/js/components/Login.vue`, `resources/js/components/views/MiPerfil.vue` y `resources/js/components/views/Notificaciones.vue`.

### 2. Usuarios, personas, roles, perfiles y unidades

- `GET|POST|PUT|DELETE /api/users` administra usuarios.
- `POST /api/users/{id}/reset-password` genera y envía una contraseña temporal.
- La información personal se normalizó en `Person`; el usuario conserva relaciones con personas, unidades y perfiles.
- La estructura de usuario utiliza `first_name`, `second_name`, `first_last_name`, `second_last_name`, `carnet`, `email`, `role`, `unit_id` e `is_active`; no se utiliza la columna `name`.
- Se implementaron perfiles, roles, permisos por endpoint y permisos de interfaz mediante `EnsureUserHasPermission`.
- `GET|POST|PUT|DELETE /api/units` administra unidades y dependencias jerárquicas.
- Se agregaron relaciones múltiples entre usuarios y unidades, perfiles y roles, incluyendo selección de unidad activa.
- Archivos principales: `UserController.php`, `UnitController.php`, `ProfileController.php`, `User.php`, `Person.php`, `Profile.php`, `Role.php`, `Unit.php`, `Usuarios.vue` y `Unidades.vue`.

### 3. Series y subseries documentales

- `GET|POST|PUT|DELETE /api/documentary-series` administra series documentales.
- Se agregaron rutas para crear, editar y eliminar subseries asociadas a una serie.
- Se implementaron códigos documentales, validación de unicidad, selección de unidades responsables y relación entre series/subseries y unidades.
- Se agregaron permisos específicos para leer, crear, actualizar y eliminar series documentales.
- Archivos principales: `DocumentarySeriesController.php`, `DocumentarySeries.php`, `DocumentarySubseries.php` y `SeriesDocumentales.vue`.
- Migraciones relacionadas: `2026_04_19_010900_create_documentary_series_table.php`, `2026_04_19_011000_create_documentary_subseries_table.php`, `2026_04_26_150000_expand_documentary_codes_length.php`, `2026_06_11_010000_create_documentary_series_unit_table.php` y `2026_06_11_010100_create_documentary_subseries_unit_table.php`.

### 4. Transferencias documentales

- `POST /api/transfers` crea una solicitud de transferencia.
- `GET /api/transfers/next-code` genera el siguiente código de transferencia.
- `POST /api/transfers/import-documents` permite importar documentos desde una plantilla.
- Se agregaron validaciones para documentos, series, subseries, años, códigos únicos y cajas.
- El flujo contempla autorización por unidad, denegación, programación, revisión física, observaciones, finalización de ubicaciones y rechazo.
- Se implementó la corrección y reenvío de transferencias mediante `resubmit-correction`.
- Se agregaron descarga de documentos y hoja resumen mediante URLs firmadas.
- Se implementaron cajas con número y `box_code`, documentos reservados, etiquetas y reasignación de ubicación.
- Archivos principales: `TransferController.php`, `RequestCatalogController.php`, `Transfer.php`, `TransferBox.php`, `TransferDocument.php`, `TransferEvent.php`, `NuevaSolicitud.vue`, `Solicitudes.vue`, `DetalleTransferencia.vue`, `ArchivoGestion.vue`, `CrearCajaArchivo.vue`, `CrearDocumentoArchivo.vue`, `DocumentosReservados.vue`, `ReasignacionArchivo.vue` y `EtiquetaTransferencia.vue`.

### 5. Préstamos documentales

- `POST /api/loans` crea una solicitud de préstamo.
- `GET /api/loans/pending-authorization` lista préstamos pendientes de autorización.
- El flujo contempla autorización por unidad, autorización final, observación, denegación, inicio y finalización de búsqueda, registro del préstamo y devolución.
- Se agregó el registro de modificaciones realizadas a documentos durante el préstamo.
- Se implementaron correcciones y reenvío de solicitudes observadas.
- Se agregaron notificaciones relacionadas con correcciones y vencimientos de préstamos.
- Archivos principales: `LoanController.php`, `Loan.php`, `LoanDocument.php`, `LoanDispatch.php`, `LoanDispatchItem.php`, `LoanReturn.php`, `LoanReturnItem.php`, `LoanEvent.php`, `LoanDocumentModification.php`, `SolicitudPrestamo.vue`, `PrestamosPendientesAutorizacion.vue` y `DetallePrestamo.vue`.

### 6. Archivo y ubicaciones físicas

- `GET /api/archive/catalog` consulta el catálogo documental.
- `GET|POST /api/archive/boxes` consulta y crea cajas.
- `PUT /api/archive/boxes/{id}/location` cambia la ubicación de una caja.
- `GET|POST /api/archive/documents` consulta y crea documentos.
- `PUT /api/archive/documents/{id}/box` mueve un documento de caja.
- `GET|POST|DELETE /api/archive/reserved-documents` administra documentos reservados.
- Se implementaron catálogos de oficinas, pasillos y estantes con operaciones CRUD independientes.
- Se agregaron búsqueda, paginación, contador de caracteres, estado activo y validación para evitar reasignar a la misma ubicación.
- Se implementó `SignedPdfUrl` para proteger las descargas de documentos y hojas resumen.
- Archivos principales: `ArchiveController.php`, `PhysicalLocationController.php`, `PhysicalLocationOffice.php`, `PhysicalLocationAisle.php`, `PhysicalLocationShelf.php`, `ArchivoGeneral.vue`, `ArchivoGestion.vue` y `UbicacionesFisicas.vue`.

### 7. Reportes

- Se implementó el resumen general de solicitudes y documentos.
- Se agregaron reportes de registro de ingreso, historial de consultas de acceso, documentos consultados y tiempos de respuesta.
- Se agregaron reportes de clasificación y organización por series, subseries y documentos sin clasificar.
- Se agregaron consultas de documentos transferidos y filtros por rango de fechas.
- Se restringió el acceso a reportes mediante el permiso `endpoint.ui.reports.read`.
- Rutas principales: `/api/reports/summary`, `/api/reports/entry-registry`, `/api/reports/access-consultation/*` y `/api/reports/classification-organization/*`.
- Archivos principales: `ReportController.php`, `Reportes.vue`, `ReportSummaryTest.php` y `ReportAccessConsultationDemoSeeder.php`.

### 8. Infraestructura, datos de prueba y documentación

- `docker-compose.yml` coordina los servicios de Laravel, Vite, PostgreSQL 16 y Mailpit.
- `docker/start-app.sh` instala dependencias PHP, ejecuta el arranque de la aplicación y prepara el almacenamiento.
- `docker/start-vite.sh` instala dependencias frontend y levanta Vite.
- Se agregaron seeders para roles, perfiles, series documentales, estados de solicitudes, flujos demo, notificaciones y reportes.
- Se agregaron pruebas de autenticación y usuarios, catálogo de solicitudes, reportes, archivo y login E2E.
- Se documentaron la instalación administrativa, la arquitectura técnica, el uso de Docker y las reglas para no eliminar ni reiniciar la base de datos.

## Historial cronológico de tareas

### Enero de 2026: base del sistema, autenticación y estructura inicial

| Fecha | Commit | Responsable | Tarea realizada |
|---|---|---|---|
| 2026-01-26 | `bea4a02` | Walter Alexander Hernández Guzmán | Se creó la versión inicial funcional con login, autenticación 2FA y medidas iniciales de seguridad. Se establecieron la aplicación Laravel, el modelo de usuario, el correo 2FA, las migraciones base y la estructura inicial del frontend. |
| 2026-01-26 | `2834bdf` | Walter Alexander Hernández Guzmán | Se actualizaron los estilos y la interfaz del componente `Login.vue`. |
| 2026-01-27 | `7d4f3f4` | Walter Alexander Hernández Guzmán | Se actualizó el logo institucional utilizado en el login. |
| 2026-01-28 | `b362e5f` | Walter Alexander Hernández Guzmán | Se agregó el sidebar administrativo, el layout principal, la navegación SPA y la integración inicial del dashboard con las rutas protegidas. |
| 2026-01-29 | `dffe312` | Walter Alexander Hernández Guzmán | Se ajustaron los iconos, logos y estilos visuales del layout y del sidebar. |
| 2026-01-30 | `56973f4` | Walter Alexander Hernández Guzmán | Se implementó el CRUD de unidades y la jerarquía entre unidades, incluyendo modelo, migraciones, controlador, rutas y vista Vue. |

### Febrero de 2026: usuarios y solicitudes de transferencia

| Fecha | Commit | Responsable | Tarea realizada |
|---|---|---|---|
| 2026-02-02 | `fe11ead` | AlexHernandezSV | Se implementó la lógica de usuarios: campos institucionales, roles, unidades, contraseñas, correo de credenciales, validaciones y vista de administración. También se ajustó la gestión de unidades para integrarla con usuarios. |
| 2026-02-02 | `8bc6571` | Walter Alexander Hernández Guzmán | Se reestructuró la arquitectura frontend para colocar el sidebar fuera del layout y reutilizarlo de forma consistente en las vistas. |
| 2026-02-02 | `f30ba46` | Walter Alexander Hernández Guzmán | Se construyó el frontend inicial de solicitudes de transferencia, con modelos, migraciones, controlador, listado y formulario para crear solicitudes. |

### Abril de 2026: Docker, normalización de usuarios y series documentales

| Fecha | Commit | Responsable | Tarea realizada |
|---|---|---|---|
| 2026-04-19 | `d6fb9ba` | Bryan Aguilar | Se corrigió el levantamiento local en Windows mediante Docker Compose y se documentó la estructura del proyecto. Se agregaron los scripts de arranque de Laravel y Vite, además de ajustes en seeders, factory y Vite. |
| 2026-04-19 | `448baf7` | Bryan Aguilar | Se corrigió la configuración de entorno para que el login funcionara correctamente dentro de Docker. |
| 2026-04-19 | `bdbb5c9` | Bryan Aguilar | Se normalizó la estructura de usuarios y unidades. Se incorporaron personas, perfiles, roles, relaciones usuario-unidad, soft deletes, seeders, permisos, validaciones, ajustes de login, sidebar, modales y listados responsive. También se agregaron pruebas de autenticación y administración de usuarios. |
| 2026-04-19 | `efa5df4` | Bryan Aguilar | Se implementó y ajustó la gestión de series y subseries documentales, incluyendo permisos, rutas, modelos, migraciones, seeders, formularios y modales responsive. |
| 2026-04-26 | `39d210a` | Bryan Aguilar | Se dinamizaron los flujos documentales de transferencias y préstamos. Se agregaron estados de solicitudes, eventos, documentos, cajas, despachos, devoluciones, catálogo de estados, dashboard documental y vistas de detalle. |
| 2026-04-26 | `57b58a7` | Bryan Aguilar | Se incorporaron pruebas E2E de login y documentación operativa especializada para autenticación 2FA, CRUD, Docker, integración Laravel-Vue y UI PrimeVue. |

### Mayo de 2026: transferencia completa y seguridad de cuentas

| Fecha | Commit | Responsable | Tarea realizada |
|---|---|---|---|
| 2026-05-03 | `a136499` | Bryan Aguilar | Se completó el flujo de transferencia documental, incluyendo catálogo de solicitudes, navegación, detalle de transferencia, formulario de nueva solicitud y rutas de acceso. |
| 2026-05-03 | `eb973f2` | AlexHernandezSV | Se actualizaron configuraciones auxiliares del proyecto y del entorno Docker. |
| 2026-05-11 | `dc20173` | Bryan Aguilar | Se implementó el restablecimiento de contraseña para usuarios, notificaciones del sistema, bitácora de actividad, campos de seguridad, servicios de autenticación y nuevas vistas de perfil, notificaciones, préstamos y solicitudes. |

### Junio de 2026: solicitudes avanzadas, reportes y ubicaciones físicas

| Fecha | Commit | Responsable | Tarea realizada |
|---|---|---|---|
| 2026-06-09 | `3cc40b3` | Bryan Aguilar | Se mejoró `NuevaSolicitud` con manejo de series, modal de confirmación, estados de revisión física, notificaciones y ajustes en los detalles de transferencias y préstamos. |
| 2026-06-09 | `277c366` | rolnet69 | Se agregó la validación para impedir que los años de solicitudes de transferencia y préstamo sean mayores al año actual. |
| 2026-06-10 | `cba4625` | rolnet69 | Se corrigió la altura del botón `+` para agregar unidades en formularios de solicitudes y usuarios. |
| 2026-06-10 | `100d4fd` | Bryan Aguilar | Se implementó el resumen de reportes con endpoint API, integración frontend, vista de reportes y pruebas funcionales. |
| 2026-06-10 | `8a8e364` | Bryan Aguilar | Se mejoró la gestión de series y subseries con selección de unidades y se agregaron rutas y vistas para el archivo de gestión y la creación de cajas. |
| 2026-06-14 | `26edcf8` | Bryan Aguilar | Se ajustó el estilo del icono del botón principal en el login. |
| 2026-06-14 | `2a240dd` | Bryan Aguilar | Se implementó el bloqueo de cuentas después de múltiples intentos fallidos de inicio de sesión, con el comportamiento correspondiente en backend y frontend. |
| 2026-06-14 | `c42f4b3` | Bryan Aguilar | Se mejoró la administración de usuarios y la sanitización de entradas de texto en login, perfil, usuarios, solicitudes, transferencias y préstamos. |
| 2026-06-15 | `a85f3f6` | Bryan Aguilar | Se implementó la gestión de ubicaciones físicas de archivo, con catálogos de oficinas, pasillos y estantes, asignación/reasignación y ajustes en préstamos, transferencias y cajas. |
| 2026-06-20 | `98a005e` | Bryan Aguilar | Se refactorizaron estilos y se mejoró la accesibilidad en múltiples componentes, formularios, mensajes, correos, navegación y vistas documentales. |
| 2026-06-20 | `6fd203f` | Bryan Aguilar | Se corrigieron errores de ortografía, acentuación y redacción en mensajes, vistas, correos, seeders y pruebas. |
| 2026-06-24 | `6761078` | Walter Alexander Hernández Guzmán | Se actualizaron configuraciones del entorno SRF y dependencias del proyecto. |
| 2026-06-29 | `460953f` | AlexHernandezSV | Se corrigió la visualización de los resultados de búsqueda en el archivo general. |
| 2026-06-29 | `860ce77` | AlexHernandezSV | Se corrigieron los encabezados de las pantallas de mantenimiento de reportes, series, ubicaciones físicas, unidades y usuarios. |
| 2026-06-29 | `21b880f` | AlexHernandezSV | Se corrigieron las tipografías de la pantalla de series documentales. |
| 2026-06-30 | `721d8a7` | Bryan Aguilar | Se mejoró la gestión documental con vista previa de PDF, validaciones adicionales y ajustes en archivo general, archivo de gestión, cajas, documentos, préstamos, transferencias, usuarios y ubicaciones. |

### Julio de 2026: validaciones documentales, vistas previas y reportes

| Fecha | Commit | Responsable | Tarea realizada |
|---|---|---|---|
| 2026-07-01 | `9fd6ba5` | Bryan Aguilar | Se agregaron validaciones y reglas de negocio para la importación de documentos y la administración de series documentales. |
| 2026-07-03 | `c74c654` | Bryan Aguilar | Se simplificó la lógica de validación y se mejoró la retroalimentación visual de los formularios de series y subseries. |
| 2026-07-05 | `d1e7c3e` | Bryan Aguilar | Se creó el diálogo reutilizable de vista previa de documentos, se simplificó la previsualización de PDF, se agregaron filtros dinámicos de solicitudes, paginación y búsqueda de ubicaciones físicas, pruebas de unicidad para series/subseries y reglas para notas de corrección y observaciones de revisión física. También se añadió documentación técnica e instalación administrativa. |
| 2026-07-08 | `e02643b` | Bryan Aguilar | Se agregó la vista previa de documentos y se mejoró la generación de etiquetas para cajas y transferencias. |
| 2026-07-09 | `1c2c830` | AlexHernandezSV | Se corrigieron aspectos del login y del modal del centro de ayuda. |
| 2026-07-09 | `aaa8fb7` | Bryan Aguilar | Se mejoró la pantalla de ubicaciones físicas con contador de caracteres y control de estado activo. También se incorporaron documentos reservados, campos reservados, nuevas vistas de creación y reasignación, y ajustes asociados al flujo documental. |
| 2026-07-09 | `ecaaf5d` | Bryan Aguilar | Se corrigió la escritura de “ubicación física” y se implementó `SignedPdfUrl` para generar enlaces seguros de descarga de documentos. |
| 2026-07-09 | `cb74564` | Bryan Aguilar | Se agregaron diálogos de confirmación para eliminar cajas y documentos reservados, además de mejoras en la eliminación de ubicaciones físicas. |
| 2026-07-09 | `74c7c00` | Bryan Aguilar | Se corrigieron mensajes de validación y el manejo de URLs en el sidebar. |
| 2026-07-10 | `9d60183` | Bryan Aguilar | Se actualizaron las rutas para permitir el acceso del rol “Usuario UGDA” y se agregó un endpoint de reportes. |
| 2026-07-11 | `94efab9` | Bryan Aguilar | Se agregaron rutas, endpoint, vista y datos demo para reportes de consulta de acceso. |
| 2026-07-19 | `511364d` | Bryan Aguilar | Se implementaron reportes de clasificación y organización documental, incluyendo series y documentos sin clasificar. |
| 2026-07-22 | `430be10` | AlexHernandezSV | Se rediseñaron los correos del sistema con un estándar visual común para credenciales, contraseñas temporales y códigos 2FA. |
| 2026-07-23 | `7ae9245` | Bryan Aguilar | Se ajustó el manejo de documentos y los permisos de acceso en el archivo y el catálogo de solicitudes, incluyendo validaciones, URLs firmadas y vista previa. |
| 2026-07-23 | `027db8b` | Bryan Aguilar | Se actualizaron las instrucciones de administración de la base de datos y se incorporaron reglas explícitas para proteger los datos existentes. |
| 2026-07-25 | `a912928` | rolnet69 | Se configuraron como solo lectura el nombre y la unidad del solicitante en la solicitud de préstamo. |
| 2026-07-26 | `cca6ebc` | rolnet69 | Se corrigió el color de fuente del encabezado de confirmación al enviar una solicitud de préstamo. |

### Agosto de 2026: ajustes finales de archivo, búsquedas y préstamos

| Fecha | Commit | Responsable | Tarea realizada |
|---|---|---|---|
| 2026-08-02 | `5be9cb6` | rolnet69 | Se corrigió el espaciado del subtítulo en la pantalla de archivo de gestión para evitar superposición. |
| 2026-08-02 | `74a3439` | rolnet69 | Se corrigió el tamaño de la vista previa de impresión. |
| 2026-08-06 | `95e0dbb` | rolnet69 | Se sanitizó el número de caja para aceptar únicamente enteros positivos. |
| 2026-08-07 | `eb3b64f` | rolnet69 | Se evitó reasignar un documento a la misma ubicación y se corrigieron búsquedas en la pantalla de reasignación. |
| 2026-08-07 | `8f68475` | rolnet69 | Se corrigió la búsqueda de documentos en la pantalla de reasignación. |
| 2026-08-08 | `cc87abf` | rolnet69 | Se corrigieron las búsquedas de documentos reservados. |
| 2026-08-08 | `53fd3e7` | Bryan Aguilar | Se actualizó la hoja resumen de transferencias para formatear correctamente la fecha y hora de solicitud. También se agregaron reglas de unicidad de documentos por caja y ajustes de configuración y documentación. |
| 2026-08-09 | `d83cb2e` | Bryan Aguilar | Se implementó la notificación y el flujo relacionado con el envío de correcciones de préstamos, incluyendo ajustes en reportes, catálogo de solicitudes, archivos y pruebas. |
| 2026-08-09 | `4f183d1` | Bryan Aguilar | Se ampliaron los reportes para consultar documentos transferidos y filtrar resultados por fechas. |
| 2026-08-09 | `2d73dd0` | Bryan Aguilar | Se agregó `box_code` al modelo de cajas de transferencia, con migración, validaciones, generación, consultas, reportes y prueba funcional. |
| 2026-08-13 | `ebbb7f7` | Bryan Aguilar | Se actualizaron las funciones de gestión documental y creación de cajas. Se incorporó la relación entre transferencias y unidades, mejoras en notificaciones, permisos, comandos de vencimiento de préstamos y ajustes en archivo, documentos, reasignación, reportes y Docker. |
| 2026-08-13 | `f8e67f4` | Bryan Aguilar | Se corrigieron la ortografía y la redacción de mensajes de validación, respuestas, correos y textos de la interfaz en los módulos de autenticación, usuarios, series, archivo, transferencias, préstamos, reportes y notificaciones. |

## Integraciones de ramas registradas

Estos commits representan integraciones de trabajo y no se cuentan como tareas independientes para evitar duplicar funcionalidades ya descritas arriba:

| Fecha | Commit | Integración |
|---|---|---|
| 2026-04-19 | `d02adc1` | Integración de cambios desde `AlexHernandezSV/Bryan-dev`. |
| 2026-04-19 | `6387d23` | Integración posterior de cambios desde `AlexHernandezSV/Bryan-dev`. |
| 2026-04-26 | `ae9e38a` | Integración de cambios desde `AlexHernandezSV/Bryan-dev`. |
| 2026-05-03 | `3c9a546` | Integración de la rama `Bryan-dev` dentro de `Walter-dev`. |
| 2026-05-11 | `a941f91` | Integración de cambios desde `AlexHernandezSV/Bryan-dev`. |
| 2026-06-10 | `41eeb92` | Integración de cambios desde la rama remota `rolando-dev` hacia `Bryan-dev`. |
| 2026-06-25 | `3d93710` | Integración de la rama `Bryan-dev` dentro de `Walter-dev`. |
| 2026-07-01 | `d1b2cb6` | Integración de cambios desde la rama remota `Bryan-dev`. |

## Componentes y evidencias actuales

El resultado acumulado del trabajo se refleja actualmente en:

- Autenticación y seguridad: `AuthController`, `TwoFactorCodeMail`, `TemporaryPasswordMail`, bloqueo de cuentas, sanitización, permisos y bitácora de actividad.
- Administración: `UserController`, `UnitController`, `ProfileController`, modelos de usuarios, unidades, personas, roles y perfiles.
- Transferencias: `TransferController`, `Transfer`, `TransferBox`, `TransferDocument`, `TransferEvent`, `DetalleTransferencia.vue` y `NuevaSolicitud.vue`.
- Préstamos: `LoanController`, modelos de préstamos, despachos, devoluciones, documentos y modificaciones, además de sus vistas de autorización y detalle.
- Archivo: `ArchiveController`, `ArchivoGestion.vue`, `ArchivoGeneral.vue`, `CrearCajaArchivo.vue`, `CrearDocumentoArchivo.vue`, `DocumentosReservados.vue` y `ReasignacionArchivo.vue`.
- Clasificación: `DocumentarySeriesController`, `SeriesDocumentales.vue`, `DocumentarySeries` y `DocumentarySubseries`.
- Ubicaciones: `PhysicalLocationController`, modelos de oficinas, pasillos y estantes, y `UbicacionesFisicas.vue`.
- Reportes: `ReportController` y `Reportes.vue`, con resúmenes, consulta de acceso, clasificación, organización y documentos transferidos.
- Infraestructura: `docker-compose.yml`, `docker/start-app.sh`, `docker/start-vite.sh`, PostgreSQL y Mailpit.
- Pruebas: `tests/e2e/login.spec.js`, `tests/Feature/NormalizedAuthAndUserManagementTest.php`, `tests/Feature/RequestCatalogTest.php`, `tests/Feature/ReportSummaryTest.php` y `tests/Feature/ArchiveTest.php`.
- Documentación: `AGENTS.md`, `docs/TECHNICAL_SYSTEM_DOCUMENTATION.md` y `docs/INSTALLATION_ADMIN_ONLY.md`.

## Nota sobre el alcance

La lista representa las tareas que pueden comprobarse en el historial Git disponible del repositorio. Si existieron actividades realizadas fuera de Git —por ejemplo, reuniones, configuraciones manuales, pruebas no committeadas o cambios en otra rama que no forma parte de este historial— no pueden reconstruirse con certeza únicamente desde el código actual.
