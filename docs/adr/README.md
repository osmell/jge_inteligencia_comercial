# Decisiones de arquitectura (ADR)

Un ADR es una nota corta que responde **por qué** el sistema está hecho así, para que quien llegue después (o nosotros dentro de un año) no tenga que adivinarlo ni deshacerlo sin saberlo.

- **Cuándo escribir uno**: cuando una decisión es costosa de revertir, afecta a varios módulos o alguien preguntará «¿por qué no se hizo de otra forma?». Las decisiones de **negocio** siguen en los documentos de cada fase en `docs/`.
- **Cómo**: copiar `000-plantilla.md` a `NNN-titulo-corto.md` con el siguiente número. Un ADR aceptado **no se edita**: si la decisión cambia, se escribe uno nuevo que lo reemplaza y el viejo pasa a «Reemplazado por ADR-NNN».
- **Estados**: Propuesto · Aceptado · Reemplazado.

| N.º | Decisión | Estado |
|---|---|---|
| [001](001-autenticacion-sesion-spa.md) | Autenticación por usuario y sesión de cookie (Sanctum SPA) | Aceptado |
| [002](002-postgresql-y-pruebas-sqlite.md) | PostgreSQL en producción, SQLite en memoria en pruebas, SQL portable | Aceptado |
| [003](003-cantidades-e-importes-enteros.md) | Importes y cantidades como enteros escalados, nunca `float` | Aceptado |
| [004](004-importaciones-como-tipos-enchufables.md) | Importaciones como tipos enchufables con validación en cola | Aceptado |
| [005](005-autorizacion-en-capas.md) | Autorización en capas: permiso de ruta, alcance de datos y Policy | Aceptado |
| [006](006-libro-inmutable-credito-e-inventario.md) | Crédito e inventario como libros de movimientos inmutables | Aceptado |
| [007](007-whatsapp-cloud-api-y-mensajes-unificados.md) | WhatsApp Cloud API, tabla de mensajes unificada y ventana de 24 h | Aceptado |
| [008](008-tiempo-real-con-reverb.md) | Tiempo real con Laravel Reverb y canales privados por persona | Aceptado |
| [009](009-puertos-para-sistemas-externos.md) | Puertos (interfaces) para WhatsApp y webhooks | Aceptado |
| [010](010-secciones-del-dashboard-enchufables.md) | Dashboard por secciones enchufables | Aceptado |
| [011](011-calidad-automatizada-phpstan-8.md) | Calidad automatizada: PHPStan nivel 8, `strict_types` y reglas de arquitectura | Aceptado |
| [012](012-contrato-de-api-generado.md) | Contrato de la API generado del código (OpenAPI) y tipos del frontend derivados | Aceptado |
