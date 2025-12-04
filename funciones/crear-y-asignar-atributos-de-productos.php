<?php
if (!function_exists('crear_y_asignar_atributos_producto')) {

function crear_y_asignar_atributos_producto($product_id, $atributos = [], $es_variacion = false) {
    if (empty($atributos)) return;

    $product = wc_get_product($product_id);
    if (!$product) return;

    $existing_attrs = $product->get_attributes();
    $new_attrs = $existing_attrs;

    // ============================================================
    // 🔍 1️⃣ Combinar filtros amarillos (genéricos) y verdes (variaciones)
    // ============================================================
    $atributos_finales = [];

    if (isset($atributos['filtros_genericos']) || isset($atributos['filtros_variaciones'])) {
        if (!empty($atributos['filtros_genericos'])) {
            foreach ($atributos['filtros_genericos'] as $nombre => $vals) {
                $atributos_finales[$nombre] = $vals;
            }
        }
        if (!empty($atributos['filtros_variaciones'])) {
            foreach ($atributos['filtros_variaciones'] as $nombre => $vals) {
                $atributos_finales[$nombre] = $vals;
            }
        }
    } else {
        $atributos_finales = $atributos;
    }

    // ============================================================
    // 🧩 2️⃣ Procesar cada atributo
    // ============================================================
    foreach ($atributos_finales as $nombre_attr => $valor) {
        if ($valor === '' || $valor === null) continue;

        // Asegurar array limpio
        if (!is_array($valor)) $valor = [$valor];
        $valor = array_values(array_filter(array_map('trim', $valor)));
        $valor = array_unique($valor);
        if (empty($valor)) continue;

        // Normalizar nombres
        $slug_base = sanitize_title($nombre_attr);
        $taxonomy  = wc_attribute_taxonomy_name($slug_base);
        $label     = ucwords(str_replace(['_', '-'], ' ', $slug_base));

        // ============================================================
        // 3️⃣ Validar que el atributo global exista
        // ============================================================
        $attribute_id = wc_attribute_taxonomy_id_by_name($taxonomy);
        if (!$attribute_id) {
            log_msg("⚠️ Atributo global {$taxonomy} no existe (debe crearse antes con preparar_atributos_globales).");
            continue;
        }

        // ============================================================
        // 4️⃣ Registrar taxonomía si no está registrada aún (solo frontend)
        // ============================================================
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
        // 5️⃣ Buscar términos (ya deben existir)
        // ============================================================
        $term_ids = [];
        foreach ($valor as $val) {
            $slug_val = sanitize_title($val);
            $term = get_term_by('slug', $slug_val, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $term_ids[] = (int)$term->term_id;
            } else {
                log_msg("⚠️ El término '{$val}' no existe en {$taxonomy} (debería haberse creado en Paso 0).");
            }
        }

        if (empty($term_ids)) continue;

        // ============================================================
        // 6️⃣ Asignar términos al producto
        // ============================================================
        wp_set_object_terms($product_id, $term_ids, $taxonomy, true);

        // ============================================================
        // 7️⃣ Construir objeto WC_Product_Attribute
        // ============================================================
        $attr_obj = $new_attrs[$taxonomy] ?? new WC_Product_Attribute();
        $attr_obj->set_id((int)$attribute_id);
        $attr_obj->set_name($taxonomy);
        $attr_obj->set_options($term_ids);
        $attr_obj->set_visible(true);
        $attr_obj->set_variation(false); // en simples, nunca son de variación

        $new_attrs[$taxonomy] = $attr_obj;
        log_msg("✅ Atributo '{$nombre_attr}' asignado correctamente al producto {$product_id}");
    }

    // ============================================================
    // 8️⃣ Guardar los atributos
    // ============================================================
    if ($new_attrs !== $existing_attrs) {
        $product->set_attributes($new_attrs);
        $product->save();
        log_msg("💾 Atributos guardados en producto simple {$product_id}");
    }
}
}
