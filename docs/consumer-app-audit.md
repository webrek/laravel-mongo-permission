# Corrección de la auditoría de la aplicación consumidora

Estado: **corregido localmente el 2026-09-14**, en la rama `fix/permission-audit`. La aplicación consumidora usa el paquete por enlace Composer y ya ejecuta estas correcciones. No se ha publicado una versión ni enviado los cambios a GitHub.

## Validación final

- Suite del paquete: **211 pruebas, 354 aserciones**, todas correctas. Incluye las 172 originales y 39 pruebas de regresión, seguridad y concurrencia.
- Aplicación Laravel: **35 pruebas, 84 aserciones**, todas correctas: 28 escenarios de auditoría y 7 flujos funcionales.
- PHPStan nivel 5: **sin errores**, sin nuevas exclusiones ni supresiones.
- Caché de archivos: cuatro procesos independientes, 40 incrementos cada uno; resultado exacto **160**, sin actualizaciones perdidas.
- `permission:create-indexes` ejecutado en el laboratorio. Aplicación disponible en http://127.0.0.1:8018.

La prueba de herencia entre equipos ahora comprueba que la API rechaza la arista con `TeamDoesNotMatch`; se añadió otra prueba que inserta una arista inválida como dato legacy y comprueba que las lecturas tampoco conceden ese permiso. El resultado esperado sigue siendo denegar el acceso, y se verifica tanto al escribir como al leer.

## Qué cambió

Scope común para equipos y guards; búsquedas con preferencia por catálogo del equipo y fallback global; sincronizaciones que conservan otros equipos/guards; renovación de grants; compare-and-swap de arrays de asignaciones y permisos de roles; generación de caché protegida con locks compartidos; lectura de versiones entre procesos; respeto de store y TTL; invalidación sin vaciar caché ajena; validación de profundidad de descendientes y locks para cambios de jerarquía; comandos con herencia, wildcard y datos legacy; índices inversos en usuarios.

## Rendimiento: mismas operaciones y datos sintéticos

| Operación | Antes | Después |
|---|---:|---:|
| 100 comprobaciones denegadas | 100 consultas find | 1 consulta find |
| 100 hasRole por nombre | 100 consultas find | 2 consultas find |
| Permiso con 10 roles y padre compartido | 12 consultas find | 4 consultas find |
| Asignar 100 permisos | 200 find + 1 update | 3 find + 1 getMore + 1 update |
| Consulta inversa de usuarios por rol | 1,004 documentos examinados | 1 documento examinado |

Las lecturas iniciales rellenan la caché; las comprobaciones siguientes reutilizan esos datos. El primer permiso permitido pasa de 2 a 3 consultas porque se vuelve a leer el usuario antes de reconstruir su autorización. Los 100 checks permitidos calientes siguen haciendo cero consultas MongoDB, pero la muestra pasa de 0.592 ms a 3.392 ms por la comprobación de generaciones compartidas. Los tiempos locales son orientativos; no son percentiles ni garantías de producción. Ver `permission-lab/docs/benchmark.json` y `benchmark-after.json` para los comandos completos.

## Compatibilidad y uso

- Utilizar un store Laravel compartido que implemente locks atómicos (por ejemplo file o Redis) para múltiples workers. Array cache es apropiado para tests aislados.
- La caducidad de caché predeterminada es ahora 86400 segundos; un config publicado con null también usa ese límite. Las generaciones obsoletas se retiran por TTL; el reset cambia de generación y no purga claves de otros módulos.
- Los grants globales siguen siendo reutilizables como definiciones, pero las asignaciones pertenecen a un equipo. Operaciones explícitas con modelos de otro equipo lanzan `TeamDoesNotMatch`.
- Las mutaciones de grants usan actualizaciones atómicas de sus arrays y emiten los eventos del paquete. No guardan otros atributos pendientes del modelo; usar `save()` por separado para cambios ajenos a permisos.
- Las escrituras directas de query builder no ejecutan eventos de modelo. Usar las APIs del paquete o invalidar explícitamente después de una edición masiva del catálogo.
- No se ejecutó toda la matriz de PHP/Laravel ni el análisis de mutación de CI. La prueba multiproceso usa file cache local; no se ha probado Redis distribuido ni Octane real.

## Reproducir

En el paquete:

```sh
MONGO_DB_HOST=127.0.0.1 MONGO_DB_PORT=27018 MONGO_DB_DATABASE=permission_baseline_test php vendor/bin/phpunit
php vendor/bin/phpstan analyse --memory-limit=1G
```

En `/Users/victor/Sites/permission-lab`:

```sh
php artisan test
composer lab:benchmark
```

Los tests usan bases con sufijo `_test` y el benchmark reinicia exclusivamente `permission_benchmark_test`.

---

# Evidencia histórica: auditoría antes de las correcciones

Los resultados y propuestas siguientes corresponden al código base `d3cb5a4`, antes de esta rama. Los fallos históricos se conservan como evidencia, no como pendientes actuales.

# Auditoría con aplicación consumidora Laravel + MongoDB

Fecha de trabajo: 2026-09-13. Código verificado: `webrek/laravel-mongo-permission` main `d3cb5a4` (el commit de v1.7.0 es `227ea3d`).

## Entorno y método

Aplicación en `/Users/victor/Sites/permission-lab`, paquete enlazado desde `/Users/victor/Sites/permisos`. Laravel 12.69.2, PHP 8.3.31, extensión mongodb 1.21.7, integración mongodb/laravel-mongodb 5.11.0, MongoDB 7 en Docker con puerto local 27018. Autenticación y artículos reales, almacenamiento MongoDB; pruebas aisladas en `permission_lab_test`.

La copia local inicial (`92d5cb6`) estaba nueve commits atrasada. Se actualizó con fast-forward a origin/main. Tres problemas de invalidación al sincronizar, revocar y eliminar roles se reproducían en esa copia, pero **ya están corregidos en el código actual**. No deben abrirse como bugs nuevos. Se verificó que `src/` y `config/` no tienen diferencias entre v1.7.0 y el main auditado.

La suite existente del paquete pasa: **172 pruebas, 286 aserciones** con las dependencias locales disponibles. No equivale a validar toda la matriz de CI. La plataforma tiene pruebas HTTP de autenticación, páginas, creación y asignación, restricciones 403 y flujo editorial; ver `composer lab:test`.

## Casos actuales reproducibles

Ejecutar desde la aplicación: `composer lab:audit`. Los métodos están en `tests/Feature/PackageAuditTest.php`. Los nombres siguientes omiten el prefijo `test_`.

| Prioridad | Caso | Resultado observado | Resultado esperado / implicación |
|---|---|---|---|
| Alta | `role_name_resolves_in_active_team` | Creando reviewer en alpha y beta, findByName en beta devuelve el rol de alpha. | Resolver por equipo activo; un nombre repetido no debe elegir otro tenant. |
| Alta | `same_role_can_be_granted_in_two_teams` | Otorgar el mismo rol global en alpha y beta solo conserva la asignación de alpha. | La identidad de una asignación debe incluir equipo y rol. |
| Alta | `removing_in_another_team_preserves_original_grant` | removeRole en beta elimina la asignación de alpha aunque beta no la tenía. | Una revocación debe limitarse al equipo activo. |
| Alta | `role_rejects_permission_from_other_guard` | Un rol web acepta una instancia Permission con guard api, sin excepción. | Aplicar GuardDoesNotMatch también a las mutaciones de Role. |
| Media | `cache_reset_preserves_unrelated_application_data` | permission:cache-reset elimina una clave ajena al paquete. | Limpiar solo el namespace del paquete; hoy utiliza Cache::flush. |
| Media | `missing_permission_can_return_false_when_configured` | throw_on_missing_permission=false sigue lanzando PermissionDoesNotExist. | Respetar la opción documentada y devolver false. |
| Media | `expired_role_can_be_renewed` | Reasignar un rol vencido con fecha futura no renueva el grant. | Permitir renovación o proporcionar una API explícita; actualmente falla silenciosamente. |

Resultado actual: **7 fallos, 3 casos correctos, 15 aserciones**. Los casos de equipo activan `teams=true` y `strict_team_isolation=true`; la UI mantiene `teams=false`. La renovación se clasifica como funcionalidad faltante o comportamiento no documentado, además de ser una expectativa fallida.

## Causas y correcciones propuestas

1. `Models/Role::findByName` filtra nombre y guard, pero no team_id. Definir una política única para la resolución por equipo y fallback global, aplicable también a los permisos y búsquedas por ID.
2. `Traits/HasRoles::attachRoles` deduplica solo por role_id. Usar la pareja (role_id, team_id); revisar el mismo patrón en permisos directos.
3. `Traits/HasRoles::removeRole` elimina por role_id sin scope. Aplicar scope a remove y sync, y emitir el team correcto para invalidar cachés.
4. `Models/Role::resolvePermissionIds` acepta instancias sin validar guard. Validar antes de guardar cualquier cambio, incluida syncPermissions.
5. `PermissionRegistrar::flush` llama Cache::flush; aprovechar invalidación por generación sin tocar claves de la app. Considerar además almacenamiento y caducidad configurados.
6. `Traits/HasPermissions::hasPermissionTo` consulta findByName sin atender throw_on_missing_permission. Condicionar la excepción a la opción.
7. `attachRoles` devuelve temprano si ya existe el ID aunque el grant esté vencido. Definir reemplazo/renovación con actualización de expires_at e invalidación.

Estas correcciones están propuestas, no implementadas. El código fuente del paquete se conserva tal como está en origin/main para tener una base reproducible.

## Cobertura y siguientes capacidades útiles

La auditoría no es exhaustiva ni incluye pruebas de concurrencia, carga o todos los guards y configuraciones. Siguientes capacidades recomendadas a partir del uso del panel:

- Renovar y cambiar la caducidad de grants desde una API explícita.
- Operaciones consistentes de asignar, sincronizar y revocar dentro de un equipo.
- Explicar el origen de un permiso efectivo (directo, rol, ancestro o wildcard), útil para soporte y para un inspector de acceso.
- Auditoría persistente en la aplicación consumidora de cambios de acceso; los eventos del paquete son una base, no un historial durable.

El flujo de navegador y las pruebas HTTP se documentan en el README de la aplicación. No se ha hecho publicación ni se han enviado issues externos.

## Uso real en Safari

Se inició sesión como editor, se creó «Prueba real: flujo editorial» y se comprobó que no aparecía Publicar. Una navegación directa a /roles devolvió la página 403. Después se entró como admin, se otorgó articles.publish al rol editor y se guardó. En una nueva sesión del editor, el permiso apareció como Permitido y se publicó el artículo correctamente, sin limpiar la caché manualmente. Finalmente se restauró el rol editor a lectura y creación. El artículo permanece publicado como evidencia del flujo.

## Ampliación: seguridad, consistencia y rendimiento

La segunda ronda añade 18 escenarios: 16 fallan y 2 pasan. El total combinado es **28 escenarios: 23 fallidos y 5 correctos, 42 aserciones**. Hay causas compartidas entre roles y permisos, por lo que esta cifra no equivale a 23 bugs independientes. Código reproducible: `tests/Feature/ExtendedAuditTest.php` de la aplicación. Salida: `docs/audit-extended.txt` y `docs/audit-all.txt`.

| Área | Caso ampliado | Observación confirmada | Prioridad |
|---|---|---|---|
| Autorización | `team_revocation_invalidates_cached_authorization` | En alpha, se calienta la caché, se revoca un permiso directo y hasDirectPermission da false, pero hasPermissionTo sigue dando true. El evento de revocación lleva team null y no borra la entrada alpha. | Alta |
| Herencia | `hierarchy_cannot_grant_other_tenant_permissions` | Un rol beta hereda de alpha; un usuario beta recibe el permiso tenant.secret de alpha aun con strict_team_isolation=true. | Alta |
| Guards | `hierarchy_rejects_cross_guard_parent` | Un rol web acepta padre api sin GuardDoesNotMatch. | Alta |
| Escrituras | `two_loaded_users_do_not_lose_independent_grants` | Dos instancias del mismo usuario se leen antes de guardar. La primera otorga create, la segunda publish: se pierde create. Reproduce determinísticamente una intercalación de escrituras; no es una prueba de carga paralela. | Alta |
| Generación de caché | `overlapping_registrars_do_not_reuse_cache_generation` | Dos registrars leen la misma versión; ambos incrementos escriben el mismo número. El segundo cambio no genera una clave distinta. | Alta |
| Procesos persistentes | `long_lived_registrar_sees_external_role_revocation` | Un registrar ya calentado no observa una revocación ejecutada por otro registrar. Reproduce una instancia de larga vida; no se instaló ni validó Octane. | Alta si se usa ese modelo de ejecución |
| Equipo | `permission_lookup_respects_active_team` | findByName de Permission selecciona el documento del otro equipo. | Alta |
| Equipo | `direct_permission_can_be_granted_in_two_teams` | La deduplicación por ID impide el segundo grant del mismo permiso en otro equipo. | Alta |
| Equipo | `direct_permission_revocation_does_not_touch_other_team` | Una revocación desde beta elimina el grant de alpha. | Alta |
| Lecturas | `permission_listing_agrees_with_active_team` | getAllPermissions expone un permiso de alpha dentro de beta aunque hasDirectPermission lo niega. | Media |
| Jerarquía | `extending_existing_ancestor_respects_total_depth` | Con máximo 2, se forma A→B→C y luego C→D; la operación permite una cadena total de 3. Solo se valida hacia arriba del nodo modificado. | Media |
| Expiración | `expired_direct_permission_can_be_renewed` | Reotorgar con fecha futura conserva el grant vencido. | Media |
| Configuración | `configured_cache_store_is_used` | permission.cache.store no dirige las entradas al store configurado. | Media |
| Configuración | `configured_cache_ttl_is_respected` | permission.cache.expiration_time=1 deja la entrada activa después de avanzar el reloj 2 segundos. | Media |
| CLI | `cli_lists_users_with_inherited_permission` | hasPermissionTo reconoce la herencia pero permission:list-users --permission informa cero usuarios. | Media |
| CLI / legacy | `cli_lists_legacy_flat_role_assignments` | hasRole reconoce IDs planos, pero permission:list-users omite ese usuario. | Media |

Controles positivos de la ampliación: la expiración de un permiso directo sí corta acceso con caché caliente y la detección de ciclos simples funciona. Los siete flujos funcionales de la plataforma también pasan, con 41 aserciones.

### Mediciones

Ejecutar `composer lab:benchmark` desde la aplicación. Instrumentación con CommandSubscriber del driver, sin registrar contenidos de consultas ni credenciales. MongoDB 7.0.41 local, cuatro usuarios funcionales y 1,000 documentos sintéticos para el explain. Los tiempos son una muestra orientativa, no percentiles ni capacidad de producción; el conteo de comandos es la evidencia principal.

| Operación | Lecturas MongoDB | Tiempo de la muestra |
|---|---:|---:|
| Primer permiso permitido | 2 | 0.959 ms |
| 100 comprobaciones permitidas, caché caliente | 0 | 0.592 ms |
| 100 comprobaciones denegadas, caché caliente | 100 | 39.927 ms |
| 100 hasRole por nombre | 100 | 41.621 ms |
| Primer permiso de usuario con 10 roles que comparten padre | 12 | 7.222 ms |
| Asignar 100 permisos directos | 200, más 1 escritura | 74.697 ms |

La consulta inversa que usa Role::users examinó **1,004 documentos** y devolvió uno, aun después de ejecutar permission:create-indexes. Con índices de prueba sobre users.role_ids y users.role_ids.role_id examinó **1 documento y 1 clave**. Los índices se añadieron solo a permission_benchmark_test. Esto valida esa optimización para la forma de consulta y datos ensayados; no mide todavía su coste de escritura.

También se verificó que la entrada de una generación anterior sigue almacenada después de guardar un rol. Como se usa rememberForever y se ignora el TTL configurado, las generaciones obsoletas pueden acumularse; no se midió una curva de memoria ni crecimiento en producción.

### Orden de trabajo recomendado

1. Corregir primero las revocaciones, la identidad (equipo, guard, ID) y los límites de herencia; cubrir mutaciones y lecturas con la misma política de scope.
2. Evitar pérdida de actualizaciones mediante operaciones atómicas o compare-and-swap; para grants estructurados no basta aplicar addToSet sin considerar equipo y expiración. MongoDB documenta la atomicidad por documento y el uso de condiciones de actualización en [Atomicity and Transactions](https://www.mongodb.com/docs/manual/core/write-operations-atomicity/).
3. Hacer atómica la generación de caché, respetar store y TTL, conservar claves ajenas y definir un ciclo de vida seguro para registrars persistentes.
4. Reducir lecturas repetidas: cachear catálogo y resolución de nombres incluyendo equipo y guard; hacer batch de los 100 permisos; recorrer ancestros compartidos una vez por evaluación. Medir otra vez después del cambio.
5. Añadir índices inversos según modelos/colecciones configurados y evitar escanear todos los usuarios en list-users; mantener resultados consistentes con herencia, wildcard, TTL y datos legacy.

Fuentes del instrumental: [CommandSubscriber de PHP](https://www.php.net/manual/en/class.mongodb-driver-monitoring-commandsubscriber.php) y [MongoDB Client::addSubscriber](https://www.mongodb.com/docs/php-library/v1.x/reference/method/mongodbclient-addsubscriber/).

La revisión deja evidencia y propuestas; no se han aplicado correcciones al código del paquete. No cubre despliegues distribuidos, stress, transacciones en replica set, compatibilidad completa con Laravel 13 ni una auditoría de seguridad exhaustiva.
