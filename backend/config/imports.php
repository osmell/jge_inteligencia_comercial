<?php

/*
|--------------------------------------------------------------------------
| Importación de archivos Excel
|--------------------------------------------------------------------------
| Los encabezados de las columnas nuevas (fecha, compañía, sucursal) son PROVISIONALES:
| el responsable del Excel aún no ha confirmado sus nombres exactos
| (ver docs/10_DECISIONES_PENDIENTES.md). Cada columna admite varios alias.
*/

return [
    'disk' => 'imports',

    // Tamaño máximo del archivo subido, en kilobytes.
    'max_file_kb' => (int) env('IMPORT_MAX_FILE_KB', 20480),

    // Cantidad de errores detallados que se guardan por lote (el total sí se cuenta completo).
    'max_stored_errors' => (int) env('IMPORT_MAX_STORED_ERRORS', 1000),

    // Máximo de filas de datos por archivo (protege memoria y tiempo de proceso).
    'max_rows' => (int) env('IMPORT_MAX_ROWS', 200000),

    // Tipos de importación registrados: clave => clase que implementa App\Imports\Contracts\ImportType.
    'types' => [],

    // Filas mostradas en la vista previa.
    'preview_rows' => 20,

    // Diferencia máxima aceptada al conciliar importes (redondeo). PROVISIONAL.
    'amount_tolerance' => (float) env('IMPORT_AMOUNT_TOLERANCE', 0.01),

    'sales' => [
        'sheet' => null, // null = primera hoja

        // clave interna => alias aceptados de encabezado (el primero es el nombre canónico)
        'columns' => [
            'document' => ['Documento'],
            'series' => ['Serie'],
            'issued_on' => ['Fecha'],
            'company' => ['Compañía', 'Compania', 'Código Compañía'],
            'branch' => ['Sucursal', 'Código Sucursal'],
            'customer_code' => ['Código Cliente'],
            'customer_name' => ['Nombre Cliente'],
            'product_code' => ['Código del Producto'],
            'units' => ['Und.Vendidas'],
            'product_name' => ['Nombre del Producto'],
            'line_cost_total' => ['Costo Tot.Vta.'],
            'unit_cost' => ['Costo Unit.Vta.'],
            'unit_price' => ['Precio Unit.Vta.'],
            'normal_price' => ['Precio Unit.Normal'],
            'net_total' => ['Total Neto'],
            'tax_total' => ['Total Impuesto'],
            'sale_plus_tax' => ['Venta + Imp'],
            'grand_total' => ['Gran Total'],
            'seller_name' => ['Nombre Vendedor'],
            'category_name' => ['Nombre Nivel 2 Inv'],
            'operator_name' => ['Nombre Operador', 'Nombre Facturador'],
        ],

        // Columnas que pueden venir vacías en una fila. El resto es obligatoria.
        'optional' => ['normal_price'],
    ],
];
