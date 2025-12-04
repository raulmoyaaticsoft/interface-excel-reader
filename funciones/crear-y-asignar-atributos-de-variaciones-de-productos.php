<?php
if (!function_exists('crear_y_asignar_atributos_de_variaciones_de_producto')) {
function crear_y_asignar_atributos_de_variaciones_de_producto($variation_or_parent_id, $atributos = []) {

    if (empty($atributos)) {
        log_msg("⚠️ Sin atributos recibidos para {$variation_or_parent_id}");
        return;
    }

    $producto = wc_get_product($variation_or_parent_id);
    if (!$producto) {
        log_msg("❌ Producto no encontrado ID={$variation_or_parent_id}");
        return;
    }

    // Detectar si es variación o producto padre
    $parent_id = ($producto->get_type() === 'variation') ? $producto->get_parent_id() : $variation_or_parent_id;
    if (!$parent_id) {
        log_msg("❌ No se pudo determinar el producto padre para {$variation_or_parent_id}");
        return;
    }

    $parent_product = wc_get_product($parent_id);
    if (!$parent_product || $parent_product->get_type() !== 'variable') {
        log_msg("❌ El padre {$parent_id} no es un producto variable válido");
        return;
    }

    // ============================================================
    // 🎨 1️⃣ Determinar estructura de atributos
    // ============================================================
    $atributos_finales = [];
    if (isset($atributos['filtros_variaciones'])) {
        $atributos_finales = $atributos['filtros_variaciones'];
    } else {
        $atributos_finales = $atributos;
    }

    if (empty($atributos_finales)) {
        log_msg("⚠️ Sin filtros_variaciones válidos para {$variation_or_parent_id}");
        return;
    }

    // ============================================================
    // 🧩 2️⃣ Atributos actuales del padre
    // ============================================================
    $parent_attributes = $parent_product->get_attributes();

    foreach ($atributos_finales as $nombre_attr => $valor) {
        if ($valor === '' || $valor === null) continue;

        if (is_array($valor)) $valor = reset($valor);
        $valor = trim((string)$valor);
        if ($valor === '') continue;

        // ============================================================
        // 🏷️ 3️⃣ Normalizar nombre y determinar tipo
        // ============================================================
        $nombre_limpio = strtolower(trim($nombre_attr));
        $es_filtro = str_starts_with($nombre_limpio, 'filtro_');

        // 🧩 Eliminar cualquier prefijo "pa_" duplicado
        $slug_sin_prefijo = preg_replace('/^pa_/', '', $nombre_limpio);
        $slug_base = sanitize_title($slug_sin_prefijo);

        // 🧩 Evitar que wc_attribute_taxonomy_name() añada otro "pa_"
        $taxonomy = 'pa_' . $slug_base;

        $label = ucwords(str_replace(['-', '_'], ' ', $slug_base));


        // ============================================================
        // 4️⃣ Validar existencia del atributo global
        // ============================================================
        $attribute_id = wc_attribute_taxonomy_id_by_name($taxonomy);
        if (!$attribute_id) {
            log_msg("⚠️ Atributo global {$taxonomy} no existe (debe crearse antes con preparar_atributos_globales)");
            continue;
        }

        // Registrar la taxonomía si no está
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
        // 5️⃣ Buscar el término existente (no crearlo)
        // ============================================================
        $valor_normalizado = str_replace(',', '.', $valor); // normaliza 0,3 → 0.3
        $slug_valor = sanitize_title($valor_normalizado);
        $term = get_term_by('slug', $slug_valor, $taxonomy);

        if (!$term || is_wp_error($term)) {
            log_msg("⚠️ Término '{$valor}' no encontrado en {$taxonomy} para padre {$parent_id}");
            continue;
        }

        // ============================================================
        // 6️⃣ Asignar el término al padre
        // ============================================================
        wp_set_post_terms($parent_id, [$term->term_id], $taxonomy, true);

        // ============================================================
        // 7️⃣ Crear o actualizar el objeto de atributo del padre
        // ============================================================
        if (!isset($parent_attributes[$taxonomy])) {
            $attr_obj = new WC_Product_Attribute();
            $attr_obj->set_id((int)$attribute_id);
            $attr_obj->set_name($taxonomy);
            $attr_obj->set_options([$term->slug]);
            $attr_obj->set_visible(true);
            $attr_obj->set_variation(!$es_filtro);
            $parent_attributes[$taxonomy] = $attr_obj;
        } else {
            $attr_obj = $parent_attributes[$taxonomy];
            $opts = $attr_obj->get_options();
            if (!in_array($term->slug, $opts, true)) {
                $opts[] = $term->slug;
                $attr_obj->set_options($opts);
            }
            $attr_obj->set_visible(true);
            $attr_obj->set_variation(!$es_filtro);
            $parent_attributes[$taxonomy] = $attr_obj;
        }
    }

    // ============================================================
    // 8️⃣ Guardar y sincronizar
    // ============================================================
    $parent_product->set_attributes($parent_attributes);
    $parent_product->save();

    try {
        WC_Product_Variable::sync($parent_id);
        wc_delete_product_transients($parent_id);
        log_msg("✅ Atributos aplicados correctamente y marcados para variaciones en padre {$parent_id}");
    } catch (Exception $e) {
        log_msg("❌ Error al sincronizar padre {$parent_id}: " . $e->getMessage());
    }
}
}
