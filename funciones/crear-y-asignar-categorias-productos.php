<?php
if (!function_exists('crear_y_asignar_categorias_productos')) {
    function crear_y_asignar_categorias_productos($product_id, $categoria_slug, $subcategoria_slug = '') {
        if (empty($product_id) || empty($categoria_slug)) {
            return;
        }

        // ============================================================
        // 🧩 Normalizar categoría padre
        // ============================================================
        $categoria_slug   = sanitize_title($categoria_slug);
        $categoria_nombre = ucwords(str_replace(['-', '_'], ' ', $categoria_slug));

        // ============================================================
        // 🔍 Descomponer subcategoría (múltiples niveles o hermanas)
        // ============================================================
        $niveles = [];
        $modo_hermanas = false;

        if (!empty($subcategoria_slug)) {
            // Normalizar separadores y formato
            $sub_raw = trim(str_replace('__', '_', $subcategoria_slug));
            $sub_raw = strtolower(str_replace(' ', '_', $sub_raw));

            // ✅ Si empieza por el slug de la categoría, mantenemos prefijo jerárquico con "-"
            if (strpos($sub_raw, $categoria_slug . '_') === 0) {
                $sub_raw = str_replace($categoria_slug . '_', $categoria_slug . '-', $sub_raw);
            }

            // 🧠 Si hay "/", tratamos como subcategorías hermanas (mismo nivel)
            if (strpos($sub_raw, '/') !== false) {
                $niveles = array_filter(array_map('trim', explode('/', $sub_raw)));
                $modo_hermanas = true;
            } else {
                $niveles = array_filter(array_map('trim', explode('_', $sub_raw)));
            }
        }

        // ============================================================
        // 🏗️ Crear categoría padre (nivel 1)
        // ============================================================
        $categoria_term = get_term_by('slug', $categoria_slug, 'product_cat');

        if (!$categoria_term) {
            $resultado = wp_insert_term($categoria_nombre, 'product_cat', ['slug' => $categoria_slug]);
            if (is_wp_error($resultado)) return;
            $categoria_term_id = $resultado['term_id'];
        } else {
            $categoria_term_id = $categoria_term->term_id;
        }

        $ultimo_parent_id = $categoria_term_id;
        $term_ids = [$categoria_term_id];

        // ============================================================
        // 🧱 Crear subniveles o hermanas
        // ============================================================
        if (!empty($niveles)) {
            foreach ($niveles as $nivel) {
                if (empty($nivel)) continue;

                // Normalizar slug (para estructura única)
                $slug = sanitize_title($nivel);

                // 🧠 Si el slug contiene el nombre de la categoría padre, limpiamos el prefijo en el nombre visible
                $nombre_limpio = $nivel;
                if (strpos($nombre_limpio, $categoria_slug . '-') === 0) {
                    $nombre_limpio = substr($nombre_limpio, strlen($categoria_slug) + 1);
                }

                // Convertir en formato visible
                $nombre = ucwords(str_replace(['-', '_'], ' ', $nombre_limpio));

                // Determinar jerarquía
                if (strpos($nivel, $categoria_slug) === 0) {
                    $parent_id = $categoria_term_id;
                } else {
                    $parent_id = $modo_hermanas ? $categoria_term_id : $ultimo_parent_id;
                }

                // Crear o recuperar término
                $term = get_term_by('slug', $slug, 'product_cat');
                if (!$term) {
                    $resultado_sub = wp_insert_term($nombre, 'product_cat', [
                        'slug'   => $slug,
                        'parent' => $parent_id,
                    ]);
                    if (is_wp_error($resultado_sub)) continue;
                    $term_id = $resultado_sub['term_id'];
                } else {
                    $term_id = $term->term_id;
                }

                $term_ids[] = $term_id;
                $ultimo_parent_id = $modo_hermanas ? $categoria_term_id : $term_id;
            }
        }

        // ============================================================
        // 🏷️ Asignar categorías al producto
        // ============================================================
        wp_set_object_terms($product_id, $term_ids, 'product_cat');
    }
}
