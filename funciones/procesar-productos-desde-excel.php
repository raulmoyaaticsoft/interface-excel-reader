<?php
function procesar_productos_desde_excel(array $productos, string $idioma = 'es') {
    $addedprods = [];

    foreach ($productos as $arr) {
        $ref = trim($arr['referencia'] ?? '');
        if ($ref === '') continue;

        $tipo_woo     = strtolower(trim($arr['tipo_woo'] ?? ''));
        $tipoProducto = strtolower(trim($arr['tipo_producto'] ?? ''));
        $sku          = $ref . '-' . strtoupper($idioma);

        // Solo procesar simples (incluye bodegones, que también son tipo simple)
        if ($tipo_woo !== 'simple') continue;

        // ✅ Distinguir simple normal de bodegón
        $es_bodegon = ($tipoProducto === 'bodegon' || str_starts_with($ref, '94'));
        $es_simple_normal = !$es_bodegon;

        // Crear o actualizar producto simple
        $product_id = wc_get_product_id_by_sku($sku);
        $creado = false;

        if ($product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;
        } else {
            $product = new WC_Product_Simple();
            $product->set_sku($sku);
            $creado = true;
        }

        // ======== NOMBRE Y DESCRIPCIÓN ========
        $nombre = $arr['descripcion_' . $idioma] ?? $arr['descripcion_es'] ?? $ref;
        $descripcion = $arr['descripcion_larga_' . $idioma] ?? $arr['descripcion_larga_es'] ?? '';
        $product->set_name(trim($nombre));
        $product->set_description(trim($descripcion));
        $product_id = $product->save();
        guardar_caracteristicas_personalizadas($product_id, $arr);

        // ======== ATRIBUTOS (amarillos + verdes) ========
        $filtros_genericos = [];
        $filtros_variaciones = [];

        // 🟡 Para productos simples vienen como "atributos_filtros" y "atributos_variacion"
        if (!empty($arr['atributos_filtros'])) {
            $filtros_genericos = $arr['atributos_filtros'];
        }
        if (!empty($arr['atributos_variacion'])) {
            $filtros_variaciones = $arr['atributos_variacion'];
        }

        // 🟢 Para padres variables podrían venir como "filtros_genericos" y "filtros_variaciones"
        if (!empty($arr['filtros_genericos'])) {
            $filtros_genericos = array_merge($filtros_genericos, $arr['filtros_genericos']);
        }
        if (!empty($arr['filtros_variaciones'])) {
            $filtros_variaciones = array_merge($filtros_variaciones, $arr['filtros_variaciones']);
        }

        if (!empty($filtros_genericos) || !empty($filtros_variaciones)) {
            crear_y_asignar_atributos_producto(
                $product_id,
                [
                    'filtros_genericos'   => $filtros_genericos,
                    'filtros_variaciones' => $filtros_variaciones
                ],
                false
            );
        }

        // ======== BODEGONES ========
        if ($es_bodegon) {
            $codigos_raw = trim((string)($arr['codigos_asociados_raw'] ?? ''));
            $asociados_bodegon = [];

            if ($codigos_raw !== '') {
                $asociados_bodegon = array_filter(array_map('trim', explode('-', $codigos_raw)));
            } elseif (!empty($arr['codigos_asociados']) && is_array($arr['codigos_asociados'])) {
                $asociados_bodegon = array_values(array_filter(array_map('trim', $arr['codigos_asociados'])));
            }

            $asociados_bodegon = array_values(array_unique(array_filter($asociados_bodegon, fn($c) => $c !== $ref)));

            if (!empty($asociados_bodegon)) {
                update_post_meta($product_id, 'bodegon', $asociados_bodegon);
            } else {
                delete_post_meta($product_id, 'bodegon');
            }
        }
            $cat_slug    = trim($arr['categoria'] ?? '');
            $subcat_slug = trim($arr['subcategoria'] ?? '');
            crear_y_asignar_categorias_productos($product_id, $cat_slug, $subcat_slug);

        // ======== SOLO SIMPLES NORMALES ========
       

            // Categorías
        


            // ======== METADATOS PERSONALIZADOS ========
           $meta_mapa = [
                'cantidad_minima'       => 'unidad_compra_minima',
                'largo_producto'        => 'medida_unitaria',
                'ancho_producto'        => 'medida_unitaria',
                'alto_producto'         => 'medida_unitaria',
                'peso_producto'         => 'peso_unitario',
                'und_caja'              => 'unidades_caja',
                'largo_caja'            => 'medidas_caja',
                'ancho_caja'            => 'medidas_caja',
                'alto_caja'             => 'medidas_caja',
                'peso_caja'             => 'peso_caja',
                'niveles_palet_eur'     => 'niveles_palet_eur',
                'niveles_palet_usa'     => 'niveles_palet_usa',
                'cajas_nivel_eur'       => 'niveles_cajas_eur',
                'cajas_nivel_usa'       => 'niveles_cajas_usa',
                'cajas_palet_eur'       => 'cajas_palet_eur',
                'cajas_palet_usa'       => 'cajas_palet_usa',
                'altura_palet_eur'      => 'altura_palet_eur',
                'altura_palet_usa'      => 'altura_palet_usa',
                'caracteristica_material' => 'material',
                'caracteristica_color'  => 'color',
            ];


            
            $medidas_producto = [];
            $medidas_caja = [];

            $productos_complementarios=$arr['productos_complementarios'];



             update_post_meta($product_id, 'productos_complementarios', $productos_complementarios);
             $campo_orden=$arr['campo_orden'];
             update_post_meta($product_id, 'campo_orden', $campo_orden);
            // 🔹 Guardar todo, incluso si está vacío
            foreach ($meta_mapa as $excel_key => $meta_key) {
                $valor = isset($arr[$excel_key]) ? trim((string)$arr[$excel_key]) : '';

                if (in_array($excel_key, ['largo_producto', 'ancho_producto', 'alto_producto'])) {
                    if ($valor !== '') $medidas_producto[] = $valor;
                } elseif (in_array($excel_key, ['largo_caja', 'ancho_caja', 'alto_caja'])) {
                    if ($valor !== '') $medidas_caja[] = $valor;
                }

                // Guardar meta aunque esté vacío
                update_post_meta($product_id, $meta_key, $valor);
            }

            // 🔹 Formatear medidas
            if (!empty($medidas_producto)) {
                update_post_meta($product_id, 'medida_unitaria', implode(' x ', $medidas_producto));
            } else {
                delete_post_meta($product_id, 'medida_unitaria');
            }

            if (!empty($medidas_caja)) {
                update_post_meta($product_id, 'medidas_caja', implode(' x ', $medidas_caja));
            } else {
                delete_post_meta($product_id, 'medidas_caja');
            }

            $idioma_suffix = '_' . $idioma;
            $meta_mapa_multi = [
                'video'             => 'url_video',
                'caracteristica_1'  => 'caracteristica_1',
                'caracteristica_2'  => 'caracteristica_2',
                'caracteristica_3'  => 'caracteristica_3',
                'caracteristica_4'  => 'caracteristica_4',
                'caracteristica_5'  => 'caracteristica_5',
                'descripcion_larga' => 'descripcion_larga',

            ];



            foreach ($meta_mapa_multi as $excel_base => $meta_base) {
                $excel_key = $excel_base . $idioma_suffix;
                $meta_key  = $meta_base . $idioma_suffix;
                if (!empty($arr[$excel_key])) {
                    update_post_meta($product_id, $meta_key, $arr[$excel_key]);
                }
            }
        


         // Imágenes
            if (function_exists('obtener_o_importar_imagenes_por_referencia')) {
                $imagenes = obtener_o_importar_imagenes_por_referencia($ref,$product_id );

                if (!empty($imagenes['destacada'])) {
                    $attach_id = attachment_url_to_postid($imagenes['destacada']);
                    if ($attach_id) set_post_thumbnail($product_id, $attach_id);
                }

                if (!empty($imagenes['galeria'])) {
                    $ids_galeria = [];
                    foreach ($imagenes['galeria'] as $url) {
                        $id = attachment_url_to_postid($url);
                        if ($id) $ids_galeria[] = $id;
                    }
                    if (!empty($ids_galeria)) {
                        update_post_meta($product_id, '_product_image_gallery', implode(',', $ids_galeria));
                        update_post_meta($product_id, 'wc_product_custom_images', maybe_serialize($imagenes['galeria']));
                    }
                }
            } 


        $addedprods[$ref] = $product_id;
    }






    return [
        'success' => true,
        'data' => [
            'type' => 'success',
            'message' => '✅ Sincronización completada (simples y bodegones) con atributos amarillos y verdes.',
            'productos' => $addedprods
        ]
    ];
}
