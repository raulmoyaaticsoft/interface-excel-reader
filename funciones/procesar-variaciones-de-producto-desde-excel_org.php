<?php
// Asegurarse de que WordPress/WooCommerce está cargado
defined('ABSPATH') || exit;

if (!function_exists('log_msg_paso_3')) {
    function log_msg_paso_3($msg) {
        $log_dir  = __DIR__ . '/logs/';
        $log_file = $log_dir . 'paso3.txt';
        if (!file_exists($log_dir)) mkdir($log_dir, 0777, true);
        $line = '[ ARRANCA EL PASO 3 ' . date('Y-m-d H:i:s') . '] ' . print_r($msg, true) . PHP_EOL;
        file_put_contents($log_file, $line, FILE_APPEND);
    }
}
// ============================================================
// 🔍 DEBUG PROFUNDO – PASO 3
// ============================================================
function log_paso3_debug($msg, $data = null) {
    $file = __DIR__ . '/logs/paso3_debug.txt';
    $line = "\n[" . date('Y-m-d H:i:s') . "] " . $msg;
    if ($data !== null) {
        $line .= " => " . print_r($data, true);
    }
    file_put_contents($file, $line, FILE_APPEND);
}


// ============================================================
// ✅ NORMALIZADOR DE VALORES DEL EXCEL PARA ATRIBUTOS
// ============================================================
if (!function_exists('normalizar_valor_excel')) {
    function normalizar_valor_excel($valor) {

        if (!is_string($valor)) return '';

        // Limpieza básica
        $v = trim(str_replace(["\r", "\n", "\t"], " ", $valor));

        // Unificar separadores típicos del Excel
        $v = str_replace([' / ', '/', '|', ';', ','], ' ', $v);

        // Reducir espacios duplicados
        $v = preg_replace('/\s+/', ' ', $v);

        // Si trae concatenaciones tipo "15 cms.   Palo"
        // los separadores suelen ser doble o triple espacio
        $partes = preg_split('/\s{2,}/', $v);

        if (count($partes) <= 1) {
            return $v; // valor único
        }

        // Múltiples valores → array limpio
        return array_filter(array_map('trim', $partes));
    }
}


/**
 * Elimina TODAS las variaciones de los padres variables presentes en $sortedArr['parent'].
 */
function eliminar_todas_las_variaciones($sortedArr, $idioma = 'es') {
    log_msg_paso_3("=== 🧩 LIMPIEZA DE VARIACIONES EXISTENTES ===");

    if (empty($sortedArr['parent'])) {
        log_msg_paso_3("⚠️ No hay padres definidos en \$sortedArr['parent']");
        return;
    }

    foreach ($sortedArr['parent'] as $parent_ref => $padre) {

        log_paso3_debug("---- Analizando padre ----", $parent_ref);
        log_paso3_debug("Tipo producto", $padre['tipo_producto']);
        log_paso3_debug("Hijos encontrados", count($padre['hijos'] ?? []));
        log_paso3_debug("Atributos variación del padre", $padre['atributos_variacion'] ?? 'none');

        // Si es bodegón, registrar y saltar
        if (($padre['tipo_producto'] ?? '') === 'bodegon') {
            log_paso3_debug("⛔ Padre es bodegón → se omite creación de variaciones", $parent_ref);
        }



        if (!isset($padre['tipo_woo']) || strtolower($padre['tipo_woo']) !== 'variable') continue;

        $parent_sku = $parent_ref . '-' . strtoupper($idioma);
        $parent_id  = wc_get_product_id_by_sku($parent_sku);
        if (!$parent_id) continue;

        $product = wc_get_product($parent_id);
        if ($product && $product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                wp_delete_post($variation_id, true);
            }
            log_msg_paso_3("🗑️ Eliminadas variaciones antiguas del padre {$parent_ref}");
        }
    }
}

/**
 * Crea/actualiza VARIACIONES a partir del array del Excel.
 * Reglas:
 *  - Cualquier taxonomía que contenga 'pa_filtro_' => NO es de variación (solo visible en padre)
 *  - Cualquier taxonomía 'pa_*' que NO sea 'pa_filtro_*' => SÍ es de variación
 *  - La asignación de IMÁGENES de variación está DESACTIVADA (se puede reactivar más adelante)
 */
function procesar_variaciones_de_producto_desde_excel($sortedArr, $idioma = 'es') {

    log_paso3_debug("===== INICIO PASO 3 DEBUG PROFUNDO =====");
    log_paso3_debug("Padres detectados en sortedArr['parent']", array_keys($sortedArr['parent']));
    log_paso3_debug("Variantes detectadas en sortedArr['variantes']", count($sortedArr['variantes']));
    log_paso3_debug("Simples detectados", count($sortedArr['simples']));
    log_paso3_debug("Bodegones detectados", count($sortedArr['bodegones']));

    eliminar_todas_las_variaciones($sortedArr, $idioma);
    log_msg_paso_3("=== 🧩 INICIO PROCESO VARIACIONES ===");

    $added_variations    = [];
    // Estructura: $atributos_por_padre[parent_id][taxonomy] = ['terms'=>[], 'is_variation'=>0|1]
    $atributos_por_padre = [];

    if (empty($sortedArr['parent'])) {
        log_msg_paso_3("⚠️ No hay padres en sortedArr['parent']");
        return ['success' => true, 'data' => ['type' => 'info', 'message' => 'Sin productos padre para procesar']];
    }

    foreach ($sortedArr['parent'] as $parent_ref => $padre) {
        log_paso3_debug("---- Analizando padre ----", $parent_ref);
        if (!isset($padre['tipo_woo']) || strtolower($padre['tipo_woo']) !== 'variable') {
            log_msg_paso_3("⚠️ Saltando padre {$parent_ref} porque no es variable");
            continue;
        }

        $parent_sku = $parent_ref . '-' . strtoupper($idioma);
        $parent_id  = wc_get_product_id_by_sku($parent_sku);
        if (!$parent_id) {
            log_msg_paso_3("❌ Padre no encontrado por SKU: {$parent_sku}");
            continue;
        }

        $parent_product = wc_get_product($parent_id);
        if (!$parent_product || $parent_product->get_type() !== 'variable') {
            log_msg_paso_3("❌ El padre {$parent_id} no es variable");
            continue;
        }

        $hijos = $padre['hijos'] ?? [];

        // ============================================================
        // ✅ REGISTRO PREVIO DE TODAS LAS TAXONOMÍAS DE ATRIBUTOS
        //    DE TODOS LOS HIJOS → evita errores 500 al asignar términos
        // ============================================================
        foreach ($hijos as $h) {
            if (!empty($h['atributos_variacion']) && is_array($h['atributos_variacion'])) {
                foreach ($h['atributos_variacion'] as $nombre => $valor) {

                    if ($valor === '' || $valor === null) continue;

                    $slug_tax = 'pa_' . sanitize_title($nombre);

                    // Evitar taxonomías inválidas como "pa_"
                    if ($slug_tax === 'pa_') continue;

                    if (!taxonomy_exists($slug_tax)) {
                        register_taxonomy($slug_tax, ['product'], [
                            'label'        => ucwords(str_replace(['pa_', '-', '_'], ' ', $slug_tax)),
                            'hierarchical' => false,
                            'show_ui'      => true,
                            'query_var'    => true,
                            'rewrite'      => false,
                        ]);
                        log_msg_paso_3("✅ Registrada taxonomía previa: {$slug_tax}");
                    }
                }
            }
        }



                // ✅ Inyectar atributos del propio padre en el array de atributos del producto variable
        if (!empty($padre['atributos_variacion'])) {
            foreach ($padre['atributos_variacion'] as $nombre => $valor) {

    if ($valor === '' || $valor === null) continue;

    $slug_tax = 'pa_' . sanitize_title($nombre);
    $slug_val = sanitize_title($valor);

    // ✅ VALIDACIÓN CRÍTICA – EVITA ERROR 500
    if ($slug_tax === 'pa_' || $slug_val === '') {
        log_msg_paso_3("❌ Atributo inválido del padre: nombre='$nombre' valor='$valor'");
        continue;
    }

    if (!taxonomy_exists($slug_tax)) {
        register_taxonomy($slug_tax, ['product'], [
            'label'        => ucwords(str_replace(['pa_', '-', '_'], ' ', $slug_tax)),
            'hierarchical' => false,
            'show_ui'      => true,
            'query_var'    => true,
            'rewrite'      => false,
        ]);
    }

    // Crear término del valor del PADRE
    $term = get_term_by('slug', $slug_val, $slug_tax);
    if (!$term) {
        $label = ucwords(str_replace(['-', '_'], ' ', $slug_val));
        $ins = wp_insert_term($label, $slug_tax, ['slug' => $slug_val]);

        // ✅ Detectar errores reales
        if (is_wp_error($ins)) {
            log_msg_paso_3("❌ ERROR insertando término '$slug_val' en '$slug_tax': " . $ins->get_error_message());
            continue;
        }

        $term = get_term($ins['term_id'], $slug_tax);
    }

    if (!isset($atributos_por_padre[$parent_id][$slug_tax])) {
        $atributos_por_padre[$parent_id][$slug_tax] = [
            'terms'        => [],
            'is_variation' => 1
        ];
    }

    $atributos_por_padre[$parent_id][$slug_tax]['terms'][] = $term->slug;
}

        }

        if (empty($hijos)) {
            log_msg_paso_3("⚠️ Padre {$parent_ref} sin hijos, se omite.");
            continue;
        }

        log_msg_paso_3("➡️ Procesando padre {$parent_ref} con " . count($hijos) . " hijos.");

        // === Metadatos personalizados para cada variación
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

        // ============================================================
        // ✅ PRECONSTRUIR $arr['filtros'] EN CADA HIJO
        //    desde atributos_filtros (valores "bonitos") y
        //    atributos_filtros_normalizados (slugs)
        // ============================================================
        foreach ($hijos as &$arr) {
            if (!isset($arr['filtros']) || !is_array($arr['filtros'])) {
                $arr['filtros'] = [];
            }

            if (!empty($arr['atributos_filtros']) && is_array($arr['atributos_filtros'])) {
                foreach ($arr['atributos_filtros'] as $k => $v) {
                    if ($v !== '' && $v !== null) {
                        $arr['filtros'][$k] = $v; // se slugifica más abajo
                    }
                }
            }

            if (!empty($arr['atributos_filtros_normalizados']) && is_array($arr['atributos_filtros_normalizados'])) {
                foreach ($arr['atributos_filtros_normalizados'] as $k => $vals) {
                    if (is_array($vals)) {
                        foreach ($vals as $slugVal) {
                            if ($slugVal !== '' && $slugVal !== null) {
                                // si no había valor, toma el slug normalizado
                                if (!isset($arr['filtros'][$k]) || $arr['filtros'][$k] === '') {
                                    $arr['filtros'][$k] = $slugVal;
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }
        unset($arr); // rompe la referencia del foreach por referencia

        // ✅ Antes de crear variaciones, el padre DEBE tener los atributos asignados
            if (!empty($atributos_por_padre[$parent_id])) {

                log_msg_paso_3("✅ Asignando atributos al padre {$parent_ref} ANTES de crear variaciones");

                $attr_data = [];

                foreach ($atributos_por_padre[$parent_id] as $taxonomy => $dataAttr) {
                    $terms = array_values(array_unique($dataAttr['terms']));
                    $is_variation = !empty($dataAttr['is_variation']) ? 1 : 0;

                    if (strpos($taxonomy, 'pa_filtro_') !== false) {
                        $is_variation = 0;
                    }

                    $attr_data[$taxonomy] = [
                        'name'         => $taxonomy,
                        'value'        => implode(' | ', $terms),
                        'is_visible'   => 1,
                        'is_variation' => $is_variation,
                        'is_taxonomy'  => 1
                    ];

                    // asignar términos al padre
                    if (!empty($terms)) {
                        wp_set_object_terms($parent_id, $terms, $taxonomy, false);
                    }
                }

                update_post_meta($parent_id, '_product_attributes', $attr_data);

                $parent_obj = wc_get_product($parent_id);
                if ($parent_obj instanceof WC_Product) {
                    $parent_obj->save();
                }

                log_msg_paso_3("✅ Padre {$parent_ref} preparado con atributos");
            }


        // ============================================================
        // 🔄 Procesar cada hijo -> crear/actualizar variación
        // ============================================================
        foreach ($hijos as $arr) {
            log_paso3_debug("Procesando hijo", $arr['referencia'] ?? 'sin-ref');
            log_paso3_debug("Atributos hijo", $arr['atributos_variacion'] ?? []);
            log_paso3_debug("Filtros hijo", $arr['atributos_filtros'] ?? []);
            $ref = trim((string)($arr['referencia'] ?? ''));
            if ($ref === '') continue;

            // === Atributos de VARIACIÓN (verdes)
            $mapa_variacion = [];
            if (!empty($arr['atributos_variacion']) && is_array($arr['atributos_variacion'])) {
                foreach ($arr['atributos_variacion'] as $nombre => $valorBruto) {
                    if ($valorBruto === '' || $valorBruto === null) continue;

                    $valores = normalizar_valor_excel($valorBruto);

                    // Forzar array siempre
                    if (!is_array($valores)) {
                        $valores = [$valores];
                    }

                    foreach ($valores as $valor) {

                        if ($valor === '') continue;

                        $slug_tax = 'pa_' . sanitize_title($nombre);
                        $slug_val = sanitize_title($valor);

                        if ($slug_tax === 'pa_' || $slug_val === '') {
                            log_msg_paso_3("❌ Valor de variación inválido: $nombre = $valorBruto");
                            continue;
                        }

                        // Registrar taxonomía si no existe
                        if (!taxonomy_exists($slug_tax)) {
                            register_taxonomy($slug_tax, ['product'], [
                                'label'        => ucwords(str_replace(['pa_', '-', '_'], ' ', $slug_tax)),
                                'hierarchical' => false,
                                'show_ui'      => true,
                                'query_var'    => true,
                                'rewrite'      => false,
                            ]);
                        }

                        // Crear término si no existe
                        $term = get_term_by('slug', $slug_val, $slug_tax);
                        if (!$term) {
                            $ins = wp_insert_term(ucwords($valor), $slug_tax, ['slug' => $slug_val]);
                            if (!is_wp_error($ins)) {
                                $term = get_term($ins['term_id'], $slug_tax);
                            }
                        }

                        // Guardar en el mapa de variación
                        if ($term && !is_wp_error($term)) {
                            $mapa_variacion[$slug_tax] = $term->slug;
                            $atributos_por_padre[$parent_id][$slug_tax]['terms'][] = $term->slug;
                        }
                    }
                }

            }

            // === Filtros AMARILLOS del hijo (NO variación): $arr['filtros']
            if (!empty($arr['filtros']) && is_array($arr['filtros'])) {
                foreach ($arr['filtros'] as $nombre => $valor) {
                    if ($valor === '' || $valor === null) continue;

                    $slug_tax = 'pa_' . preg_replace('/^pa_/', '', sanitize_title($nombre));
                    $slug_val = sanitize_title((string)$valor);

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
                        $nombre_normalizado = ucwords(str_replace(['-', '_'], ' ', $slug_val));
                        $ins = wp_insert_term($nombre_normalizado, $slug_tax, ['slug' => $slug_val]);
                        if (!is_wp_error($ins)) {
                            $term = get_term($ins['term_id'], $slug_tax);
                        }
                    }

                    if ($term && !is_wp_error($term)) {
                        if (!isset($atributos_por_padre[$parent_id][$slug_tax])) {
                            $atributos_por_padre[$parent_id][$slug_tax] = [
                                'terms'        => [],
                                'is_variation' => 0 // Filtros nunca son variación
                            ];
                        }
                        $atributos_por_padre[$parent_id][$slug_tax]['terms'][] = $term->slug;
                        // Fuerza a NO variación si alguien llamó esto como pa_filtro_
                        if (strpos($slug_tax, 'pa_filtro_') !== false) {
                            $atributos_por_padre[$parent_id][$slug_tax]['is_variation'] = 0;
                        }
                    }
                }
            }

            if (empty($mapa_variacion)) {
                log_msg_paso_3("⚠️ Sin atributos de variación en {$ref}, se omite la creación de variación.");
                continue;
            }

            // === Crear o actualizar variación
            $sku_var       = $ref . '-VAR-' . strtoupper($idioma);
            $variation_id  = wc_get_product_id_by_sku($sku_var);

            if ($variation_id) {
                $variation = new WC_Product_Variation($variation_id);
                log_msg_paso_3("↻ Actualizando variación existente {$ref} (ID={$variation_id})");
            } else {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($parent_id);
                $variation->set_sku($sku_var);
                $variation->set_status('publish');
                log_msg_paso_3("➕ Creando variación {$ref} para padre {$parent_ref}");
            }

            // Datos de la variación
            $nombre_var      = $arr['descripcion_' . $idioma] ?? $arr['descripcion_es'] ?? $ref;
            $descripcion_var = $arr['descripcion_larga_' . $idioma] ?? $arr['descripcion_larga_es'] ?? '';

            $variation->set_name(trim($nombre_var));
            $variation->set_description(trim($descripcion_var));



            $stock  = 110;
            $precio = 110.0;

            $variation->set_regular_price($precio);
            $variation->set_price($precio);
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($stock);
            $variation->set_stock_status($stock > 0 ? 'instock' : 'outofstock');

            // Atributos en la variación
            $variation->set_attributes($mapa_variacion);

            // --- BLOQUE DE IMAGEN DESACTIVADO (estabilizado)
            log_msg_paso_3("🟢 Variación {$ref}: bloque de imagen desactivado temporalmente");

            // Guardar variación
            try {
                log_msg_paso_3("🟢 Variación {$ref}: antes de save() con parent_id={$parent_id}");

                log_paso3_debug("Antes de save() variación", $sku_var);

                $variation_id = $variation->save();

                log_paso3_debug("Variación guardada correctamente", $variation_id);


                // ✅ Guardar productos_complementarios si existe y no rompe el proceso
                    if (array_key_exists('productos_complementarios', $arr)) {

                        $pc = $arr['productos_complementarios'];

                        // Log del contenido que llega
                        log_paso3_debug("productos_complementarios recibido", $pc);

                        // Evitar valores que causan error 500
                        if (
                            is_null($pc) ||
                            $pc === '' ||
                            $pc === [] ||
                            $pc === false
                        ) {
                            // Guardar como vacío seguro
                            update_post_meta($variation_id, 'productos_complementarios', '');
                            log_paso3_debug("productos_complementarios guardado vacío", $arr['referencia']);
                        }
                        elseif (is_array($pc) || is_string($pc) || is_numeric($pc)) {
                            // Guardar valores válidos
                            update_post_meta($variation_id, 'productos_complementarios', $pc);
                            log_paso3_debug("productos_complementarios guardado OK", $arr['referencia']);
                        }
                        else {
                            // Detectar valores peligrosos
                            log_paso3_debug("❌ productos_complementarios inválido, no se guarda", gettype($pc));
                        }

                    } else {
                        log_paso3_debug("productos_complementarios no existe para hijo", $arr['referencia']);
                    }


                log_msg_paso_3("✅ Variación {$ref} guardada correctamente con ID={$variation_id}");
            } catch (Throwable $e) {
                log_msg_paso_3("❌ ERROR al guardar variación {$ref}: " . $e->getMessage());

                 log_paso3_debug("❌ ERROR GRAVE EN SAVE VARIACIÓN", $e->getMessage());
                continue;
            }

            // Características personalizadas en la VARIACIÓN
            if (function_exists('guardar_caracteristicas_personalizadas')) {
                try {
                    guardar_caracteristicas_personalizadas($variation_id, $arr);
                    log_msg_paso_3("🟢 Variación {$ref}: características guardadas");
                } catch (Throwable $e) {
                    log_msg_paso_3("⚠️ guardar_caracteristicas_personalizadas (var {$ref}): " . $e->getMessage());
                }
            }

            // Metadatos en la VARIACIÓN
            try {
                $medidas_producto = [];
                $medidas_caja     = [];

                foreach ($meta_mapa as $excel_key => $meta_key) {
                    $valor = isset($arr[$excel_key]) ? trim((string)$arr[$excel_key]) : '';

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
                log_msg_paso_3("🧩 Metadatos personalizados guardados correctamente para variación {$ref}");
            } catch (Throwable $e) {
                log_msg_paso_3("❌ ERROR guardando metadatos en variación {$ref}: " . $e->getMessage());
            }

            // Atributos (meta) en la variación y marcar atributos de variación en padre vía tu función
            try {
                // Relación con el padre
                wp_update_post(['ID' => $variation_id, 'post_parent' => $parent_id]);

                // Llamada a tu función, si existe
                if (function_exists('crear_y_asignar_atributos_de_variaciones_de_producto')) {
                    crear_y_asignar_atributos_de_variaciones_de_producto($variation_id, [
                        'filtros_variaciones' => $mapa_variacion
                    ]);
                    log_msg_paso_3("✅ Atributos aplicados y marcados como usados para variaciones en padre {$parent_ref}");
                }

                // Guardar atributos también en meta de la variación
                foreach ($mapa_variacion as $tax => $slug_val) {
                    update_post_meta($variation_id, 'attribute_' . $tax, $slug_val);
                }

                // Extras multi-idioma
                update_post_meta($variation_id, '_titulo_variacion', $arr['descripcion_' . $idioma] ?? '');
                foreach ($meta_mapa_multi as $excel_base => $meta_base) {
                    $excel_key = $excel_base . $idioma_suffix;
                    $meta_key  = $meta_base . $idioma_suffix;
                    if (!empty($arr[$excel_key])) {
                        update_post_meta($variation_id, $meta_key, $arr[$excel_key]);
                    }
                }

                $added_variations[$ref] = ['variation_id' => $variation_id, 'parent_id' => $parent_id];
                log_msg_paso_3("💾 Variación {$ref} guardada (ID={$variation_id}) con attrs: " . json_encode($mapa_variacion));
            } catch (Throwable $e) {
                log_msg_paso_3("❌ ERROR al finalizar variación {$ref} (ID={$variation_id}): " . $e->getMessage());
            }
        }

        // === Asignar atributos acumulados al PADRE (visibles vs. de variación)
       
        log_paso3_debug("Asignando atributos al padre", $parent_ref);   // ✅ AÑADIDO
        log_msg_paso_3("🟪 CHECKPOINT 1 - Antes de asignar atributos al padre {$parent_ref}");

      

        try {
            log_msg_paso_3("🟩 CHECKPOINT 3 - Antes del retorno final del proceso");

            // ✅ MARCAR ATRIBUTOS DE VARIACIÓN EN EL PADRE
                if (!empty($atributos_por_padre[$parent_id])) {

                    $attr_data = [];

                    foreach ($atributos_por_padre[$parent_id] as $taxonomy => $dataAttr) {
                        $terms = array_values(array_unique($dataAttr['terms']));

                        // Filtros = nunca variación
                        $is_variation = (strpos($taxonomy, 'pa_filtro_') !== false)
                                        ? 0
                                        : (!empty($dataAttr['is_variation']) ? 1 : 0);

                        $attr_data[$taxonomy] = [
                            'name'         => $taxonomy,
                            'value'        => implode(' | ', $terms),
                            'is_visible'   => 1,
                            'is_variation' => $is_variation,
                            'is_taxonomy'  => 1
                        ];
                    }

                    // Guardar estructura completa de atributos en el padre
                    update_post_meta($parent_id, '_product_attributes', $attr_data);

                    // Guardar términos en el padre
                    foreach ($atributos_por_padre[$parent_id] as $taxonomy => $dataAttr) {
                        $terms = array_values(array_unique($dataAttr['terms']));
                        if (!empty($terms)) {
                            wp_set_object_terms($parent_id, $terms, $taxonomy, true);
                        }
                    }

                    $parent_obj = wc_get_product($parent_id);
                    if ($parent_obj instanceof WC_Product) {
                        $parent_obj->save();
                    }
                }



            log_msg_paso_3("✅ FIN PROCESO VARIACIONES");
        } catch (Throwable $e) {
            log_msg_paso_3("❌ ERROR en checkpoint final: " . $e->getMessage());
        }
    }

    log_msg_paso_3("✅ FIN PROCESO VARIACIONES");

   log_paso3_debug("===== FIN PASO 3 DEBUG =====");   // ✅ AÑADIDO



    return [
        'success' => true,
        'data' => [
            'type'     => 'success',
            'message'  => '✅ Variaciones creadas y asociadas correctamente.',
            'variaciones' => $added_variations
        ]
    ];
}
