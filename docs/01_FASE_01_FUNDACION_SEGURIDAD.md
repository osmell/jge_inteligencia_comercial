# Fase 01 — Fundación, organización y seguridad

## Objetivo
Crear la estructura base ejecutable y segura, sin implementar todavía la lógica analítica completa.

## Alcance
- Backend y frontend en contenedores separados.
- Configuración por entorno; `.env.example` sin secretos.
- Conexión a PostgreSQL en VM dedicada y Redis según configuración.
- Autenticación con nombre de usuario y contraseña.
- Roles: Administrador, Gerente General, Supervisor de Ventas y Vendedor.
- Compañías, sucursales, permisos y auditoría.
- Layout base con menú lateral, barra superior y paleta JGE.

## Reglas
- El nombre de usuario es único y no es un correo.
- Autorización en backend, no solo ocultando controles.
- PostgreSQL no se despliega dentro del Compose de la aplicación.
- No incluir módulos funcionales de fases posteriores.
- Secretos fuera del repositorio y contraseñas con hash seguro.

## Entregables
Aplicación ejecutable, migraciones iniciales, autenticación, permisos, datos de desarrollo ficticios, pruebas y documentación de arranque.

## Criterios de aceptación
1. Inicio de sesión por username/contraseña.
2. Un vendedor no puede invocar rutas administrativas.
3. El ámbito de compañía/sucursal se aplica en las consultas.
4. Backend y frontend corren en contenedores distintos.
5. Pruebas y chequeos estáticos pasan.

## Fuera de alcance
Importaciones reales, dashboards completos, WhatsApp, scoring y lógica de inventario.
