<?php

function crear_variaciones_para_producto($sortedArr, $idioma) {
    $log_file = __DIR__ . '/funciones/logs_variaciones.txt';
    $fecha = date('Y-m-d H:i:s');

    // 🧹 Limpiar log solo idioma base
    if ($idioma === 'es') file_put_contents($log_file, "");
    file_put_contents($log_file, "[$fecha] 🚀 Inicio creación/actualización de variaciones ({$idioma})\n", FILE_APPEND);

    $campos = ['color','capacidad','patas','modelo','tipo','material'];

    foreach ($sortedArr['parent'] as $parent) {
        $ref_padre = trim($parent['referencia'] ?? '');
        if ($ref_padre === '' || empty($parent['codigos_asociados'])) continue;

        $sku_padre = strtoupper($ref_padre) . '-' . strtoupper($idioma);
        $parent_id = wc_get_product_id_by_sku($sku_padre);
        if (!$parent_id) {
            file_put_contents($log_file, "[$fecha] ⚠️ Padre {$ref_padre} no encontrado\n", FILE_APPEND);
            continue;
        }

        $refs_hijas = array_filter(array_map('trim', explode('-', $parent['codigos_asociados'] ?? '')));
        file_put_contents($log_file, "[$fecha] 🔎 Padre {$ref_padre} ({$sku_padre}) → Hijos detectados: " . json_encode($refs_hijas) . "\n", FILE_APPEND);

        $product_parent = wc_get_product($parent_id);
        if ($product_parent && $product_parent->get_type() !== 'variable') {
            wp_set_object_terms($parent_id, 'variable', 'product_type', false);
            $product_parent = wc_get_product($parent_id);
            file_put_contents($log_file, "[$fecha] 🔧 Tipo corregido a VARIABLE para {$sku_padre}\n", FILE_APPEND);
        }

        if (!$product_parent || !($product_parent instanceof WC_Product_Variable)) {
            file_put_contents($log_file, "[$fecha] ⏭️ Padre {$sku_padre} omitido: tipo ".($product_parent ? $product_parent->get_type() : 'NULL')."\n", FILE_APPEND);
            continue;
        }

        $existing_attrs = $product_parent->get_attributes();

        foreach ($sortedArr['children'] as $child) {
            $ref_child = trim($child['referencia'] ?? '');
            if ($ref_child === '' || !in_array($ref_child, $refs_hijas)) continue;
            if ($ref_child === $ref_padre) continue;

            $sku_variacion = strtoupper($ref_child) . '-' . strtoupper($idioma);
            $existing_id = wc_get_product_id_by_sku($sku_variacion);

            if ($existing_id) {
                $variation = wc_get_product($existing_id);
                file_put_contents($log_file, "[$fecha] ♻️ Actualizando variación existente {$sku_variacion}\n", FILE_APPEND);
            } else {
                $variation = new WC_Product_Variation();
                file_put_contents($log_file, "[$fecha] 🆕 Creando nueva variación {$sku_variacion}\n", FILE_APPEND);
            }

            // 🧩 Construir atributos válidos
            $atributos_variacion = [];
            foreach ($campos as $campo) {
                $valor = trim($child[$campo] ?? '');
                if ($valor === '') continue;

                $taxonomy = 'pa_' . sanitize_title($campo);
                if (!taxonomy_exists($taxonomy)) {
                    register_taxonomy($taxonomy, 'product', ['hierarchical'=>false,'show_ui'=>false]);
                }

                $term = term_exists($valor, $taxonomy);
                if (!$term) $term = wp_insert_term($valor, $taxonomy, ['slug'=>sanitize_title($valor)]);
                if (is_wp_error($term)) continue;

                $term_obj = get_term(is_array($term) ? $term['term_id'] : $term, $taxonomy);
                if (!$term_obj) continue;

                wp_set_object_terms($parent_id, [$term_obj->slug], $taxonomy, true);

                if (!isset($existing_attrs[$taxonomy])) {
                    $attr_obj = new WC_Product_Attribute();
                    $attr_obj->set_name($taxonomy);
                    $attr_obj->set_options([$term_obj->slug]);
                    $attr_obj->set_visible(true);
                    $attr_obj->set_variation(true);
                    $existing_attrs[$taxonomy] = $attr_obj;
                }

                $atributos_variacion[$taxonomy] = $term_obj->slug;
            }

            $product_parent->set_attributes($existing_attrs);
            $product_parent->save();

            if (empty($atributos_variacion)) {
                file_put_contents($log_file, "[$fecha] ⚠️ Sin atributos para {$sku_variacion}\n", FILE_APPEND);
                continue;
            }

            // Guardar variación
            $variation->set_parent_id($parent_id);
            $variation->set_attributes($atributos_variacion);
            $variation->set_name($child["descripcion_$idioma"] ?? 'Variante ' . $ref_child);
            $variation->set_status('publish');
            $variation->set_sku($sku_variacion);
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity(100);
            $variation->set_regular_price(100);
            $variation_id = $variation->save();

            file_put_contents($log_file, "[$fecha] ✅ Guardada variación {$sku_variacion} (ID {$variation_id}) | Atributos: " . json_encode($atributos_variacion) . "\n", FILE_APPEND);
        }
    }

    file_put_contents($log_file, "[$fecha] ✅ Finalizado idioma {$idioma}\n\n", FILE_APPEND);
}