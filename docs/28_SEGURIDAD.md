# Seguridad: revisión, pruebas y guía de despliegue

> Revisión técnica **propia** hecha el 2026-10-22 sobre una instalación aislada y desechable (SQLite, sin datos reales). No sustituye una auditoría independiente: ver la sección 7.

## 1. Alcance y método
| # | Qué se hizo | Cómo |
|---|---|---|
| 1 | Auditoría de dependencias | `composer audit` y `npm audit` (backend, frontend y pruebas E2E) |
| 2 | Escaneo dinámico (DAST) | OWASP ZAP 2.17 en modo API contra las 192 operaciones del documento OpenAPI, **con sesión de administración** y escaneo activo |
| 3 | Pruebas de control de acceso y aislamiento (IDOR) | Pruebas automáticas que recorren **todas** las rutas, con cada rol y con datos de otra compañía u otro vendedor |
| 4 | Revisión de configuración y código | Cabeceras, cookies, CORS, CSRF, límites de intentos, subida de archivos, llamadas salientes (SSRF), secretos, entradas hostiles |

## 2. Hallazgos y correcciones
Gravedad estimada para esta aplicación (datos de clientes y de crédito). «Corregido» significa que además hay una prueba automática que lo impide volver.

| # | Hallazgo | Gravedad | Estado |
|---|---|---|---|
| H1 | **Un vendedor podía cancelar promesas de pago de clientes de otro vendedor** de su compañía (la lista las ocultaba, pero `POST /credit/promises/{id}/cancel` no aplicaba el alcance del vendedor). Lo encontró la prueba de aislamiento. | Media | Corregido (`PaymentPromise::visibleCustomers`) |
| H2 | **Sin límite general de peticiones** en la API: permitía recorrer datos o saturar el servidor con una sola cuenta. | Media | Corregido: 600 por minuto y por persona (configurable con `API_RATE_PER_MINUTE`) |
| H3 | **Rociado de claves**: el límite de intentos era por usuario+dirección; probar una clave contra muchos usuarios desde una dirección no se frenaba. | Media | Corregido: 20 fallos por minuto por dirección |
| H4 | **La dirección de descarga de archivos de WhatsApp** la entrega Meta y se llamaba **con el token de acceso**: una respuesta alterada podía filtrarlo a un tercero o llevar al servidor a la red interna (SSRF). | Media | Corregido: solo HTTPS en dominios de Meta |
| H5 | **Destino del webhook de salida** configurable sin restricción: un administrador podía apuntarlo a la red interna o al servicio de metadatos de la nube (SSRF). | Media (requiere ser administrador) | Corregido: en producción exige HTTPS y rechaza direcciones privadas, locales y reservadas; se vuelve a comprobar en cada entrega |
| H6 | **Una cuenta desactivada con la sesión abierta** seguía viendo `/auth/me` y podía autorizar los canales de tiempo real. | Baja–Media | Corregido |
| H7 | **Sin cabeceras de seguridad** (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, CSP de la API, HSTS) y se anunciaba la versión de PHP (`X-Powered-By`). | Baja | Corregido |
| H8 | **Cookie de sesión sin marca `Secure` por omisión** en producción si se olvidaba la variable. | Baja (configuración) | Corregido: segura por omisión en producción; `ops:status` avisa si falta |
| H9 | **Auditoría de dependencias sin efecto**: la CI informaba pero no bloqueaba. | Informativa | Corregido: bloquea vulnerabilidades conocidas |
| H10 | La raíz del servidor mostraba la página de bienvenida de Laravel (anuncia el marco de trabajo). La encontró ZAP. | Baja | Corregido: la raíz responde 404 |
| H11 | Detrás de un proxy o balanceador todas las peticiones parecerían venir de la misma dirección, con lo que los límites de intentos se aplicarían a todo el mundo a la vez. | Baja (despliegue) | Corregido: variable `TRUSTED_PROXIES` |
| H12 | Parte de las pruebas de archivos hostiles usaba archivos «de mentira» de Laravel, que deducen el tipo por el nombre y **no probaban** la detección por contenido. | Informativa (pruebas) | Corregido: ahora son archivos reales |

Dependencias: `composer audit`, `npm audit` (frontend) y `npm audit` (E2E) → **0 vulnerabilidades conocidas**.

## 3. Resultado del escaneo dinámico (OWASP ZAP)
Escaneo activo de **211 solicitudes** (las 192 operaciones, con sus variantes) con sesión de administración, ~7 minutos, contra la instalación aislada ya corregida:

| Resultado | Cantidad |
|---|---|
| Comprobaciones superadas (inyección SQL, de comandos, de plantillas, XSS, rutas, XXE, SSRF, cabeceras…) | **118** |
| Fallos | **0** |
| Advertencias | 2 |
| Errores de aplicación registrados durante el escaneo | **0** (ninguna excepción no controlada) |

Las dos advertencias y la información que reporta:
- *Cookie sin HttpOnly* (`XSRF-TOKEN`): **por diseño**. Es el token anti-CSRF que la interfaz debe leer y reenviar; no da acceso a la sesión (la cookie de sesión sí es HttpOnly).
- *Tipo de contenido inesperado en `/`*: era la página de bienvenida de Laravel → corregido (H10).
- *Información sensible en la URL* (`hub_verify_token`, filtro `username`): el token de verificación lo impone el protocolo de Meta para registrar el webhook y se compara en tiempo constante; el filtro de usuario de Auditoría no es un secreto.
- Las 546 respuestas 4xx informativas son lo esperado: ZAP envía entradas inválidas y la API las rechaza.

Cómo repetirlo: `e2e/tools/zap-api-scan.sh <API> <origen de la interfaz>` (solo contra una instalación desechable).

## 4. Lo que se comprobó que ya estaba bien
- **Autenticación**: 189 de las 192 rutas de la API exigen sesión; las 3 públicas son el inicio de sesión y los dos webhooks de Meta, y estos últimos rechazan lo que no trae firma válida.
- **Autorización**: toda ruta con permiso rechaza (403) a quien está conectado pero no lo tiene; 173 de las 192 exigen un permiso concreto; las 16 que solo exigen sesión están enumeradas y revisadas, y agregar una nueva falla la prueba hasta revisarla.
- **Aislamiento entre compañías y entre vendedores**: más de 80 combinaciones de ruta × registro ajeno (cotizaciones, crédito, conversaciones, clientes, contactos, importaciones, administración…) responden 403/404 sin filtrar datos; ninguna lista contiene datos ajenos, tampoco pidiendo otra compañía con `company_id`.
- **CSRF**: una petición con sesión pero sin el token se rechaza (419); con un origen ajeno la sesión ni se reconoce (401). CORS permite solo el origen de la interfaz.
- **Inicio de sesión**: mismo mensaje para usuario inexistente y clave errónea; la sesión se regenera al entrar y se invalida al salir; contraseña mínima de 10 caracteres.
- **Archivos subidos**: se valida el contenido real (HTML, PHP, SVG, ejecutables o texto disfrazados de PDF/imagen se rechazan); se guardan en disco privado con nombre aleatorio y se descargan como adjunto con `nosniff`.
- **Inyección**: ninguna de las 102 rutas de lectura devolvió un error de servidor ante 12 cadenas hostiles (SQL, comodines, scripts, rutas, cadenas enormes, nulos) en 23 parámetros. Los importes del usuario siempre van como parámetros; el SQL dinámico sale de listas blancas (`phpstan.neon`).
- **Hojas de cálculo**: los textos que vienen de archivos importados se neutralizan al exportar (inyección de fórmulas).
- **Interfaz**: sin `dangerouslySetInnerHTML`, `innerHTML`, `eval` ni `document.write`; no guarda sesión ni datos en `localStorage` (solo una preferencia de sonido). Recorre las pantallas principales **sin una sola violación** bajo una política de contenido estricta (sección 6).
- **Secretos**: SMTP, WhatsApp, webhook y su firma se guardan cifrados y no se devuelven ni se escriben en Auditoría.

## 5. Medidas incorporadas (resumen técnico)
| Medida | Dónde |
|---|---|
| Cabeceras de seguridad en toda respuesta, HSTS bajo HTTPS, sin `X-Powered-By` | `App\Http\Middleware\SecurityHeaders`, `docker/security.ini` (`expose_php = Off`) |
| Límite general de la API por persona (y por dirección sin sesión) | `RateLimiter::for('api')` en `AppServiceProvider`; `API_RATE_PER_MINUTE` (600) |
| Límite de intentos de inicio de sesión por usuario+dirección **y** por dirección | `AuthController`; `LOGIN_RATE_PER_IP` (20/min) |
| Cuenta desactivada: `me` y los canales de tiempo real también la rechazan | `routes/api.php`, `bootstrap/app.php` |
| Promesas de pago con el alcance del vendedor | `PaymentPromise::scopeVisibleCustomers` |
| Llamadas salientes seguras (SSRF) | `App\Support\PublicUrl`, `App\Rules\SafeOutboundUrl`, `GraphApiWhatsapp::isMetaMediaUrl` |
| Cookie de sesión `Secure` por omisión en producción | `config/session.php`; `ops:status` la verifica |
| Auditoría de dependencias que bloquea | `.github/workflows/ci.yml` |
| Documentación de la API solo para administración activa | `Gate viewApiDocs` |

### Pruebas que lo protegen (`backend/tests/Feature/Security`, 56 pruebas)
- `AccessMatrixTest`: sin sesión → 401 en todas las rutas; sin permisos → 403 en todas las que exigen uno; lista cerrada de rutas sin permiso; cuenta desactivada; webhooks sin firma.
- `TenantIsolationTest`: datos de otra compañía y de otro vendedor en todas las rutas con identificador, listas y filtros `company_id`.
- `HardeningTest`: cabeceras, límites, rociado de claves, enumeración de usuarios, secretos, archivos hostiles reales, plantillas con rutas, entradas hostiles en 102 lecturas, `ops:status` en producción.
- `OutboundSafetyTest`: SSRF en webhooks y descarga de archivos de Meta.
- `e2e/tests/08-politica-de-contenido.spec.ts`: la interfaz funciona bajo una CSP estricta.

## 6. Despliegue seguro (lista para quien publica)
**Servidor web de la interfaz** (la API ya envía sus cabeceras; la interfaz las debe enviar quien la publica). Política verificada con la interfaz real, sin violaciones:
```nginx
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://API.EJEMPLO.COM; connect-src 'self' https://API.EJEMPLO.COM wss://TIEMPO-REAL.EJEMPLO.COM; font-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "no-referrer" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```
(`'unsafe-inline'` en estilos lo exigen los estilos en línea de React y los gráficos; los scripts quedan sin él.)

**Variables de entorno obligatorias en producción**
| Variable | Valor |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` (con `true` se muestran trazas y variables) |
| `SESSION_SECURE_COOKIE` | `true` (o dejarla sin definir: en producción es `true`) |
| `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `FRONTEND_URL` | el dominio real de la interfaz y la API (mismo sitio) |
| `LOG_CHANNEL` | `json` |
| `ALLOW_PRIVATE_URLS` | sin definir (en producción se rechazan siempre las direcciones internas) |
| `REVERB_SCHEME` | `https` y WebSocket por TLS |
| `TRUSTED_PROXIES` | si hay un balanceador o proxy, configurar los proxies confiables para que el límite de intentos use la dirección real del cliente y no la del proxy |

**Verificar tras desplegar**: `php artisan ops:status` (avisa si queda `APP_DEBUG` o la cookie sin `Secure`), `curl -I https://API/api/v1/auth/me` (cabeceras) y que `https://API/docs/api` responda 403 sin sesión de administración.

**Base de datos**: usuario de la aplicación sin `UPDATE`/`DELETE` sobre `audit_logs`; copias de seguridad cifradas y fuera de la VM (ver `docs/26_OPERACION.md`).

## 7. Riesgos que quedan y recomendaciones
| # | Tema | Riesgo | Recomendación |
|---|---|---|---|
| R1 | **Sin segundo factor (2FA)** | Una contraseña robada da acceso completo a esa cuenta | Muy recomendable para Administrador y Gerencia antes de producción |
| R2 | ~~Sin cambio de clave por la propia persona~~ **RESUELTO (2026-10-22, ADR-013)**: las claves fijadas por administración son temporales y se cambian en el primer ingreso; cualquiera puede cambiar la suya. | — | Pendiente solo la recuperación autónoma («olvidé mi clave»), que exige correo configurado por cada persona |
| R3 | ~~Cierre de sesión que no cierra / sesiones que sobreviven a un cambio de clave~~ **RESUELTO (2026-10-23, ADR-014)**: «época de sesión» por usuario. Sigue vigente el tiempo de inactividad de 120 minutos (`SESSION_LIFETIME`): bajarlo es una decisión de uso. | — | Opcional: reducir `SESSION_LIFETIME` |
| R4 | **Servidor SMTP y webhook configurables por administración** | Un administrador malicioso podría usar el servidor como proxy (el webhook ya rechaza la red interna; el SMTP no) | Limitar quién tiene `mail.manage`; registrar y alertar los cambios (ya quedan en Auditoría) |
| R5 | **Hojas de cálculo (`.xlsx`) subidas** | Archivos comprimidos hostiles (bombas de descompresión) | El tamaño está limitado (20 MB) y la lectura es por flujo, pero conviene un límite de memoria/tiempo del proceso `queue` (ya tiene 1 GB) |
| R6 | **Comunicación con Meta** | El secreto de la aplicación firma los webhooks | Rotarlo si alguien con acceso a la configuración deja la empresa |
| R7 | **Copias de seguridad y `audit_logs`** | Fuera del alcance del código | Seguir `docs/26_OPERACION.md` y revocar `UPDATE`/`DELETE` sobre `audit_logs` |
| R8 | **Esto no es un pentest independiente** | Una revisión propia comparte los puntos ciegos de quien construyó el sistema | Encargar una prueba de penetración externa antes de publicar a producción (con datos reales), idealmente con cuentas de cada rol |
| R9 | **Reverb (WebSocket)** | No se probó con herramientas de ataque; la autorización de canales sí está probada | Incluirlo en la prueba externa |

## 8. Cómo mantenerlo
- Las pruebas de `tests/Feature/Security` corren en cada Pull Request: una ruta nueva sin permiso, o que deje ver datos ajenos, rompe la CI.
- `composer audit` y `npm audit` bloquean la CI ante vulnerabilidades conocidas; Dependabot propone las actualizaciones cada mes.
- Repetir el escaneo de ZAP antes de cada entrega importante (sección 3) y tras cambiar autenticación, archivos o llamadas salientes.
- Una vulnerabilidad nueva se registra aquí (tabla de hallazgos) con su prueba automática.
