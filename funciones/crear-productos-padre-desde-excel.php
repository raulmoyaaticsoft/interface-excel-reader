<?php

function crear_productos_padre_desde_excel($sortedArr, $idioma, $trid = null) {
    $log_file = __DIR__ . '/funciones/logs_productos_padre.txt';
    $fecha = date('Y-m-d H:i:s');

    // 🧹 Limpiar log solo la primera vez (idioma base)
    if ($idioma === 'es') file_put_contents($log_file, "");
    file_put_contents($log_file, "[$fecha] ▶ Procesando idioma {$idioma}\n", FILE_APPEND);

    $campos_atributos = [
        'filtro_tipo','filtro_capacidad','filtro_color','filtro_modelo','filtro_material',
        'color','capacidad','patas','modelo','tipo','material'
    ];

    foreach ($sortedArr['parent'] as $arr) {
        $ref = trim($arr['referencia'] ?? '');
        if ($ref === '') continue;

        $sku = strtoupper($ref) . '-' . strtoupper($idioma);
        $name = $arr["descripcion_$idioma"] ?? ('Producto ' . $ref);
        $desc_larga = $arr["descripcion_larga_$idioma"] ?? '';
        $desc_corta = $arr["filtros_descripcion_$idioma"] ?? '';

        $product_id = wc_get_product_id_by_sku($sku);
        $product = $product_id ? wc_get_product($product_id) : null;

        // 🧩 Crear o actualizar producto padre
        if (!$product) {
            $codigos = trim($arr['codigos_asociados'] ?? '');
            $refCodigos = explode('-', $codigos);
            $refCodigos = trim($refCodigos[0] ?? '');

            if (empty($codigos)) {
                $product = new WC_Product_Simple();
            } elseif ($ref === $refCodigos) {
                $product = new WC_Product_Variable();
            } else {
                $product = new WC_Product_Simple();
            }

            file_put_contents($log_file, "[$fecha] 🆕 Creando nuevo producto padre: {$sku}\n", FILE_APPEND);
        } else {
            file_put_contents($log_file, "[$fecha] ♻️ Actualizando producto existente: {$sku} (ID {$product_id})\n", FILE_APPEND);
        }

        $product->set_name($name);
        $product->set_description($desc_larga);
        $product->set_short_description($desc_corta);
        $product->set_sku($sku);
        $product->set_status('publish');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(365);
        $product->set_stock_status('instock');
        $product->set_regular_price(365);

        // Categorías
        if (!empty($arr['subcategoria'])) {
            [$cat_name, $cat_slug] = build_subcat_from_excel($arr['categoria'], $arr['subcategoria']);
            $term = get_term_by('slug', $cat_slug, 'product_cat');
            $parent_term = get_term_by('slug', sanitize_title($arr['categoria']), 'product_cat');
            if ($term && $parent_term) {
                $product->set_category_ids([(int)$parent_term->term_id, (int)$term->term_id]);
            }
        }

        $product_id = $product->save();
        file_put_contents($log_file, "[$fecha] 💾 Producto {$sku} guardado con ID {$product_id}\n", FILE_APPEND);

        // 🎨 Atributos
        $attributes = [];
        foreach ($campos_atributos as $campo) {
            $valor = trim($arr[$campo] ?? '');
            if ($valor === '') continue;

            $taxonomy = 'pa_' . sanitize_title($campo);

            if (!taxonomy_exists($taxonomy)) {
                register_taxonomy($taxonomy, 'product', ['hierarchical'=>false,'show_ui'=>false]);
            }

            $term = term_exists($valor, $taxonomy);
            if (!$term) $term = wp_insert_term($valor, $taxonomy, ['slug'=>sanitize_title($valor)]);
            if (is_wp_error($term)) continue;

            $term_id = is_array($term) ? $term['term_id'] : $term;
            $term_obj = get_term($term_id, $taxonomy);
            if (!$term_obj) continue;

            wp_set_object_terms($product_id, [$term_obj->slug], $taxonomy, true);

            $attribute_tax_id = wc_attribute_taxonomy_id_by_name(str_replace('pa_', '', $taxonomy));
            $pa = new WC_Product_Attribute();

            if ($attribute_tax_id) $pa->set_id($attribute_tax_id);
            $pa->set_name($taxonomy);
            $pa->set_options([$term_obj->slug]);
            $pa->set_position(0);
            $pa->set_visible(true);

            // ✅ Solo los verdes son variación
            $pa->set_variation(in_array($campo, ['color','capacidad','patas','modelo','tipo','material']));

            if (method_exists($pa, 'set_is_taxonomy')) {
                $pa->set_is_taxonomy(true);
            } elseif (property_exists($pa, 'is_taxonomy')) {
                $pa->is_taxonomy = true;
            }

            $attributes[$taxonomy] = $pa;
        }

        if (!empty($attributes)) {
            $product->set_attributes($attributes);
            $product->save();
            file_put_contents($log_file, "[$fecha] ✅ Atributos guardados para {$sku}\n", FILE_APPEND);
        }

        // Metadatos
        $medidas_producto = $arr['largo_producto'].'x'.$arr['ancho_producto'].'x'.$arr['alto_producto'];
        $medidas_caja = $arr['largo_caja'].'x'.$arr['ancho_caja'].'x'.$arr['alto_caja'];

        update_post_meta($product_id, 'medida_unitaria', $medidas_producto);
        update_post_meta($product_id, 'medidas_caja', $medidas_caja);
        update_post_meta($product_id, 'peso_unitario', $arr['peso_producto']);
        update_post_meta($product_id, 'peso_caja', $arr['peso_caja']);
        update_post_meta($product_id, 'unidades_caja', $arr['und_caja']);
        update_post_meta($product_id, 'unidad_compra_minima', $arr['cantidad_minima']);

        // Imagen
        $image_id = obtener_o_importar_imagen_por_referencia($ref);
        if ($product_id && $image_id) set_post_thumbnail($product_id, $image_id);
    }

    file_put_contents($log_file, "[$fecha] ✅ Procesamiento idioma {$idioma} completado\n", FILE_APPEND);
}
