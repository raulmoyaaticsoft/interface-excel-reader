<?php
// =================== Debug ===================
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
@set_time_limit(0);

// =================== WordPress guard ===================
if (!defined('ABSPATH')) {
    // Si por lo que sea lo llamas fuera de WP, corta:
    die('Este script debe ejecutarse dentro de WordPress (ABSPATH no definido).');
}

// Fallback ligero si esc_html no está disponible en el contexto
if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// =================== Carga de la función de sincronización ===================
$plugin_path = ABSPATH . 'wp-content/plugins/interface-excel-reader/process-sync.php';

if (!file_exists($plugin_path)) {
    die("❌ No se encontró el archivo process-sync.php en: {$plugin_path}");
}
require_once $plugin_path; // <- AQUÍ se define read_excel_to_array_interface()

// =================== Helpers de salida ===================
function arr_get(array $a, string $k, $default = null) {
    return array_key_exists($k, $a) ? $a[$k] : $default;
}

/**
 * Cuenta cuántos elementos hay por idioma en arrays del tipo:
 *   [ 'es' => ['REF1'=>123, 'REF2'=>456], 'en' => [...], ... ]
 */
function total_por_idioma(array $map): int {
    $total = 0;
    foreach ($map as $lang => $refs) {
        if (is_array($refs)) { $total += count($refs); }
    }
    return $total;
}

function dump_section(string $title, $data, bool $collapsed = true): void {
    $open = $collapsed ? '' : ' open';
    echo "<details{$open}><summary><strong>" . esc_html($title) . "</strong></summary>";
    echo '<pre style="white-space:pre-wrap;">' . htmlspecialchars(print_r($data, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    echo '</details>';
}

// =================== Ejecución ===================
// ¿Salida en JSON crudo?
if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $res = read_excel_to_array_interface();
    if (is_wp_error($res)) {
        echo json_encode([
            'type'    => 'error',
            'message' => $res->get_error_message(),
            'data'    => $res,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Salida HTML bonita
$res = read_excel_to_array_interface();

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>Resultado de la sincronización</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial,sans-serif;
     padding:24px;color:#222;line-height:1.4;}
code,pre{background:#fafafa;border:1px solid #eee;border-radius:6px;padding:12px;}
summary{cursor:pointer;margin:8px 0;}
.badge{display:inline-block;background:#eef;border:1px solid #ccd;border-radius:12px;padding:2px 8px;margin-left:6px;font-size:12px;color:#334;}
.ok{color:#0a0;}
.err{color:#a00;}
ul{margin:0 0 12px 20px;}
</style>
</head>
<body>
