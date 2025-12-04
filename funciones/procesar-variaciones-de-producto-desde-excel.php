<?php
defined('ABSPATH') || exit;

/* ============================================================
 * LOGS
 * ============================================================ */
// ============= CAPTURA DE ERRORES/FATALES/EXCEPCIONES =============
if (!function_exists('paso3_log')) {
    function paso3_log($msg, $data = null) {
        $file = __DIR__ . '/logs/paso3_debug.txt';
        if (!file_exists(dirname($file))) @mkdir(dirname($file), 0777, true);
        $line = "[" . date('Y-m-d H:i:s') . "] " . $msg;
        if ($data !== null) $line .= " => " . print_r($data, true);
        @file_put_contents($file, $line . "\n", FILE_APPEND);
    }
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    paso3_log("⚠️ PHP ERROR [$errno] $errstr en $errfile:$errline");
    // Devolver false permite que PHP siga su flujo normal si es necesario
    return false;
});

set_exception_handler(function($ex) {
    paso3_log("💥 EXCEPTION: " . $ex->getMessage() . " @ " . $ex->getFile() . ":" . $ex->getLine());
    paso3_log("🧵 Trace: " . $ex->getTraceAsString());
});

register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        paso3_log("💥 FATAL SHUTDOWN", $e);
    }
    paso3_log("🧹 SHUTDOWN | memory=" . memory_get_usage(true) . " peak=" . memory_get_peak_usage(true));
});


/* ============================================================
 * Normalizador de valores del Excel
 * ============================================================ */
if (!function_exists('normalizar_valor_excel')) {
    function normalizar_valor_excel($valor) {
        if (!is_string($valor)) return '';
        $v = trim(str_replace(["\r", "\n", "\t"], " ", $valor));
        $v = str_replace([' / ', '/', '|', ';', ','], ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v);
        $partes = preg_split('/\s{2,}/', $v);
        if (count($partes) <= 1) return $v;
        return array_filter(array_map('trim', $partes));
    }
}


if (!function_exists('slug_valor_variacion')) {
    function slug_valor_variacion($valor) {
        $v = trim((string)$valor);
        $v = str_replace(['/', '\\', '·', '–', '—'], '-', $v);
        $v = str_replace([','], '.', $v);
        $v = preg_replace('/\s+/', ' ', $v);
        return sanitize_title($v);
    }
}

/* ============================================================
 * Eliminar TODAS las variaciones existentes de los padres
 * ============================================================ */
if (!function_exists('eliminar_variaciones_existentes')) {
    function eliminar_variaciones_existentes($sortedArr, $idioma = 'es') {
        paso3_log("=== 🔥 LIMPIEZA DE VARIACIONES EXISTENTES ===");
        if (empty($sortedArr['parent'])) {
            paso3_log("⚠️ No hay padres para limpiar variaciones");
            return;
        }
        foreach ($sortedArr['parent'] as $parent_ref => $padre) {
            if (empty($padre['tipo_woo']) || strtolower($padre['tipo_woo']) !== 'variable') continue;
            $parent_sku = $parent_ref . '-' . strtoupper($idioma);
            $parent_id  = wc_get_product_id_by_sku($parent_sku);
            if (!$parent_id) { paso3_log("❌ Padre no encontrado por SKU: $parent_sku"); continue; }
            $product = wc_get_product($parent_id);
            if (!$product || !$product->is_type('variable')) { paso3_log("❌ El padre $parent_ref existe pero no es variable"); continue; }
            foreach ($product->get_children() as $variation_id) {
                wp_delete_post($variation_id, true);
            }
            paso3_log("🗑️ Eliminadas variaciones antiguas del padre $parent_ref");
        }
    }
}

if (!function_exists('procesar_variaciones_de_producto_desde_excel')) {
    function procesar_variaciones_de_producto_desde_excel($sortedArr, $idioma = 'es') {

               $option_key = 'variaciones_procesadas';
               delete_option($option_key);

        paso3_log("===== INICIO PASO 3 (variaciones) =====", ['idioma' => $idioma]);

        if (empty($sortedArr['parent']) || !is_array($sortedArr['parent'])) {
            paso3_log("⚠️ Sin padres para procesar");
            return [
                'success' => true,
                'data'    => [
                    'type'    => 'info',
                    'message' => 'Sin productos padre para procesar variaciones'
                ]
            ];
        }

        // Mapa de metadatos técnicos/logísticos que guardamos en cada variación
        $meta_mapa = [
            'cantidad_minima'        => 'unidad_compra_minima',
            'largo_producto'         => 'medida_unitaria',
            'ancho_producto'         => 'medida_unitaria',
            'alto_producto'          => 'medida_unitaria',
            'peso_producto'          => 'peso_unitario',
            'und_caja'               => 'unidades_caja',
            'largo_caja'             => 'medidas_caja',
            'ancho_caja'             => 'medidas_caja',
            'alto_caja'              => 'medidas_caja',
            'peso_caja'              => 'peso_caja',
            'niveles_palet_eur'      => 'niveles_palet_eur',
            'niveles_palet_usa'      => 'niveles_palet_usa',
            'cajas_nivel_eur'        => 'niveles_cajas_eur',
            'cajas_nivel_usa'        => 'niveles_cajas_usa',
            'cajas_palet_eur'        => 'cajas_palet_eur',
            'cajas_palet_usa'        => 'cajas_palet_usa',
            'altura_palet_eur'       => 'altura_palet_eur',
            'altura_palet_usa'       => 'altura_palet_usa',
            'caracteristica_material'=> 'material',
            'caracteristica_color'   => 'color',
        ];

        $idioma_suffix = '_' . $idioma;

        $meta_mapa_multi = [
            'video'            => 'url_video',
            'caracteristica_1' => 'caracteristica_1',
            'caracteristica_2' => 'caracteristica_2',
            'caracteristica_3' => 'caracteristica_3',
            'caracteristica_4' => 'caracteristica_4',
            'caracteristica_5' => 'caracteristica_5',
        ];

        $added_variations    = [];
        $atributos_por_padre = [];

        foreach ($sortedArr['parent'] as $parent_ref => $padre) {

            paso3_log("---- Analizando padre {$parent_ref} ----");

            // Debe ser variable
            if (empty($padre['tipo_woo']) || strtolower($padre['tipo_woo']) !== 'variable') {
                paso3_log("⛔ Padre {$parent_ref} NO es variable, se omite");
                continue;
            }

            $parent_sku = $parent_ref . '-' . strtoupper($idioma);
            $parent_id  = wc_get_product_id_by_sku($parent_sku);

            if (!$parent_id) {
                paso3_log("❌ Padre no encontrado por SKU: {$parent_sku}");
                continue;
            }

            $parent_product = wc_get_product($parent_id);
            if (!$parent_product || $parent_product->get_type() !== 'variable') {
                paso3_log("❌ El padre {$parent_ref} (ID={$parent_id}) no es tipo variable");
                continue;
            }

            // Hijos Excel
            $hijos = $padre['hijos'] ?? [];
            if (empty($hijos)) {
                paso3_log("⚠️ Padre {$parent_ref} sin hijos; se omite creación de variaciones.");
                continue;
            }

            if (!isset($atributos_por_padre[$parent_id])) {
                $atributos_por_padre[$parent_id] = [];
            }

            // 🔹 Variaciones actuales del padre (antes de procesar Excel)
            $variaciones_actuales = $parent_product->get_children(); // array de IDs
            $variaciones_usadas   = []; // aquí guardaremos las que sigan existiendo según el Excel

            /* ---------------------------------------------------------
             * 1) Registrar taxonomías necesarias según atributos de hijos y padre
             * --------------------------------------------------------- */
            foreach ($hijos as $h) {
                if (!empty($h['atributos_variacion']) && is_array($h['atributos_variacion'])) {
                    foreach ($h['atributos_variacion'] as $nombre => $valor) {
                        if ($valor === '' || $valor === null) continue;
                        $slug_tax = 'pa_' . sanitize_title($nombre);
                        if ($slug_tax === 'pa_') continue;

                        if (!taxonomy_exists($slug_tax)) {
                            register_taxonomy($slug_tax, ['product'], [
                                'label'        => ucwords(str_replace(['pa_', '-', '_'], ' ', $slug_tax)),
                                'hierarchical' => false,
                                'show_ui'      => true,
                                'query_var'    => true,
                                'rewrite'      => false,
                            ]);
                            paso3_log("✅ Taxonomía creada: {$slug_tax}");
                        }
                    }
                }
            }

            if (!empty($padre['atributos_variacion'])) {
                foreach ($padre['atributos_variacion'] as $nombre => $valor) {
                    if ($valor === '' || $valor === null) continue;
                    $slug_tax = 'pa_' . sanitize_title($nombre);
                    $slug_val = slug_valor_variacion($valor);
                    if ($slug_tax === 'pa_' || $slug_val === '') continue;

                    $term = get_term_by('slug', $slug_val, $slug_tax);
                    if (!$term) {
                        $label = ucwords(str_replace(['-', '_'], ' ', $slug_val));
                        $ins   = wp_insert_term($label, $slug_tax, ['slug' => $slug_val]);
                        if (!is_wp_error($ins)) {
                            $term = get_term($ins['term_id'], $slug_tax);
                        }
                    }

                    if ($term && !is_wp_error($term)) {
                        $atributos_por_padre[$parent_id][$slug_tax]['terms'][]       = $term->slug;
                        $atributos_por_padre[$parent_id][$slug_tax]['is_variation'] = 1;
                    }
                }
            }


            /* ---------------------------------------------------------
             * 1.5) Aplicar atributos al PADRE antes de crear variaciones
             * --------------------------------------------------------- */
            if (!empty($atributos_por_padre[$parent_id])) {
                $attr_data_pre = [];

                foreach ($atributos_por_padre[$parent_id] as $taxonomy => $dataAttr) {
                    $terms = array_values(array_unique(array_filter($dataAttr['terms'] ?? [])));
                    if (empty($terms)) continue;

                    $is_variation = (strpos($taxonomy, 'pa_filtro_') !== false) ? 0 : (!empty($dataAttr['is_variation']) ? 1 : 0);
                    $attr_data_pre[$taxonomy] = [
                        'name'         => $taxonomy,
                        'value'        => '',
                        'is_visible'   => 1,
                        'is_variation' => $is_variation,
                        'is_taxonomy'  => 1,
                    ];

                    wp_set_object_terms($parent_id, $terms, $taxonomy, false);
                }

                update_post_meta($parent_id, '_product_attributes', $attr_data_pre);

                $parent_obj = wc_get_product($parent_id);
                if ($parent_obj instanceof WC_Product) {
                    try { $parent_obj->save(); }
                    catch (\Throwable $e) {
                        paso3_log("⚠️ Error guardando padre {$parent_ref} (pre-variaciones): " . $e->getMessage());
                    }
                }

                paso3_log("🧷 Atributos PRE aplicados al padre {$parent_ref} (antes de crear variaciones)");
            }



            /* ---------------------------------------------------------
             * 2) Crear / actualizar variaciones según los hijos del Excel
             * --------------------------------------------------------- */
            foreach ($hijos as $hijo) {

                $ref = trim((string)($hijo['referencia'] ?? ''));
                if ($ref === '') {
                    paso3_log("⚠️ Hijo sin referencia en {$parent_ref}, se omite.");
                    continue;
                }

                // Atributos de variación (map taxonomy => slug term)
                $mapa_variacion = [];

                if (!empty($hijo['atributos_variacion']) && is_array($hijo['atributos_variacion'])) {
                    foreach ($hijo['atributos_variacion'] as $nombre => $valorBruto) {
                        if ($valorBruto === '' || $valorBruto === null) continue;

                        $valores = normalizar_valor_excel($valorBruto);
                        if (!is_array($valores)) $valores = [$valores];

                        foreach ($valores as $valor) {
                            if ($valor === '') continue;

                            $slug_tax = 'pa_' . sanitize_title($nombre);
                            $slug_val = sanitize_title($valor);
                            if ($slug_tax === 'pa_' || $slug_val === '') continue;

                            if (!taxonomy_exists($slug_tax)) {
                                register_taxonomy($slug_tax, ['product'], [
                                    'label'        => ucwords(str_replace(['pa_', '-', '_'], ' ', $slug_tax)),
                                    'hierarchical' => false,
                                    'show_ui'      => true,
                                    'query_var'    => true,
                                    'rewrite'      => false,
                                ]);
                            }

                            $term = get_term_by('slug', $slug_val, $slug_tax);
                            if (!$term) {
                                $ins = wp_insert_term(ucwords($valor), $slug_tax, ['slug' => $slug_val]);
                                if (!is_wp_error($ins)) {
                                    $term = get_term($ins['term_id'], $slug_tax);
                                }
                            }

                            if ($term && !is_wp_error($term)) {
                                $mapa_variacion[$slug_tax] = $term->slug;
                                // Acumular en padre
                                $atributos_por_padre[$parent_id][$slug_tax]['terms'][]       = $term->slug;
                                $atributos_por_padre[$parent_id][$slug_tax]['is_variation'] = 1;
                            }
                        }
                    }
                }

                if (empty($mapa_variacion)) {
                    paso3_log("⚠️ Hijo {$ref} sin atributos de variación, no se crea/actualiza variación.");
                    continue;
                }

                // SKU de variación
                $sku_var      = $ref . '-VAR-' . strtoupper($idioma);
                $variation_id = wc_get_product_id_by_sku($sku_var);

                if ($variation_id) {
                    $variation = new WC_Product_Variation($variation_id);
                    paso3_log("↻ Actualizando variación existente {$ref} (ID={$variation_id})");
                } else {
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id($parent_id);
                    $variation->set_sku($sku_var);
                    $variation->set_status('publish');
                    paso3_log("➕ Creando variación {$ref} para padre {$parent_ref}");
                }

                // Nombre/Descripción
                $nombre_var = $hijo['descripcion_' . $idioma] ?? $hijo['descripcion_es'] ?? $ref;
                $desc_var   = $hijo['descripcion_larga_' . $idioma] ?? $hijo['descripcion_larga_es'] ?? '';

                $variation->set_name(trim($nombre_var));
                $variation->set_description(trim($desc_var));

                // Precio/stock
                $precio = isset($hijo['precio']) ? (float)$hijo['precio'] : 0.0;
                $stock  = isset($hijo['stock'])  ? (int)$hijo['stock']  : 0;

                $variation->set_regular_price($precio);
                $variation->set_price($precio);
                $variation->set_manage_stock(false);
                $variation->set_stock_status('instock');


                // Atributos
                $variation->set_attributes($mapa_variacion);

                // Guardar variación (sin imagen aún)
                try {
                    paso3_log("🧩 Hijo {$ref} atributos=", $mapa_variacion);
                    $variation_id = $variation->save();
                    paso3_log("✅ Variación {$ref} guardada (ID={$variation_id})");
                } catch (\Throwable $e) {
                    paso3_log("❌ ERROR guardando variación {$ref}: " . $e->getMessage());
                    continue;
                }

                // Registrar que esta variación sigue vigente según Excel
                $variaciones_usadas[] = $variation_id;

                // Guardar attribute_* en postmeta
                foreach ($mapa_variacion as $tax => $slug_val) {
                    update_post_meta($variation_id, 'attribute_' . $tax, $slug_val);
                }

                // Asegurar relación con el padre
                wp_update_post([
                    'ID'          => $variation_id,
                    'post_parent' => $parent_id,
                ]);

                // productos_complementarios
                if (array_key_exists('productos_complementarios', $hijo)) {
                    $pc = $hijo['productos_complementarios'];
                    if ($pc === null || $pc === '' || $pc === false || $pc === []) {
                        update_post_meta($variation_id, 'productos_complementarios', '');
                    } elseif (is_array($pc) || is_string($pc) || is_numeric($pc)) {
                        update_post_meta($variation_id, 'productos_complementarios', $pc);
                    }
                }

                // Metadatos técnicos
                try {
                    $medidas_producto = [];
                    $medidas_caja     = [];

                    foreach ($meta_mapa as $excel_key => $meta_key) {
                        $valor = isset($hijo[$excel_key]) ? trim((string)$hijo[$excel_key]) : '';

                        if (in_array($excel_key, ['largo_producto','ancho_producto','alto_producto'], true)) {
                            if ($valor !== '') $medidas_producto[] = $valor;
                        } elseif (in_array($excel_key, ['largo_caja','ancho_caja','alto_caja'], true)) {
                            if ($valor !== '') $medidas_caja[] = $valor;
                        }

                        update_post_meta($variation_id, $meta_key, $valor);
                    }

                    if (!empty($medidas_producto)) {
                        update_post_meta($variation_id, 'medida_unitaria', implode(' x ', $medidas_producto));
                    } else {
                        delete_post_meta($variation_id, 'medida_unitaria');
                    }

                    if (!empty($medidas_caja)) {
                        update_post_meta($variation_id, 'medidas_caja', implode(' x ', $medidas_caja));
                    } else {
                        delete_post_meta($variation_id, 'medidas_caja');
                    }

                     $campo_orden=$hijo['campo_orden'];
                    update_post_meta($variation_id, 'campo_orden', $campo_orden);


                    // Extras multi-idioma
                    update_post_meta($variation_id, '_titulo_variacion', $hijo['descripcion_' . $idioma] ?? '');

                    foreach ($meta_mapa_multi as $excel_base => $meta_base) {
                        $excel_key = $excel_base . $idioma_suffix;
                        $meta_key  = $meta_base . $idioma_suffix;
                        if (!empty($hijo[$excel_key])) {
                            update_post_meta($variation_id, $meta_key, $hijo[$excel_key]);
                        }
                    }
                } catch (\Throwable $e) {
                    paso3_log("⚠️ ERROR metadatos variación {$ref}: " . $e->getMessage());
                }

                // 🔹 IMAGEN DE VARIACIÓN (opcional pero recomendada)
               /* if (function_exists('obtener_imagen_variacion_fast')) {
                    try {
                        $imgs = obtener_imagen_variacion_fast($ref, $variation_id);

                        if (!empty($imgs['destacada_id'])) {
                            paso3_log("🖼️ Asignando imagen a variación {$ref}, attach_id={$imgs['destacada_id']}");
                            $variation->set_image_id($imgs['destacada_id']);
                            $variation->save(); // solo si hay imagen
                        } else {
                            paso3_log("⚠️ No se encontró imagen válida para la variación {$ref}");
                        }
                    } catch (\Throwable $e) {
                        paso3_log("⚠️ ERROR imagen variación {$ref}: " . $e->getMessage());
                    }
                } elseif (!empty($hijo['imagen_destacada'])) {
                    $attach_id = attachment_url_to_postid($hijo['imagen_destacada']);
                    if ($attach_id) {
                        paso3_log("🖼️ Asignando imagen desde campo imagen_destacada para variación {$ref}");
                        $variation->set_image_id($attach_id);
                        $variation->save();
                    } else {
                        paso3_log("⚠️ imagen_destacada no válida para variación {$ref}");
                    }
                } */

                $added_variations[$ref] = [
                    
                    'variation_id' => $variation_id,
                    'parent_id'    => $parent_id,

                ];
            } // fin foreach hijos 

            /* ---------------------------------------------------------
             * 3) Eliminar SOLO variaciones que ya no aparecen en el Excel
             * --------------------------------------------------------- */
            try {
                $variaciones_a_borrar = array_diff($variaciones_actuales, $variaciones_usadas);

                foreach ($variaciones_a_borrar as $id_eliminar) {
                    paso3_log("🗑️ Eliminando variación obsoleta ID={$id_eliminar} del padre {$parent_ref}");
                    wp_delete_post($id_eliminar, true);
                }
            } catch (\Throwable $e) {
                paso3_log("⚠️ ERROR eliminando variaciones obsoletas del padre {$parent_ref}: " . $e->getMessage());
            }

            /* ---------------------------------------------------------
             * 4) Atributos finales en el padre
             * --------------------------------------------------------- */
            try {


                if (!empty($atributos_por_padre[$parent_id])) {
                    $attr_data = [];

                    foreach ($atributos_por_padre[$parent_id] as $taxonomy => $dataAttr) {

                        $terms = array_values(array_unique(array_filter($dataAttr['terms'] ?? [])));
                        if (empty($terms)) {
                            continue;
                        }

                        // Filtros nunca son de variación
                        $is_variation = (strpos($taxonomy, 'pa_filtro_') !== false)
                            ? 0
                            : (!empty($dataAttr['is_variation']) ? 1 : 0);

                        // 👇 IMPORTANTE: no metemos los slugs en "value"
                        $attr_data[$taxonomy] = [
                            'name'         => $taxonomy,   // ej: 'pa_color'
                            'value'        => '',          // Woo usará los términos de la taxonomía
                            'is_visible'   => 1,
                            'is_variation' => $is_variation,
                            'is_taxonomy'  => 1,
                        ];

                        // Asignar términos al padre
                        wp_set_object_terms($parent_id, $terms, $taxonomy, false);
                    }

                    // Guardamos SOLO la estructura ligera de atributos
                    update_post_meta($parent_id, '_product_attributes', $attr_data);

                    // Refrescar padre, pero sin romper toda la sync si falla
                    $parent_obj = wc_get_product($parent_id);
                    if ($parent_obj instanceof WC_Product) {
                        try {
                            $parent_obj->save();
                        } catch (\Throwable $e) {
                            paso3_log("⚠️ Error al guardar padre {$parent_ref} (pero la sync continúa): " . $e->getMessage());
                        }
                    }

                    paso3_log("✅ Atributos finales aplicados al padre {$parent_ref}");
                }
            } catch (\Throwable $e) {
                paso3_log("❌ ERROR asignando atributos al padre {$parent_ref}: " . $e->getMessage());
            }


            paso3_log("✅ FIN PROCESO VARIACIONES PARA PADRE {$parent_ref}");
        } // fin foreach padres

     
        paso3_log("---- DEBUG: Paso3 va a devolver respuesta final ----");


        // ======================================
        // 🔄 GUARDAR VARIACIONES PROCESADAS EN wp_options (sin fecha, solo última tanda)
        // ======================================
        try {
            // Evita saturar la base de datos si hay miles de variaciones
            $conteo_total = count($added_variations);
            if ($conteo_total > 500) {
                $muestra = array_slice($added_variations, -500, null, true);
                paso3_log("⚠️ Truncando lista de variaciones para guardar solo las últimas 500 de {$conteo_total}");
            } else {
                $muestra = $added_variations;
            }

            $solo_variaciones = json_encode($muestra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($solo_variaciones === false) {
                paso3_log("⚠️ json_encode falló: " . json_last_error_msg());
                $solo_variaciones = '{}';
            }

           // update_option($option_key, $solo_variaciones, false);
            paso3_log("💾 Variaciones procesadas guardadas correctamente (última tanda, {$conteo_total} totales)");
        } catch (\Throwable $e) {
            paso3_log("❌ ERROR guardando variaciones procesadas: " . $e->getMessage());
        }




      return [
            'type'    => 'success',
            'message' => 'Procesamiento de DATOS finalizado correctamente.',
            'resumen' => [
                'simples'     => count($sortedArr['simples']    ?? []),
                'padres'      => count($sortedArr['parent']     ?? []),
                'bodegones'   => count($sortedArr['bodegones']  ?? []),
                'variaciones' => $added_variations  ?? [],
            ],
        ];


    }
}

