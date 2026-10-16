# Fase 04 — Cliente 360°

## Objetivo
Centralizar datos maestros, compras, productos, asignaciones, actividades y alertas de cada cliente.

## Alcance
- Catálogo editable de tipos de cliente.
- Datos principales, contactos y búsqueda.
- Historial de ventas y detalle de productos.
- Resumen de compras, frecuencia, ticket, última compra y líneas principales.
- Asignación de varios vendedores por cliente, con responsable principal opcional.
- Clasificación comercial independiente del tipo de cliente.
- Importación futura de clientes y alta/edición manual.
- Auditoría de cambios.

## Reglas
- El código de origen identifica al cliente; resolver duplicados mediante proceso explícito.
- No crear clientes nuevos automáticamente por diferencias de escritura.
- Indicar si el historial disponible es parcial.
- Aplicar permisos a la ficha y sus datos.
- Potencial comercial y scoring de pago son conceptos diferentes.

## Aceptación
Desde la ficha se navega a compras, productos, actividades y asignaciones; los totales coinciden con ventas validadas.
