<?php

/*
 * Parámetros de la inteligencia comercial avanzada (Fase 8). Estos son los valores por omisión propuestos; los valores vigentes
 * se guardan en la base de datos (AppSetting, claves «insights.*») y los cambia solo quien tenga el permiso insights.manage,
 * con Auditoría. Cada parámetro indica su grupo, tipo, rango permitido y qué controla.
 */
return [
    // Análisis que dependen de historial: cuántos meses de ventas (de la primera a la última venta de la compañía) hacen falta.
    'analyses' => [
        'rfm' => ['label' => 'Segmentación RFM', 'min_months' => 'rfm.min_history_months'],
        'concentration' => ['label' => 'Concentración de clientes y productos', 'min_months' => 'concentration.min_history_months'],
        'cohorts' => ['label' => 'Cohortes de clientes', 'min_months' => 'cohorts.min_history_months'],
        'seasonality' => ['label' => 'Estacionalidad', 'min_months' => 'seasonality.min_history_months'],
        'cross_sell' => ['label' => 'Venta cruzada', 'min_months' => 'cross_sell.min_history_months', 'min_documents' => 'cross_sell.min_documents'],
        'inactivity' => ['label' => 'Riesgo de inactividad', 'min_months' => 'anomaly.min_history_months'],
        'anomalies' => ['label' => 'Cambios anormales de venta', 'min_months' => 'anomaly.min_history_months'],
    ],

    // Si la última venta de una compañía es más antigua que esto, los análisis se marcan como desactualizados.
    'stale_after_days' => 45,

    'parameters' => [
        'rfm.period_months' => ['group' => 'RFM', 'label' => 'Periodo de análisis', 'unit' => 'meses', 'default' => 12, 'min' => 3, 'max' => 36, 'help' => 'Meses de ventas que se miden para recencia, frecuencia y valor.'],
        'rfm.min_customers' => ['group' => 'RFM', 'label' => 'Clientes mínimos con compras', 'unit' => 'clientes', 'default' => 20, 'min' => 5, 'max' => 10000, 'help' => 'Con menos clientes con compras en el periodo los quintiles no significan nada y no se calcula.'],
        'rfm.new_customer_days' => ['group' => 'RFM', 'label' => 'Cliente nuevo', 'unit' => 'días desde su primera compra', 'default' => 90, 'min' => 15, 'max' => 365, 'help' => 'Un cliente cuya primera compra es más reciente y que ha comprado pocas veces se clasifica como Nuevo.'],
        'rfm.min_history_months' => ['group' => 'RFM', 'label' => 'Historial mínimo', 'unit' => 'meses', 'default' => 6, 'min' => 1, 'max' => 36, 'help' => 'Sin este historial la segmentación no se activa.'],

        'concentration.period_months' => ['group' => 'Concentración', 'label' => 'Periodo de análisis', 'unit' => 'meses', 'default' => 12, 'min' => 3, 'max' => 36, 'help' => 'Meses de ventas sobre los que se mide la concentración.'],
        'concentration.min_history_months' => ['group' => 'Concentración', 'label' => 'Historial mínimo', 'unit' => 'meses', 'default' => 3, 'min' => 1, 'max' => 36, 'help' => 'Meses de ventas para calcular la concentración.'],
        'concentration.alert_pct' => ['group' => 'Concentración', 'label' => 'Aviso por dependencia de un cliente', 'unit' => '% de la venta', 'default' => 15, 'min' => 1, 'max' => 100, 'help' => 'Aviso informativo cuando un cliente supera este porcentaje de la venta de la compañía.'],

        'cohorts.min_history_months' => ['group' => 'Cohortes', 'label' => 'Historial mínimo', 'unit' => 'meses', 'default' => 6, 'min' => 2, 'max' => 36, 'help' => 'Meses de ventas para calcular cohortes de retención.'],
        'cohorts.retention_months' => ['group' => 'Cohortes', 'label' => 'Meses de retención que se muestran', 'unit' => 'meses', 'default' => 12, 'min' => 3, 'max' => 24, 'help' => 'Cuántos meses se siguen a cada cohorte.'],

        'seasonality.min_history_months' => ['group' => 'Estacionalidad', 'label' => 'Historial mínimo', 'unit' => 'meses', 'default' => 24, 'min' => 12, 'max' => 60, 'help' => 'Un índice por mes del año necesita al menos dos años para no confundir tendencia con temporada.'],

        'seasonality.top_lines' => ['group' => 'Estacionalidad', 'label' => 'Líneas que se analizan', 'unit' => 'líneas', 'default' => 10, 'min' => 1, 'max' => 50, 'help' => 'Cuántas líneas (Nivel 2 del catálogo) con más venta tienen su propio índice estacional.'],
        'seasonality.marked_points' => ['group' => 'Estacionalidad', 'label' => 'Estacionalidad marcada', 'unit' => 'puntos de índice', 'default' => 20, 'min' => 5, 'max' => 100, 'help' => 'Diferencia entre el mejor y el peor mes para considerar que hay una temporada marcada.'],
        'cross_sell.period_months' => ['group' => 'Venta cruzada', 'label' => 'Periodo de análisis', 'unit' => 'meses', 'default' => 12, 'min' => 3, 'max' => 36, 'help' => 'Meses de ventas sobre los que se buscan asociaciones entre categorías.'],
        'cross_sell.level' => ['group' => 'Venta cruzada', 'label' => 'Nivel de análisis (1 = línea, 2 = categoría)', 'unit' => '1 o 2', 'default' => 2, 'min' => 1, 'max' => 2, 'help' => 'Si las asociaciones se buscan entre líneas (Nivel 2 del catálogo) o entre categorías (Nivel 3).'],
        'cross_sell.max_per_customer' => ['group' => 'Venta cruzada', 'label' => 'Oportunidades por cliente', 'unit' => 'oportunidades', 'default' => 3, 'min' => 1, 'max' => 10, 'help' => 'Máximo de oportunidades que se guardan por cliente, las de mayor confianza.'],
        'cross_sell.min_history_months' => ['group' => 'Venta cruzada', 'label' => 'Historial mínimo', 'unit' => 'meses', 'default' => 3, 'min' => 1, 'max' => 36, 'help' => 'Meses de ventas para calcular asociaciones.'],
        'cross_sell.min_documents' => ['group' => 'Venta cruzada', 'label' => 'Facturas mínimas', 'unit' => 'facturas', 'default' => 1000, 'min' => 100, 'max' => 1000000, 'help' => 'Con menos facturas las asociaciones no son confiables.'],
        'cross_sell.min_confidence_pct' => ['group' => 'Venta cruzada', 'label' => 'Confianza mínima', 'unit' => '%', 'default' => 30, 'min' => 5, 'max' => 100, 'help' => 'Porcentaje de clientes que compran A y también B para sugerir B.'],
        'cross_sell.min_support_customers' => ['group' => 'Venta cruzada', 'label' => 'Clientes que respaldan la regla', 'unit' => 'clientes', 'default' => 20, 'min' => 3, 'max' => 10000, 'help' => 'Mínimo de clientes que cumplen la asociación.'],

        'inactivity.early_warning_multiplier' => ['group' => 'Inactividad', 'label' => 'Aviso temprano', 'unit' => '× su intervalo habitual', 'default' => 2, 'min' => 1, 'max' => 10, 'help' => 'Avisa cuando un cliente pasa más de esta cantidad de veces su intervalo habitual entre compras.'],
        'inactivity.min_purchases' => ['group' => 'Inactividad', 'label' => 'Compras mínimas para medir su frecuencia', 'unit' => 'compras', 'default' => 3, 'min' => 2, 'max' => 50, 'help' => 'Con menos compras no se calcula un intervalo habitual.'],

        'anomaly.min_history_months' => ['group' => 'Cambios anormales', 'label' => 'Historial mínimo', 'unit' => 'meses', 'default' => 6, 'min' => 2, 'max' => 36, 'help' => 'Meses de ventas para detectar cambios y riesgo de inactividad.'],
        'anomaly.baseline_months' => ['group' => 'Cambios anormales', 'label' => 'Meses de referencia', 'unit' => 'meses', 'default' => 3, 'min' => 1, 'max' => 12, 'help' => 'La venta del mes se compara contra el promedio de los meses anteriores del mismo cliente.'],
        'anomaly.min_purchases' => ['group' => 'Cambios anormales', 'label' => 'Compras mínimas en la referencia', 'unit' => 'compras', 'default' => 3, 'min' => 1, 'max' => 50, 'help' => 'Clientes con menos compras en el periodo de referencia no se evalúan.'],
        'anomaly.drop_pct' => ['group' => 'Cambios anormales', 'label' => 'Caída que genera aviso', 'unit' => '%', 'default' => 40, 'min' => 5, 'max' => 100, 'help' => 'Caída de la venta del mes contra su referencia.'],
        'anomaly.rise_pct' => ['group' => 'Cambios anormales', 'label' => 'Alza que genera aviso', 'unit' => '%', 'default' => 100, 'min' => 10, 'max' => 1000, 'help' => 'Alza de la venta del mes contra su referencia.'],
    ],
];
