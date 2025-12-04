<?php

/*ini_set('display_errors', 1);
ini_set('display_startup_errors', 1); */
error_reporting(E_ALL); 
require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__.  '/funciones/ordenar-excel-a-sortedarr.php';
require_once __DIR__ . '/funciones/crear-variaciones-para-producto.php';
require_once __DIR__ . '/funciones/crear-productos-padre-desde-excel.php';
require_once __DIR__ . '/funciones/crear-productos-simples-desde-excel.php';
require_once __DIR__ . '/funciones/crear-productos-bodegones-desde-excel.php';
require_once __DIR__ . '/funciones/preparar-atributos-globales.php';

require_once __DIR__ . '/funciones/trazas.php';

require_once __DIR__.'/funciones/crear-y-asignar-categorias-productos.php';


require_once __DIR__ . '/funciones/procesar-productos-desde-excel.php';
require_once __DIR__ . '/funciones/crear-y-asignar-atributos-de-productos.php';
require_once __DIR__ . '/funciones/crear-y-asignar-atributos-de-producto-variable.php';

require_once __DIR__ . '/funciones/procesar-productos-variables-desde-excel.php';

require_once __DIR__.'/funciones/procesar-variaciones-de-producto-desde-excel.php';

require_once __DIR__.'/funciones/crear-y-asignar-atributos-de-variaciones-de-productos.php';
require_once __DIR__.'/funciones/procesar-imagenes-desde-excel.php';
require_once __DIR__.'/funciones/verificar-y-montar-galeria-de-productos.php';








use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

//require_once( ABSPATH . 'prueba-conexion.php' );

// Asegurarse de que WordPress esté cargado
defined('ABSPATH') || exit;
ini_set('max_execution_time',0);
set_time_limit(0);
ini_set('memory_limit', '5000M');

/**
 * Busca un producto existente por SKU, ignorando mayúsculas y espacios.
 * Si no lo encuentra con la función estándar de WooCommerce, hace una búsqueda directa en postmeta.
 */
$log_file = plugin_dir_path(__FILE__) . 'log_asociaciones.txt';


/**
 * Log especial de depuración para productos y atributos
 * Guarda los mensajes en /wp-content/uploads/logs_productos.txt


 /**
 * Guarda trazas específicas de categorías en un log separado.
 * Archivo: /logs_categorias.txt (en el mismo directorio del script principal)
 *
 * @param string $mensaje Texto a registrar en el log.
 * @param bool $reset Indica si se debe limpiar el archivo al iniciar (por defecto false)



 */




function log_categoria_debug(string $mensaje, bool $reset = false) {
    static $log_limpiado = false;
    $log_file = __DIR__ . '/logs_categorias.txt';
    $fecha = date('Y-m-d H:i:s');

    // 🧹 Limpiar solo la primera vez o si se solicita explícitamente
    if (($reset && file_exists($log_file)) || !$log_limpiado) {
        file_put_contents($log_file, ""); // Vaciar el log
        $mensaje_inicial = "[$fecha] 🧹 Log de categorías limpiado automáticamente\n";
        file_put_contents($log_file, $mensaje_inicial, FILE_APPEND);
        $log_limpiado = true;
    }

    // Añadir el mensaje formateado
    $linea = "[$fecha] $mensaje\n";
    file_put_contents($log_file, $linea, FILE_APPEND);
}

 

function log_producto_atributo($mensaje) {

    static $log_limpiado = false;
    $log_file = __DIR__ .  '/funciones/logs/logs_productos.txt';

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



function build_full_category_hierarchy_es(string $subcat_raw): array {
    global $created_categories;
    $tax = 'product_cat';
    $out = [];

    $raw = strtolower(trim($subcat_raw));
    if (empty($raw)) return $out;

    // Normalizar formato: '/' → '_'
    $raw = str_replace('/', '_', $raw);
    $niveles = array_filter(explode('_', $raw), fn($v) => trim($v) !== '');
    if (empty($niveles)) return $out;

    $current_parent_id = 0;
    $current_parent_slug = '';

    foreach ($niveles as $i => $slug_piece) {
        $slug_clean = sanitize_title(trim($slug_piece));
        $name_clean = ucwords(str_replace('-', ' ', $slug_clean));

        // Slug jerárquico: padre-hijo
        $slug_final = $i === 0 ? $slug_clean : $current_parent_slug . '-' . $slug_clean;

        // 🚫 Buscar solo categorías existentes en español
        $term = null;
        $maybe_term = get_term_by('slug', $slug_final, $tax);

        if ($maybe_term) {
            $lang = apply_filters('wpml_element_language_code', null, [
                'element_id'   => $maybe_term->term_id,
                'element_type' => 'tax_product_cat'
            ]);
            if ($lang === 'es') {
                $term = $maybe_term;
            }
        }

        if ($term && !is_wp_error($term)) {
            $current_parent_id = (int)$term->term_id;
            $current_parent_slug = $slug_final;
            log_categoria_debug("✔ Reutilizada categoría '{$term->name}' (slug={$term->slug}) [ID={$term->term_id}] [lang=es]");
        } else {
            // 🧩 Crear nueva categoría (solo español)
            $res = wp_insert_term($name_clean, $tax, [
                'slug'   => $slug_final,
                'parent' => $current_parent_id,
            ]);

            if (!is_wp_error($res)) {
                $term_id = (int)$res['term_id'];
                $term = get_term($term_id, $tax);

                // 🌍 Forzar idioma español en WPML
                do_action('wpml_set_element_language_details', [
                    'element_id'           => $term_id,
                    'element_type'         => 'tax_product_cat',
                    'trid'                 => null,
                    'language_code'        => 'es',
                    'source_language_code' => null,
                ]);

                $current_parent_id = $term_id;
                $current_parent_slug = $slug_final;
                $created_categories[] = [
                    'id'     => $term_id,
                    'name'   => $term->name,
                    'slug'   => $term->slug,
                    'parent' => $term->parent,
                ];

                log_categoria_debug("🆕 Creada categoría '{$term->name}' (slug={$term->slug}) bajo padre ID={$term->parent} [lang=es]");
            } else {
                log_categoria_debug("⚠️ Error creando categoría '{$name_clean}' → " . $res->get_error_message());
                continue;
            }
        }

        // 🌍 Crear traducciones automáticas si el padre tiene equivalentes
        /*if (!empty($term->term_id) && $term->term_id > 0) {
            $trid_term = apply_filters('wpml_element_trid', null, $term->term_id, 'tax_product_cat');
            $translations_parent = [];

            // Solo tiene sentido si la categoría tiene padre (para mantener jerarquía)
            if ($term->parent > 0) {
                $trid_parent = apply_filters('wpml_element_trid', null, $term->parent, 'tax_product_cat');
                if ($trid_parent) {
                    $translations_parent = apply_filters('wpml_get_element_translations', [], $trid_parent, 'tax_product_cat');
                }
            }

            foreach (['en', 'fr'] as $lang) {
                if (!empty($translations_parent[$lang])) {
                    $parent_translated_id = (int)$translations_parent[$lang]->element_id;

                    $slug_lang = $slug_final . '-' . $lang;
                    $term_translated = get_term_by('slug', $slug_lang, $tax);

                    if (!$term_translated) {
                        $res_lang = wp_insert_term($name_clean, $tax, [
                            'slug'   => $slug_lang,
                            'parent' => $parent_translated_id,
                        ]);

                        if (!is_wp_error($res_lang)) {
                            $term_lang_id = (int)$res_lang['term_id'];

                            // Obtener TRID del término español base
                            $trid = $trid_term ?: apply_filters('wpml_element_trid', null, $term->term_id, 'tax_product_cat');


                            // Asociar la traducción
                            do_action('wpml_set_element_language_details', [
                                'element_id'           => $term_lang_id,
                                'element_type'         => 'tax_product_cat',
                                'trid'                 => $trid,
                                'language_code'        => $lang,
                                'source_language_code' => 'es',
                            ]);

                            log_categoria_debug("🌍 Traducción creada para '{$term->name}' → {$lang} (ID={$term_lang_id}) bajo padre traducido ID={$parent_translated_id}");
                        } else {
                            log_categoria_debug("⚠️ No se pudo crear traducción {$lang} para '{$term->name}' → " . $res_lang->get_error_message());
                        }
                    } else {
                        log_categoria_debug("♻️ Ya existe traducción {$lang} para '{$term->name}' (slug={$slug_lang})");
                    }
                }
            }
        } */

        $out[] = [
            'id'   => (int)$term->term_id,
            'slug' => $term->slug,
            'name' => $term->name
        ];
    }

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

/**
 * Devuelve el ID del placeholder "woocommerce-placeholder".
 */




function obtener_o_importar_imagen_por_referencia($referencia) {


    // postmeta para la galeríad e imagens del producto -> _product_image_gallery
    $log_file = __DIR__ . '/logs/logs_imagenes.txt';
    $fecha = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$fecha] 🔍 Buscando imagen para {$referencia}\n", FILE_APPEND);

    $filename = $referencia . '.jpg';
    $upload_dir = wp_upload_dir();
    $local_path = $upload_dir['path'] . '/' . $filename;

    // 1️⃣ Buscar en la galería de medios
    $existing = get_page_by_title($referencia, OBJECT, 'attachment');
    if ($existing) {
        file_put_contents($log_file, "[$fecha] ✅ Imagen encontrada en galería: ID {$existing->ID}\n", FILE_APPEND);
        return $existing->ID;
    }

    // 2️⃣ Intentar conexión FTP
    if (!function_exists("ftp_ssl_connect")) {
        file_put_contents($log_file, "[$fecha] ⚠️ PHP no tiene soporte para ftp_ssl_connect.\n", FILE_APPEND);
        return obtener_imagen_placeholder($referencia, $log_file);
    }

    $ftp_conn = @ftp_ssl_connect('datos.copele.com', 21, 15);
    if (!$ftp_conn) {
        file_put_contents($log_file, "[$fecha] ⚠️ No se pudo conectar al servidor FTP.\n", FILE_APPEND);
        return obtener_imagen_placeholder($referencia, $log_file);
    }

    if (!@ftp_login($ftp_conn, 'copele', 'cZfNauaZjdm225x')) {
        file_put_contents($log_file, "[$fecha] ⚠️ Error en autenticación FTP.\n", FILE_APPEND);
        ftp_close($ftp_conn);
        return obtener_imagen_placeholder($referencia, $log_file);
    }

    ftp_pasv($ftp_conn, true);
    $remote_path = 'Product Images/' . $filename;

    if (!file_exists($upload_dir['path'])) {
        wp_mkdir_p($upload_dir['path']);
    }

    if (!ftp_get($ftp_conn, $local_path, $remote_path, FTP_BINARY)) {
        file_put_contents($log_file, "[$fecha] ⚠️ No se encontró {$remote_path} en el FTP.\n", FILE_APPEND);
        ftp_close($ftp_conn);
        return obtener_imagen_placeholder($referencia, $log_file);
    }

    ftp_close($ftp_conn);
    file_put_contents($log_file, "[$fecha] ✅ Imagen descargada desde FTP: {$local_path}\n", FILE_APPEND);

    // 3️⃣ Subir a la biblioteca de WordPress
    $filetype = wp_check_filetype($filename, null);
    $attachment = [
        'post_mime_type' => $filetype['type'],
        'post_title'     => $referencia,
        'post_content'   => '',
        'post_status'    => 'inherit'
    ];

    $attach_id = wp_insert_attachment($attachment, $local_path);
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $local_path);
    wp_update_attachment_metadata($attach_id, $attach_data);

    file_put_contents($log_file, "[$fecha] 🖼️ Imagen importada con ID {$attach_id}\n", FILE_APPEND);
    return $attach_id;
}

/**
 * 📦 Devuelve el ID del placeholder si no se encuentra imagen real.
 */
function obtener_imagen_placeholder($referencia, $log_file) {
    $fecha = date('Y-m-d H:i:s');

    // Intentar obtener el placeholder existente
    $placeholder_id = wc_get_image_id_from_placeholder();

    if (!$placeholder_id || !is_numeric($placeholder_id)) {
        $placeholder_url = wc_placeholder_img_src();
        file_put_contents($log_file, "[$fecha] ⚙️ Usando URL del placeholder: {$placeholder_url}\n", FILE_APPEND);

        // Registrar placeholder si no existe
        $attachment = [
            'post_mime_type' => 'image/png',
            'post_title'     => 'woocommerce-placeholder',
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $placeholder_id = wp_insert_attachment($attachment, $placeholder_url);
        if (is_wp_error($placeholder_id)) {
            file_put_contents($log_file, "[$fecha] ❌ Error al crear placeholder: " . $placeholder_id->get_error_message() . "\n", FILE_APPEND);
            return 0;
        }
    }

    file_put_contents($log_file, "[$fecha] 🧩 Asignado placeholder ID {$placeholder_id} para {$referencia}\n", FILE_APPEND);
    return $placeholder_id;
}









function crear_atributos_globales_desde_excel($sortedArr) {
    $log_file = __DIR__ . '/logs_atributos.txt';
    $fecha = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$fecha] 🚀 INICIO PASO 2 → Creación de atributos globales\n");

    // 1️⃣ Atributos que queremos controlar globalmente
    $atributos = [
        'color', 'capacidad', 'modelo', 'material', 'tipo', 'patas',
        'filtro_tipo', 'filtro_capacidad', 'filtro_color', 'filtro_modelo', 'filtro_material'
    ];

    // 2️⃣ Crear cada taxonomía global si no existe
    foreach ($atributos as $attr_name) {
        $slug = 'pa_' . sanitize_title($attr_name);
        $exists = taxonomy_exists($slug);
        if (!$exists) {
            wc_create_attribute([
                'slug'         => $slug,
                'name'         => ucfirst(str_replace('_', ' ', $attr_name)),
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ]);
            register_taxonomy($slug, 'product', ['hierarchical' => false]);
            file_put_contents($log_file, "[$fecha] 🆕 Atributo global creado: {$slug}\n", FILE_APPEND);
        } else {
            file_put_contents($log_file, "[$fecha] ✔ Atributo ya existe: {$slug}\n", FILE_APPEND);
        }
    }

    // 3️⃣ Recoger valores únicos del Excel para añadir términos
    $valores_unicos = [];
    foreach ($sortedArr as $grupo) {
        foreach ($grupo as $fila) {
            foreach ($atributos as $campo) {
                if (!empty($fila[$campo])) {
                    $valor = trim($fila[$campo]);
                    if ($valor !== '') {
                        $valores_unicos[$campo][$valor] = true;
                    }
                }
            }
        }
    }

    // 4️⃣ Insertar términos en cada taxonomía
    foreach ($valores_unicos as $campo => $vals) {
        $slug_tax = 'pa_' . sanitize_title($campo);
        foreach (array_keys($vals) as $valor) {
            $term = term_exists($valor, $slug_tax);
            if (!$term) {
                $insert = wp_insert_term($valor, $slug_tax);
                if (!is_wp_error($insert)) {
                    file_put_contents($log_file, "[$fecha] ➕ Término añadido: {$valor} → {$slug_tax}\n", FILE_APPEND);
                } else {
                    file_put_contents($log_file, "[$fecha] ⚠️ Error añadiendo {$valor} en {$slug_tax}: " . $insert->get_error_message() . "\n", FILE_APPEND);
                }
            }
        }
    }

    $count = count($atributos);
    file_put_contents($log_file, "[$fecha] ✅ Paso 2 completado: {$count} atributos procesados correctamente.\n", FILE_APPEND);
}




function build_subcat_from_excel(string $parent_slug_raw, string $subcat_raw): ?array {
    global $created_categories;
    $parent_slug = sanitize_title(trim($parent_slug_raw));
    if (empty($parent_slug)) return [null, null];
    $parent_term = get_term_by('slug', $parent_slug, 'product_cat');
    if (!$parent_term) {
        $parent_name = ucwords(str_replace('-', ' ', $parent_slug));
        $res = wp_insert_term($parent_name, 'product_cat', ['slug' => $parent_slug]);
        if (is_wp_error($res)) {
            escribir_log_debug("❌ Error creando categoría padre '{$parent_slug}' → ".$res->get_error_message());
            return [null, null];
        }
        $parent_term = get_term($res['term_id']);
        $created_categories[] = ['name'=>$parent_name,'slug'=>$parent_slug,'parent'=>null,'id'=>$parent_term->term_id,'level'=>1];
        escribir_log_debug("🟢 Creada categoría padre '{$parent_name}' (slug={$parent_slug})");
    }
    $last_parent_id = (int)$parent_term->term_id;
    $raw = strtolower(trim($subcat_raw));
    if (empty($raw)) return [$parent_term->name,$parent_term->slug];
    $raw = str_replace('/', '_', $raw);
    if (strpos($raw,$parent_slug.'_')===0) $raw = substr($raw,strlen($parent_slug)+1);
    $parts = array_filter(explode('_',$raw),fn($v)=>trim($v)!=='');
    $chain_names = [$parent_term->name];
    $level = 2;
    foreach ($parts as $slug_piece) {
        $slug_clean = sanitize_title($slug_piece);
        $name_clean = ucwords(str_replace('-', ' ', $slug_clean));
        $unique_slug = $parent_slug.'-'.$slug_clean;
        $existing = get_term_by('slug',$unique_slug,'product_cat');
        if ($existing) {
            $last_parent_id = (int)$existing->term_id;
            escribir_log_debug("✔ Subcategoría existente '{$name_clean}' (slug={$unique_slug}) reutilizada");
        } else {
            $res = wp_insert_term($name_clean,'product_cat',['slug'=>$unique_slug,'parent'=>$last_parent_id]);
            if (!is_wp_error($res)) {
                $last_parent_id = (int)$res['term_id'];
                $created_categories[] = ['name'=>$name_clean,'slug'=>$unique_slug,'parent'=>$chain_names[count($chain_names)-1]??null,'id'=>$last_parent_id,'level'=>$level];
                escribir_log_debug("🧩 Creada subcategoría '{$name_clean}' (slug={$unique_slug}) bajo '{$chain_names[count($chain_names)-1]}'");
            } else {
                escribir_log_debug("⚠️ Error creando subcategoría '{$name_clean}' → ".$res->get_error_message());
            }
        }
        $parent_slug = $unique_slug;
        $chain_names[] = $name_clean;
        $level++;
    }
    escribir_log_debug("📂 Jerarquía final: ".implode(' → ',$chain_names));
    $term_final = get_term($last_parent_id);
    return [$term_final->name??'',$term_final->slug??''];
}

/**
 * 🔹 Guarda las características personalizadas (caracteristica_1_es...caracteristica_5_fr)
 * para cualquier producto o variación.
 */
if (!function_exists('guardar_caracteristicas_personalizadas')) {
    function guardar_caracteristicas_personalizadas($product_id, $row) {
        if (empty($product_id) || !is_array($row)) return;

        $idiomas = ['es', 'en', 'fr'];

        for ($i = 1; $i <= 5; $i++) {
            foreach ($idiomas as $lang) {

                $campo = "caracteristica_{$i}_{$lang}";

                // ✅ SOLO guardar si existe EN EL ARRAY y NO está vacío
                if (isset($row[$campo]) && trim($row[$campo]) !== '') {
                    update_post_meta($product_id, $campo, trim($row[$campo]));
                }

                // ❌ NO BORRAR si no llega.
                // → si quieres, solo borrar si VIENE explícitamente vacío en el Excel
            }
        }
    }
}



function read_excel_to_array_interface() {
    log_producto_atributo('', true);
    log_producto_atributo("🚀 INICIO DE SINCRONIZACIÓN");

    $log_trace = __DIR__ . '/logs/trace_debug.txt';
    file_put_contents($log_trace, "======================\n📘 INICIO DE LECTURA EXCEL\n======================\n");

    function attach_image_to_product_by_sku($sku, $image_url) {
        global $wpdb;
        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) return;
        $filename = basename($image_url);

        $attachment_data = $wpdb->get_row("SELECT * FROM $wpdb->posts WHERE post_title = '$filename'");
        if ($attachment_data) {
            $attachment_id = $attachment_data->ID;
        } else {
            $image_data = @file_get_contents($image_url);
            if (!$image_data) return;
            $upload = wp_upload_bits($filename, null, $image_data);
            if ($upload['error']) return;
            $wp_filetype = wp_check_filetype($filename, null);
            $attachment = [
                'post_mime_type' => $wp_filetype['type'],
                'post_title'     => sanitize_file_name($filename),
                'post_content'   => '',
                'post_status'    => 'inherit'
            ];
            $attachment_id = wp_insert_attachment($attachment, $upload['file']);
            if (!$attachment_id) return;
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_data);
        }
        set_post_thumbnail($product_id, $attachment_id);
    }

    $local_dir = '/home/copelepruebas/public_html/pics/';
    $fileNames = scandir($local_dir);
    if (!$fileNames || !is_array($fileNames)) $fileNames = [];
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

    foreach ($skus as $sku) {
        $parts = explode('-', $sku);
        $file = reset($parts);
        $res = array_filter($fileNames, fn($fileName) => strpos($fileName, $file) !== false);
        foreach ($res as $img) {
            attach_image_to_product_by_sku($sku, 'https://pruebas.copele.com/pics/' . $img);
        }
    }

    $langs = ['es', 'en', 'fr'];
   $file_path = __DIR__ . '/files/ultimo_excel.xlsx';
    $relative_path = get_option('interface_excel_reader_last_file_url');

    if (!empty($relative_path)) {
        // Asegurar formato correcto de la ruta
        $clean_path = ltrim($relative_path, '/');
        $possible_path = trailingslashit(ABSPATH) . $clean_path;

        if (file_exists($possible_path)) {
            $file_path = $possible_path;
        } else {
            // Si el archivo no existe, lo registramos
            file_put_contents(__DIR__ . '/logs/trace_debug.txt', 
                "[" . date('Y-m-d H:i:s') . "] ⚠️ Archivo no encontrado: {$possible_path}\n", 
                FILE_APPEND
            );
        }
    }


    $trace_file = __DIR__ . '/logs/trazas-y_errrores.txt';
    if (!file_exists(dirname($trace_file))) {
        mkdir(dirname($trace_file), 0755, true);
    }


    try {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file_path);
        $sheet = $spreadsheet->getSheet($spreadsheet->getFirstSheetIndex());
        $data = $sheet->toArray();

        $log_excel = __DIR__ . '/logs/logs_excel_leido.txt';
        file_put_contents($log_excel, "=== INICIO DUMP EXCEL ===\n");

        foreach ($data as $i => $fila) {
            file_put_contents($log_excel, "[" . $i . "] " . json_encode($fila, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        }

        unset($data[0]);
        $cabeceras = array_map('trim', $data[1]);
        $data = array_slice($data, 2);

        $sortedArr = [];
        $rows_asociativos = [];

        foreach ($data as $index => $row) {
            if (!is_array($row)) continue;

            // Crea array asociativo
            $row = array_combine($cabeceras, $row);

            // Añadimos trazas de depuración
            $ref_debug = trim((string)($row['referencia'] ?? ''));
            $desc_debug = trim((string)($row['descripcion_es'] ?? ''));
            $padre_debug = trim((string)($row['padre'] ?? ''));
            $asociados_debug = trim((string)($row['codigos_asociados'] ?? ''));

            $empty = empty(array_filter($row));

            file_put_contents(
                $log_trace,
                sprintf(
                    "📄 Fila %d → ref=%s | desc=%s | padre=%s | asociados=%s | vacía=%s\n",
                    $index,
                    $ref_debug ?: '(sin ref)',
                    $desc_debug ?: '(sin descripción)',
                    $padre_debug ?: '-',
                    $asociados_debug ?: '-',
                    $empty ? 'sí' : 'no'
                ),
                FILE_APPEND
            );

            // Descarta filas completamente vacías
            if ($empty) continue;

            // ✅ Leer y normalizar CÓDIGOS COMPLEMENTARIOS
            $complementarios_raw = trim((string)($row['codigos_complementarios'] ?? ''));

            if ($complementarios_raw !== '') {
                // Soporta coma, punto y coma, espacios y saltos de línea
                $partes = preg_split('/[\s,;]+/', $complementarios_raw);
                $row['codigos_complementarios'] = array_filter(array_map('trim', $partes));
            } else {
                $row['codigos_complementarios'] = [];
            }

            $rows_asociativos[] = $row;
        }

        // ========================================
        // 🔍 Clasificación de productos
        // ========================================
                foreach ($rows_asociativos as $k => $row) {
                    $ref = trim((string)($row['referencia'] ?? ''));
                    $padre = trim((string)($row['padre'] ?? ''));
                    $asociados = trim((string)($row['codigos_asociados'] ?? ''));
                    $tipo = '???';

                    $primerAsociado=explode('-', $asociados);
                    $primerAsociado=$primerAsociado[0];

                    // Determinar tipo según tus reglas
                    if ($padre !== '') {
                        $tipo = 'child';
                    } elseif ($asociados !== '' || ($primerAsociado === $ref ) ) {
                        $tipo = 'parent';
                    } else {
                        $tipo = 'simple';
                    }

                    // Si es padre con referencia que empieza por 94 → convertir en bodegón
                    if ($tipo === 'parent' && strpos($ref, '94') === 0) {
                        $tipo = 'bodegon';
                    }

                    // Registrar traza
                    file_put_contents(
                        $trace_file,
                        "🧩 Clasificación fila #{$k}: REF={$ref}, PADRE={$padre}, ASOCIADOS={$asociados}, → tipo={$tipo}\n",
                        FILE_APPEND
                    );

                    // Clasificar en array
                    if ($tipo === 'simple') {
                        $sortedArr['simples'][$k] = $row;
                    } elseif ($tipo === 'parent') {
                        $sortedArr['parent'][$k] = $row;
                    } elseif ($tipo === 'bodegon') {
                        $sortedArr['bodegones'][$k] = $row;
                    } elseif ($tipo === 'child') {
                        // Se agregará luego como hijo del padre
                    } else {
                        file_put_contents($trace_file, "⚠️ Fila #{$k} con tipo desconocido (REF={$ref})\n", FILE_APPEND);
                    }
                }


        // ========================================
        // 📊 Resumen clasificación
        // ========================================
        $conteo = [
            'parent' => count($sortedArr['parent'] ?? []),
            'child'  => count($sortedArr['child'] ?? []),
            'simple' => count($sortedArr['simple'] ?? []),
        ];
        file_put_contents($log_trace, "📊 RESUMEN: " . json_encode($conteo, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

        // ========================================
        // 🧩 Procesamiento principal
        // ========================================

        // 🔍 Dump intermedio de filas leídas
        $trace_mid = __DIR__ . '/logs/trace_mid_rows.txt';
        file_put_contents($trace_mid, "======================\n📄 DUMP FILAS LEÍDAS\n======================\n");
        foreach ($rows_asociativos as $i => $r) {
            $ref = $r['referencia'] ?? '(sin ref)';
            $padre = $r['padre'] ?? '-';
            $asoc = $r['codigos_asociados'] ?? '-';
            $desc = substr($r['descripcion_es'] ?? '', 0, 40);
            file_put_contents($trace_mid, "Fila #{$i} → REF={$ref} | PADRE={$padre} | ASOC={$asoc} | DESC={$desc}\n", FILE_APPEND);
        }
        file_put_contents($trace_mid, "TOTAL FILAS LEÍDAS: " . count($rows_asociativos) . "\n", FILE_APPEND);



        $arrayDatosOrdenados = ordenar_excel_a_sortedArr($rows_asociativos);

           /* return ['type' => 'success', 
                'message' => 'Sincronización finalizada correctamente proces-sync.',
                'arrayDatosOrdenados'=>$arrayDatosOrdenados];*/


        // 🔍 Dump final de array ordenado
        $trace_sorted = __DIR__ . '/logs/trace_sorted_after_ordenar.txt';
        file_put_contents($trace_sorted, "======================\n📦 DUMP ARRAY ORDENADO\n======================\n");

        foreach ($arrayDatosOrdenados as $grupo => $items) {
            file_put_contents($trace_sorted, "### Grupo {$grupo}: " . count($items) . " elementos ###\n", FILE_APPEND);
            foreach ($items as $j => $r) {
                $ref = $r['referencia'] ?? '(sin ref)';
                $padre = $r['padre'] ?? '-';
                $desc = substr($r['descripcion_es'] ?? '', 0, 40);
                file_put_contents($trace_sorted, "   → REF={$ref} | PADRE={$padre} | DESC={$desc}\n", FILE_APPEND);
            }
        }


       // ===============================================
        // 🚀 NUEVA LÓGICA: MODO DATOS o MODO FOTOS
        // ===============================================

        // Recuperar modo desde AJAX
        $modo = $_POST['modo'] ?? 'procesar_datos';

        file_put_contents(__DIR__.'/logs/trace_debug.txt',
            "[".date('Y-m-d H:i:s')."] MODO RECIBIDO → {$modo}\n",
            FILE_APPEND
        );

        // ===============================================
        // 🔵 MODO: PROCESAR DATOS
        // ===============================================

        if ($modo === 'procesar_datos') {

            $idiomas = ['es'];

            foreach ($idiomas as $idioma) {
                try {

              
             return [
                    'type'    =>'success',
                    'message' =>'Ordenado de DATOS finalizado correctamente.',
                    'arrayDatosOrdenados' => $arrayDatosOrdenados,
                ];


                    //$productos_simples_y_bodegones =array_merge($arrayDatosOrdenados['simples'], $arrayDatosOrdenados['bodegones']);

                    //$paso0=preparar_atributos_globales($arrayDatosOrdenados['_atributos_amarillos'] ?? [],$arrayDatosOrdenados['_atributos_verdes'] ?? []);

                    //$paso1=procesar_productos_desde_excel($productos_simples_y_bodegones, $idioma);
                    //$paso2=procesar_productos_variables_desde_excel($arrayDatosOrdenados['parent'], $idioma);
                    $paso3=procesar_variaciones_de_producto_desde_excel($arrayDatosOrdenados, $idioma);

                return [
                    'type'    => $paso3['type']    ?? 'success',
                    'message' => $paso3['message'] ?? 'Procesamiento de DATOS finalizado correctamente.',
                    'resumen' => $paso3['resumen'] ?? [],
                    'arrayDatosBodegones' => $arrayDatosOrdenados,
                    // opcional: para debug interno, si lo quieres ver en el SweetAlert con <pre>
                    'pasos' => [
                        'paso0' => 'OK',
                        'paso3' => $paso3,
                    ],
                ];

                } catch (\Throwable $e) {

                    $log_debug = __DIR__ . '/logs/logs_error_procesar.txt';
                    file_put_contents(
                        $log_debug,
                        "[".date('Y-m-d H:i:s')."] ERROR DATOS: ".$e->getMessage()."\n",
                        FILE_APPEND
                    );

                    throw $e;
                }
            }
        }

        // ===============================================
        // 🟣 MODO: PROCESAR FOTOS
        // ===============================================

        if ($modo === 'procesar_fotos') {

            try {

                $resultado = procesar_imagenes_desde_excel($arrayDatosOrdenados, $idioma);

                return [
                    'type' => 'success',
                    'message' => 'Procesamiento de IMÁGENES finalizado correctamente.',
                    'resultado' => $resultado
                ];

            } catch (\Throwable $e) {

                $log = __DIR__ . '/logs/logs_error_procesar_fotos.txt';
                file_put_contents(
                    $log,
                    "[".date('Y-m-d H:i:s')."] ERROR FOTOS: ".$e->getMessage()."\n",
                    FILE_APPEND
                );

                throw $e;
            }
        }


        // ===============================================
        // ❌ Si llega aquí: error de modo
        // ===============================================
        return [
            'type' => 'error',
            'message' => 'Modo no reconocido: ' . $modo
        ];

        

    } catch (\Throwable $e) {
        $fecha = date('Y-m-d H:i:s');
        $detalle = "💥 [GLOBAL CATCH] {$fecha}\n".
                   "Mensaje: {$e->getMessage()}\n".
                   "Archivo: {$e->getFile()}\n".
                   "Línea: {$e->getLine()}\n".
                   "Traza:\n{$e->getTraceAsString()}\n\n";
        file_put_contents(__DIR__ . '/logs_error_global.txt', $detalle, FILE_APPEND);
        return new \WP_Error('sync_error', "🛑 Error global durante la sincronización: {$detalle}");
    }
}



