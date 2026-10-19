# Contexto Maestro — JGE Inteligencia Comercial
**Versión:** 1.0 · **Estado:** Base aprobada para análisis y desarrollo por fases  
**Proyecto:** Plataforma web de Inteligencia Comercial, Seguimiento de Clientes, Crédito e Inventario para JGE Soluciones Integrales.

## 1. Instrucción principal para Claude Code
Actúa como arquitecto de software y desarrollador senior. Antes de modificar código, lee este documento y el contexto de la fase activa. No inventes reglas de negocio ni alteres decisiones aprobadas. Si una decisión bloquea el desarrollo, registra la pregunta y detente en ese punto. Trabaja en cambios pequeños, comprobables y documentados. No implementes varias fases simultáneamente salvo autorización expresa.

## 2. Objetivo
Construir una aplicación web para analizar ventas por compañía, sucursal, vendedor, facturador, cliente y producto; gestionar ficha 360°; organizar seguimientos y cotizaciones; controlar crédito y abonos; y controlar inventario con equivalencias de unidades. Debe transformar datos operativos importados desde Excel en indicadores accionables, sin confundir ventas, rentabilidad, deuda y cobros.

## 3. Decisiones aprobadas
- Estructura empresarial: **varias compañías del mismo grupo**, cada una con sus sucursales.
- Ventas: importación diaria.
- Identificación del cliente en ventas: existe código estable.
- Identificación del vendedor: cada venta incluye vendedor.
- Costos: disponibles y actualizados según lo informado; se debe validar historial y semántica de campos.
- Inventario: existen existencias por producto y sucursal.
- Un cliente puede ser atendido por varios vendedores.
- Facturas de crédito: incluyen fecha de vencimiento y condiciones de pago.
- WhatsApp: registro manual al inicio; integración futura con API oficial.
- Metas: mensuales por vendedor.
- Backend y frontend en contenedores separados.
- Base de datos PostgreSQL en una máquina virtual dedicada.
- Mantener el stack usado en proyectos relacionados: Laravel + PostgreSQL + React + TypeScript + Tailwind CSS + Redis/colas; Docker; n8n solo cuando aporte valor.
- Acceso con nombre de usuario único y contraseña; no usar correo como nombre de usuario.
- Cuatro roles: Administrador, Gerente General, Supervisor de Ventas y Vendedor.
- Añadir catálogo/entidad de facturadores y KPI de desempeño de facturación.
- Ventas, clientes, productos, entradas de inventario y facturas de crédito se alimentarán por plantillas. La plantilla de ventas existe; las demás están en preparación. Los abonos/cancelaciones se registran manualmente.
- El logo JGE utiliza rojo, azul, gris y blanco. La paleta propuesta se detalla en el contexto de interfaz.

## 4. Stack de referencia
- Backend: Laravel (usar la versión compatible aprobada en el repositorio/proyecto; no actualizar versiones mayores sin autorización).
- API: REST versionada `/api/v1`.
- Frontend: React + TypeScript + Vite.
- UI: Tailwind CSS, componentes accesibles y gráficos con una librería mantenida ya presente en el repositorio; si no existe, proponer antes de incorporar.
- Base de datos: PostgreSQL alojado en VM dedicada; no incluir la base de datos dentro del contenedor de aplicación.
- Colas/cache: Redis.
- Infraestructura: Docker Compose para backend y frontend separados; configuración por variables de entorno; secretos fuera del repositorio.
- Archivos: almacenamiento privado para plantillas, cotizaciones y adjuntos; servir descargas mediante autorización.
- Pruebas: pruebas unitarias y de integración para reglas financieras, permisos e importaciones.
- No asumir que n8n es obligatorio para lógica crítica; reglas transaccionales permanecen en Laravel.

## 5. Modelo funcional de alto nivel
1. Organizaciones: compañías y sucursales.
2. Identidad: usuarios, roles, permisos, sesiones y auditoría.
3. Catálogos: tipos de cliente, líneas, categorías, productos, unidades, equivalencias, facturadores, motivos/resultados.
4. Ventas: cabecera lógica de factura y líneas de detalle importadas desde Excel.
5. Clientes: ficha 360°, contactos, asignaciones múltiples de vendedores y segmentación.
6. Seguimiento: actividades, tareas, resultados, próxima acción y archivos adjuntos.
7. Cotizaciones: cabecera, detalle cuando esté disponible, documento adjunto, estados y relación con venta.
8. Crédito: facturas, vencimientos, abonos, asignaciones de pago y saldo calculado.
9. Inventario: saldo inicial y movimientos trazables por producto/sucursal/unidad.
10. Analítica: KPI, metas mensuales, alertas y reportes.
11. Importaciones: archivo, tipo, usuario, validación, filas aceptadas/rechazadas, errores y trazabilidad.

## 6. Plantilla de ventas inspeccionada
Archivo recibido: `Plantilla Ventas.xlsx`, hoja `Hoja1`. Se observan 18 columnas:
- Documento
- Serie
- Código Cliente
- Nombre Cliente
- Código del Producto
- Und.Vendidas
- Nombre del Producto
- Costo Tot.Vta.
- Costo Unit.Vta.
- Precio Unit.Vta.
- Precio Unit.Normal
- Total Neto
- Total Impuesto
- Venta + Imp
- Gran Total
- Nombre Vendedor
- Nombre Nivel 2 Inv
- Nombre Facturador

La muestra contiene filas de detalle por producto y repite el documento en múltiples líneas. La importación debe agrupar lógicamente por documento/serie y preservar cada línea. No asumir que la muestra contiene fecha, compañía, sucursal, moneda, tipo de venta o estado del documento: no aparecen entre las 18 columnas observadas. Confirmar cómo se obtienen esos datos antes de producir KPI temporal, multiempresa, sucursal o crédito.
**Actualización (2026-10-18):** el responsable del Excel confirmó que se agregarán columnas de fecha, compañía y sucursal; que `Documento` + `Serie` es el identificador único; que `Costo Unit.Vta.` es costo unitario; que Nivel 2 es la categoría (existe Nivel 1 = línea); que no vienen facturas anuladas; y que la columna del facturador se llama `Nombre Operador`. Los datos de la plantilla son ficticios. Detalle y pendientes en `10_DECISIONES_PENDIENTES.md`.

**Observación de calidad:** en la muestra hay valores en que `Venta + Imp` y `Gran Total` no coinciden, y también diferencias aparentes entre `Total Neto`, impuesto y total. No corregir silenciosamente: definir la semántica con el propietario del dato y crear controles de conciliación.
Los nombres “Nombre Nivel 2 Inv” y “Nombre Facturador” requieren definición funcional. El primero parece una clasificación de inventario, pero no debe darse por hecho que sea línea/categoría hasta confirmación.

## 7. Paleta corporativa inicial
Basada visualmente en el logo, pendiente de validar contra los colores exactos del archivo original:
- JGE Rojo: `#E50918` — acentos, alertas de atención y elementos de marca.
- JGE Azul: `#07579B` — color primario, navegación y acciones principales.
- Gris carbón: `#353535` — texto secundario/elementos neutros.
- Blanco: `#FFFFFF` — superficies.
- Fondo claro: `#F5F7FA`.
- Borde: `#E2E8F0`.
- Verde de éxito: `#16803C`.
- Ámbar de advertencia: `#B7791F`.
- Rojo de error: `#C62828`.
Usar rojo corporativo con moderación para evitar que toda la interfaz parezca un estado de error. No depender únicamente del color para comunicar estados; acompañar con texto/icono. Garantizar contraste accesible. Los colores de estado no deben confundirse con los colores de marca.

## 8. KPI y definiciones que requieren consistencia
- Venta neta, impuesto, total facturado y costo son métricas separadas.
- Margen bruto = venta neta menos costo de venta validado; confirmar si el costo importado incluye impuestos/otros componentes.
- Cliente nuevo: primera compra registrada en el universo histórico disponible; advertir si el historial es incompleto.
- Cliente recuperado: cliente que vuelve a comprar después de un periodo configurable de inactividad, basado en su frecuencia histórica o regla aprobada.
- Cliente en riesgo: señal preventiva, no etiqueta definitiva de pérdida.
- Cotización convertida: existe vínculo comprobable con una factura/venta; no atribuir automáticamente toda venta posterior.
- Scoring comercial y scoring de pago son dimensiones distintas.
- Saldo de crédito se calcula desde importe original, abonos aplicados y ajustes autorizados; nunca se modifica directamente sin movimiento trazable.
- Rotación no es lo mismo que facturación: ofrecer unidades, frecuencia, valor, velocidad de salida y cobertura de inventario cuando los datos lo permitan.
- KPI de facturadores debe medir velocidad/calidad de emisión y errores únicamente si existen marcas de tiempo y eventos fiables. La plantilla actual identifica el facturador, pero no incluye fecha/hora de emisión ni duración.

## 9. Seguridad y permisos
Aplicar autorización en backend, no solo ocultar controles en frontend. Todos los registros empresariales deben tener compañía y, cuando corresponda, sucursal. Gerencia ve el ámbito autorizado; supervisores ven su equipo/ámbito; vendedores solo clientes y datos autorizados. Como un cliente puede tener varios vendedores, modelar asignaciones muchos-a-muchos con responsable principal opcional. Registrar accesos y operaciones sensibles. No almacenar contraseñas en texto plano. Proteger archivos adjuntos y limitar tipos/tamaños.

## 10. Reglas de importación
- Vista previa antes de confirmar importación.
- Validar encabezados, tipos, filas vacías y duplicados.
- Identificar errores por fila y columna.
- No hacer importación parcial silenciosa.
- Permitir modo de validación sin escribir datos.
- Mantener hash/identificador de archivo, usuario, fecha, resultado y errores.
- Definir una clave idempotente por tipo de plantilla; no asumir que `Documento` es único sin considerar `Serie`, compañía y otras dimensiones.
- No borrar o sobrescribir ventas históricas automáticamente.
- Las correcciones deben tener auditoría.
- Las importaciones deben ejecutarse en cola si son grandes.

## 11. Método de trabajo
- Leer contexto maestro y contexto de fase antes de codificar.
- Inspeccionar el repositorio y respetar sus convenciones.
- Presentar plan breve antes de cambios grandes.
- Implementar solo el alcance de la fase.
- Ejecutar pruebas, lint, typecheck y migraciones en entorno de desarrollo.
- Actualizar documentación y decisiones.
- No inventar datos faltantes. Mantener preguntas abiertas en `docs/DECISIONES_PENDIENTES.md`.
- Nunca usar datos reales sensibles en fixtures o pruebas.
- No introducir integración WhatsApp, IA predictiva o automatizaciones externas hasta la fase autorizada.

## 12. Decisiones pendientes críticas (ver estado actualizado en `10_DECISIONES_PENDIENTES.md`; siguen abiertos principalmente el punto 3, importes, y el detalle de costo, devoluciones y operador)
1. Cómo se identifican compañía y sucursal en la plantilla de ventas, dado que no aparecen en las columnas observadas.
2. Qué columna representa fecha de factura/venta; no se observa en la plantilla.
3. Semántica de `Venta + Imp` frente a `Gran Total` y reglas de impuestos/descuentos.
4. Qué representa `Nombre Nivel 2 Inv`.
5. Si el costo es costo histórico de cada venta o costo actual.
6. Si hay devoluciones, anulaciones, notas de crédito y ventas negativas.
7. Cómo se relacionan facturas de venta con facturas de crédito.
8. Definición de KPI de facturadores y disponibilidad futura de fecha/hora y errores.
9. Inventario inicial y formato de entradas/salidas.
10. Política de metas y periodos de inactividad por línea de negocio/cliente.
11. Reglas de privacidad, retención y respaldo de documentos.
