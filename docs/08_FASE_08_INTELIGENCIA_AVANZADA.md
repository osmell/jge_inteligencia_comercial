# Fase 08 — Inteligencia comercial avanzada

## Objetivo
Generar segmentación y oportunidades a partir de datos históricos confiables.

## Alcance
- RFM: recencia, frecuencia y valor monetario.
- Estacionalidad, concentración por cliente y cohortes.
- Venta cruzada por líneas/productos.
- Riesgo de inactividad y cambios anormales.
- Panel explicativo de recomendaciones.

## Reglas
- No activar predicciones con historial insuficiente.
- Alertas son recomendaciones, no decisiones automáticas.
- Mostrar periodo, fecha de cálculo y factores explicativos.
- No confundir scoring de crédito con segmentación comercial.
- Permitir cerrar alertas con motivo.

## Aceptación
Cada recomendación se puede rastrear a reglas/datos; parámetros y resultados son consultables por usuarios autorizados.

## Preguntas abiertas
Ver `16_PREGUNTAS_FASE_08_INTELIGENCIA.md` (historial disponible, definición de segmentos, umbrales de alerta y quién ve qué).

## Pasos propuestos
Los pasos 1 a 3 solo dependen de las ventas que ya están cargadas; los demás se apoyan en ellos.

1. **Base de análisis y reglas de historial suficiente.** Tabla de parámetros consultables (periodos, mínimos de historial, umbrales) con permiso propio para cambiarlos y Auditoría; servicio que dice qué análisis están disponibles según los meses de historial reales de cada compañía; y registro de cada cálculo (periodo usado, fecha de cálculo, parámetros). Sin historial suficiente, la función no se activa y lo explica.
2. **Segmentación RFM.** Recencia, frecuencia y valor monetario (venta neta menos devoluciones) por compañía, con quintiles y segmentos con nombre (Campeones, Leales, En riesgo, Dormidos…), el detalle de por qué cada cliente cayó en su segmento, pantalla de segmentos con exportación y el segmento en la ficha del cliente. Aparte del scoring de crédito y de la clasificación ABC.
3. **Concentración y cohortes.** Participación de los principales clientes y productos (curva de Pareto, porcentaje de los 10 principales), aviso informativo de dependencia de un cliente, y cohortes por mes de primera compra con retención mensual.
4. **Estacionalidad.** Índice por mes del año a nivel de compañía y de línea; se activa solo con 24 meses de historial y muestra la comparación contra el mismo mes del año anterior.
5. **Venta cruzada.** Reglas de asociación entre líneas o categorías (confianza, respaldo y elevación) calculadas sobre las facturas, con las oportunidades por cliente («compra A y aún no B») y las evidencias que respaldan cada una.
6. **Riesgo de inactividad y cambios anormales.** Aviso temprano relativo a la frecuencia propia de cada cliente, caídas y alzas anormales de venta frente a su propio promedio, y líneas que el cliente dejó de comprar; cada alerta con el periodo, los datos y la regla que la generó.
7. **Panel de recomendaciones.** Lista unificada de alertas y oportunidades con su explicación, filtros por vendedor, tipo y gravedad, cierre con motivo (quién y cuándo), creación opcional de una acción en la agenda de seguimiento (nunca automática) y recálculo nocturno. Alcance: cada quien ve lo de sus clientes.

## Paso 1 — Base de análisis y reglas de historial suficiente (hecho)
- **Pantalla «Inteligencia»** (`/inteligencia`, permiso nuevo `insights.view`): por compañía muestra el historial de ventas (primera y última venta, meses, facturas), si está desactualizado (última venta de hace más de 45 días) y, para cada análisis, si está **disponible** o **falta historial**, con la razón exacta («tiene 9 de 24 meses de historial») y cuándo se calculó por última vez.
- **Historial suficiente (propuesta, ajustable):** RFM 6 meses; concentración 3; cohortes 6; estacionalidad 24; venta cruzada 3 meses y 1.000 facturas; inactividad y cambios anormales 6. Un análisis sin historial suficiente **no se activa**.
- **Parámetros consultables:** periodos, mínimos de historial y umbrales (caída 40 %, alza 100 %, confianza 30 %, 20 clientes, aviso de dependencia 15 %, etc.), con rango válido y valor por omisión. Los ve quien tenga `insights.view`; **solo `insights.manage` los cambia**, y cada cambio queda en Auditoría con el valor anterior y el nuevo. Los valores por omisión están en `backend/config/insights.php`.
- **Registro de cada cálculo** (`insight_runs`, inmutable): análisis, compañía, periodo, parámetros vigentes en ese momento, resumen y fecha de cálculo; es lo que permitirá rastrear cada recomendación hasta sus reglas y datos. Lo usarán los pasos 2 a 7.
- Los permisos `insights.view` e `insights.manage` no se otorgan solos; se asignan en «Roles y permisos».

## Paso 2 — Segmentación RFM (hecho, con los supuestos propuestos)
- **Pantalla** `/inteligencia/segmentos` (`insights.view`) y **segmento en la ficha del cliente**, con la explicación de por qué cayó ahí y la fecha de cálculo. Exportación a CSV (Auditoría). Es distinta del scoring de crédito y de la clasificación ABC.
- **Qué mide**, por compañía y en los últimos 12 meses: **recencia** (días desde la última compra), **frecuencia** (días con compra) y **valor** (venta neta menos devoluciones vigentes, es decir, notas de crédito no anuladas). Cada una se puntúa de 1 a 5 por **quintiles entre los clientes de la compañía** (los empates reciben la misma puntuación).
- **Segmentos** (reglas fijas sobre R y el promedio de frecuencia y valor, FM): *Nuevos* (primera compra en los últimos 90 días y 2 compras o menos) · *Campeones* (R ≥ 4 y FM ≥ 4) · *Leales* (R ≥ 3 y FM ≥ 3) · *Prometedores* (R ≥ 3 y FM < 3) · *En riesgo* (R ≤ 2 y FM ≥ 3) · *Dormidos* (R = 2 y FM < 3) · *Perdidos* (R = 1 y FM < 3). Toda combinación cae en un segmento.
- **Solo se calcula con datos suficientes:** historial mínimo de RFM (6 meses) y al menos 20 clientes con compras en el periodo (parámetros `rfm.min_history_months`, `rfm.min_customers`, `rfm.period_months`, `rfm.new_customer_days`, consultables en Inteligencia). Si no, el cálculo queda registrado como «insuficiente» con la razón, y no se muestran segmentos.
- **Trazabilidad:** cada cálculo queda en el registro (`insight_runs`) con periodo, parámetros y resumen; cada cliente guarda sus valores y puntuaciones (`rfm_scores`, solo el último cálculo de cada compañía).
- **Recálculo:** cada noche a las 2:00 (`php artisan insights:rfm`; requiere el programador de Laravel en ejecución) o a mano con el botón «Recalcular» (`insights.manage`).
- **Alcance:** el cálculo usa todos los clientes de la compañía, pero cada usuario ve solo los segmentos de los clientes que puede ver (un vendedor, los suyos).

## Paso 3 — Concentración y cohortes (hecho)
Pantalla `/inteligencia/concentracion` (`insights.view`; **no disponible para vendedores**, porque son cifras de toda la compañía). Se calculan cada noche (`php artisan insights:refresh`, que ahora recalcula RFM, concentración y cohortes) o con «Recalcular» (`insights.manage`); cada cálculo queda registrado con su periodo y parámetros.
- **Concentración, por clientes y por productos**, sobre los últimos 12 meses (parámetro `concentration.period_months`): peso del principal, peso de los 10 principales, cuántos suman el **80 % de la venta** (Pareto) con su curva, el **índice HHI** (0 a 10.000, más alto = más concentrado) y los 10 principales con su venta, peso y acumulado. La venta de los clientes es **neta de devoluciones** (notas de crédito vigentes).
- **Aviso por dependencia** (informativo): clientes que pesan el 15 % o más de la venta de la compañía (`concentration.alert_pct`). Es una señal para diversificar, no una acción automática. Los productos no llevan aviso.
- **Cohortes:** clientes agrupados por mes de su primera compra y, para cada mes siguiente (hasta 12), el porcentaje que volvió a comprar, con un promedio ponderado. **El primer mes del historial** agrupa a clientes que ya existían antes de que hubiera datos: se muestra aparte con esa aclaración y no entra en el promedio. **El mes de la última venta** se considera incompleto y no se mide; las celdas aún no observables quedan vacías.
- **Historial suficiente:** concentración 3 meses, cohortes 6. Sin eso el cálculo queda registrado como «insuficiente», con la razón, y la pantalla no muestra resultados.
- Son descripciones del historial: no predicen nada ni disparan acciones.

## Paso 5 — Venta cruzada (hecho, con los supuestos propuestos)
Pantalla `/inteligencia/venta-cruzada` (`insights.view`), y en la ficha del cliente la sección «Oportunidades de venta cruzada». Se recalcula cada noche (`insights:refresh`) o con «Recalcular» (`insights.manage`).
- **Qué hace:** para cada cliente toma las **categorías** que compró en los últimos 12 meses (solo compras netas positivas y categorías clasificadas). Una **regla A → B** dice qué porcentaje de los clientes de A compra también B (**confianza**) y cuántas veces más que el cliente promedio (**elevación** = confianza ÷ proporción de clientes que compran B).
- **Cuándo se guarda una regla:** al menos 20 clientes que la respalden, 30 % de confianza y elevación mayor que 1 (parámetros `cross_sell.min_support_customers`, `cross_sell.min_confidence_pct`). El nivel es categoría (Nivel 3 del catálogo) por omisión; con `cross_sell.level` = 1 se analiza por línea (Nivel 2).
- **Oportunidad:** un cliente que compra el antecedente de una regla y todavía no su consecuente. Por cliente se conservan las 3 de mayor confianza (`cross_sell.max_per_customer`), una por categoría sugerida.
- **Cada una trae su evidencia en palabras:** «20 de 30 clientes que compran X (66,7 %) también compran Y; la elevación es 1,33…» y los conteos en las reglas. Es una **sugerencia para conversar con el cliente**: no registra ningún contacto ni venta por sí sola.
- **Solo con datos suficientes:** 3 meses de historial, 1.000 facturas y al menos 20 clientes con compras clasificadas; si no, el cálculo queda registrado como «insuficiente» con la razón y no se muestran asociaciones.
- **Alcance:** las reglas son agregadas de la compañía; las oportunidades las ve cada usuario solo para los clientes que puede ver (un vendedor, los suyos).
- Solo se guarda el último cálculo de cada compañía; los anteriores quedan en el registro de cálculos.
- Queda fuera por ahora el nivel de producto, que se activaría si el catálogo tiene volumen suficiente.

## Paso 6 — Riesgo de inactividad y cambios anormales (hecho, con los supuestos propuestos)
Pantalla `/inteligencia/alertas` (`insights.view`) y sección «Alertas del cliente» en su ficha. Se calculan cada noche (`insights:refresh`). Son **recomendaciones**: no cambian nada por sí solas. Cada alerta guarda los datos que la generaron, una explicación en palabras y el cálculo (`insight_runs`) del que salió.
- **Riesgo de inactividad (aviso temprano):** se compara lo que lleva el cliente sin comprar con **su propio ritmo** (la mediana de sus intervalos entre compras de los últimos 24 meses). Si pasa más de **2 veces** ese intervalo (`inactivity.early_warning_multiplier`) y todavía no está «perdido» según las reglas de actividad (90 días), se avisa antes de que pase a inactivo. Solo se evalúan clientes con al menos 3 compras (`inactivity.min_purchases`). Gravedad: baja, media (3 veces) o alta (4 veces o más).
- **Caída o alza de venta:** la venta neta del **último mes completo** (el anterior al mes de la última venta, que está incompleto) contra el **promedio de los 3 meses anteriores** del mismo cliente (`anomaly.baseline_months`). Aviso con una caída de **40 % o más** o un alza de **100 % o más** (`anomaly.drop_pct`, `anomaly.rise_pct`), solo si el cliente tuvo al menos 3 días de compra en esos meses (`anomaly.min_purchases`). Gravedad de la caída: media desde −60 %, alta desde −75 %; el alza es informativa.
- **Categoría abandonada:** una categoría que el cliente compraba en al menos **3 meses distintos** de los 6 anteriores a la ventana reciente y que no compra en los últimos 3 meses, **mientras sigue comprando otras**. Si dejó de comprar todo, el aviso es el de inactividad. Hasta 3 por cliente.
- **Ciclo de vida de una alerta:** abierta mientras la condición sigue; **resuelta sola** cuando deja de cumplirse; una alerta que una persona cierre (paso 7, con motivo) **no se reabre por el mismo hecho**. Repetir el cálculo no duplica alertas.
- **Historial suficiente:** 6 meses (`anomaly.min_history_months`). Sin él, el cálculo queda registrado como «insuficiente».
- **Alcance:** cada usuario ve las alertas de los clientes que puede ver (un vendedor, los suyos).
- Pendiente para el paso 7: cerrar alertas con motivo, crear una acción en la agenda a partir de una alerta y el panel unificado.

## Paso 7 — Panel de recomendaciones (hecho)
Pantalla **Recomendaciones** (`/inteligencia/alertas`, `insights.view`) y sección «Recomendaciones del cliente» en la ficha. Reúne en una sola lista, con el mismo ciclo de vida, lo que producen los análisis:
- **Tipos:** riesgo de inactividad, caída y alza de venta, categoría abandonada, **oportunidad de venta cruzada** (cada oportunidad es ahora también una recomendación) y **cliente en riesgo (RFM)** (los clientes del segmento «En riesgo»). Se recalculan cada noche con `insights:refresh`.
- **Cada una trae su explicación en palabras, su gravedad, cuándo se detectó y de qué cálculo salió** (periodo, parámetros y fecha en el registro de cálculos). Un contador por tipo filtra la lista; también hay filtros de estado (abiertas o cerradas), gravedad, **vendedor** y búsqueda de cliente.
- **Cerrar con motivo** (quien tiene `interactions.manage`, sobre los clientes que puede ver): *ya contacté al cliente*, *no aplica*, *el cliente no desea ser contactado*, *resuelto* u *otro* (la nota es obligatoria). Queda registrado quién y cuándo (y en Auditoría). **Una recomendación cerrada no se reabre por el mismo hecho** aunque se recalcule. Las que dejan de cumplirse se marcan «resueltas» solas.
- **Crear una acción en la agenda de seguimiento**, solo a petición de una persona (nunca automática): propone un texto según el tipo y pide la fecha. La acción queda en la agenda como próxima acción **«planeada»**: **no es un contacto**, así que no cuenta en los indicadores de contactos ni como «último contacto» al buscar clientes por reactivar. Una recomendación tiene como máximo una acción, y no se crea para clientes marcados como «no contactar».
- **Alcance:** cada usuario ve y gestiona las recomendaciones de los clientes que puede ver (un vendedor, las suyas).
- Los parámetros y las reglas de cada análisis se consultan en Inteligencia; los análisis sin historial suficiente no generan recomendaciones.

## Fase 8 completa en código
Falta el paso 4 (estacionalidad), que se activa por sí solo cuando haya 24 meses de historial, y validar todo con datos reales y las respuestas del cuestionario (`16_PREGUNTAS_FASE_08_INTELIGENCIA.md`).
