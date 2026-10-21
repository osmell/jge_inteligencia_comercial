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
