<?php
/**
 * CRM — Helpers de UI reutilizables (v1.19.0).
 *
 * crm_badge()  → genera un pill con icono + estado.
 * crm_avatar_initials() → genera iniciales para el header.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderiza una etiqueta de estado con icono y color.
 *
 * @param string $estado  borrador|enviado|presupuesto_aceptado|contratos_generados|contratos_firmados|origen_marketing|origen_comercial|duplicado|info
 * @param string $size    sm|md
 * @return string HTML
 */
function crm_badge($estado, $size = 'sm') {
    $estado = (string) $estado;
    $size   = $size === 'md' ? 'md' : 'sm';

    $map = [
        'borrador' => [
            'label' => 'Borrador',
            'icon'  => 'pencil',
            'class' => 'crm-pill--neutral',
        ],
        'enviado' => [
            'label' => 'Enviado',
            'icon'  => 'paper-plane',
            'class' => 'crm-pill--accent',
        ],
        'presupuesto_aceptado' => [
            'label' => 'Presupuesto aceptado',
            'icon'  => 'check-circle',
            'class' => 'crm-pill--violet',
        ],
        'contratos_generados' => [
            'label' => 'Contrato generado',
            'icon'  => 'file-text',
            'class' => 'crm-pill--warn',
        ],
        'contratos_firmados' => [
            'label' => 'Contrato firmado',
            'icon'  => 'seal-check',
            'class' => 'crm-pill--ok',
        ],
        'origen_marketing' => [
            'label' => 'Marketing',
            'icon'  => 'target',
            'class' => 'crm-pill--accent',
        ],
        'origen_comercial' => [
            'label' => 'Comercial',
            'icon'  => 'users',
            'class' => 'crm-pill--neutral',
        ],
        'duplicado' => [
            'label' => 'Duplicado',
            'icon'  => 'magnifying',
            'class' => 'crm-pill--warn',
        ],
        'activo' => [
            'label' => 'Activo',
            'icon'  => 'check-circle',
            'class' => 'crm-pill--ok',
        ],
    ];

    if (!isset($map[$estado])) {
        return sprintf(
            '<span class="crm-pill crm-pill--neutral crm-pill--%s">%s</span>',
            esc_attr($size),
            esc_html(ucfirst(str_replace('_', ' ', $estado)))
        );
    }

    $cfg = $map[$estado];
    $icon = function_exists('crm_icon') ? crm_icon($cfg['icon'], $size === 'md' ? 14 : 12) : '';

    return sprintf(
        '<span class="crm-pill %1$s crm-pill--%2$s">%3$s%4$s</span>',
        esc_attr($cfg['class']),
        esc_attr($size),
        $icon,
        esc_html($cfg['label'])
    );
}

/**
 * Genera 1-2 iniciales para el avatar del header.
 */
function crm_avatar_initials($nombre, $empresa = '') {
    $src = trim((string) ($nombre !== '' ? $nombre : $empresa));
    if ($src === '') {
        return '??';
    }
    $parts = preg_split('/\s+/', $src);
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $second = isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '';
    $ini = mb_strtoupper($first . $second);
    return $ini !== '' ? $ini : '??';
}
