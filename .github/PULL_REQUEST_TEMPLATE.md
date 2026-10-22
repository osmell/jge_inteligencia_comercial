## Qué cambia y por qué
<!-- Una o dos frases. Enlazar la fase (docs/NN_...) o el ajuste de revisión (docs/23) -->

## Tipo
- [ ] Funcionalidad de una fase
- [ ] Ajuste de la revisión (n.º ___ en `docs/23_AJUSTES_REVISION.md`)
- [ ] Corrección de error (incluye prueba que fallaba antes)
- [ ] Refactor sin cambio de comportamiento
- [ ] Documentación / infraestructura

## Cómo probarlo
<!-- Pasos, usuario de desarrollo y datos necesarios. Capturas si hay interfaz -->

## Lista de revisión (docs/24, sección 4)
- [ ] Cada clase nueva o cambiada tiene un solo motivo de cambio (S)
- [ ] Un caso nuevo se añadió con una clase, sin editar un `switch/if` central (O)
- [ ] Las dependencias llegan por constructor; sin `app()` ni `new` de colaboradores (D)
- [ ] La regla de negocio tiene prueba, unitaria si es posible
- [ ] La autorización está en middleware/Policy y respeta el alcance de datos
- [ ] Controlador delgado: sin consultas encadenadas ni `DB::`
- [ ] Pasan `composer check` y `npm run typecheck && npm run lint && npm test && npm run build`
- [ ] Documenté el cambio en el documento de la fase (y en `docs/23` si es un ajuste)

## Riesgo y reversión
<!-- ¿Migraciones? ¿Permisos? ¿Datos movidos o borrados? ¿Cómo se revierte? -->
