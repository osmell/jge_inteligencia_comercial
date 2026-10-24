# Contrato de la API y tipos del frontend

El contrato entre el backend (Laravel) y el frontend (React) deja de ser «lo que cada quien recuerda» y pasa a **generarse del código**:

```
Resource / FormRequest / servicios  ──(Scramble)──►  backend/openapi.json  ──(openapi-typescript)──►  frontend/src/lib/api-types.ts
```

- **`backend/openapi.json`**: documento OpenAPI 3.1 de las operaciones de la API (206 documentadas), generado por [Scramble](https://scramble.dedoc.co) leyendo rutas, `FormRequest` (entradas), `Resource` y arreglos devueltos (salidas). No se edita a mano.
- **`frontend/src/lib/api-types.ts`**: tipos de TypeScript generados del documento (`npm run api:types`). No se edita a mano; está excluido del lint.
- **Documentación navegable**: `/docs/api` (y `/docs/api.json`) en el backend, **solo para administración** (`Gate viewApiDocs`), también en producción.

## Cómo se mantiene al día (no depende de la memoria de nadie)
| Qué cambia | Qué falla si no se actualiza | Cómo se actualiza |
|---|---|---|
| Una respuesta o una validación del backend | `OpenApiSpecTest` (backend) y la CI | `UPDATE_OPENAPI=1 php artisan test --filter=OpenApiSpecTest` |
| `openapi.json` | El paso «Tipos de la API al día» de la CI (frontend) | `cd frontend && npm run api:types` |
| Un tipo del frontend que ya no coincide | `tsc` (typecheck) | Corregir la pantalla: el compilador señala el lugar |

Suba **los tres** archivos en el mismo commit. El documento se genera dentro de la prueba (SQLite con las migraciones aplicadas) porque Scramble lee el esquema de la base para tipar los modelos; así el resultado es el mismo en cualquier equipo y en la CI.

En Docker, el contenedor del frontend ve el documento en `/api-spec/openapi.json`: `docker compose exec -e API_SPEC=/api-spec/openapi.json frontend npm run api:types`.

## Cómo escribir una respuesta tipada
1. **Un `Resource` por forma de respuesta** (`app/Http/Resources`), con `toArray` escrito como **arreglo literal** y `@mixin Modelo`. Scramble deduce los tipos de los campos del modelo (columnas, casts, relaciones).
   Ejemplo completo: `QuotationResource` (listado) y `QuotationDetailResource` (detalle).
2. El controlador devuelve el recurso: `new QuotationDetailResource($q)` o `QuotationResource::collection($rows)->additional(['meta' => [...]])`. El JSON no cambia (`data` + `meta`).
3. **Evite `response()->json([... closures ...])`** con métodos privados `present()`: son opacos para el generador (aparecen como `{}`).
4. Si un campo es una lista cerrada (estado, motivo) el generador solo ve `string`: en el frontend se precisa con un tipo auxiliar (ver `Narrowed` en `lib/quotations.ts`). Mejor aún: un `enum` de PHP en el modelo (`casts`).
5. En el frontend, **derive el tipo del esquema generado** en vez de escribirlo a mano:
   ```ts
   import type { components } from './api-types'
   export type QuotationRow = Omit<components['schemas']['QuotationResource'], keyof Narrowed> & Narrowed
   ```
   Así, si el backend cambia el campo, `tsc` falla en la pantalla que lo usa.

## Estado de la migración
- **Cotizaciones**: migradas (Resources + tipos derivados). Al hacerlo el contrato encontró un error real: el tamaño del adjunto puede ser nulo y el tipo escrito a mano decía que no.
- **Usuarios y lotes de importación**: ya usaban `Resource`.
- **162 de 192 operaciones (84 %)** tienen la respuesta completamente tipada (muchas por la inferencia de Scramble sobre los servicios).
- **30 operaciones con partes sin tipo** (la «cuenta atrás» de `OpenApiSpecTest::OPAQUE_BACKLOG`, que solo puede bajar):

| Módulo | Operaciones pendientes |
|---|---|
| Conversaciones | `POST /conversations/{id}/assign`, `PUT /conversations/{id}/labels` |
| Crédito | `GET /credit/invoices`, `/credit/invoices/{id}`, `/credit/aging`, `/credit/customer`, `/credit/promises` |
| Clientes | `GET /customers/profile`, `/customers/agenda`, `/customers/follow-up-kpis`, `/customers/sales-report` |
| Dashboard y resúmenes | `GET /dashboard`, `/dashboard/{section}`, `/digests/{digest}` |
| Inteligencia | `GET /insights/cross-sell` (+ `/opportunities`, `/customer`, `POST /recalculate`), `GET /insights/rfm` (+ `/customer`, `POST /recalculate`) |
| Inventario | `GET /inventory/rotation`, `/inventory/kardex` |
| Administración | `GET /admin/role-permissions`, `PUT /admin/role-permissions/{role}`, `GET`/`PUT /webhook/settings`, `GET`/`PUT /whatsapp/settings`, `GET /whatsapp/messages` |

**Regla para cada módulo que se toque**: migrar sus respuestas a `Resource`, regenerar el documento y los tipos, derivar los tipos del frontend, y bajar `OPAQUE_BACKLOG`. Se hace módulo por módulo (no «todo de golpe») porque cada migración exige revisar la pantalla que consume la respuesta.

## Límites conocidos
- Los `meta` agregados con `additional()` (por ejemplo `counts` por estado) los infiere como texto; su tipo exacto sigue escrito a mano en el frontend.
- Scramble escribe `[]` donde no pudo deducir un tipo; `OpenApiSpecTest` lo normaliza a `{}` (cualquier valor) para que el generador de tipos lo acepte.
- `openapi-typescript` exige TypeScript 5 como dependencia opcional y el proyecto usa TypeScript 7: por eso se ejecuta con `npx` (versión fijada), no como dependencia del frontend.
