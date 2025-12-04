<?php

function crear_bodegones_desde_excel($sortedArr, $idioma) {
    $log_file = __DIR__ . '/logs_bodegones.txt';
    $fecha = date('Y-m-d H:i:s');

    // 🧹 Limpiar log solo idioma base
    if ($idioma === 'es') file_put_contents($log_file, "");
    file_put_contents($log_file, "[$fecha] ▶ Procesando bodegones idioma {$idioma}\n", FILE_APPEND);

    foreach ($sortedArr['bodegones'] as $bodegon) {
        $ref = trim($bodegon['referencia'] ?? '');
        if ($ref === '') continue;

        $sku = strtoupper($ref) . '-' . strtoupper($idioma);
        $name = $bodegon["descripcion_$idioma"] ?? ('Bodegón ' . $ref);

        $product_id = wc_get_product_id_by_sku($sku);
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();

        if ($product_id) {
            file_put_contents($log_file, "[$fecha] ♻️ Actualizando bodegón: {$sku} (ID {$product_id})\n", FILE_APPEND);
        } else {
            file_put_contents($log_file, "[$fecha] 🆕 Creando nuevo bodegón: {$sku}\n", FILE_APPEND);
        }

        $product->set_name($name);
        $product->set_description($bodegon["descripcion_larga_$idioma"] ?? '');
        $product->set_short_description($bodegon["filtros_descripcion_$idioma"] ?? '');
        $product->set_sku($sku);
        $product->set_status('publish');
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->set_regular_price(100);

        // Categorías
        if (!empty($bodegon['subcategoria'])) {
            [$cat_name, $cat_slug] = build_subcat_from_excel($bodegon['categoria'], $bodegon['subcategoria']);
            $term = get_term_by('slug', $cat_slug, 'product_cat');
            $parent_term = get_term_by('slug', sanitize_title($bodegon['categoria']), 'product_cat');
            if ($term && $parent_term) {
                $product->set_category_ids([(int)$parent_term->term_id, (int)$term->term_id]);
            }
        }

        // Imagen
        $image_id = obtener_o_importar_imagen_por_referencia($ref);
        if ($product_id && $image_id) set_post_thumbnail($product_id, $image_id);

        $product_id = $product->save();
        file_put_contents($log_file, "[$fecha] ✅ Bodegón {$sku} guardado con ID {$product_id}\n", FILE_APPEND);
    }

    file_put_contents($log_file, "[$fecha] ✅ Finalizado idioma {$idioma}\n\n", FILE_APPEND);
}
