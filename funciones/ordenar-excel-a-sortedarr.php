<?php
function ordenar_excel_a_sortedArr(array $data): array {
    // =====================================
    // 🔹 0️⃣ Preparar carpetas y logs
    // =====================================
    $logs_dir = __DIR__ . '/logs';
    if (!is_dir($logs_dir)) { @mkdir($logs_dir, 0755, true); }

    $log_debug_ordenar  = $logs_dir . '/logs_debug_ordenar.txt';
    $log_resumen        = $logs_dir . '/logs_sortedArr.txt';
    $log_estructura     = $logs_dir . '/logs_sortedarr_estructura.txt';
    $log_errores_excel  = $logs_dir . '/errores-excel.txt';

    file_put_contents($log_debug_ordenar, "🔍 INICIO ordenar_excel_a_sortedArr (" . date('Y-m-d H:i:s') . ")\n");
    file_put_contents($log_resumen, "🧾 INICIO ORDENACIÓN EXCEL\n");
    file_put_contents($log_estructura, "");
    file_put_contents($log_errores_excel, "🧾 VALIDACIÓN DE ESTRUCTURA EXCEL (" . date('Y-m-d H:i:s') . ")\n\n");

    $productos = [];
    $global_amarillos = []; // p.ej. ['filtro_color' => ['Azul','Blanco',...]]
    $global_verdes    = []; // p.ej. ['color' => ['Azul','Blanco'], 'modelo'=>['Con Soporte','Sin Soporte']]



    // =====================================
    // 🔹 1️⃣ LEER FILAS Y CONSTRUIR ESTRUCTURA BASE
    // =====================================
    foreach ($data as $i => $row) {
        if (!is_array($row)) continue;
        $ref = trim((string)($row['referencia'] ?? ''));
        if ($ref === '') continue;

        $padre_flag = strtolower(trim((string)($row['padre'] ?? '')));
        $asociados  = trim((string)($row['codigos_asociados'] ?? ''));
        $codigos    = array_values(array_filter(array_map('trim', explode('-', $asociados))));
        $is_bodegon = str_starts_with($ref, '94');

        $complementarios=trim((string)($row['productos_complementarios'] ?? ''));
        $productos_complementarios = array_values(array_filter(array_map('trim', explode('-', $complementarios))));
 


             // 🧩 VALIDACIÓN DE FILA
        if (empty($ref)) {
            file_put_contents($log_errores_excel, "❌ [Fila $i] Sin referencia.\n", FILE_APPEND);
            continue; // no tiene sentido procesar una fila sin referencia
        }

        $es_padre = in_array($padre_flag, ['1', 'si', 'sí', 'true']);
        if ($es_padre && empty($asociados)) {
            file_put_contents($log_errores_excel, "⚠️ [{$ref}] Marcado como padre pero sin asociados.\n", FILE_APPEND);
        }


         // 🧩 VALIDACIÓN DE FILA
        if (empty($ref)) {
            file_put_contents($log_errores_excel, "❌ [Fila $i] Sin referencia.\n", FILE_APPEND);
            continue; // no tiene sentido procesar una fila sin referencia
        }

        $es_padre = in_array($padre_flag, ['1', 'si', 'sí', 'true']);
        if ($es_padre && empty($asociados)) {
            file_put_contents($log_errores_excel, "⚠️ [{$ref}] Marcado como padre pero sin asociados.\n", FILE_APPEND);
        }

        

        // === Campos de filtros ===
        $campos_filtros_amarillos = ['filtro_tipo', 'filtro_capacidad', 'filtro_color', 'filtro_modelo', 'filtro_material'];
        $atributos_filtros = [];
        foreach ($campos_filtros_amarillos as $campo) {
            $valor = trim((string)($row[$campo] ?? ''));
            $valor = str_replace(',', '.', $valor);
            if ($valor !== '') {
                $atributos_filtros[$campo] = $valor;
                $global_amarillos[$campo][] = $valor; // 👈 recolecta para Paso 0
            }
        }

        // === Campos de variación ===
        $campos_variacion_verdes = ['color','capacidad','patas','modelo','tipo','material'];
        $atributos_variacion = [];
        foreach ($campos_variacion_verdes as $campo) {
            $valor = isset($row[$campo]) ? trim((string)$row[$campo]) : '';
            $columna_otro = 'otro_' . $campo;
            $valor_otro = isset($row[$columna_otro]) ? trim((string)$row[$columna_otro]) : '';

            // normaliza decimales
            $valor      = str_replace(',', '.', $valor);
            $valor_otro = str_replace(',', '.', $valor_otro);

          // si modelo está vacío pero otro_modelo tiene valor, usar otro_modelo
            if ($campo === 'modelo' || $campo === 'capacidad' ) {
                if ($valor === '' && $valor_otro !== '') {
                    $valor = $valor_otro;
                } elseif ($valor !== '' && $valor_otro !== '') {
                    $valor = trim($valor . ' ' . $valor_otro);
                }
            }

            if ($valor !== '') {
                $atributos_variacion[$campo] = $valor;
                $global_verdes[$campo][] = $valor; // 👈 recolecta para Paso 0
            }
        }


        // ✅ Versión corregida: conserva el prefijo y normaliza valores decimales
        $filtros_normalizados = [];
        foreach ($row as $col => $valor) {
            if (strpos($col, 'filtro_') !== false && $valor !== '' && $valor !== null) {
                $nombre_attr = strtolower(trim($col)); // 👈 conserva "filtro_color"
                $valor_limpio = str_replace(',', '.', trim((string)$valor)); // normaliza "0,3" → "0.3"
                $slug = sanitize_title($valor_limpio);
                $filtros_normalizados[$nombre_attr][] = $slug;
            }
        }


        // === 💠 CONSTRUCCIÓN BASE (la parte que preguntas)
        $productos[$ref] = [
            'referencia' => $ref,
            'fila_excel' => $i + 1,
            'padre_flag' => $padre_flag,
            'campo_orden'=>trim((string)($row['orden'])),
            'codigos_asociados_raw' => $asociados,
            'codigos_asociados' => $codigos,
            'productos_complementarios'=>$productos_complementarios,
            'categoria' => trim((string)($row['categoria'] ?? '')),
            'subcategoria' => trim((string)($row['subcategoria'] ?? '')),
            'descripcion_es' => trim((string)($row['descripcion_es'] ?? '')),
            'descripcion_en' => trim((string)($row['descripcion_en'] ?? '')),
            'descripcion_fr' => trim((string)($row['descripcion_fr'] ?? '')),
            'descripcion_larga_es' => trim((string)($row['descripcion_larga_es'] ?? '')),
            'descripcion_larga_en' => trim((string)($row['descripcion_larga_en'] ?? '')),
            'descripcion_larga_fr' => trim((string)($row['descripcion_larga_fr'] ?? '')),
            'video_es' => trim((string)($row['video_es'] ?? '')),
            'video_en' => trim((string)($row['video_en'] ?? '')),
            'video_fr' => trim((string)($row['video_fr'] ?? '')),
            'cantidad_minima' => trim((string)($row['cantidad_minima'] ?? '')),
            'largo_producto' => trim((string)($row['largo_producto'] ?? '')),
            'ancho_producto' => trim((string)($row['ancho_producto'] ?? '')),
            'alto_producto' => trim((string)($row['alto_producto'] ?? '')),
            'peso_producto' => trim((string)($row['peso_producto'] ?? '')),
            'largo_caja' => trim((string)($row['largo_caja'] ?? '')),
            'ancho_caja' => trim((string)($row['ancho_caja'] ?? '')),
            'alto_caja' => trim((string)($row['alto_caja'] ?? '')),
            'peso_caja' => trim((string)($row['peso_caja'] ?? '')),
            'niveles_palet_eur' => trim((string)($row['niveles_palet_eur'] ?? '')),
            'niveles_palet_usa' => trim((string)($row['niveles_palet_usa'] ?? '')),
            'cajas_nivel_eur' => trim((string)($row['cajas_nivel_eur'] ?? '')),
            'cajas_nivel_usa' => trim((string)($row['cajas_nivel_usa'] ?? '')),
            'cajas_palet_eur' => trim((string)($row['cajas_palet_eur'] ?? '')),
            'cajas_palet_usa' => trim((string)($row['cajas_palet_usa'] ?? '')),
            'altura_palet_eur' => trim((string)($row['altura_palet_eur'] ?? '')),
            'altura_palet_usa' => trim((string)($row['altura_palet_usa'] ?? '')),
            'caracteristica_material' => trim((string)($row['caracteristica_material'] ?? '')),
            'caracteristica_color' => trim((string)($row['caracteristica_color'] ?? '')),
            'atributos_filtros' => $atributos_filtros,
            'atributos_filtros_normalizados' => $filtros_normalizados,
            'atributos_variacion' => $atributos_variacion,
            'tipo_producto' => null,
            'tipo_woo' => null,
            'parent_ref' => null,
            'hijos' => [],
        ];

        // === Características multilingües ===
        for ($c = 1; $c <= 5; $c++) {
            foreach (['es', 'en', 'fr'] as $lang) {
                $columna = "caracteristica_{$c}_{$lang}";
                if (!empty(trim((string)($row[$columna] ?? '')))) {
                    $productos[$ref][$columna] = trim((string)$row[$columna]);
                }
            }
        }

    }

    foreach ($global_amarillos as $k => &$vals) {
        $vals = array_values(array_unique(array_map('trim', $vals)));
        sort($vals, SORT_NATURAL|SORT_FLAG_CASE);
    }
    unset($vals);

    foreach ($global_verdes as $k => &$vals) {
        $vals = array_values(array_unique(array_map('trim', $vals)));
        sort($vals, SORT_NATURAL|SORT_FLAG_CASE);
    }
    unset($vals);


    // ============================================================
    // 2️⃣ Clasificación según reglas + detección inversa
    // ============================================================
    foreach ($productos as $ref => &$p) {
        $is_bodegon = str_starts_with($ref, '94');
        $tiene_asociados = !empty(array_filter($p['codigos_asociados']));
        $es_padre_flag = in_array($p['padre_flag'], ['1', 'si', 'sí', 'true'], true);

        if ($es_padre_flag && !$tiene_asociados) $es_padre_flag = false;

        if ($is_bodegon) {
            $p['tipo_producto'] = 'bodegon';
            $p['tipo_woo'] = 'simple';
        } elseif ($es_padre_flag && !$is_bodegon) {
            $p['tipo_producto'] = 'variable';
            $p['tipo_woo'] = 'variable';
        } elseif ($tiene_asociados && !$es_padre_flag) {
            $p['tipo_producto'] = 'variante';
            $p['tipo_woo'] = 'variante';
        } elseif (!$tiene_asociados) {
            $p['tipo_producto'] = 'simple';
            $p['tipo_woo'] = 'simple';
        } else {
            $p['tipo_producto'] = 'indefinido';
            $p['tipo_woo'] = null;
        }
    }
    unset($p);

    file_put_contents($log_debug_ordenar, "▶️ Iniciando detección inversa robusta...\n", FILE_APPEND);

    // === Detección inversa de variantes (robusta y lógica mejorada con resumen final)
    $padres_degradados = []; // 👈 guardaremos aquí los padres degradados a simple

    foreach ($productos as $ref_padre => &$padre) {
        file_put_contents($log_debug_ordenar, "🔍 Analizando padre $ref_padre\n", FILE_APPEND);
        $es_padre_flag = in_array(trim(strtolower((string)$padre['padre_flag'])), ['1', 'si', 'sí', 'true']);
        $hijos_validos = [];
        $hijos_inexistentes = [];

        if ($es_padre_flag && !empty($padre['codigos_asociados'])) {
            foreach ($padre['codigos_asociados'] as $ref_hijo) {
                if ($ref_hijo === $ref_padre) continue; // ignorar el propio producto

                if (isset($productos[$ref_hijo])) {
                    // hijo existente → marcar como variante
                    $productos[$ref_hijo]['tipo_producto'] = 'variante';
                    $productos[$ref_hijo]['tipo_woo'] = 'variante';
                    $productos[$ref_hijo]['parent_ref'] = $ref_padre;
                    $hijos_validos[] = $ref_hijo;
                } else {
                    // hijo no existe → registrar
                    $hijos_inexistentes[] = $ref_hijo;
                }
            }

            // === Clasificación final del padre según hijos válidos ===
            if (count($hijos_validos) > 0) {
                // tiene al menos un hijo válido → sigue siendo variable
                $padre['tipo_producto'] = 'variable';
                $padre['tipo_woo'] = 'variable';
            } else {
                // todos los hijos inexistentes → degradar a simple
                $padre['tipo_producto'] = 'simple';
                $padre['tipo_woo'] = 'simple';
                $padres_degradados[] = [
                    'ref' => $ref_padre,
                    'fila' => $padre['fila_excel'] ?? '?',
                    'hijos_inexistentes' => $hijos_inexistentes,
                ];
                $lista_inexistentes = is_array($hijos_inexistentes) && count($hijos_inexistentes) > 0 ? implode(', ', $hijos_inexistentes) : 'ninguno';
                file_put_contents(
                    $log_debug_ordenar,
                    "⚠️ Padre '{$ref_padre}' degradado a SIMPLE: hijos inexistentes ($lista_inexistentes)\n",
                    FILE_APPEND
                );

            }
        }
    }
    unset($padre);

    // === Resumen final de padres degradados ===
    if (!empty($padres_degradados)) {
        file_put_contents($log_debug_ordenar, "\n=== 🧩 RESUMEN DE PADRES DEGRADADOS A SIMPLES ===\n", FILE_APPEND);
        foreach ($padres_degradados as $pd) {
            file_put_contents(
                $log_debug_ordenar,
                "- Ref: {$pd['ref']} (fila Excel: {$pd['fila']}) → hijos inexistentes: " . implode(', ', $pd['hijos_inexistentes']) . "\n",
                FILE_APPEND
            );
        }
        file_put_contents($log_debug_ordenar, "=== FIN RESUMEN ===\n", FILE_APPEND);
    } else {
        file_put_contents($log_debug_ordenar, "✅ No se detectaron padres degradados a simples.\n", FILE_APPEND);
    }



    // ============================================================
    // 3️⃣ Construir $sortedArr con padres e hijos correctos
    // ============================================================
    $sortedArr = ['parent' => [], 'variantes' => [], 'simples' => [], 'bodegones' => []];

    foreach ($productos as $ref => $p) {
        if ($p['tipo_producto'] === 'variable') $sortedArr['parent'][$ref] = $p;
    }

    foreach ($productos as $ref => $p) {
        if ($p['tipo_producto'] === 'variante' && !empty($p['parent_ref'])) {
            $parent_ref = $p['parent_ref'];
            if (isset($sortedArr['parent'][$parent_ref])) {
                $sortedArr['parent'][$parent_ref]['hijos'][] = $p;
                $sortedArr['variantes'][] = $p;
            }
        }
    }

    // ============================================================
    // 3️⃣½ Agrupar atributos de variación de los hijos
    // ============================================================
    foreach ($sortedArr['parent'] as $ref_padre => &$padre) {
        $filtros_variaciones = [];
        foreach ($padre['hijos'] as $hijo) {
            foreach ($hijo['atributos_variacion'] as $nombre => $valor) {
                if ($valor !== '') $filtros_variaciones[$nombre][] = trim($valor);
            }
        }
        foreach ($filtros_variaciones as &$valores) {
            $valores = array_values(array_unique($valores));
        }
        unset($valores);
        if (!empty($filtros_variaciones)) {
            $padre['filtros_variaciones'] = $filtros_variaciones;
        }
    }
    unset($padre);

    // === 3.75) Inyectar "hijo sintético" si el padre tiene su propia combinación y no existe en los hijos
    foreach ($sortedArr['parent'] as $ref_padre => &$padre) {

         if (($padre['tipo_producto'] ?? '') === 'bodegon') {
            continue;
        }


        if (empty($padre['atributos_variacion']) || !is_array($padre['atributos_variacion'])) {
            continue;
        }

        // Normalizamos la combinación del padre (clave simple para comparar)
        $combo_padre = [];
        foreach ($padre['atributos_variacion'] as $k => $v) {
            $combo_padre[strtolower(sanitize_title($k))] = sanitize_title((string)$v);
        }

        // ¿Algún hijo ya tiene exactamente la misma combinación?
        $ya_existe = false;
        foreach ($padre['hijos'] as $h) {
            if (!empty($h['atributos_variacion']) && is_array($h['atributos_variacion'])) {
                $combo_hijo = [];
                foreach ($h['atributos_variacion'] as $kk => $vv) {
                    $combo_hijo[strtolower(sanitize_title($kk))] = sanitize_title((string)$vv);
                }
                if ($combo_hijo == $combo_padre) { $ya_existe = true; break; }
            }
        }

        if ($ya_existe) { continue; }

       
       // ➕ Crear un "hijo sintético" limpio (no clonar el padre completo)
        
        $hijo = [
            'referencia'                  => $ref_padre,
            'fila_excel'                  => $padre['fila_excel'],
            'parent_ref'                  => $ref_padre,
            'campo_orden'                 =>trim((string)($row['orden'])),

            // Identificación WooCommerce
            'tipo_producto'               => 'variante',
            'tipo_woo'                    => 'variante',

            // Atributos de variación — clave del hijo sintético
            'atributos_variacion'         => $padre['atributos_variacion'],

            // Filtros heredados
            'atributos_filtros'           => $padre['atributos_filtros'],
            'atributos_filtros_normalizados' => $padre['atributos_filtros_normalizados'],

            // Categoría, subcategoría y textos
            'categoria'                   => $padre['categoria'],
            'subcategoria'                => $padre['subcategoria'],
            'descripcion_es'              => $padre['descripcion_es'],
            'descripcion_en'              => $padre['descripcion_en'],
            'descripcion_fr'              => $padre['descripcion_fr'],

            // Videos
            'video_es'                    => $padre['video_es'],
            'video_en'                    => $padre['video_en'],
            'video_fr'                    => $padre['video_fr'],

            // Características físicas
            'cantidad_minima'             => $padre['cantidad_minima'],
            'largo_producto'              => $padre['largo_producto'],
            'ancho_producto'              => $padre['ancho_producto'],
            'alto_producto'               => $padre['alto_producto'],
            'peso_producto'               => $padre['peso_producto'],

            'largo_caja'                  => $padre['largo_caja'],
            'ancho_caja'                  => $padre['ancho_caja'],
            'alto_caja'                   => $padre['alto_caja'],
            'peso_caja'                   => $padre['peso_caja'],

            'niveles_palet_eur'           => $padre['niveles_palet_eur'],
            'niveles_palet_usa'           => $padre['niveles_palet_usa'],
            'cajas_nivel_eur'             => $padre['cajas_nivel_eur'],
            'cajas_nivel_usa'             => $padre['cajas_nivel_usa'],
            'cajas_palet_eur'             => $padre['cajas_palet_eur'],
            'cajas_palet_usa'             => $padre['cajas_palet_usa'],
            'altura_palet_eur'            => $padre['altura_palet_eur'],
            'altura_palet_usa'            => $padre['altura_palet_usa'],

            'caracteristica_material'     => $padre['caracteristica_material'],
            'caracteristica_color'        => $padre['caracteristica_color'],

            // Complementarios
            'productos_complementarios'   => $padre['productos_complementarios'],

            // Control
            'es_sintetico'                => true,


            // Un hijo nunca tiene hijos
            'hijos'                       => [],
        ];


        // Lo agregamos
        $sortedArr['parent'][$ref_padre]['hijos'][] = $hijo;
        $sortedArr['variantes'][] = $hijo;
    }
    unset($padre);




    // ============================================================
    // 4️⃣ Añadir simples y bodegones
    // ============================================================
    foreach ($productos as $ref => $p) {
        if ($p['tipo_producto'] === 'simple')  $sortedArr['simples'][] = $p;
        if ($p['tipo_producto'] === 'bodegon') $sortedArr['bodegones'][] = $p;
    }

    // ============================================================
    // 5️⃣ Logs finales
    // ============================================================
    $total_padres = count($sortedArr['parent']);
    $total_hijos  = array_sum(array_map(fn($p) => count($p['hijos']), $sortedArr['parent']));
    $total_simples = count($sortedArr['simples']);
    $total_bodegones = count($sortedArr['bodegones']);

    file_put_contents(
        $log_resumen,
        "✅ Resultado agrupado → Padres: $total_padres | Hijos: $total_hijos | Simples: $total_simples | Bodegones: $total_bodegones\n",
        FILE_APPEND
    );
    file_put_contents($log_estructura, print_r($sortedArr, true));

    $sortedArr['_atributos_amarillos'] = $global_amarillos;
    $sortedArr['_atributos_verdes']    = $global_verdes;

    // Log opcional
    $log_atributos = $logs_dir . '/atributos-globales.txt';
    file_put_contents($log_atributos, "🧭 ATRIBUTOS AMARILLOS:\n" . print_r($global_amarillos, true));
    file_put_contents($log_atributos, "\n🧭 ATRIBUTOS VERDES:\n" . print_r($global_verdes, true), FILE_APPEND);


    return $sortedArr;
}
