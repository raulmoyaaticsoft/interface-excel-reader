<?php
require_once __DIR__ . '/vendor/autoload.php';


require_once __DIR__ . '/includes/crear-productos.php';
require_once __DIR__ . '/includes/crear-categorias-productos.php';


use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

//require_once( ABSPATH . 'prueba-conexion.php' );

// Asegurarse de que WordPress esté cargado
defined('ABSPATH') || exit;
ini_set('max_execution_time',0);
set_time_limit(0);
ini_set('memory_limit', '2048M');

/**
 * Busca un producto existente por SKU, ignorando mayúsculas y espacios.
 * Si no lo encuentra con la función estándar de WooCommerce, hace una búsqueda directa en postmeta.
 */
$log_file = plugin_dir_path(__FILE__) . 'log_asociaciones.txt';


/**
 * Log especial de depuración para productos y atributos
 * Guarda los mensajes en /wp-content/uploads/logs_productos.txt
 */

function log_producto_atributo($mensaje) {

    static $log_limpiado = false;
    $log_file = __DIR__ .  '/logs_productos.txt';

    $fecha = date('Y-m-d H:i:s');

    // 🧹 Limpiar solo la primera vez que se llame en esta ejecución
    if (!$log_limpiado && file_exists($log_file)) {
        file_put_contents($log_file, ""); // Vaciar el log
        $log_limpiado = true;
        // Dejar constancia de la limpieza
        $mensaje_inicial = "[$fecha] 🧹 Log limpiado automáticamente al iniciar sincronización" . PHP_EOL;
        file_put_contents($log_file, $mensaje_inicial, FILE_APPEND);
    }

    $mensaje_final = "[$fecha] $mensaje" . PHP_EOL;
    file_put_contents($log_file, $mensaje_final, FILE_APPEND); 
}

if (!function_exists('find_existing_product_by_sku')) {
    function find_existing_product_by_sku($sku) {
        global $wpdb;

        $sku = trim($sku);

        // Intento 1: API WooCommerce
        $id = wc_get_product_id_by_sku($sku);
        if ($id) return (int) $id;

        // Intento 2: SQL directo
        $id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_sku'
            AND UPPER(TRIM(meta_value)) = UPPER(%s)
            LIMIT 1
        ", $sku));

        return $id ? (int) $id : false;
    }
}


/**
 * A partir de: parent slug (F) y subcat cruda (G),
 * devuelve [subcat_name_legible, subcat_slug_final].
 * - G suele venir como "parent_base" (p. ej. "aves_bebederos").
 * - Resultado: name "Bebederos", slug "bebederos-aves".
 */

/*
function build_subcat_from_excel(string $parent_slug_raw, string $subcat_raw): array {
    $parent_slug = sanitize_title(trim($parent_slug_raw));
    $raw = strtolower(trim($subcat_raw));

    // Si empieza por "parent_", quita ese prefijo.
    if (strpos($raw, $parent_slug . '_') === 0) {
        $raw = substr($raw, strlen($parent_slug) + 1);
    }

    // "incubadoras-y-nacedoras" -> name "Incubadoras y Nacedoras"
    $base_slug = sanitize_title($raw);
    $name = ucwords(str_replace('-', ' ', $base_slug));

    $final_slug = $base_slug . '-' . $parent_slug; // ej: "bebederos-aves"
    return [$name, $final_slug];
}
*/

/**
 * Crea o devuelve la jerarquía completa de categorías a partir de los datos del Excel.
 * 
 * Soporta:
 *  - Formato con guiones bajos:  "varios_instalacion-de-agua_conexiones"
 *  - Formato con barra:          "ovejas-y_cerdos_vallas/parideras"
 *
 * Columna F → categoría padre (ej: "varios")
 * Columna G → subcategoría jerárquica (ej: "varios_instalacion-de-agua_conexiones")
 *
 * Devuelve el ID de la categoría más profunda creada/asignada.
 */
function build_subcat_from_excel(string $parent_slug_raw, string $subcat_raw): ?array {
    global $created_categories;
    $parent_slug = sanitize_title(trim($parent_slug_raw));
    if (empty($parent_slug)) return [null, null];

    // 🟩 Asegurar categoría padre
    $parent_term = get_term_by('slug', $parent_slug, 'product_cat');
    if (!$parent_term) {
        $parent_name = ucwords(str_replace('-', ' ', $parent_slug));
        $res = wp_insert_term($parent_name, 'product_cat', ['slug' => $parent_slug]);
        if (is_wp_error($res)) return [null, null];
        $parent_term = get_term($res['term_id']);
        $created_categories[] = [
            'name'   => $parent_name,
            'slug'   => $parent_slug,
            'parent' => null,
            'id'     => $parent_term->term_id,
            'level'  => 1
        ];
        escribir_log_debug("🟢 Creada categoría padre '{$parent_name}' (slug={$parent_slug})");
    }
    $last_parent_id = (int) $parent_term->term_id;

    // 🟨 Subcategoría vacía → solo padre
    $raw = strtolower(trim($subcat_raw));
    if (empty($raw)) return [$parent_term->name, $parent_term->slug];

    // Normalizar formato: reemplazar / por _
    $raw = str_replace('/', '_', $raw);

    // Quitar prefijo duplicado del padre (ej: "aves_comederos" → "comederos")
    if (strpos($raw, $parent_slug . '_') === 0) {
        $raw = substr($raw, strlen($parent_slug) + 1);
    }

    // Separar posibles niveles: ej. "bebederos_automaticos"
    $parts = array_filter(explode('_', $raw), fn($v) => trim($v) !== '');
    $chain_names = [$parent_term->name];
    $level = 2;

    foreach ($parts as $slug_piece) {
        $slug_clean = sanitize_title($slug_piece);
        $name_clean = ucwords(str_replace('-', ' ', $slug_clean));

        // 👇 Slug final único con el slug del padre
        //$unique_slug = $slug_clean . '-' . $parent_slug;
        $unique_slug =  $parent_slug. '-' . $slug_clean;


        // Buscar si ya existe esa subcategoría exacta (por slug único)
        $existing = get_term_by('slug', $unique_slug, 'product_cat');

        if ($existing) {
            $last_parent_id = (int)$existing->term_id;
            escribir_log_debug("✔ Reutilizada subcategoría '{$name_clean}' (slug={$unique_slug})");
        } else {
            // Crear subcategoría con slug compuesto y padre correcto
            $res = wp_insert_term($name_clean, 'product_cat', [
                'slug'   => $unique_slug,
                'parent' => $last_parent_id
            ]);

            if (!is_wp_error($res)) {
                $last_parent_id = (int)$res['term_id'];
                $created_categories[] = [
                    'name'   => $name_clean,
                    'slug'   => $unique_slug,
                    'parent' => $chain_names[count($chain_names)-1] ?? null,
                    'id'     => $last_parent_id,
                    'level'  => $level
                ];
                escribir_log_debug("🧩 Creada subcategoría '{$name_clean}' (slug={$unique_slug}) bajo '{$chain_names[count($chain_names)-1]}'");
            } else {
                escribir_log_debug("⚠️ Error creando subcategoría '{$name_clean}' → " . $res->get_error_message());
            }
        }

        // Actualizamos contexto
        $parent_slug = $unique_slug; // para el siguiente nivel
        $chain_names[] = $name_clean;
        $level++;
    }

    // Log final de la jerarquía
    escribir_log_debug("📂 Jerarquía creada/reutilizada: " . implode(' → ', $chain_names));

    $term_final = get_term($last_parent_id);
    return [$term_final->name ?? '', $term_final->slug ?? ''];
}


/**
 * Crea (si no existe) toda la jerarquía de categorías de un producto.
 * Lee directamente desde la columna G (subcategoría completa).
 * 
 * Ejemplo:
 *   G = "varios_instalacion-de-agua_conexiones"
 *   → varios → varios-instalacion-de-agua → varios-instalacion-de-agua-conexiones
 *
 * Devuelve todos los niveles creados/reutilizados con id, slug y name.
 */
function build_full_category_hierarchy_es(string $subcat_raw): array {
    global $created_categories;
    $tax = 'product_cat';
    $out = [];

    $raw = strtolower(trim($subcat_raw));
    if (empty($raw)) return $out;

    // Normalizar formato
    $raw = str_replace('/', '_', $raw);
    $niveles = array_filter(explode('_', $raw), fn($v) => trim($v) !== '');
    if (empty($niveles)) return $out;

    $current_parent_id = 0;
    $current_parent_slug = '';

    foreach ($niveles as $i => $slug_piece) {
        $slug_clean = sanitize_title($slug_piece);
        $name_clean = ucwords(str_replace('-', ' ', $slug_clean));

        // Slug acumulado progresivamente
        $slug_final = $i === 0 ? $slug_clean : $current_parent_slug . '-' . $slug_clean;

        // Buscar categoría existente
        $term = get_term_by('slug', $slug_final, $tax);
        if ($term) {
            $current_parent_id = (int)$term->term_id;
            $current_parent_slug = $slug_final;
            escribir_log_debug("✔ Reutilizada categoría '{$term->name}' (slug={$slug_final})");
        } else {
            // Crear nueva categoría
            $res = wp_insert_term($name_clean, $tax, [
                'slug'   => $slug_final,
                'parent' => $current_parent_id
            ]);
            if (is_wp_error($res)) {
                escribir_log_debug("⚠️ Error creando categoría '{$name_clean}' → " . $res->get_error_message());
                continue;
            }
            $term_id = (int)$res['term_id'];
            $term = get_term($term_id, $tax);

            $current_parent_id = $term_id;
            $current_parent_slug = $slug_final;
            $created_categories[] = [
                'id'     => $term_id,
                'name'   => $term->name,
                'slug'   => $term->slug,
                'parent' => $term->parent,
            ];
            escribir_log_debug("🧩 Creada categoría '{$term->name}' (slug={$term->slug}) con padre={$term->parent}");
        }

        $out[] = [
            'id'   => (int)$term->term_id,
            'slug' => $term->slug,
            'name' => $term->name
        ];
    }

    //escribir_log_debug("📂 Jerarquía final ES: " . implode(' → ', array_column($out, 'name')));
    return $out;
}

function obtener_traducciones_categoria_hierarchy(array $categorias_es, string $langDestino = 'en'): array {
    $traducidas = [];

    foreach ($categorias_es as $cat) {
        $tr_id = apply_filters('wpml_object_id', $cat['id'], 'product_cat', false, $langDestino);

        if (!$tr_id) {
            // 🟠 Si no existe traducción, la creamos automáticamente
            $term_es = get_term($cat['id'], 'product_cat');
            if ($term_es && !is_wp_error($term_es)) {
                $res = wp_insert_term($term_es->name, 'product_cat', [
                    'slug' => $term_es->slug . '-' . $langDestino,
                    'parent' => 0, // luego lo corregimos si tiene padre
                ]);
                if (!is_wp_error($res)) {
                    $tr_id = $res['term_id'];

                    // Vincular con WPML
                    do_action('wpml_set_element_language_details', [
                        'element_id' => $tr_id,
                        'element_type' => 'tax_product_cat',
                        'trid' => apply_filters('wpml_element_trid', null, $cat['id'], 'tax_product_cat'),
                        'language_code' => $langDestino,
                        'source_language_code' => 'es',
                    ]);
                    escribir_log_debug("🧩 Creada traducción automática de '{$term_es->name}' → {$langDestino}");
                }
            }
        }

        if ($tr_id) {
            $traducidas[] = (int)$tr_id;
        }
    }
    return $traducidas;
}




function get_category_id_by_name_interface($category_name) {
    $category = get_term_by('name', $category_name, 'product_cat');
    if ($category) {
        return $category->term_id;
    }
    return 0; 
}

function get_category_id_by_slug_interface($category_name) {
    $category = get_term_by('slug', $category_name, 'product_cat');
    if ($category) {
        return $category->term_id;
    }
    return 0;
}

function get_subcategory_id_by_name_interface($subcategory_name) {
    $subcategory = get_term_by('name', $subcategory_name, 'product_cat');
    if ($subcategory) {
        return $subcategory->term_id;
    }
    return 0; 
}

function get_subcategory_id_by_slug_interface($subcategory_name) {
    $subcategory = get_term_by('slug', $subcategory_name, 'product_cat');
    if ($subcategory) {
        return $subcategory->term_id;
    }
    return 0; 
}

function get_product_id_by_sku_and_language_interface_old($sku, $lang) {
    
    $product_id = wc_get_product_id_by_sku($sku);
    if (!$product_id) {
        return null; 
    }
 // Ignora productos no publicados (papelera, borrador, etc.)
    $status = get_post_status($product_id);
    if ($status !== 'publish') { return null; }

    // Evita confundir variaciones con productos padre
    $post_type = get_post_type($product_id);
    if ($tipoEsperado === 'product' && $post_type !== 'product') { return null; }
    if ($tipoEsperado === 'product_variation' && $post_type !== 'product_variation') { return null; }
    if ($lang == 'es') {
        return $product_id;
    }

    $type = apply_filters( 'wpml_element_type', get_post_type( $product_id ) );

    $trid = apply_filters('wpml_element_trid', null, $product_id, $type);
    if (!$trid) {
        return null; // Si no se encuentra el trid, devolver null
    }

    $translations = apply_filters( 'wpml_get_element_translations', array(), $trid, $type );

    if ($translations[$lang]) {
        return $translations[$lang]->translation_id;
    } else {
        return null;
    }

}

/**
 * Devuelve el ID de un elemento con un SKU dado SOLO si:
 *  - Existe
 *  - Está publicado (post_status = 'publish')
 *  - Es del tipo esperado ('product' o 'product_variation')
 * Si $lang !== 'es', intenta devolver la TRADUCCIÓN publicada de ese elemento mediante WPML.
 */
function get_valid_id_by_sku_and_lang_org(string $sku, string $lang, string $tipoEsperado = 'product'): ?int {
    $id = wc_get_product_id_by_sku($sku);
    if (!$id) return null;

    // Estado publicado
    if (get_post_status($id) !== 'publish') return null;

    // Tipo correcto
    $pt = get_post_type($id);
    if ($tipoEsperado === 'product' && $pt !== 'product') return null;
    if ($tipoEsperado === 'product_variation' && $pt !== 'product_variation') return null;

    // ES = base
    if ($lang === 'es') return (int)$id;

    // WPML: traducción publicada
    $type = apply_filters('wpml_element_type', $pt);
    $trid = apply_filters('wpml_element_trid', null, $id, $type);
    if (!$trid) return null;

    $translations = apply_filters('wpml_get_element_translations', [], $trid, $type);
    if (!empty($translations[$lang])) {
        $tid = (int) ($translations[$lang]->translation_id ?? 0);
        return (get_post_status($tid) === 'publish') ? $tid : null;
    }
    return null;
}

function get_valid_id_by_sku_and_lang(string $sku, string $lang, string $tipoEsperado = 'product'): ?int {
    global $wpdb;

    // Normalizar SKU (mayúsculas y sin espacios)
    $sku_original = $sku;
    $sku = strtoupper(trim($sku));

    // 🔹 Log inicial
    //escribir_log_debug("🔍 [SKU CHECK] Buscando SKU={$sku} | Lang={$lang} | Tipo={$tipoEsperado}");

    // 1️⃣ Intento WooCommerce estándar
    $id = wc_get_product_id_by_sku($sku);
    if ($id) {
        //escribir_log_debug("🟢 Encontrado por wc_get_product_id_by_sku → ID={$id}");
    }

    // 2️⃣ Intento SQL directo si Woo no devuelve nada
    if (!$id) {
        $id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id 
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_sku'
              AND UPPER(TRIM(meta_value)) = %s
            LIMIT 1
        ", $sku));

        if ($id) {
            //escribir_log_debug("🟡 Encontrado por SQL directo en wp_postmeta → ID={$id}");
        }
    }

    if (!$id) {
        //escribir_log_debug("❌ No se encontró SKU={$sku_original} en WooCommerce ni en BD");
        return null;
    }

    $pt = get_post_type($id);
    $status = get_post_status($id);

    // ✅ Verificar tipo correcto
    if ($tipoEsperado === 'product' && $pt !== 'product') {
        //escribir_log_debug("⚠️ Tipo incorrecto: esperaba 'product', encontró '{$pt}' → se ignora");
        return null;
    }
    if ($tipoEsperado === 'product_variation' && $pt !== 'product_variation') {
        //escribir_log_debug("⚠️ Tipo incorrecto: esperaba 'product_variation', encontró '{$pt}' → se ignora");
        return null;
    }

    // ✅ Verificar estado
    if (!in_array($status, ['publish', 'draft', 'private'], true)) {
        //escribir_log_debug("⚠️ Estado no válido para {$sku} (status={$status}) → ignorado");
        return null;
    }

    // ✅ WPML: intentar obtener traducción del idioma solicitado
    $type = apply_filters('wpml_element_type', $pt);
    $trid = apply_filters('wpml_element_trid', null, $id, $type);

    if (!$trid) {
       // escribir_log_debug("ℹ️ Producto ID={$id} (SKU={$sku}) sin TRID WPML → devolvemos original");
        return (int)$id;
    }

    $translations = apply_filters('wpml_get_element_translations', [], $trid, $type);

    if (!empty($translations[$lang])) {
        $tid = (int) ($translations[$lang]->element_id ?? 0);
        $tstatus = get_post_status($tid);

        if (in_array($tstatus, ['publish', 'draft', 'private'], true)) {
            //escribir_log_debug("🌍 Traducción encontrada → Lang={$lang} | ID={$tid} | Estado={$tstatus}");
            return $tid;
        } else {
            //escribir_log_debug("⚠️ Traducción encontrada pero con estado no válido ({$tstatus})");
        }
    } else {
        //escribir_log_debug("🚫 Sin traducción WPML para {$sku} en idioma {$lang}");
    }

    // Si no hay traducción, devolvemos el ID base
    //escribir_log_debug("🔁 Devolviendo ID base {$id} para SKU={$sku}");
    return (int)$id;
}








function get_product_id_by_sku_and_language_interface($sku, $lang) {
    $product_id = wc_get_product_id_by_sku($sku);
    if (!$product_id) {
        return null; 
    }

    if ($lang == 'es') {
        return $product_id;
    }

    $type = apply_filters('wpml_element_type', get_post_type($product_id));
    $trid = apply_filters('wpml_element_trid', null, $product_id, $type);
    if (!$trid) {
        return null;
    }

    $translations = apply_filters('wpml_get_element_translations', array(), $trid, $type);

    if (isset($translations[$lang])) {
        return $translations[$lang]->translation_id;
    } else {
        return null;
    }
}

/*function wpml_set_element_language_details_interface($term_id, $term_id_trid, $type, $type_trid, $lang, $source_lang = null) {
    do_action('wpml_set_element_language_details_interface', array(
        'element_id' => $term_id,
        'element_type' => $type,
        'trid' => wpml_get_content_trid($type_trid, $term_id_trid),
        'language_code' => $lang,
        'source_language_code' => $source_lang
    ));
} */

function insert_new_term_interface($val, $tax_name, $lang = '') {
    return wp_insert_term(
        $val, // Nombre del nuevo atributo
        wc_attribute_taxonomy_name($tax_name), // Obtener el nombre completo de la taxonomía del atributo
        array(
            'slug' => ($lang ? sanitize_title($val).'-'.$lang : sanitize_title($val)),
        )
    );
}

// Normaliza todos los SKUs: sin espacios a los lados y en minúsculas
function normalize_sku(string $sku): string {
    return strtoupper(trim($sku));
}

// Función para leer un archivo Excel y convertirlo en un array
function read_excel_to_array_interface() {

    log_producto_atributo('', true);
    log_producto_atributo("🚀 INICIO DE SINCRONIZACIÓN");


    function attach_image_to_product_by_sku($sku, $image_url) {
        global $wpdb;

        // Obtener el ID del producto por SKU
        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return 'Producto no encontrado.';
        }

        // Obtener el nombre del archivo desde la URL
        $filename = basename($image_url);

        // Buscar la imagen en la biblioteca de medios por nombre de archivo
        $attachment_data = $wpdb->get_row( "SELECT * FROM $wpdb->posts WHERE post_title = '$filename' " );
        
        // Si la imagen ya existe, obtener su ID
        if ($attachment_data) {
            $attachment_id = $attachment_data->ID;
        } else {
            // Descargar la imagen desde la URL
            $image_data = file_get_contents($image_url);
            if (!$image_data) {
                return 'No se pudo descargar la imagen.';
            }

            // Subir la imagen al servidor
            $upload = wp_upload_bits($filename, null, $image_data);
            if ($upload['error']) {
                return 'Error al subir la imagen: ' . $upload['error'];
            }

            // Obtener el tipo de archivo de la imagen subida
            $wp_filetype = wp_check_filetype($filename, null);
            $attachment = array(
                'post_mime_type' => $wp_filetype['type'],
                'post_title' => sanitize_file_name($filename),
                'post_content' => '',
                'post_status' => 'inherit'
            );

            // Insertar la imagen en la biblioteca de medios
            $attachment_id = wp_insert_attachment($attachment, $upload['file']);
            if (!$attachment_id) {
                return 'Error al insertar la imagen en la biblioteca de medios.';
            }

            // Generar los metadatos de la imagen
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_data);
        }

        // Adjuntar la imagen al producto
        set_post_thumbnail($product_id, $attachment_id);

        //return 'Imagen adjuntada exitosamente.';
    }

    /*$local_dir = ABSPATH.'/pics/'; // cambaido el 12-09-2025
    $fileNames = scandir(ABSPATH.'/pics/');
    arsort($fileNames);*/

    $local_dir = '/home/copelepruebas/public_html/pics/';
    $fileNames = scandir($local_dir);

    if ($fileNames === false || !is_array($fileNames)) {
        //return new \WP_Error('dir_not_found', '🔴 No se pudo leer la carpeta de imágenes en: ' . $local_dir);
    }

    arsort($fileNames);
    global $wpdb;

    $sql = "SELECT DISTINCT p.ID, p.post_title, pm.meta_value AS sku
    FROM wp_posts p
    LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id 
    WHERE (p.post_type = 'product' OR p.post_type = 'product_variation')
    AND p.post_status = 'publish'
    AND pm.meta_key = '_sku';";

    $results = $wpdb->get_results($sql);
    $skus = array_column($results, 'sku');



    foreach($skus as $sku) {
        $parts = explode('-', $sku);
        //$file = reset(explode('-', $sku));
        $file = reset($parts);

        $res = array_filter($fileNames, function ($fileName) use ($file) { return strpos($fileName, $file) !== false; });

        if ($res) {
            
            foreach($res as $img) {
                //echo '<pre>'; print_r($img); echo '</pre>';
                attach_image_to_product_by_sku($sku, 'https://pruebas.copele.com/pics/'.$img);
            }
        }

    }

    // Obtener todos los archivos en el directorio local
    $files = glob($local_dir . '*'); 

    // Eliminar cada archivo
    foreach ($files as $file) {
        if (is_file($file)) {
            //unlink($file); // Eliminar archivo
        }
    }



    $langs = [
        'es',
        'en',
        'fr'
    ];

    //$file_path = __DIR__.'/files/prods.xlsx';
    $file_path = __DIR__.'/files/ultimo_excel.xlsx';

    $relative_path = get_option('interface_excel_reader_last_file_url');

    if ($relative_path) { 
        $file_path = ABSPATH . $relative_path;
    }


    try {
       


       //PONEMOS TODOS LOS PRODUCTOS EN BORRADOR ANTES DE EMPEZAR.. 

    
        //END PONEMOS TODOS LOS PRODUCTOS EN BORRADOR ANTES DE EMPEZAR.. 

    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();

    // Tell the reader to only read the data. Ignore formatting etc.
    $reader->setReadDataOnly(true);

    // Read the spreadsheet file.
    $spreadsheet = $reader->load($file_path);

    $sheet = $spreadsheet->getSheet($spreadsheet->getFirstSheetIndex());
    $data = $sheet->toArray();

    // output the data to the console, so you can see what there is.

        // Leer todo el Excel
        $data = $sheet->toArray();

        // 1️⃣ Saltamos la primera fila (basura inicial)
        unset($data[0]);

        // 2️⃣ Segunda fila = cabeceras reales
        $cabeceras = array_map('trim', $data[1]); 
        $data = array_slice($data, 2);

        $sortedArr = [];
        $data = array_values($data);


    // 3️⃣ Recorremos el resto de filas de datos
        foreach ($data as $key1 => $dat) {
             // ✅ Asegurar que la fila siempre sea un array
            if (!is_array($dat)) {
                if ($dat === false || $dat === null) {
                    //escribir_log_debug("⚠️ Fila $key1 inválida (false/null) → ignorada");
                    continue;
                }
                $dat = (array)$dat; // si es string/num, lo convertimos
            }

            // ✅ Limpiar nulos y vacíos
            $fila_limpia = array_filter($dat, function($v) {
                return $v !== null && $v !== '';
            });

            if (empty($fila_limpia)) {
                //escribir_log_debug("⚠️ Fila $key1 completamente vacía → ignorada");
                continue;
            }

            // 🔎 Log de referencia si existe
            if (!empty($dat[0])) {
                //escribir_log_debug("✔ Procesando fila $key1 con referencia=" . $dat[0]);
            }

            // Determinar si es padre o hijo
            // Determinar si es padre o hijo
            $col7 = $dat[7] ?? ''; // codigos_asociados
            $col8 = $dat[8] ?? ''; // flag padre (I)

            // Si la columna padre (I) es 1 → siempre parent
            if ($col8 === '1') {
            $type = 'parent';
            }
            // Si tiene codigos_asociados y la ref NO coincide con la primera parte → child
            elseif (!empty($col7) && $dat[0] != explode('-', $col7)[0]) {
            $type = 'child';
        }
            // En cualquier otro caso → parent
            else {
                $type = 'parent';
            }



            // Mapear cada columna con su cabecera
            foreach ($dat as $key2 => $value) {
                if (!isset($cabeceras[$key2]) || $cabeceras[$key2] === '') {
                    continue; // columna sin cabecera → ignorar
                }
            $sortedArr[$type][$key1][$cabeceras[$key2]] = $value;
        }

            // 🔎 Depuración: ver referencia en cada fila
            if (!empty($sortedArr[$type][$key1]['referencia'])) {
                //escribir_log_debug("✔ Fila $key1 → referencia=" . $sortedArr[$type][$key1]['referencia'] . " | type=$type");
            } else {
                //escribir_log_debug("⚠️ Fila $key1 sin referencia detectada");
            }

            // 4️⃣ Lógica de bodegón (solo padres)
           if (isset($dat[8]) && $dat[8] == '1' && (strpos($dat[0], '94') === 0 )) {
                $codigos = !empty($dat[7]) ? explode('-', $dat[7]) : [];
            $sku_base = $dat[0]; 
                $sku_base=trim($sku_base);
            foreach (['es', 'en', 'fr'] as $lang) {
                    $product_id = get_product_id_by_sku_and_language_interface($sku_base . '-' . strtoupper($lang), $lang);

                    if ($product_id) {
                        update_post_meta($product_id, 'bodegon', $codigos);
                        //escribir_log_debug("🟢 Bodegón actualizado en SKU {$sku_base}-".strtoupper($lang)." (ID={$product_id})");
                } else {
                        //escribir_log_debug("⚠️ No se encontró producto para SKU {$sku_base}-".strtoupper($lang)." al intentar actualizar bodegón");
                }
            }
        }
    }


    $filters_num = [
        'Filtros Subcategoría' => 5,
        'Combinaciones Producto' => 8,
        'Características técnicas y logísticas' => 21,
        'Puntos descripción larga' => 16
    ];


    $attr_arr = [];
    $caracteristicas_arr = [];

      // ============================================================
// 🧭 TRAZA DE $sortedArr (estructura previa a la creación)
// ============================================================
if (!empty($sortedArr)) {

    $resumen_log = "\n===========================\n🧭 RESUMEN DE \$sortedArr\n===========================\n";

    foreach ($sortedArr as $key1 => $grupo) { // parent / child
        $count = is_array($grupo) ? count($grupo) : 0;
        $resumen_log .= strtoupper($key1) . " → {$count} productos\n";

        foreach ($grupo as $ref => $info) {
            $idioma = $info['lang'] ?? '??';
            $nombre = $info['descripcion_es'] ?? $info['descripcion_en'] ?? $info['descripcion_fr'] ?? '(sin nombre)';
            $resumen_log .= "   ▪ ref={$ref} | lang={$idioma} | nombre={$nombre}\n";

            // Mostrar atributos si existen
            if (!empty($attr_arr[$key1][$idioma][$ref])) {
                foreach ($attr_arr[$key1][$idioma][$ref] as $tax => $vals) {
                    $valores = is_array($vals) ? implode(', ', $vals) : (string)$vals;
                    $resumen_log .= "       🔸 {$tax} → {$valores}\n";
                }
            }
        }
        $resumen_log .= "---------------------------\n";
    }

    $resumen_log .= "===========================\n";
    escribir_log_debug($resumen_log);
}



    
    foreach ($sortedArr as $key0 => $baseArr) {
        if (!is_array($baseArr)) {
            return new \WP_Error('fatal_baseArr_not_array', '🔴 $baseArr no es un array. Valor: ' . print_r($baseArr, true));
        }

        if (empty($baseArr)) {
            return new \WP_Error('fatal_baseArr_empty', '🟡 $baseArr está vacío. key0=' . $key0);
        }


        log_producto_atributo('➡️ Entrando en bloque $baseArr y key0 tiene el valor de => ' . $key0 );

        foreach ($baseArr as $i => $arr) {

                // Saltar filas vacías o sin referencia válida del Excel
                if (!isset($arr['referencia']) || !is_scalar($arr['referencia']) || trim($arr['referencia']) === '') {
                    continue;
                }

            
            $iField = 1;
            $baseIndex = [];
            
            foreach($arr as $key => $val) {
           
                    if (isset($cabeceras[$iField])) {
                        $cab = $cabeceras[$iField];

                        if (isset($filters_num[$cab])) {
                            // Coincide con un bloque declarado (ej: “Características técnicas y logísticas”)
                            $baseIndex = [
                                'index' => $iField - 1,
                                'num'   => $filters_num[$cab],
                                'val'   => $cab,
                            ];
                        } elseif (strpos($cab, 'filtro_') === 0) {
                            // 🔹 Bloques tipo “Filtros Subcategoría”
                            $baseIndex = [
                                'index' => $iField - 1,
                                'num'   => 5,
                                'val'   => 'Filtros Subcategoría',
                            ];
                        } elseif (in_array($cab, ['color','capacidad','otro_capacidad','patas','modelo','otro_modelo','tipo','material'])) {
                            // 🔹 Bloques tipo “Combinaciones Producto”
                            $baseIndex = [
                                'index' => $iField - 1,
                                'num'   => 8,
                                'val'   => 'Combinaciones Producto',
                            ];
                        }
                    }

                if ($baseIndex) {

                        // 1️⃣ Campos que jamás deben tratarse como atributos
                    $columnas_excluidas = [
                        'descripcion_larga_es', 'descripcion_larga_en', 'descripcion_larga_fr',
                        'descripcion_corta_es', 'descripcion_corta_en', 'descripcion_corta_fr',
                        'nombre_es', 'nombre_en', 'nombre_fr',
                        'sku', 'referencia', 'precio', 'ean', 'imagen'
                    ];

                    // 2️⃣ Atributos válidos para el producto PADRE
                    $campos_atributos_padre = [
                        'filtro_tipo', 'filtro_capacidad', 'filtro_color', 'filtro_modelo', 'filtro_material'
                    ];

                    // 3️⃣ Atributos válidos para VARIACIONES (columnas T–AA)
                    $campos_atributos_hijo = [
                        'color', 'capacidad', 'otra_capacidad', 'patas',
                        'modelo', 'otro_modelo', 'tipo', 'material'
                    ];
                    if ($iField > $baseIndex['index'] && $iField < ($baseIndex['index'] + $baseIndex['num'])) {

                        // ❌ Saltar columnas excluidas
                        if (in_array($key, $columnas_excluidas, true)) {
                            $iField++;
                            continue;
                        }

                        // ✅ Detectar si este campo es atributo permitido
                        $esCampoAtributo = (
                            ($key0 === 'parent' && in_array($key, $campos_atributos_padre, true)) ||
                            ($key0 === 'child'  && in_array($key, $campos_atributos_hijo, true))
                        );

                        // Solo procesar los campos válidos
                        if ($esCampoAtributo) {

    // 🧹 Normalizar el valor y separar múltiples opciones

                             //escribir_log_debug("🧩 VALOR DE ATUBUTO → {$val}");
            if (is_array($val)) {
                $val = implode(',', $val); // Convierte array en string si viene del Excel como array
            }

            $val = trim(preg_replace('/\s+/', ' ', (string)$val));

    // Detectar si hay varios valores (separados por coma, punto y coma o barra)
    $valores_split = preg_split('/[,;\/]+/', $val, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($valores_split)) continue;

    // Crear nombre de taxonomía
    $tax_name = 'pa_' . sanitize_title($key);

    // Crear o registrar atributo global si no existe (solo una vez)
    $attribute_id = wc_attribute_taxonomy_id_by_name($tax_name);
    if (!$attribute_id) {
        wc_create_attribute([
            'slug'         => $tax_name,
            'name'         => ucfirst(str_replace('_', ' ', $key)),
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ]);
        register_taxonomy($tax_name, 'product', ['hierarchical' => false]);
    }

    // Recorrer y procesar cada valor de atributo detectado
    foreach ($valores_split as $valor_unitario) {

        $valor_unitario = trim($valor_unitario);
        if ($valor_unitario === '') continue;

        $valor_unitario = sanitize_text_field($valor_unitario);

        // Añadir término si no existe (solo nombre, sin objetos)
        $term = term_exists($valor_unitario, $tax_name);
        if (!$term) {
            wp_insert_term($valor_unitario, $tax_name);
        }

        // 🧩 Guardar el valor como texto limpio (sin objetos WC_Product_Attribute)
        if ($key0 === 'parent') {
            if (!isset($attr_arr['parent'][$arr['referencia']])) {
                $attr_arr['parent'][$arr['referencia']] = [];
            }
            if (!isset($attr_arr['parent'][$arr['referencia']][$tax_name])) {
                $attr_arr['parent'][$arr['referencia']][$tax_name] = [];
            }
            $attr_arr['parent'][$arr['referencia']][$tax_name][] = $valor_unitario;

        } else {
            if (!isset($attr_arr['child'][$lang][$arr['referencia']])) {
                $attr_arr['child'][$lang][$arr['referencia']] = [];
            }
            if (!isset($attr_arr['child'][$lang][$arr['referencia']][$tax_name])) {
                $attr_arr['child'][$lang][$arr['referencia']][$tax_name] = [];
            }
            $attr_arr['child'][$lang][$arr['referencia']][$tax_name][] = $valor_unitario;
        }

        // 🪶 Registrar en log para seguimiento
        $pid = ($product && $product instanceof WC_Product) ? $product->get_id() : 'NULL';

        if (trim($valor_unitario) === '') continue;
            escribir_log_debug("🧩 Atributo detectado → {$tax_name}={$valor_unitario} ({$key0}) ref={$arr['referencia']} lang={$lang} | product_id={$pid}");

    }
}

                    }

                }
                
                $iField++;
            }
            
        }
        if ($key0 === 'child') {
    error_log('🔍 key0 = ' . $key0);
    error_log('🔍 baseArr (productos hijos): ' . print_r($baseArr, true));
}

            // 🧩 Copiar estructura de atributos del idioma ES a los demás idiomas si no existen
                // Estructura por idioma → no clonar con sufijo, trabajamos con $attr_arr['parent'][$lang][$ref]


           
    }




    $created_prods  = [];  // productos CREADOS en esta ejecución
    $existing_prods = [];  // productos ya existentes (publicados)
    $created_vars   = [];  // variaciones CREADAS en esta ejecución
    $existing_vars  = [];  // variaciones ya existentes (publicadas)

    
    krsort($sortedArr);
    $addedprods = [];
    $addedvars = [];
    $logs=[];

    $ii = 0;
    $hecho = false;


    // =========================================
// 🔍 LOG: Mostrar el contenido final de $sortedArr
// =========================================

// Ruta del log de asociaciones (ajústala si tu plugin la define diferente)
// ============================================================
// 🧭 TRAZA EXTENDIDA DE $sortedArr CON AGRUPACIÓN POR IDIOMA
// ============================================================
if (!empty($sortedArr)) {
    $resumen_log = "\n===========================\n🧭 RESUMEN EXTENDIDO DE \$sortedArr\n===========================\n";

    foreach ($sortedArr as $key1 => $grupo) { // parent / child
        $count = is_array($grupo) ? count($grupo) : 0;
        $resumen_log .= strtoupper($key1) . " → {$count} productos\n";

        foreach ($grupo as $ref => $info) {
            // Detectar idioma si está en la estructura
            $idioma = $info['lang'] ?? 'es';
            $resumen_log .= "   ▪ ref={$ref} | lang={$idioma}\n";

            // Listar TODAS las columnas clave del Excel
            foreach ($info as $campo => $valor) {
                if (is_array($valor)) {
                    $valor = implode(', ', $valor);
                }
                $valor = trim((string)$valor);
                if ($valor !== '') {
                    $resumen_log .= "       {$campo}: {$valor}\n";
                }
            }

            // Mostrar atributos detectados
            if (!empty($attr_arr[$key1][$idioma][$ref])) {
                $resumen_log .= "       🧩 ATRIBUTOS:\n";
                foreach ($attr_arr[$key1][$idioma][$ref] as $tax => $vals) {
                    $valores = is_array($vals) ? implode(', ', $vals) : (string)$vals;
                    $resumen_log .= "           {$tax} → {$valores}\n";
                }
            }
            $resumen_log .= "---------------------------\n";
        }
    }

    $resumen_log .= "===========================\n";
    escribir_log_debug($resumen_log);
}


 return [
            'type'                   => 'success',
            'message'                => 'Sincronización finalizada correctamente.',
        ];


    foreach ($sortedArr as $key1 => $baseArr) {
        $key0=$key1;
        //escribir_log_debug("🔍 Iniciando bucle nivel 1 → key1={$key1}");
        foreach($baseArr as $i => $arr) {
        //escribir_log_debug("➡️ Iteración: referencia={$arr['referencia']} | key0={$key0} | key1={$key1}");

            if (empty(trim($arr['referencia']))) {
                // Saltamos esta fila porque no tiene referencia
                continue;
            }

            foreach ($langs as $lang) {
                
                $iField = 0;
                $baseIndex = [];
                // Comprobar si ya existe el producto en el idioma correspondiente
                // Comprobar si ya existe (publicado y del tipo correcto)
                $sku_base       = trim($arr['referencia']);
                $sku_final_lang = normalize_sku($sku_base . '-' . strtoupper($lang));
                $productExists  = get_valid_id_by_sku_and_lang($sku_final_lang, $lang, 'product');

                //$productExists = find_existing_product_by_sku($sku_final_lang);



                if ($productExists) {
                     $medidas_producto=$arr['largo_producto'].' x'.$arr['ancho_producto'].'x'.$arr['alto_producto'];
                     $medidas_caja=$arr['largo_caja'].' x'.$arr['ancho_caja'].'x'.$arr['alto_caja'];

                     update_post_meta($productExists,'_titulo_variacion'.$productExists,$arr['descripcion_'.$lang]);


                     //ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 
                     update_post_meta($productExists,'medida_unitaria',$medidas_producto);
                     update_post_meta($productExists,'peso_unitario',$arr['peso_producto']);
                     update_post_meta($productExists,'capacidad',$arr['peso_producto']);
                     update_post_meta($productExists,'material',$arr['peso_producto']);
                     update_post_meta($productExists,'color',$arr['peso_producto']);
                     update_post_meta($productExists,'medidas_caja',$medidas_caja);
                     update_post_meta($productExists,'peso_caja',$arr['peso_producto']);
                     update_post_meta($productExists,'peso_unitario',$arr['peso_caja']);
                     update_post_meta($productExists,'unidades_caja',$arr['und_caja']);
                     update_post_meta($productExists,'unidad_compra_minima',$arr['cantidad_minima']);
                     

                     update_post_meta($productExists,'niveles_palet_eur',$arr['niveles_palet_eur']);

                     update_post_meta($productExists,'niveles_palet_usa',$arr['niveles_palet_usa']);


                    update_post_meta($productExists,'niveles_cajas_eur',$arr['cajas_nivel_eur']);
                    update_post_meta($productExists,'niveles_cajas_usa',$arr['cajas_nivel_usa']);



                     update_post_meta($productExists,'altura_palet_eur',$arr['altura_palet_eur']);
                     update_post_meta($productExists,'altura_palet_usa',$arr['altura_palet_usa']);
                  

                     update_post_meta($productExists,'cajas_palet_eur',$arr['altura_palet_eur']);
                     update_post_meta($productExists,'cajas_palet_usa',$arr['cajas_palet_usa']);
                     

                       //END ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 

                    // 🧩 Verificar idioma real en WPML y corregir si no coincide
                    $current_lang = apply_filters('wpml_element_language_code', null, [
                        'element_id'   => $productExists,
                        'element_type' => 'post_product'
                    ]);

                    $product = wc_get_product($productExists);
                    // 🔹 Actualizar nombre y descripción si cambian
                    if (!empty($arr['descripcion_'.$lang])) {
                        $product->set_name($arr['descripcion_'.$lang]);
                    }
                    if (!empty($arr['descripcion_larga_'.$lang])) {
                        $product->set_description($arr['descripcion_larga_'.$lang]);
                    }
                    $product->set_stock_status('instock');
                    $product->save();
                    //escribir_log_debug("📝 Producto existente {$arr['referencia']} ({$lang}) → nombre/descr. actualizados");


                    if ($current_lang !== $lang) {
                        escribir_log_debug("⚠️ SKU {$sku_final_lang} (ID={$productExists}) está asignado a idioma WPML='{$current_lang}', se esperaba '{$lang}'. Corrigiendo...");

                        do_action('wpml_set_element_language_details', [
                            'element_id'           => $productExists,
                            'element_type'         => 'post_product',
                            'language_code'        => $lang,
                            'source_language_code' => 'es',
                        ]);

                        escribir_log_debug("✅ Idioma WPML actualizado correctamente → {$sku_final_lang} ahora es '{$lang}'");
                    }

                    if (!isset($existing_prods[$lang])) $existing_prods[$lang] = [];
                    $existing_prods[$lang][$sku_base] = (int)$productExists;

                    // 👉 Actualizar o asignar categorías desde Excel
                    $product = wc_get_product($productExists);
                    if ($product && !empty($arr['subcategoria'])) {
                        $categorias_es = build_full_category_hierarchy_es($arr['subcategoria']);
                        $cats_ids_es = array_column($categorias_es, 'id');

                        if ($lang === 'es' && !empty($cats_ids_es)) {
                            $product->set_category_ids($cats_ids_es);
                            $product->save();
                            //(escribir_log_debug("📦 Producto existente {$arr['referencia']} (ES) → categorías: " . implode(',', $cats_ids_es));
                        } elseif (in_array($lang, ['en', 'fr'])) {
                            $cats_ids_trad = obtener_traducciones_categoria_hierarchy($categorias_es, $lang);
                            if (!empty($cats_ids_trad)) {
                                $product->set_category_ids($cats_ids_trad);
                                $product->save();
                                //(escribir_log_debug("🌐 Producto existente {$arr['referencia']} ({$lang}) → categorías traducidas: " . implode(',', $cats_ids_trad));
                            } else {
                                //(escribir_log_debug("⚠️ Producto existente {$arr['referencia']} ({$lang}) sin traducciones de categorías");
                            }
                        }
                    }

                    if (!empty($attr_arr['parent'][$arr['referencia']])) {

    // 🔎 Verificar que el producto actual existe antes de seguir
    if (!$product || !$product->get_id()) {
        escribir_log_debug("⚠️ Producto inexistente o sin ID en idioma {$lang} para ref={$arr['referencia']} → se omite asignación de atributos");
        continue;
    }

    // 🔄 Convertir a variable si no lo es
    if (!($product instanceof WC_Product_Variable)) {
        $product = new WC_Product_Variable($product->get_id());
        escribir_log_debug("🔄 Producto {$arr['referencia']} convertido a VARIABLE (ID={$product->get_id()})");
    }

    // --- Aplicar atributos si existen ---
    if (!empty($attr_arr['parent'][$arr['referencia']]) && is_array($attr_arr['parent'][$arr['referencia']])) {

        $atts_clean = [];

        foreach ($attr_arr['parent'][$arr['referencia']] as $tax_name => $vals) {

            // 🧩 Normalizar los valores para evitar arrays anidados o vacíos
            $vals = array_map(function($v) {
                if (is_array($v)) {
                    // Aplanar arrays anidados
                    $v = implode(', ', array_filter(array_map('trim', $v)));
                }
                return trim((string)$v);
            }, (array)$vals);

            // Eliminar vacíos y duplicados
            $vals = array_values(array_unique(array_filter($vals, fn($v) => $v !== '')));

            if (empty($vals)) continue;

            // Crear el objeto de atributo WooCommerce
            $attribute = new WC_Product_Attribute();
            $attribute->set_name($tax_name);
            $attribute->set_options($vals);
            $attribute->set_visible(true);
            $attribute->set_variation(false);

            $atts_clean[$tax_name] = $attribute;
        }

        if (!empty($atts_clean)) {

            // ✅ Asignar y guardar los atributos limpios
            $product->set_attributes($atts_clean);
            $product->save();

            // 🧾 Log detallado
            $detalles = [];
            foreach ($atts_clean as $tax => $attr_obj) {
                if ($attr_obj instanceof WC_Product_Attribute) {
                    $detalles[] = "{$tax}=[" . implode(', ', $attr_obj->get_options()) . "]";
                }
            }

            $detalle_log = !empty($detalles) ? implode('; ', $detalles) : 'sin valores';
            $pid = $product->get_id();
            escribir_log_debug("✅ Atributos aplicados correctamente al producto ref={$arr['referencia']} ({$lang}) [ID={$pid}] → {$detalle_log}");

        } else {
            escribir_log_debug("⚠️ No se encontraron atributos válidos para ref={$arr['referencia']} ({$lang})");
        }

    } else {
        escribir_log_debug("ℹ️ Sin atributos aplicables para ref={$arr['referencia']} ({$lang})");
    }
}


                  









                    if ($product instanceof WC_Product_Variable) {
                        $children = $product->get_children();

                        foreach ($children as $child_id) {
                            $variation = wc_get_product($child_id);
                            if (!$variation) continue;

                            $ref_hijo = get_post_meta($child_id, '_sku', true);
                            $ref_hijo = str_replace('-' . strtoupper($lang), '', $ref_hijo);

                            if (isset($attr_arr['child'][$lang][$ref_hijo])) {
                                $atts_clean = [];
                                foreach ($attr_arr['child'][$lang][$ref_hijo] as $attr) {
                                    if ($attr instanceof WC_Product_Attribute) {
                                        $atts_clean[$attr->get_name()] = $attr;
                                    }
                                }
                                $variation->set_attributes($atts_clean);
                                $variation->save();
                            }


                        }

                        // Crear variaciones faltantes a partir de codigos_asociados
                        $faltantes = [];
                        if (!empty($arr['codigos_asociados'])) {
                            $refs = array_filter(array_map('trim', explode('-', $arr['codigos_asociados'])));
                            foreach ($refs as $refh) {
                                if ($refh === $arr['referencia']) continue; // saltar padre
                                $sku_esperado = normalize_sku($refh . '-' . strtoupper($lang));
                                if (!wc_get_product_id_by_sku($sku_esperado)) {
                                    $faltantes[] = $refh;
                                }
                            }
                        }

                        foreach ($faltantes as $refh) {
                            $sku_var = normalize_sku($refh . '-' . strtoupper($lang));
                            if (wc_get_product_id_by_sku($sku_var)) continue;

                            $new_var = new WC_Product_Variation();
                            $new_var->set_parent_id($productExists);
                            $new_var->set_sku($sku_var);
                            if (!empty($arr['descripcion_'.$lang])) { $new_var->set_name($arr['descripcion_'.$lang]); }
                            if (!empty($arr['descripcion_larga_'.$lang])) { $new_var->set_description($arr['descripcion_larga_'.$lang]); }

                            // Atributos de la variación si existen
                            if (!empty($attr_arr['child'][$lang][$refh])) {
                                $new_var->set_attributes($attr_arr['child'][$lang][$refh]);
                            } elseif (!empty($attr_arr['child']['es'][$refh])) {
                                $new_var->set_attributes($attr_arr['child']['es'][$refh]);
                            }

                            // Precio placeholder por idioma
                            $precio = ($lang==='es') ? 100 : (($lang==='en') ? 200 : 300);
                            $new_var->set_regular_price($precio);

                            $id_new = $new_var->save();
                            if ($id_new) {
                                escribir_log_debug("🆕 Variación creada en existente → ref={$refh} ({$lang}) | ID={$id_new}");
                            } else {
                                escribir_log_debug("⚠️ No se pudo crear variación (existente) ref={$refh} ({$lang})");
                            }
                        }
                    }


                    // seguimos sin crear producto nuevo
                    continue;
                }





                if (!$productExists) {

                    // Determinar tipo de producto
                        if (strpos($arr['referencia'], '94') === 0 && $key0 === 'parent') {
                            // ✅ Caso especial: referencias 94xxx → BODEGÓN simple
                            $new_product = new WC_Product_Simple();
                            //escribir_log_debug("🔍 Creación de producto BODEGÓN ref={$arr['referencia']} lang=$lang");

                            if (!empty($arr['codigos_asociados'])) {
                                $codigos = explode('-', $arr['codigos_asociados']);
                                // lo guardamos después del save(), pero dejamos log aquí
                                //escribir_log_debug("➕ Asociados listos para guardar en bodegon meta de {$arr['referencia']}");
                            }
                        }
                            elseif (!empty($arr['codigos_asociados']) && $key0 === 'parent') {
                            if (!empty($attr_arr['parent'][$arr['referencia']])) {
                                $new_product = new WC_Product_Variable();
                            } else {
                                $new_product = new WC_Product_Simple();
                            }
                        }

                        elseif (empty($arr['codigos_asociados']) && $key0 === 'parent') {
                            // Padres sin hijos: siempre simple
                            $new_product = new WC_Product_Simple();
                            //escribir_log_debug("🔍 Creación de producto SIMPLE ref={$arr['referencia']} lang=$lang");
                        }
                        elseif (!empty($arr['codigos_asociados']) && $key0 === 'child') {
                        $new_product = new WC_Product_Variation();
                            //escribir_log_debug("🔍 Creación de producto VARIACIÓN ref={$arr['referencia']} lang=$lang");
                        }
                        else {
                            throw new \Exception("No se pudo determinar el tipo de producto para ref={$arr['referencia']} LANG=$lang");
                        }



                    // Preparar el SKU
                    $sku_final = trim($arr['referencia']);
                    if (empty($sku_final)) {
                        throw new \Exception("SKU vacío o no válido en producto LANG: $lang");
                    }

                    //$sku_final_lang = $sku_final . '-' . strtoupper($lang); // CAmbiado el 12-09-2025

                    $sku_final_lang = normalize_sku($sku_final . '-' . $lang);


                    // Verificar si el SKU ya existe (control de duplicado)
                    //$existing_id = get_valid_id_by_sku_and_lang($sku_final_lang, $lang, 'product');fcontn
                    /*if ($existing_id) {
                        escribir_log_debug("⚠️ SKU $sku_final_lang ya existe (ID=$existing_id) → no se crea de nuevo");

                        // reporting (existente) + soporte a variaciones
                        if (!isset($existing_prods[$lang])) $existing_prods[$lang] = [];
                        $existing_prods[$lang][$sku_base] = (int)$existing_id;

                        if (!isset($addedprods[$lang])) $addedprods[$lang] = [];
                        $addedprods[$lang][$sku_base] = (int)$existing_id;

                        continue;
                    } */


                    // PRECHECK global (cualquier estado/tipo). Si existe, no intentes crear: trátalo como existente.
                    $dup_any = wc_get_product_id_by_sku($sku_final_lang);

                    if ($dup_any && (int)$dup_any !== (int)$new_product->get_id()) {
                        $st = get_post_status($dup_any);
                        $tp = get_post_type($dup_any);

                        // Caso 1: SKU existente en papelera → recrear
                        if ($st === 'trash') {
                            //escribir_log_debug("♻️ PRECHECK: SKU {$sku_final_lang} ya en ID={$dup_any} (papelera) → lo elimino y recreo como PRODUCTO");
                            delete_post_meta($dup_any, '_sku');
                            wp_delete_post($dup_any, true);

                            // Extra: limpieza global de ese SKU en postmeta
                            global $wpdb;
                            $wpdb->query(
                                $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s", $sku_final_lang)
                            );

                            wc_delete_product_transients($dup_any);
                            wp_cache_flush();
                        }

                        // Caso 2: SKU usado por variación pero debería ser PRODUCTO padre → recrear
                        elseif ($tp === 'product_variation' && $key0 === 'parent') {
                            //escribir_log_debug("↔️ PRECHECK: SKU {$sku_final_lang} ocupado por VARIACIÓN (ID={$dup_any}) → lo elimino y recreo como PRODUCTO padre simple");
                            delete_post_meta($dup_any, '_sku');
                            wp_delete_post($dup_any, true);

                            global $wpdb;
                            $wpdb->query(
                                $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s", $sku_final_lang)
                            );

                            wc_delete_product_transients($dup_any);
                            wp_cache_flush();
                        }

                        // Caso 3: SKU usado por PRODUCT no publicado → recrear
                        elseif ($tp === 'product' && $st !== 'publish') {
                            //escribir_log_debug("📝 PRECHECK: SKU {$sku_final_lang} ya en ID={$dup_any} con estado {$st} → lo elimino y recreo como PRODUCTO publicado");
                            delete_post_meta($dup_any, '_sku');
                            wp_delete_post($dup_any, true);

                            global $wpdb;
                            $wpdb->query(
                                $wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s", $sku_final_lang)
                            );

                            wc_delete_product_transients($dup_any);
                            wp_cache_flush();
                        }

                        // Caso 4: SKU usado por PRODUCT publicado → mantener, no recrear
                        else {
                            $tipo_post = get_post_type($dup_any);
                            escribir_log_debug("⛔ PRECHECK duro: SKU {$sku_final_lang} ya en PRODUCT publicado (ID={$dup_any}) → no se crea y es de tipo {$tipo_post}");
                            if (!isset($existing_prods[$lang])) $existing_prods[$lang] = [];
                            $existing_prods[$lang][$sku_base] = (int)$dup_any;
                            if (!isset($addedprods[$lang])) $addedprods[$lang] = [];
                            $addedprods[$lang][$sku_base] = (int)$dup_any;
                            continue; // bloqueamos
                        }

                        // 🔎 Verificación tras la limpieza
                        if (wc_get_product_id_by_sku($sku_final_lang)) {
                            escribir_log_debug("❌ Tras limpieza el SKU {$sku_final_lang} sigue ocupado en la BD");
                        } else {
                            escribir_log_debug("✅ Tras limpieza el SKU {$sku_final_lang} ya está libre, procedo a asignarlo");
                        }
                    }





                    // Si el producto es válido, le asignamos el SKU
                    if ($new_product && is_object($new_product)) {
                       try {
                        $new_product->set_sku($sku_final_lang);
                        } catch (\Throwable $e) {
                            escribir_log_debug("💥 SKU ERROR (product) ref={$arr['referencia']} lang={$lang} SKU={$sku_final_lang} → ".$e->getMessage());
                            throw $e; // lo recoge tu catch global y lo ves en pantalla/runner
                        }
                    } else {
                        throw new \Exception("Error: No se pudo inicializar el objeto WC_Product para referencia: {$arr['referencia']} LANG: $lang");
                    }
                }

                    
                if ($key0 == 'parent') {

                    $name = $arr['filtros_descripcion_'.$lang] ?? '';
                    if (empty($name)) $name = $arr['descripcion_'.$lang] ?? '';
                    if (empty($name)) $name = "Producto " . $arr['referencia']; // fallback
                    $new_product->set_name($name);
                        
                    $new_product->set_description($arr['descripcion_larga_'.$lang]);
                        
                    // 🏷️ Crear y asignar categorías
                    if (!empty($arr['subcategoria'])) {
                        $categorias_es = build_full_category_hierarchy_es($arr['subcategoria']);
                        $cats_ids_es = array_column($categorias_es, 'id');

                        if ($lang === 'es' && !empty($cats_ids_es)) {
                            $new_product->set_category_ids($cats_ids_es);
                            escribir_log_debug("📦 Nuevo producto {$arr['referencia']} (ES) → categorías: " . implode(',', $cats_ids_es));
                        } elseif (in_array($lang, ['en', 'fr'])) {
                            $cats_ids_trad = obtener_traducciones_categoria_hierarchy($categorias_es, $lang);
                            if (!empty($cats_ids_trad)) {
                                $new_product->set_category_ids($cats_ids_trad);
                                escribir_log_debug("🌐 Nuevo producto {$arr['referencia']} ({$lang}) → categorías traducidas: " . implode(',', $cats_ids_trad));
                            } else {
                                escribir_log_debug("⚠️ Nuevo producto {$arr['referencia']} ({$lang}) sin traducciones de categorías");
                            }
                        }
                    }






                } elseif ($key0 == 'child') {
                        
                       if (!isset($addedprods[$lang]) || !is_array($addedprods[$lang]) || !in_array(explode('-', $arr['codigos_asociados'])[0], array_keys((array)$addedprods[$lang]))) continue;

                        
                        $new_product->set_name($arr['descripcion_'.$lang]);


                        $new_product->set_description($arr['descripcion_larga_'.$lang]);
                        
                        $new_product->set_parent_id( $addedprods[$lang][explode('-', $arr['codigos_asociados'])[0]] );
                        
                        if($lang=="es"){
                            $precio=100;
                        }elseif($lang=="en"){
                            $precio=200;
                        }else{
                            $precio=300;
                        }

                        $new_product->set_regular_price( $precio );
                        
                    }

                       if ($key0 === 'parent') {
                            $atts = $attr_arr['parent'][$arr['referencia']] ?? null;
                            if ($atts) {
                                $new_product->set_attributes($atts);
                            }
                        } else {
                            $atts = $attr_arr['child'][$lang][$arr['referencia']] 
                                 ?? $attr_arr['child']['es'][$arr['referencia']] 
                                 ?? null;
                            if ($atts) {
                                $new_product->set_attributes($atts);
                                escribir_log_debug("⚙️ Atributos asignados a producto hijo ref={$arr['referencia']} ({$lang})");
                            }
                        }

                    
                    try {
                         // 🧱 BLINDAJE antes del save()

                        // 1️⃣ Nombre
                        $name = $arr['filtros_descripcion_'.$lang] ?? '';
                        if (empty(trim($name))) $name = $arr['descripcion_'.$lang] ?? '';
                        if (empty(trim($name))) $name = "Producto " . $arr['referencia'];
                        $new_product->set_name($name);

                        // 2️⃣ SKU
                        if (!$new_product->get_sku()) {
                            $new_product->set_sku(normalize_sku($arr['referencia'] . '-' . strtoupper($lang)));
                        }

                        // 3️⃣ Slug
                        $slug = sanitize_title(($arr['descripcion_'.$lang] ?? $name) . '-' . $lang);
                        $new_product->set_slug($slug);

                        // 4️⃣ Estado y tipo
                        if (empty($new_product->get_status())) {
                            $new_product->set_status('publish');
                        }

                       /* if (empty($new_product->get_type())) {
                            if ($new_product instanceof WC_Product_Variable) {
                                $new_product->set_type('variable');
                            } elseif ($new_product instanceof WC_Product_Variation) {
                                $new_product->set_type('variation');
                            } else {
                                $new_product->set_type('simple');
                            }
                        } */

                        // ⚙️ Ya no se fuerza el tipo manualmente, WooCommerce lo define según la clase
                        // Solo añadimos un log de verificación:
                        escribir_log_debug("🧱 Tipo de producto detectado automáticamente → " . get_class($new_product));

                        // 5️⃣ Precio
                        if (!$new_product->get_regular_price()) {

                            if($lang == "es"){
                                $precio = 100;
                            }elseif($lang == "en"){
                                $precio = 200;
                            }else{
                                $precio = 300;
                            }

                            $new_product->set_regular_price($precio); // valor simbólico si el Excel no tiene precio
                        }

                        // 6️⃣ Categorías
                        $cats = (array) $new_product->get_category_ids();
                        if (empty($cats)) {
                            $default_cat = get_term_by('slug', 'sin-categoria', 'product_cat');
                            if ($default_cat) {
                                $new_product->set_category_ids([$default_cat->term_id]);
                            } else {
                                $fallback = wp_insert_term('Sin categoría', 'product_cat', ['slug' => 'sin-categoria']);
                                if (!is_wp_error($fallback)) {
                                    $new_product->set_category_ids([(int)$fallback['term_id']]);
                                }
                            }
                        }

                        // 🔍 Log de control antes del guardado
                        escribir_log_debug("🟡 PRE-SAVE CHECK: ref={$arr['referencia']} lang={$lang} | name=" . $new_product->get_name() . " | sku=" . $new_product->get_sku() . " | status=" . $new_product->get_status() . " | cats=" . implode(',', (array)$new_product->get_category_ids()) . " | price=" . $new_product->get_regular_price() . " | tipo=" . $new_product->get_type());

                       
                        // 💾 Inserción manual si aún no tiene ID (evita save()=0)
                        if (!$new_product->get_id() || $new_product->get_id() == 0) {
                            $post_id = wp_insert_post([
                                'post_title'  => $new_product->get_name(),
                                'post_type'   => 'product',
                                'post_status' => 'publish',
                                'post_name'   => $new_product->get_slug(),
                                'post_author' => 1,
                            ], true);

                            if (is_wp_error($post_id)) {
                                escribir_log_debug("💥 wp_insert_post falló para SKU {$new_product->get_sku()}: " . $post_id->get_error_message());
                            } elseif ($post_id == 0) {
                                escribir_log_debug("💥 wp_insert_post devolvió 0 para SKU {$new_product->get_sku()}");
                            } else {
                                $new_product->set_id($post_id);
                                escribir_log_debug("🟢 Post creado manualmente → ID={$post_id} para SKU {$new_product->get_sku()}");

                                update_post_meta($post_id,'_titulo_variacion'.$post_id,$arr['descripcion_'.$lang]);

                                  //ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 
                                update_post_meta($post_id,'medida_unitaria',$medidas_producto);
                                update_post_meta($post_id,'peso_unitario',$arr['peso_producto']);
                                update_post_meta($post_id,'capacidad',$arr['peso_producto']);
                                update_post_meta($post_id,'material',$arr['peso_producto']);
                                update_post_meta($post_id,'color',$arr['peso_producto']);
                                update_post_meta($post_id,'medidas_caja',$medidas_caja);
                                update_post_meta($post_id,'peso_caja',$arr['peso_producto']);
                                update_post_meta($post_id,'peso_unitario',$arr['peso_caja']);
                                update_post_meta($post_id,'unidades_caja',$arr['und_caja']);
                                update_post_meta($post_id,'unidad_compra_minima',$arr['cantidad_minima']);
                                 

                                update_post_meta($post_id,'niveles_palet_eur',$arr['niveles_palet_eur']);

                                update_post_meta($post_id,'niveles_palet_usa',$arr['niveles_palet_usa']);


                                update_post_meta($post_id,'niveles_cajas_eur',$arr['cajas_nivel_eur']);
                                update_post_meta($post_id,'niveles_cajas_usa',$arr['cajas_nivel_usa']);



                                update_post_meta($post_id,'altura_palet_eur',$arr['altura_palet_eur']);
                                update_post_meta($post_id,'altura_palet_usa',$arr['altura_palet_usa']);
                              

                                update_post_meta($post_id,'cajas_palet_eur',$arr['altura_palet_eur']);
                                update_post_meta($post_id,'cajas_palet_usa',$arr['cajas_palet_usa']);


                                update_post_meta($post_id,'color',$arr['caracteristica_color']);
                                update_post_meta($post_id,'material',$arr['caracteristica_material']);


                                for($indice=1; $indice<5;$indice++){

                                    update_post_meta($post_id,'caracteristica_'.$indice.'_'.$lang,$arr['caracteristica_'.$indice.'_'.$lang]);
                                }


                                 update_post_meta($post_id,'url_video_'.$lang ,$arr['video_'.$lang]);
                                     

                               //END ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 



                            }
                        }
                        $new_product->set_stock_status('instock');
                        // 💾 Guardar producto (update en vez de insert)
                        $result = $new_product->save();
                            //log_producto_atributo("💾 Producto guardado → ref={$arr['referencia']} ({$lang}) ID={$new_product->get_id()} tipo=" . $new_product->get_type());

                            // 🌍 Forzar idioma correcto en WPML
                            if (!empty($lang) && in_array($lang, ['es', 'en', 'fr'])) {
                                $element_type = apply_filters('wpml_element_type', 'post_product');
                                do_action('wpml_set_element_language_details', [
                                    'element_id'           => $new_product->get_id(),
                                    'element_type'         => $element_type,
                                    'trid'                 => null, // crea nueva TRID
                                    'language_code'        => $lang,
                                    'source_language_code' => null,
                                ]);
                                escribir_log_debug("🌐 Idioma WPML asignado correctamente → ID={$new_product->get_id()} | lang={$lang}");
                            }

                        if ($result > 0) {
                             update_post_meta($result,'_titulo_variacion'.$result,$arr['descripcion_'.$lang]);
                                 //ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 
                                update_post_meta($result,'medida_unitaria',$medidas_producto);
                                update_post_meta($result,'peso_unitario',$arr['peso_producto']);
                                update_post_meta($result,'capacidad',$arr['peso_producto']);
                                update_post_meta($result,'material',$arr['peso_producto']);
                                update_post_meta($result,'color',$arr['peso_producto']);
                                update_post_meta($result,'medidas_caja',$medidas_caja);
                                update_post_meta($result,'peso_caja',$arr['peso_producto']);
                                update_post_meta($result,'peso_unitario',$arr['peso_caja']);
                                update_post_meta($result,'unidades_caja',$arr['und_caja']);
                                update_post_meta($result,'unidad_compra_minima',$arr['cantidad_minima']);
                                 

                                update_post_meta($result,'niveles_palet_eur',$arr['niveles_palet_eur']);

                                update_post_meta($result,'niveles_palet_usa',$arr['niveles_palet_usa']);


                                update_post_meta($result,'niveles_cajas_eur',$arr['cajas_nivel_eur']);
                                update_post_meta($result,'niveles_cajas_usa',$arr['cajas_nivel_usa']);



                                update_post_meta($result,'altura_palet_eur',$arr['altura_palet_eur']);
                                update_post_meta($result,'altura_palet_usa',$arr['altura_palet_usa']);
                              

                                update_post_meta($result,'cajas_palet_eur',$arr['altura_palet_eur']);
                                update_post_meta($result,'cajas_palet_usa',$arr['cajas_palet_usa']);


                                update_post_meta($result,'color',$arr['caracteristica_color']);
                                update_post_meta($result,'material',$arr['caracteristica_material']);


                                for($indice=1; $indice<5;$indice++){

                                    update_post_meta($result,'caracteristica_'.$indice.'_'.$lang,$arr['caracteristica_'.$indice.'_'.$lang]);
                                }


                                 update_post_meta($result,'url_video_'.$lang ,$arr['video_'.$lang]);
                                     

                               //END ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 


                            escribir_log_debug("✅ Producto guardado correctamente → ID=$result | ref={$arr['referencia']} | SKU={$sku_final_lang}");
                            // 🟢 Sincronizar stock con el producto ES base
                            if ($lang !== 'es') {
                                $ref_base = $arr['referencia'];
                                $id_es = $addedprods['es'][$ref_base] ?? $existing_prods['es'][$ref_base] ?? 0;

                                if ($id_es) {
                                    $stock = get_post_meta($id_es, '_stock', true);
                                    $manage_stock = get_post_meta($id_es, '_manage_stock', false);
                                    $stock_status = get_post_meta($id_es, '_stock_status', true);

                                    update_post_meta($new_product->get_id(), '_stock', $stock);
                                    update_post_meta($new_product->get_id(), '_manage_stock', $manage_stock ?: 'yes');
                                    update_post_meta($new_product->get_id(), '_stock_status', $stock_status ?: 'instock');

                                    escribir_log_debug("🔄 Stock sincronizado desde ES → ref={$ref_base} ({$lang}) | stock={$stock} | status={$stock_status}");
                                }
                            }




                        } else {
                            escribir_log_debug("❌ ERROR: save() devolvió 0 | last_error=" . $wpdb->last_error);
                            escribir_log_debug("   → ref={$arr['referencia']} | lang={$lang} | SKU={$sku_final_lang}");
                            escribir_log_debug("   → Objeto producto: " . print_r($new_product, true));

                            // 🔎 Debug extra cuando save() devuelve 0
                            $sku_dbg = $sku_final_lang;
                            $row_dbg = $wpdb->get_row(
                                $wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1", $sku_dbg),
                                ARRAY_A
                            );
                            if ($row_dbg) {
                                escribir_log_debug("🚫 SKU duplicado detectado en post_id={$row_dbg['post_id']} → save() bloqueado");
                            } else {
                                escribir_log_debug("🟡 SKU {$sku_dbg} no encontrado en postmeta → fallo por otra causa (prob. name/status/categoría vacíos)");
                            }
                        }


                        // Guardar productos complementarios
                        if (!empty($arr['productos_complementarios'])) {
                            update_post_meta($new_product->get_id(), 'productos_complementarios', $arr['productos_complementarios']);
                            escribir_log_debug("➕ Productos complementarios añadidos a ID={$new_product->get_id()}");
                        }

                        // Guardar meta bodegón (solo refs 94xxx con codigos_asociados)
                        if (strpos($arr['referencia'], '94') === 0 && !empty($arr['codigos_asociados'])) {
                            $codigos = explode('-', $arr['codigos_asociados']);
                            update_post_meta($new_product->get_id(), 'bodegon', $codigos);
                            escribir_log_debug("💾 Meta bodegon guardado en producto {$arr['referencia']} (ID={$new_product->get_id()}) → " . implode(',', $codigos));
                        }


                        if ($key0 === 'parent') {
                            // reporting
                            if (!isset($created_prods[$lang])) {
                                $created_prods[$lang] = [];
                            }
                            $created_prods[$lang][$arr['referencia']] = (int)$new_product->get_id();

                            // NECESARIO para que se creen variaciones del padre recién creado
                            if (!isset($addedprods[$lang])) {
                                $addedprods[$lang] = [];
                            }
                            $addedprods[$lang][$arr['referencia']] = (int)$new_product->get_id();

                            //escribir_log_debug("📌 Registrado en addedprods[{$lang}] → ref={$arr['referencia']} → ID={$new_product->get_id()}");
                    } else {
                            if (!isset($addedvars[$lang])) {
                                $addedvars[$lang] = [];
                            }
                            $addedvars[$lang][$arr['referencia']] = (int)$new_product->get_id();
                           //escribir_log_debug("📌 Registrado en addedvars[{$lang}] → ref={$arr['referencia']} → ID={$new_product->get_id()}");
                        }


                        $baseItem = $key0 == 'parent' ? $addedprods : $addedvars;

                        // 🔒 Blindaje WPML para productos padres/hijos
                        if ($lang !== 'es' && !$productExists) {
                            $element_id = (int)($baseItem[$lang][$arr['referencia']] ?? 0);
                            $base_es_id = (int)($baseItem['es'][$arr['referencia']] ?? 0);
                            $trid       = null;
                            $post_type = get_post_type($element_id) ?: (($key0 === 'parent') ? 'post_product' : 'post_product_variation');
                            // 🔎 Log inicial
                            //escribir_log_debug("🔎 WPML DEBUG (producto) → ref={$arr['referencia']} lang=$lang | element_id=$element_id | base_es_id=$base_es_id | post_type=$post_type");

                            if ($base_es_id > 0) {
                                try {
                                    $trid = wpml_get_content_trid('post_product', $base_es_id);
                                    //escribir_log_debug("✅ wpml_get_content_trid OK → base_es_id=$base_es_id → trid=" . var_export($trid, true));
                                } catch (\Throwable $t) {
                                    //escribir_log_debug("💥 Error en wpml_get_content_trid (producto) → base_es_id=$base_es_id | Msg=" . $t->getMessage() . " | Línea=" . $t->getLine());
                                }
                        } else {
                                //escribir_log_debug("⚠️ No existe producto base en ES para ref={$arr['referencia']} → no se obtiene TRID");
                            }

                            //escribir_log_debug("🔎 PRE safe_wpml_set_language → element_id=$element_id | trid=" . var_export($trid, true) . " | lang=$lang | post_type=post_product");

                            if (!empty($trid) && is_numeric($trid) && $element_id > 0) {
                                try {
                                    // 🚦 Antes de asignar, comprobamos si YA está enlazado en WPML
                                    $translations = apply_filters('wpml_get_element_translations', [], $trid, 'post_product');
                                    $ya_asignado  = isset($translations[$lang]) && !empty($translations[$lang]->element_id);

                                    if ($ya_asignado) {
                                       // escribir_log_debug("⚠️ WPML ya tenía asignado → ref={$arr['referencia']} lang=$lang trid=$trid element_id_existente=" . $translations[$lang]->element_id);
                                    } else {
                                        safe_wpml_set_language(
                                            $element_id,
                                            'post_product',
                                            (int)$trid,
                                            $lang,
                                            'es',
                                            'producto'
                                        );
                                        //escribir_log_debug("🌍 WPML producto asignado correctamente → ref={$arr['referencia']} lang=$lang trid=$trid element_id=$element_id");
                                    }
                                } catch (\Throwable $w) {
                                    //escribir_log_debug("💥 Error en safe_wpml_set_language (producto) → ref={$arr['referencia']} lang=$lang | element_id=$element_id | trid=$trid | Msg=" . $w->getMessage() . " | Línea=" . $w->getLine());
                                }
                            } else {
                                //escribir_log_debug("⚠️ WPML saltado → condiciones no válidas: ref={$arr['referencia']} lang=$lang | element_id=$element_id | trid=" . var_export($trid, true));
                            }
                        }

                    } catch (\Throwable $e) {
                        escribir_log_debug("🛑 Excepción al guardar producto:");
                        escribir_log_debug("   → ref={$arr['referencia']} | lang={$lang} | SKU={$sku_final_lang}");
                        escribir_log_debug("   → Mensaje: " . $e->getMessage() . " | Línea: " . $e->getLine());
                    }
                    
                    if ($key0 == 'parent') {

                         if (!isset($addedprods[$lang]) || !is_array($addedprods[$lang]) || !in_array($arr['referencia'], array_keys((array)$addedprods[$lang]))) {
                            //escribir_log_debug("⚠️ No se encontró referencia={$arr['referencia']} en addedprods[{$lang}] → no se crea variante");
                            continue;
                        }

                        $variant_sku_final = normalize_sku($arr['referencia'].'-VARIANT-'.strtoupper($lang));
                        //escribir_log_debug("🔎 Procesando variante → ref={$arr['referencia']} | lang={$lang} | SKU={$variant_sku_final}");

                        // Comprobar si la variante ya existe
                        $variantExists = get_valid_id_by_sku_and_lang($variant_sku_final, $lang, 'product_variation');
                        //$variantExists = find_existing_product_by_sku($variant_sku_final);

                        if ($variantExists) {

                            wp_update_post([
                                'ID'          => $variantExists,
                                'post_status' => 'publish'
                            ]);
                            

                              update_post_meta($variantExists,'_titulo_variacion'.$variantExists,$arr['descripcion_'.$lang]);

                                  //ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 
                                update_post_meta($variantExists,'medida_unitaria',$medidas_producto);
                                update_post_meta($variantExists,'peso_unitario',$arr['peso_producto']);
                                update_post_meta($variantExists,'capacidad',$arr['peso_producto']);
                                update_post_meta($variantExists,'material',$arr['peso_producto']);
                                update_post_meta($variantExists,'color',$arr['peso_producto']);
                                update_post_meta($variantExists,'medidas_caja',$medidas_caja);
                                update_post_meta($variantExists,'peso_caja',$arr['peso_producto']);
                                update_post_meta($variantExists,'peso_unitario',$arr['peso_caja']);
                                update_post_meta($variantExists,'unidades_caja',$arr['und_caja']);
                                update_post_meta($variantExists,'unidad_compra_minima',$arr['cantidad_minima']);
                                 

                                update_post_meta($variantExists,'niveles_palet_eur',$arr['niveles_palet_eur']);

                                update_post_meta($variantExists,'niveles_palet_usa',$arr['niveles_palet_usa']);


                                update_post_meta($variantExists,'niveles_cajas_eur',$arr['cajas_nivel_eur']);
                                update_post_meta($variantExists,'niveles_cajas_usa',$arr['cajas_nivel_usa']);



                                update_post_meta($variantExists,'altura_palet_eur',$arr['altura_palet_eur']);
                                update_post_meta($variantExists,'altura_palet_usa',$arr['altura_palet_usa']);
                              

                                update_post_meta($variantExists,'cajas_palet_eur',$arr['altura_palet_eur']);
                                update_post_meta($variantExists,'cajas_palet_usa',$arr['cajas_palet_usa']);


                                update_post_meta($variantExists,'color',$arr['caracteristica_color']);
                                update_post_meta($variantExists,'material',$arr['caracteristica_material']);


                                for($indice=1; $indice<5;$indice++){

                                    update_post_meta($variantExists,'caracteristica_'.$indice.'_'.$lang,$arr['caracteristica_'.$indice.'_'.$lang]);
                                }


                                 update_post_meta($variantExists,'url_video_'.$lang ,$arr['video_'.$lang]);
                                     

                               //END ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 



                            $post_type = get_post_type($variantExists);
                            $post_status = get_post_status($variantExists);

                            if ($post_type !== 'product_variation' || $post_status === 'trash') {
                                $variantExists = false; // Forzar recreación limpia
                            }
                        }


                        if ($variantExists) {
                            $new_variation = wc_get_product($variantExists);
                            if ($new_variation) {
                                //escribir_log_debug("✅ Variación existente encontrada → ID={$variantExists} SKU={$variant_sku_final}");
                                // Reporting: variación ya existente
                                if (!isset($existing_vars[$lang])) {
                                    $existing_vars[$lang] = [];
                                }
                                $existing_vars[$lang][$arr['referencia']] = (int)$variantExists;

                                update_post_meta($variantExists,'_titulo_variacion'.$variantExists,$arr['descripcion_'.$lang]);

                                    //ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 
                                update_post_meta($variantExists,'medida_unitaria',$medidas_producto);
                                update_post_meta($variantExists,'peso_unitario',$arr['peso_producto']);
                                update_post_meta($variantExists,'capacidad',$arr['peso_producto']);
                                update_post_meta($variantExists,'material',$arr['peso_producto']);
                                update_post_meta($variantExists,'color',$arr['peso_producto']);
                                update_post_meta($variantExists,'medidas_caja',$medidas_caja);
                                update_post_meta($variantExists,'peso_caja',$arr['peso_producto']);
                                update_post_meta($variantExists,'peso_unitario',$arr['peso_caja']);
                                update_post_meta($variantExists,'unidades_caja',$arr['und_caja']);
                                update_post_meta($variantExists,'unidad_compra_minima',$arr['cantidad_minima']);
                                 

                                update_post_meta($variantExists,'niveles_palet_eur',$arr['niveles_palet_eur']);

                                update_post_meta($variantExists,'niveles_palet_usa',$arr['niveles_palet_usa']);


                                update_post_meta($variantExists,'niveles_cajas_eur',$arr['cajas_nivel_eur']);
                                update_post_meta($variantExists,'niveles_cajas_usa',$arr['cajas_nivel_usa']);



                                update_post_meta($variantExists,'altura_palet_eur',$arr['altura_palet_eur']);
                                update_post_meta($variantExists,'altura_palet_usa',$arr['altura_palet_usa']);
                              

                                update_post_meta($variantExists,'cajas_palet_eur',$arr['altura_palet_eur']);
                                update_post_meta($variantExists,'cajas_palet_usa',$arr['cajas_palet_usa']);


                                update_post_meta($variantExists,'color',$arr['caracteristica_color']);
                                update_post_meta($variantExists,'material',$arr['caracteristica_material']);


                                for($indice=1; $indice<5;$indice++){

                                    update_post_meta($variantExists,'caracteristica_'.$indice.'_'.$lang,$arr['caracteristica_'.$indice.'_'.$lang]);
                                }


                                 update_post_meta($variantExists,'url_video_'.$lang ,$arr['video_'.$lang]);
                                     

                               //END ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 



                            } else {
                                //escribir_log_debug("⚠️ Variación existente corrupta → SKU={$variant_sku_final}. Se forzará creación");
                                $variantExists = false;
                            }
                        }


                        if (!$variantExists) {

                            // 🔎 PRE-CHECK GLOBAL (cualquier estado/tipo): ¿ya hay algo con este SKU?
                            $dup_any_var = wc_get_product_id_by_sku($variant_sku_final);

                            if ($dup_any_var) {
                                // Existe un post (producto/variación) con ese SKU, aunque esté en draft/trash.
                                $st = get_post_status($dup_any_var);
                                $tp = get_post_type($dup_any_var);
                                escribir_log_debug("🚫 PRECHECK VAR: SKU duplicado → SKU={$variant_sku_final} ya en ID={$dup_any_var} status={$st} type={$tp}");

                                // 📊 Reporting como "variación existente" para no contarlo como creado
                                if (!isset($existing_vars[$lang])) { $existing_vars[$lang] = []; }
                                $existing_vars[$lang][$arr['referencia']] = (int)$dup_any_var;

                                // No intentamos crear de nuevo esta variación
                                continue;
                            }

                            // ➕ Crear nueva variación
                            escribir_log_debug("➕ Creando nueva variación para ref={$arr['referencia']} | lang={$lang} | SKU={$variant_sku_final}");
                            $new_variation = new WC_Product_Variation();

                            // Asignamos el SKU con try/catch para ver el SKU exacto si WooCommerce protesta
                            try {
                            $new_variation->set_sku($variant_sku_final);
                            } catch (\Throwable $e) {
                                escribir_log_debug("💥 SKU ERROR (variation) ref={$arr['referencia']} lang={$lang} SKU={$variant_sku_final} → ".$e->getMessage());
                                throw $e; // Lo captura tu catch global y aparece en el runner
                            }
                        }

                        // Asignar datos básicos
                        $new_variation->set_name($arr['descripcion_'.$lang]);
                        $new_variation->set_description($arr['descripcion_larga_'.$lang]);
                        $new_variation->set_parent_id($addedprods[$lang][$arr['referencia']] ?? 0);

                        if($lang == "es"){
                            $precio = 100;
                        }elseif($lang == "en"){
                            $precio = 200;
                        }else{
                            $precio = 300;
                        }


                        $new_variation->set_regular_price($precio);
                        // Asignar atributos
                        
                        if (isset($attr_arr['child'][$lang][$arr['referencia']])&& is_array($attr_arr['child'][$lang][$arr['referencia']])&& $attr_arr['child'][$lang][$arr['referencia']]) {

                            $new_variation->set_attributes($attr_arr['child'][$lang][$arr['referencia']]);
                            escribir_log_debug("⚙️ Atributos asignados a variante ref={$arr['referencia']} | lang={$lang}");
                        }
                        // Guardar variante
                        $new_variation->set_status('publish');
                        escribir_log_debug("💾 Guardando variación → SKU={$variant_sku_final} | tipo=".get_class($new_variation));

                        $id_new = $new_variation->save();
                        update_post_meta($id_new,'_titulo_variacion'.$id_new,$arr['descripcion_'.$lang]);
                            //ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 
                                update_post_meta($id_new,'medida_unitaria',$medidas_producto);
                                update_post_meta($id_new,'peso_unitario',$arr['peso_producto']);
                                update_post_meta($id_new,'capacidad',$arr['peso_producto']);
                                update_post_meta($id_new,'material',$arr['peso_producto']);
                                update_post_meta($id_new,'color',$arr['peso_producto']);
                                update_post_meta($id_new,'medidas_caja',$medidas_caja);
                                update_post_meta($id_new,'peso_caja',$arr['peso_producto']);
                                update_post_meta($id_new,'peso_unitario',$arr['peso_caja']);
                                update_post_meta($id_new,'unidades_caja',$arr['und_caja']);
                                update_post_meta($id_new,'unidad_compra_minima',$arr['cantidad_minima']);
                                 

                                update_post_meta($id_new,'niveles_palet_eur',$arr['niveles_palet_eur']);

                                update_post_meta($id_new,'niveles_palet_usa',$arr['niveles_palet_usa']);


                                update_post_meta($id_new,'niveles_cajas_eur',$arr['cajas_nivel_eur']);
                                update_post_meta($id_new,'niveles_cajas_usa',$arr['cajas_nivel_usa']);



                                update_post_meta($id_new,'altura_palet_eur',$arr['altura_palet_eur']);
                                update_post_meta($id_new,'altura_palet_usa',$arr['altura_palet_usa']);
                              

                                update_post_meta($id_new,'cajas_palet_eur',$arr['altura_palet_eur']);
                                update_post_meta($id_new,'cajas_palet_usa',$arr['cajas_palet_usa']);


                                update_post_meta($id_new,'color',$arr['caracteristica_color']);
                                update_post_meta($id_new,'material',$arr['caracteristica_material']);


                                for($indice=1; $indice<5;$indice++){

                                    update_post_meta($id_new,'caracteristica_'.$indice.'_'.$lang,$arr['caracteristica_'.$indice.'_'.$lang]);
                                }


                                 update_post_meta($id_new,'url_video_'.$lang ,$arr['video_'.$lang]);
                                     

                               //END ACTUALIZAMOS LOS POSTMETA DE CARACTERISITICAS TÉCNCIAS 

                        if ($id_new) {

                            if (!isset($created_vars[$lang])) {
                                    $created_vars[$lang] = [];
                            }
                            $created_vars[$lang][$arr['referencia']] = (int)$id_new;



                            escribir_log_debug("✅ Variación guardada correctamente → ID={$id_new} | SKU={$variant_sku_final}");
                        } else {
                            escribir_log_debug("❌ ERROR al guardar variación → ref={$arr['referencia']} | SKU={$variant_sku_final}");
                        }

                        // 🔒 Registro en addedvars
                        $addedvars[$lang][$arr['referencia']] = $new_variation->get_id();
                        $element_id = (int)($addedvars[$lang][$arr['referencia']] ?? 0);

                        escribir_log_debug("📌 Registrada variación en addedvars[{$lang}][{$arr['referencia']}] → ID={$element_id}");

                        // 🔒 Blindaje WPML para variaciones
                        $trid_var = null;
                        $base_es_var_id = (int)($addedvars['es'][$arr['referencia']] ?? 0);

                        // 🔎 Debug antes de tocar WPML
                        escribir_log_debug("🔎 WPML DEBUG (variación) → ref={$arr['referencia']} lang=$lang | element_id=$element_id | base_es_var_id=$base_es_var_id");

                        if ($base_es_var_id > 0 && get_post_status($base_es_var_id) !== false) {
                            try {
                                $trid_var = wpml_get_content_trid('post_product_variation', $base_es_var_id);
                                escribir_log_debug("✅ wpml_get_content_trid OK (variación) → base_es_var_id=$base_es_var_id | trid_var=" . var_export($trid_var, true));
                            } catch (\Throwable $t) {
                                escribir_log_debug("💥 Error en wpml_get_content_trid (variación) → ref={$arr['referencia']} lang=$lang | Msg=" . $t->getMessage() . " | Línea=" . $t->getLine());
                            }
                        } else {
                            escribir_log_debug("⚠️ Variación base en ES no válida → ref={$arr['referencia']} | base_es_var_id=$base_es_var_id");
                        }

                        // 🔎 Debug previo
                        escribir_log_debug("🔎 PRE safe_wpml_set_language (variación) → element_id=$element_id (status=" . var_export(get_post_status($element_id), true) . ") | trid_var=" . var_export($trid_var, true) . " | lang=$lang");

                        if ($element_id > 0 && get_post_status($element_id) !== false && !empty($trid_var) && is_numeric($trid_var)) {
                            try {
                                safe_wpml_set_language(
                                    $element_id,
                                    'post_product_variation',
                                    (int)$trid_var,
                                    $lang,
                                    'es',
                                    'variación'
                                );
                                escribir_log_debug("🌍 WPML variación asignada correctamente → ref={$arr['referencia']} lang=$lang trid=$trid_var element_id=$element_id");
                            } catch (\Throwable $t) {
                                escribir_log_debug("💥 Error en safe_wpml_set_language (variación) → ref={$arr['referencia']} lang=$lang | element_id=$element_id | trid=$trid_var | Msg=" . $t->getMessage() . " | Línea=" . $t->getLine());
                            }
                        } else {
                            escribir_log_debug("⚠️ WPML saltado (variación) → ref={$arr['referencia']} lang=$lang | element_id=$element_id | trid_var=" . var_export($trid_var, true));
                        }
                        
                        if (isset($caracteristicas_arr['child'][$arr['referencia']])&& is_array($caracteristicas_arr['child'][$arr['referencia']])&& $caracteristicas_arr['child'][$arr['referencia']]) {

                            foreach ($caracteristicas_arr['child'][$arr['referencia']] as $k => $v) {
                                if (strpos($k, '_'.$lang) === false) continue;
                                if ($v) {
                                    update_post_meta($new_variation->get_id(), $k, $v);
                                }
                            }
                            escribir_log_debug("📑 Características técnicas guardadas para variante ID={$new_variation->get_id()}");
                        }

                        // Guardar productos complementarios
                        if (!empty($arr['productos_complementarios'])) {
                            update_post_meta($new_variation->get_id(), 'productos_complementarios', $arr['productos_complementarios']);
                            escribir_log_debug("➕ Productos complementarios añadidos a variante ID={$new_variation->get_id()}");
                        }
                         if (strpos($arr['referencia'], '94') === 0 && !empty($arr['codigos_asociados'])) {
                            $codigos = explode('-', $arr['codigos_asociados']);
                            update_post_meta($new_product->get_id(), 'bodegon', $codigos);
                            escribir_log_debug("💾 Meta bodegon guardado en producto {$arr['referencia']} (ID={$new_product->get_id()}) → " . implode(',', $codigos));
                        }
                    }
                    if ($caracteristicas_arr[$key0][$arr['referencia']]) {
                        foreach ($caracteristicas_arr[$key0][$arr['referencia']] as $k => $v) {
                            if (strpos($k, '_'.$lang) === false) continue;

                            if ($v) {
                                update_post_meta($new_product->get_id(), $k, $v);
                            }
                            
                        }
                    }
                }  
            }
        }
       return [
            'type'                   => 'success',
            'message'                => 'Sincronización finalizada correctamente.',
            // Compatibilidad hacia atrás: ahora solo los creados de verdad
            'added_products'         => $created_prods,
            // Reporting explícito y separado:
            'productos_creados'      => $created_prods,
            'productos_existentes'   => $existing_prods,
            'variaciones_creadas'    => $created_vars,
            'variaciones_existentes' => $existing_vars,
            'logs'                   => $logs,
        ];

    } catch (\Throwable $e) {
        escribir_log_debug("💥 [GLOBAL CATCH] Excepción en read_excel_to_array_interface → " . $e->getMessage() . " | File=" . $e->getFile() . " | Line=" . $e->getLine());
        if (strpos($e->getFile(), 'sitepress-multilingual-cms') !== false) {
            escribir_log_debug("💥 ERROR DETECTADO DENTRO DE WPML (sitepress)");
    } 
        return new \WP_Error('sync_error', "🛑 Error al leer el Excel: " . $e->getMessage() . " | Archivo: " . $e->getFile() . " | Línea: " . $e->getLine());
} 
} 


