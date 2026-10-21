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
