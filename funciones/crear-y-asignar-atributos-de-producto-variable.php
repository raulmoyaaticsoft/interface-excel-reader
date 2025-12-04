<?php
if (!function_exists('crear_y_asignar_atributos_producto_variable')) {

function crear_y_asignar_atributos_producto_variable($product_id, $atributos = [], $es_variacion = false) {
    if (empty($atributos)) return;

    $product = wc_get_product($product_id);
    if (!$product) return;

    $is_variable = $product->get_type() === 'variable';
    $existing_attrs = $product->get_attributes();
    $new_attrs = $existing_attrs;

    $atributos_finales = [];
    $mapa_variacion = [];

    // ============================================================
    // 🔍 1️⃣ Unificar entrada (amarillos + verdes)
    // ============================================================
    if (!empty($atributos['filtros_genericos'])) {
        foreach ($atributos['filtros_genericos'] as $nombre => $vals) {
            $atributos_finales[$nombre] = $vals;
            $mapa_variacion[$nombre] = false; // visibles, no de variación
        }
    }
    if (!empty($atributos['filtros_variaciones'])) {
        foreach ($atributos['filtros_variaciones'] as $nombre => $vals) {
            if (!isset($atributos_finales[$nombre])) {
                $atributos_finales[$nombre] = $vals;
            } else {
                $atributos_finales[$nombre] = array_unique(array_merge(
                    (array)$atributos_finales[$nombre],
                    (array)$vals
                ));
            }
            $mapa_variacion[$nombre] = true; // usados para variación
        }
    }

    // ============================================================
    // 🧩 2️⃣ Procesar cada atributo
    // ============================================================
    foreach ($atributos_finales as $nombre_attr => $valores) {
        if (empty($valores)) continue;
        if (!is_array($valores)) $valores = [$valores];
        $valores = array_values(array_filter(array_map('trim', $valores)));
        if (empty($valores)) continue;

        $nombre_limpio = strtolower($nombre_attr);
        $es_filtro = str_starts_with($nombre_limpio, 'filtro_');

        $slug_base = sanitize_title($nombre_limpio);
        $taxonomy  = wc_attribute_taxonomy_name($slug_base);
        $label     = ucwords(str_replace(['_', '-'], ' ', $slug_base));

        // ============================================================
        // 3️⃣ Validar atributo global existente
        // ============================================================
        $attribute_id = wc_attribute_taxonomy_id_by_name($taxonomy);
        if (!$attribute_id) {
            log_msg("⚠️ Atributo global {$taxonomy} no existe (debe crearse antes con preparar_atributos_globales)");
            continue;
        }

        // Registrar taxonomía si aún no está
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['product'], [
                'label'        => $label,
                'hierarchical' => false,
                'show_ui'      => true,
                'query_var'    => true,
                'rewrite'      => false,
            ]);
        }

        // ============================================================
        // 4️⃣ Buscar términos (ya deben existir)
        // ============================================================
        $term_ids = [];
        foreach ($valores as $val) {
            if ($val === '') continue;

            // Normaliza valores decimales (0,3 → 0.3)
            $val_norm = str_replace(',', '.', $val);
            $slug_val = sanitize_title($val_norm);

            $term = get_term_by('slug', $slug_val, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $term_ids[] = (int)$term->term_id;
            } else {
                log_msg("⚠️ Término '{$val}' no encontrado en {$taxonomy}");
            }
        }

        if (empty($term_ids)) continue;

        // ============================================================
        // 5️⃣ Asignar términos al producto
        // ============================================================
        wp_set_object_terms($product_id, $term_ids, $taxonomy, true);

        // ============================================================
        // 6️⃣ Construir objeto WC_Product_Attribute
        // ============================================================
        $attr_obj = $new_attrs[$taxonomy] ?? new WC_Product_Attribute();
        $attr_obj->set_id((int)$attribute_id);
        $attr_obj->set_name($taxonomy);
        $attr_obj->set_options($term_ids);
        $attr_obj->set_visible(true);

        // ============================================================
        // 7️⃣ Marcar si se usa para variación
        // ============================================================
        if ($es_filtro) {
            $attr_obj->set_variation(false);
            log_msg("🟡 Atributo filtro '{$nombre_attr}' asignado solo como visible (no de variación) al producto {$product_id}");
        } else {
            $usar_para_variacion = $is_variable && ($mapa_variacion[$nombre_attr] ?? true);
            $attr_obj->set_variation($usar_para_variacion);
            log_msg($usar_para_variacion
                ? "🟢 Marcado '{$nombre_attr}' como usado para variaciones en producto {$product_id}"
                : "ℹ️ Atributo '{$nombre_attr}' visible pero no de variación en producto {$product_id}"
            );
        }

        $new_attrs[$taxonomy] = $attr_obj;
    }

    // ============================================================
    // 💾 8️⃣ Guardar atributos y sincronizar
    // ============================================================
    $product->set_attributes($new_attrs);
    $product->save();
    log_msg("💾 Atributos guardados en producto variable {$product_id}");

    if ($is_variable) {
        try {
            WC_Product_Variable::sync($product_id);
            wc_delete_product_transients($product_id);
            log_msg("🔄 Sincronizado producto variable {$product_id}");
        } catch (Exception $e) {
            log_msg("❌ Error al sincronizar producto {$product_id}: " . $e->getMessage());
        }
    }
}
}
