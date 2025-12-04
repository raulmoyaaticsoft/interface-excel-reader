<?php

if (!function_exists('preparar_atributos_globales')) {
function preparar_atributos_globales(array $amarillos, array $verdes) {


    delete_post_meta_by_key('productos_complementarios');



    $logs_dir = __DIR__ . '/logs';
    if (!is_dir($logs_dir)) { @mkdir($logs_dir, 0755, true); }
    $log = $logs_dir . '/atributos-globales.txt';
    file_put_contents($log, "🚀 Paso 0: Crear atributos y términos globales (versión robusta)\n", FILE_APPEND);

    // Helper para slugs limpios
    $slug_attr = function(string $s) {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9_\-]/', '-', $s);
        $s = preg_replace('/-+/', '-', $s);
        return trim($s, '-');
    };

    $limpiar_valor = function(string $val): string {
        $val = trim($val);
        $val = str_replace(['/', '\\', '"', "'", 'º', 'ª'], '', $val); // quitar símbolos conflictivos
        $val = preg_replace('/\s+/', ' ', $val); // normalizar espacios
        return ucwords($val);
    };

    $crear = function(string $slug_base, array $valores) use ($slug_attr, $limpiar_valor, $log) {
        if ($slug_base === '') return;

        $slug_base = $slug_attr($slug_base);
        $taxonomy  = wc_attribute_taxonomy_name($slug_base);
        $label     = ucwords(str_replace(['_', '-'], ' ', $slug_base));

        // Crear atributo global si no existe
        $attribute_id = wc_attribute_taxonomy_id_by_name($taxonomy);
        if (!$attribute_id) {
            $res = wc_create_attribute([
                'slug'         => $slug_base,
                'name'         => $label,
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ]);
            delete_transient('wc_attribute_taxonomies');
            file_put_contents($log, "➕ Atributo GLOBAL creado: {$label} ({$taxonomy})\n", FILE_APPEND);
        }

        // Registrar taxonomía
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['product'], [
                'label'        => $label,
                'hierarchical' => false,
                'show_ui'      => true,
                'query_var'    => true,
                'rewrite'      => false,
            ]);
            file_put_contents($log, "🔄 Taxonomía registrada: {$taxonomy}\n", FILE_APPEND);
        }

        // Crear términos de forma robusta
        foreach ($valores as $val) {
            $val = $limpiar_valor((string)$val);
            if ($val === '') continue;

            $slug_val = sanitize_title($val);
            if ($slug_val === '') {
                $slug_val = strtolower(preg_replace('/[^a-z0-9\-]/', '-', $val));
                $slug_val = trim(preg_replace('/-+/', '-', $slug_val), '-');
            }

            if (!get_term_by('slug', $slug_val, $taxonomy)) {
                $ins = wp_insert_term($val, $taxonomy, ['slug' => $slug_val]);
                if (!is_wp_error($ins)) {
                    file_put_contents($log, "    • término '{$val}' creado en {$taxonomy}\n", FILE_APPEND);
                } else {
                    file_put_contents($log, "    ⚠️ Error creando término '{$val}' ({$slug_val}) en {$taxonomy}: " . $ins->get_error_message() . "\n", FILE_APPEND);
                }
            }
        }
    };

    // Procesar todos los amarillos
    foreach ($amarillos as $slug => $vals) {
        $crear($slug, $vals);
    }

    // Procesar todos los verdes
    foreach ($verdes as $slug => $vals) {
        $crear($slug, $vals);
    }

    file_put_contents($log, "✅ Paso 0 completado (atributos globales OK)\n", FILE_APPEND);
}}
