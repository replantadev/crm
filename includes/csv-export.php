<?php
/**
 * Exportación de listados a CSV — helper compartido.
 *
 * Mismo patrón ya probado en crm_logs_export_csv() (includes/logger.php):
 * header text/csv + Content-Disposition attachment + fputcsv, con BOM UTF-8
 * para que Excel abra bien los acentos. CSV plano, sin ninguna librería
 * nueva — el plugin no tiene ninguna dependencia para generar .xlsx real
 * (ver vendor/, solo dompdf y el update checker).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Envía un CSV al navegador y termina la petición. Debe llamarse solo desde
 * un handler que todavía pueda emitir cabeceras HTTP (admin-post.php, antes
 * de cualquier output) — igual que crm_logs_export_csv().
 *
 * @param string   $filename Nombre de archivo sugerido (sin ruta).
 * @param string[] $header   Fila de cabecera (nombres de columna legibles).
 * @param iterable $rows     Cada fila como array indexado, mismo orden que $header.
 */
function crm_csv_export_stream($filename, array $header, iterable $rows) {
    if (headers_sent()) {
        return;
    }
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/**
 * Roadmap de "Exportación a CSV" (v1.20.188) — ver includes/flujos-page.php.
 * Añadida en v1.20.189 por el mismo motivo que la de Incidencias: se quedó
 * fuera por descuido al construir la función inicial. Cerrada por el
 * usuario en v1.20.193 tras añadir los comentarios del lead al CSV de
 * Leads MK.
 */
add_filter('crm_roadmap_fases', function ($fases) {
    $fases[] = [
        'fase'    => 'Exportación de listados a CSV',
        'titulo'  => 'Instalaciones, Clientes, Leads MK y Ventas-Presupuestos',
        'estado'  => 'hecho',
        'detalle' => 'Cierra el último hueco abierto de la auditoría original del presupuesto. Alcance acordado con el usuario: solo CSV (sin añadir ninguna librería nueva para .xlsx real, el plugin no tenía ninguna) y 4 listados — Instalaciones, Clientes ("Todas las altas"), Leads de Marketing y Ventas-Presupuestos (Holded). Instalaciones respeta los filtros activos en pantalla (estado/tipo/búsqueda/atención); los otros 3 exportan todo, igual que su query de pantalla. Verificado con descarga real en el navegador + contenido del CSV comprobado byte a byte para Instalaciones, Clientes y Leads MK. El de Ventas-Presupuestos no se pudo probar end-to-end porque Local no tiene clave de Holded configurada — mismo código exacto que los otros 3, ya verificados. v1.20.193: el CSV de Leads MK incluye ahora también la columna "Comentarios" (campo `comentarios`, el mismo que fusiona el importador de LeadKit, v1.20.182) — a petición del usuario, que lo dio por cerrado.',
    ];
    return $fases;
});
