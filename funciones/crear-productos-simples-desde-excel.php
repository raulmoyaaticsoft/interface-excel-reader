<?php

function crear_productos_simples_desde_excel($sortedArr, $idioma) {
    $log_file = __DIR__ . '/logs/logs_productos_simples.txt';
    $fecha = date('Y-m-d H:i:s');

    // 🧹 Limpiar log solo para español
    if ($idioma === 'es') file_put_contents($log_file, "");
    file_put_contents($log_file, "[$fecha] ▶ Procesando productos simples idioma {$idioma}\n", FILE_APPEND);

    $campos_atributos = [
        'filtro_tipo','filtro_capacidad','filtro_color','filtro_modelo','filtro_material',
        'color','capacidad','patas','modelo','tipo','material'
    ];

    foreach ($sortedArr['children'] as $child) {
        $ref = trim($child['referencia'] ?? '');
        if ($ref === '') continue;

        $sku = strtoupper($ref) . '-' . strtoupper($idioma);
        $name = $child["descripcion_$idioma"] ?? ('Producto ' . $ref);
        $desc_larga = $child["descripcion_larga_$idioma"] ?? '';
        $desc_corta = $child["filtros_descripcion_$idioma"] ?? '';

        // ⚙️ Crear o actualizar producto simple
        $product_id = wc_get_product_id_by_sku($sku);
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();

        if ($product_id) {
            file_put_contents($log_file, "[$fecha] ♻️ Actualizando producto simple: {$sku} (ID {$product_id})\n", FILE_APPEND);
        } else {
            file_put_contents($log_file, "[$fecha] 🆕 Creando nuevo producto simple: {$sku}\n", FILE_APPEND);
        }

        $product->set_name($name);
        $product->set_description($desc_larga);
        $product->set_short_description($desc_corta);
        $product->set_sku($sku);
        $product->set_status('publish');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(100);
        $product->set_stock_status('instock');
        $product->set_regular_price(100);

        // Categorías
        if (!empty($child['subcategoria'])) {
            [$cat_name, $cat_slug] = build_subcat_from_excel($child['categoria'], $child['subcategoria']);
            $term = get_term_by('slug', $cat_slug, 'product_cat');
            $parent_term = get_term_by('slug', sanitize_title($child['categoria']), 'product_cat');
            if ($term && $parent_term) {
                $product->set_category_ids([(int)$parent_term->term_id, (int)$term->term_id]);
            }
        }

        // Atributos
        $attributes = [];
        foreach ($campos_atributos as $campo) {
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

            wp_set_object_terms($product_id, [$term_obj->slug], $taxonomy, true);

            $pa = new WC_Product_Attribute();
            $pa->set_name($taxonomy);
            $pa->set_options([$term_obj->slug]);
            $pa->set_visible(true);
            $pa->set_variation(false);
            $pa->set_is_taxonomy(true);

            $attributes[$taxonomy] = $pa;
        }

        if (!empty($attributes)) {
            $product->set_attributes($attributes);
        }

        $product_id = $product->save();

        // Metadatos e imagen
        update_post_meta($product_id, 'peso_unitario', $child['peso_producto']);
        update_post_meta($product_id, 'unidades_caja', $child['und_caja']);
        $image_id = obtener_o_importar_imagen_por_referencia($ref);
        if ($product_id && $image_id) set_post_thumbnail($product_id, $image_id);

        file_put_contents($log_file, "[$fecha] ✅ Producto simple {$sku} guardado con ID {$product_id}\n", FILE_APPEND);
    }

    file_put_contents($log_file, "[$fecha] ✅ Finalizado idioma {$idioma}\n\n", FILE_APPEND);
}
