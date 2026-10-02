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
