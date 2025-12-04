<?php
// Asegurarse de que WordPress/WooCommerce está cargado
defined('ABSPATH') || exit;

if (!function_exists('log_msg_paso_2')) {
    function log_msg_paso_2($msg) {
        $log_dir  = __DIR__ . '/logs/';
        $log_file = $log_dir . 'paso2.txt';
        if (!file_exists($log_dir)) mkdir($log_dir, 0777, true);
        $line = '[ ARRANCA EL PASO 2 ' . date('Y-m-d H:i:s') . '] ' . print_r($msg, true) . PHP_EOL;
        file_put_contents($log_file, $line, FILE_APPEND);
    }
}

/**
 * Crea/actualiza productos VARIABLES padre a partir del array del Excel.
 * - Usa SKU: REF-IDIOMA (p.ej. 94060-ES)
 * - Llama a crear_y_asignar_atributos_producto_variable($product_id, $atributos_combinados)
 * - Asigna categorías, imágenes y metadatos base al padre (no a variaciones)
 */
function procesar_productos_variables_desde_excel($sortedArr, $idioma = 'es') {
    $addedprods = [];

    if (empty($sortedArr) || !is_array($sortedArr)) {
        return ['success' => true, 'data' => ['type' => 'info', 'message' => 'Sin datos para procesar (padres).']];
    }

    foreach ($sortedArr as $ref => $arr) {
        log_msg_paso_2("🔍 ENTRADA REF {$ref} → productos_complementarios (RAW): " . print_r($arr['productos_complementarios'] ?? 'NO EXISTE', true));

        // Acepta tanto $sortedArr['parent'] como lista plana
        if (is_array($arr) && isset($arr['referencia'])) {
            $ref = trim((string)($arr['referencia'] ?? ''));
        } else {
            // $ref viene como clave
            $ref = trim((string)$ref);
        }
        if ($ref === '') continue;

        $es_variable = (isset($arr['tipo_woo']) && strtolower($arr['tipo_woo']) === 'variable');
        if (!$es_variable) continue;

        $sku = $ref . '-' . strtoupper($idioma);

        // Buscar o crear producto variable
        $product_id = wc_get_product_id_by_sku($sku);
        $creado = false;

        if ($product_id) {
            $product = wc_get_product($product_id);
            if (!$product || $product->get_type() !== 'variable') {
                // Si existe pero con otro tipo, lo reemplazamos
                wp_delete_post($product_id, true);
                $product = new WC_Product_Variable();
                $product->set_sku($sku);
                $creado = true;
            }
        } else {
            $product = new WC_Product_Variable();
            $product->set_sku($sku);
            $creado = true;
        }

        // Nombre y descripciones
        $nombre       = $arr['descripcion_' . $idioma] ?? $arr['descripcion_es'] ?? $ref;
        $descripcion  = $arr['descripcion_larga_' . $idioma] ?? $arr['descripcion_larga_es'] ?? '';

        $product->set_name(trim($nombre));
        $product->set_description(trim($descripcion));
        $product->set_status('publish');
        $product->set_stock_status('instock');

        try {
            $product_id = $product->save();
            // GUARDAR COMPLEMENTARIOS EN EL PADRE ANTES DE MODIFICAR $arr
            if (!empty($arr['productos_complementarios'])) {
                update_post_meta($product_id, 'productos_complementarios', $arr['productos_complementarios']);
            }
        } catch (Throwable $e) {
            log_msg_paso_2("❌ Error guardando padre {$ref}: " . $e->getMessage());
            continue;
        }

        // Características personalizadas en el PADRE (si la función existe)
        if (function_exists('guardar_caracteristicas_personalizadas')) {
            try {
                guardar_caracteristicas_personalizadas($product_id, $arr);
            } catch (Throwable $e) {
                log_msg_paso_2("⚠️ guardar_caracteristicas_personalizadas (padre {$ref}): " . $e->getMessage());
            }
        }

        // === Atributos amarillos (genéricos) y verdes (variación) para el PADRE ===
        $filtros_genericos   = [];
        $filtros_variaciones = [];

        if (!empty($arr['atributos_filtros']) && is_array($arr['atributos_filtros'])) {
            $filtros_genericos = $arr['atributos_filtros'];
        }
        if (!empty($arr['filtros_variaciones']) && is_array($arr['filtros_variaciones'])) {
            $filtros_variaciones = $arr['filtros_variaciones'];
        }
        // Compatibilidad retro
        if (!empty($arr['filtros_genericos']) && is_array($arr['filtros_genericos'])) {
            $filtros_genericos = array_merge($filtros_genericos, $arr['filtros_genericos']);
        }

        // Combina y delega en tu función existente
        $atributos_combinados = [
            'filtros_genericos'   => $filtros_genericos,   // NO son de variación
            'filtros_variaciones' => $filtros_variaciones, // SÍ son de variación
        ];

        if (function_exists('crear_y_asignar_atributos_producto_variable')) {
            try {
                crear_y_asignar_atributos_producto_variable($product_id, $atributos_combinados);
            } catch (Throwable $e) {
                log_msg_paso_2("⚠️ crear_y_asignar_atributos_producto_variable (padre {$ref}): " . $e->getMessage());
            }
        }

        // Categorías
        $cat_slug    = trim((string)($arr['categoria'] ?? ''));
        $subcat_slug = trim((string)($arr['subcategoria'] ?? ''));
        if ($cat_slug !== '' && function_exists('crear_y_asignar_categorias_productos')) {
            try {
                crear_y_asignar_categorias_productos($product_id, $cat_slug, $subcat_slug);
            } catch (Throwable $e) {
                log_msg_paso_2("⚠️ crear_y_asignar_categorias_productos (padre {$ref}): " . $e->getMessage());
            }
        }

        // Imágenes del PADRE
        

        if (function_exists('obtener_o_importar_imagenes_por_referencia')) {
            try {
                $imagenes = obtener_o_importar_imagenes_por_referencia($ref,$product_id);
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
                    }
                }
            } catch (Throwable $e) {
                log_msg_paso_2("⚠️ Imágenes (padre {$ref}): " . $e->getMessage());
            }
        } 


        // Metadatos personalizados del PADRE
        $meta_mapa = [
            'cantidad_minima'         => 'unidad_compra_minima',
            'largo_producto'          => 'medida_unitaria',
            'ancho_producto'          => 'medida_unitaria',
            'alto_producto'           => 'medida_unitaria',
            'peso_producto'           => 'peso_unitario',
            'und_caja'                => 'unidades_caja',
            'largo_caja'              => 'medidas_caja',
            'ancho_caja'              => 'medidas_caja',
            'alto_caja'               => 'medidas_caja',
            'peso_caja'               => 'peso_caja',
            'niveles_palet_eur'       => 'niveles_palet_eur',
            'niveles_palet_usa'       => 'niveles_palet_usa',
            'cajas_nivel_eur'         => 'niveles_cajas_eur',
            'cajas_nivel_usa'         => 'niveles_cajas_usa',
            'cajas_palet_eur'         => 'cajas_palet_eur',
            'cajas_palet_usa'         => 'cajas_palet_usa',
            'altura_palet_eur'        => 'altura_palet_eur',
            'altura_palet_usa'        => 'altura_palet_usa',
            'caracteristica_material' => 'material',
            'caracteristica_color'    => 'color',
        ];

        $medidas_producto = [];
        $medidas_caja     = [];

        foreach ($meta_mapa as $excel_key => $meta_key) {
            $valor = isset($arr[$excel_key]) ? trim((string)$arr[$excel_key]) : '';

            if (in_array($excel_key, ['largo_producto','ancho_producto','alto_producto'], true)) {
                if ($valor !== '') $medidas_producto[] = $valor;
            } elseif (in_array($excel_key, ['largo_caja','ancho_caja','alto_caja'], true)) {
                if ($valor !== '') $medidas_caja[] = $valor;
            }

            update_post_meta($product_id, $meta_key, $valor);
        }
        
            $campo_orden=$arr['campo_orden'];
            update_post_meta($product_id, 'campo_orden', $campo_orden);
        // Formateos finales
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

        update_post_meta($product_id, '_stock_status', 'instock');
        wp_set_post_terms($product_id, ['instock'], 'product_visibility', true);

        $addedprods[$ref] = $product_id;
    }

    return [
        'success' => true,
        'data' => [
            'type' => 'success',
            'message' => '✅ Sincronización completada (productos variables padre).',
            'productos' => $addedprods
        ]
    ];
}
