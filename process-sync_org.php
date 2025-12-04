<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Asegurarse de que WordPress esté cargado
defined('ABSPATH') || exit;
ini_set('max_execution_time',7000);
set_time_limit(0);
ini_set('memory_limit', '2048M');


function escribir_log_debug($mensaje) {
    $log_file = __DIR__ . '/debug_sync_log.txt'; // Puedes cambiar el nombre y la ruta si quieres
    $fecha = date('Y-m-d H:i:s');
    $mensaje_final = "[$fecha] $mensaje" . PHP_EOL;

    file_put_contents($log_file, $mensaje_final, FILE_APPEND);
}

   

/* function list_product_categories_with_subcategories() {
    // Función recursiva para mostrar las categorías y subcategorías con indentación
    function display_category_hierarchy($parent_id = 0, $indent = 0) {
        // Configurar los argumentos para obtener las categorías
        $args = array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'parent' => $parent_id
        );

        // Obtener las categorías
        $categories = get_terms($args);

        if (!empty($categories) && !is_wp_error($categories)) {
            echo '<ul>';
            foreach ($categories as $category) {
                echo '<li>' . str_repeat('&nbsp;', $indent * 8) . $category->name . ' -> ' . $category->slug . '</li>';

                // Llamada recursiva para mostrar las subcategorías
                display_category_hierarchy($category->term_id, $indent + 1);
            }
            echo '</ul>';
        }
    }

    // Iniciar la jerarquía desde las categorías principales (parent_id = 0)
    display_category_hierarchy();

    // Terminar el script después de la ejecución
    wp_die();
} */


 // Incluir la librería PhpSpreadsheet




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

function get_product_id_by_sku_and_language_interface($sku, $lang) {
    
    
    $product_id = wc_get_product_id_by_sku($sku);
    if (!$product_id) {
        return null; 
    }

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



function wpml_set_element_language_details_interface($term_id, $term_id_trid, $type, $type_trid, $lang, $source_lang = null) {
    do_action('wpml_set_element_language_details_interface', array(
        'element_id' => $term_id,
        'element_type' => $type,
        'trid' => wpml_get_content_trid($type_trid, $term_id_trid),
        'language_code' => $lang,
        'source_language_code' => $source_lang
    ));
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

// Función para leer un archivo Excel y convertirlo en un array
function read_excel_to_array_interface() {
    


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

        return 'Imagen adjuntada exitosamente.';
    }

    $local_dir = ABSPATH.'/pics/';
    $fileNames = scandir(ABSPATH.'/pics/');
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
                var_dump(attach_image_to_product_by_sku($sku, 'https://pruebas.copele.com/pics/'.$img));
            }
        }

    }

    // Obtener todos los archivos en el directorio local
    $files = glob($local_dir . '*'); 

    // Eliminar cada archivo
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file); // Eliminar archivo
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
       

    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();

    // Tell the reader to only read the data. Ignore formatting etc.
    $reader->setReadDataOnly(true);

    // Read the spreadsheet file.
    $spreadsheet = $reader->load($file_path);

    $sheet = $spreadsheet->getSheet($spreadsheet->getFirstSheetIndex());
    $data = $sheet->toArray();

    // output the data to the console, so you can see what there is.

    $indices = array_values($data[1]);
    array_shift($data);
    $cabeceras = $indices;
      

    foreach($indices as $key => $val) {
        if (!$val) {
            unset($indices[$key]);
        }
    }


    $sortedArr = [];

    foreach($data as $key1 => $dat) {

        if (( $dat[0] == explode('-',$dat[7])[0] and $dat[8] ) or ($dat[7] == '' /* and $dat[8] */)) {
            $type = 'parent';
        } else {
            $type = 'child';
        }

        foreach($dat as $key2 => $value) {
            $sortedArr[$type][$key1][$cabeceras[$key2]] = $value;
        }


            // Aquí añadimos la lógica que pides
        if (substr($dat[0], 0, 2) === '94') {
        // si "padre" == 1
            // obtengo codigos_asociados y los convierto en array
            $codigos = explode('-', $dat[7]);

            // sku base sin idioma
            $sku_base = $dat[0]; 

            foreach (['es', 'en', 'fr'] as $lang) {

                $product_id = get_product_id_by_sku_and_language_interface($sku_base . '-' . $lang, $lang);



                if ( $product_id ) {
                    update_post_meta( $product_id, 'bodegon', $codigos );
escribir_log_debug("🧩 Claves encontradas en fila 0 despues de update post meta  : {$sku_base}");
                    //return new \WP_Error('bodegon_update_success', "✅ Se actualizó 'bodegon' en producto SKU {$sku_base}-$lang (ID $product_id) con: " . implode(',', $codigos));

                } else {
                     //return new \WP_Error('bodegon_update_not_found', "⚠️ No se encontró producto para SKU {$sku_base}-$lang para actualizar 'bodegon'");

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


    
    foreach ($sortedArr as $key0 => $baseArr) {
        if (!is_array($baseArr)) {
            return new \WP_Error('fatal_baseArr_not_array', '🔴 $baseArr no es un array. Valor: ' . print_r($baseArr, true));
        }

        if (empty($baseArr)) {
            return new \WP_Error('fatal_baseArr_empty', '🟡 $baseArr está vacío. key0=' . $key0);
        }

        foreach ($baseArr as $i => $arr) {

            if ($i == 0) {
                escribir_log_debug("🧩 Claves encontradas en fila 0: " . print_r(array_keys($arr), true));
            }
            

            
            $iField = 1;
            $baseIndex = [];
            
            escribir_log_debug("🔁 Comienza procesamiento de key0={$key0}");

            foreach($arr as $key => $val) {
           
                if (in_array($iField, array_keys($indices))) {
                     
                    $baseIndex = [
                        'index' => $iField - 1, 
                        'num' => $filters_num[$indices[$iField]], 
                        'val' => $indices[$iField]];
    
                    }
                

                if ($baseIndex) {



                    if ($iField > $baseIndex['index'] and $iField < ($baseIndex['index'] + $baseIndex['num'])) {



                        if ($baseIndex['val'] == 'Filtros Subcategoría' or $baseIndex['val'] == 'Combinaciones Producto') {

                            $valor = '';

                            if (in_array($key, ['otro_capacidad', 'otro_modelo'])) {
                                $iField++;
                                continue;
                            }

                            $attribute_id = wc_attribute_taxonomy_id_by_name('pa_'.$key);




                            if (!$attribute_id) {
                                $attribute_args = array(
                                    'name' => $key,
                                    'slug' => sanitize_title($key),
                                    'type' => 'select',
                                    'order_by' => 'menu_order',
                                    'has_archives' => true,
                                );
                                $attribute_id = wc_create_attribute($attribute_args);
                            }

                            if ($key == 'capacidad' or $key == 'modelo') {
                                if (isset($arr[$key]) and isset($arr['otro_'.$key])) {
                                    $valor = $arr[$key] .' '. $arr['otro_'.$key];
                                } elseif(isset($arr[$key])) {
                                    $valor = $arr[$key];
                                } elseif (isset($arr['otro_'.$key])) {
                                    $valor = $arr['otro_'.$key];
                                } else {
                                    $valor = '';
                                }
                            }


                            $defVal = $valor ? trim($valor) : trim($val);



                            // Si está vacío, NO hacemos nada y seguimos
                            if (empty($defVal)) {
                                $iField++;
                                continue;
                            }

                            

                            $term_exists = get_term_by('slug', sanitize_title($defVal), 'pa_'.$key);
                             
                            if (!$term_exists) {
                                $new_term_arr = [];

                                foreach (['es', 'en', 'fr'] as $lang) {

                                    if (!taxonomy_exists(wc_attribute_taxonomy_name($key))) {
                                        register_taxonomy(wc_attribute_taxonomy_name($key), 'product');
                                    }

                                    if ($lang == 'es') {
                                        $new_term = insert_new_term_interface($defVal, $key);

                                        if (!is_wp_error($new_term)) {
                                            wpml_set_element_language_details_interface($new_term['term_id'], $new_term['term_id'], 'tax_'.wc_attribute_taxonomy_name($key), 'tax_'.wc_attribute_taxonomy_name($key), 'es');

                                            $new_term_arr[$lang]['id'] = $new_term['term_id'];
                                            $new_term_arr[$lang]['slug'] = sanitize_title($defVal);
                                        }

                                    } else {
                                        $new_term = insert_new_term_interface($defVal, $key, $lang);

                                        if (!is_wp_error($new_term)) {
                                            wpml_set_element_language_details_interface($new_term['term_id'], $new_term_arr['es']['id'], 'tax_'.wc_attribute_taxonomy_name($key), 'tax_'.wc_attribute_taxonomy_name($key), $lang, 'es');

                                            $new_term_arr[$lang]['id'] = $new_term['term_id'];
                                            $new_term_arr[$lang]['slug'] = sanitize_title($defVal).'-'.$lang;
                                        }
                                    }

                                    wp_cache_flush();
                                }
                            }

                            // Ahora SIEMPRE buscar el término en cada idioma
                            foreach (['es', 'en', 'fr'] as $lang) {

                            $slug = $lang === 'es' ? sanitize_title($defVal) : sanitize_title($defVal) . '-' . $lang;


                            $term = get_term_by('slug', $slug, 'pa_' . $key);

                            try {
                                // Validamos term
                                if (!isset($term) || !is_object($term) || !isset($term->term_id)) {
                                    throw new \Exception("❌ \$term no válido. key={$key}, defVal={$defVal}");
                                }

                                // Validamos codigos_asociados
                                if (empty($arr['codigos_asociados']) || !is_string($arr['codigos_asociados'])) {
                                    escribir_log_debug("⚠️ codigos_asociados vacío o inválido. ref=" . (isset($arr['referencia']) ? $arr['referencia'] : 'NO_REF'));
                                    continue;
                                }

                                $parent_parts = explode('-', $arr['codigos_asociados']);

                                if (empty($parent_parts[0])) {
                                    throw new \Exception("❌ Primer valor en codigos_asociados vacío. ref=" . $arr['referencia']);
                                }

                                $parent = $parent_parts[0];

                                // Validamos referencia
                                if (empty($arr['referencia']) || !is_scalar($arr['referencia'])) {
                                  escribir_log_debug("❌ Fila con referencia inválida o vacía en línea $i. Array: " . print_r($arr, true));
                                    continue;
                                }

                                // Creamos objeto WC_Product_Attribute
                                if (!class_exists('WC_Product_Attribute')) {
                                    include_once WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-product-attribute.php';
                                }

                                $attribute = new WC_Product_Attribute();
                                $attribute->set_id($attribute_id);
                                $attribute->set_name('pa_' . $key);
                                $attribute->set_options([$term->term_id]);
                                $attribute->set_visible(true);
                                $attribute->set_variation(true);

                                escribir_log_debug("✅ Objeto atributo creado y configurado. ref={$arr['referencia']} | key={$key} | parent={$parent}");

                                // Aseguramos que los índices existen
                                if (!isset($attr_arr['child'][$lang])) {
                                    $attr_arr['child'][$lang] = [];
                                }

                                if (!isset($attr_arr['child'][$lang][$arr['referencia']])) {
                                    $attr_arr['child'][$lang][$arr['referencia']] = [];
                                }

                                $attr_arr['child'][$lang][$arr['referencia']]['pa_' . $key] = $term->slug;

                                // Aseguramos que el array padre existe
                                if (!isset($attr_arr['parent'][$parent]) || !is_array($attr_arr['parent'][$parent])) {
                                    $attr_arr['parent'][$parent] = [];
                                }

                                $hay = false;

                                foreach ($attr_arr['parent'][$parent] as &$att) {
                                    // Validamos estructura del atributo
                                    if (!is_object($att) || !method_exists($att, 'get_options')) {
                                        continue;
                                    }

                                    if (!isset($att->options) || !is_array($att->options)) {
                                        $att->options = [];
                                    }

                                    if ($att->get_name() === 'pa_' . $key) {
                                        $hay = true;

                                        if (!in_array((int)$term->term_id, array_map('intval', $att->get_options()), true)) {
                                            $att->set_options(array_merge($att->get_options(), [$term->term_id]));
                                        }
                                    }
                                }

                                if (!$hay) {
                                    $attr_arr['parent'][$parent][] = $attribute;
                                    escribir_log_debug("➕ Atributo añadido a parent={$parent}, ref={$arr['referencia']}, key=pa_{$key}");
                                }

                                wp_cache_flush();
                            } catch (\Throwable $e) {
                                escribir_log_debug("❌ Error en bloque atributos: " . $e->getMessage());
                                return new \WP_Error('fatal_attr_block', '❌ Error en bloque de atributos: ' . $e->getMessage());
                            }

                        }





                        } elseif ($baseIndex['val'] == 'Características técnicas y logísticas' || $baseIndex['val'] == 'Puntos descripción larga') {

                              

                            if (!isset($key0) || !is_scalar($key0)) {
                                return new \WP_Error('fatal_key0', '🔴 $key0 no está definido o no es escalar. Valor: ' . print_r($key0, true));
                            }

                            // Validamos $key
                            if (!isset($key) || !is_scalar($key)) {
                                return new \WP_Error('fatal_key', '🔴 $key no está definido o no es escalar. Valor: ' . print_r($key, true));
                            }

                            // Validamos $arr['referencia']
                            if (!isset($arr['referencia']) || !is_scalar($arr['referencia'])) {
                                return new \WP_Error('fatal_referencia', '🔴 $arr["referencia"] no está definido o no es escalar. Array actual: ' . print_r($arr, true));
                            }

                            // Validamos que exista la clave $key en $arr antes de usar $val
                            if (!array_key_exists($key, $arr)) {
                                return new \WP_Error('fatal_val_missing', '🔴 La clave "' . $key . '" no existe en $arr. Array actual: ' . print_r($arr, true));
                            }

                            $val = $arr[$key];

                            // Validamos que $val sea escalar o null (no arrays ni objetos)
                            if (!is_scalar($val) && !is_null($val)) {
                                return new \WP_Error('fatal_val_invalid', '🔴 $val no es escalar ni null. Tipo: ' . gettype($val) . ' | Contenido: ' . print_r($val, true));
                            }

                            // Inicialización segura del array
                            if (!isset($caracteristicas_arr[$key0]) || !is_array($caracteristicas_arr[$key0])) {
                                $caracteristicas_arr[$key0] = [];
                            }

                            if (!isset($caracteristicas_arr[$key0][$arr['referencia']]) || !is_array($caracteristicas_arr[$key0][$arr['referencia']])) {
                                $caracteristicas_arr[$key0][$arr['referencia']] = [];
                            }

                            // Asignación final
                            try {

                              

                                if (strpos($key, 'pa_') === 0 && trim($val) !== '') {

                                   
                                    $caracteristicas_arr[$key0][$arr['referencia']][$key] = $val;
                                
                                }

                                
                                

                                //return new \WP_Error('fatal_asignacion', '🔴 Excepción al asignar valor. Mensaje: ' . print_r($caracteristicas_arr));
                                 escribir_log_debug("despues de asignar a caracteristicas_arr ");

                            } catch (\Throwable $e) {
                                return new \WP_Error('fatal_asignacion', '🔴 Excepción al asignar valor. Mensaje: ' . print_r($caracteristicas_arr, true));

                            }

                             
                        }
                    }
                }
                
                $iField++;
                    $ref_debug = (!empty($arr['referencia']) && is_scalar($arr['referencia'])) ? $arr['referencia'] : 'NO_REF';

                    if ($ref_debug === 'referencia' || strtoupper($ref_debug) === 'REF') {
                        escribir_log_debug("⚠️ Saltando fila porque contiene cabecera en ref=$ref_debug (i=$i, key0=$key0)");
                        continue;
                    }
                    escribir_log_debug("✔ Final iteración arr. key0=$key0, i=$i, ref=$ref_debug");


            }
             escribir_log_debug("🔁 finaliza  procesamiento de key0={$key0}");
            
        }
        escribir_log_debug("fin de todo  ");

           
    }


    escribir_log_debug("despues fuera del bucle  ");
    
    krsort($sortedArr);
    $addedprods = [];
    $addedvars = [];
    

    $ii = 0;
    $hecho = false;
    foreach ($sortedArr as $key1 => $baseArr) {
        $key0=$key1;
        foreach($baseArr as $i => $arr) {

            if (empty(trim($arr['referencia']))) {
                // Saltamos esta fila porque no tiene referencia
                continue;
            }

            if ($key0 === 'child' && (empty($arr['codigos_asociados']) || !str_contains($arr['codigos_asociados'], '-'))) {
                escribir_log_debug("⚠️ Saltando producto child sin codigos_asociados válidos. i=$i, ref={$arr['referencia']}");
                continue;
            }

            foreach ($langs as $lang) {
                
                $iField = 0;
                $baseIndex = [];
                // Comprobar si ya existe el producto en el idioma correspondiente
                $productExists = get_product_id_by_sku_and_language_interface($arr['referencia'].'-'.$lang, $lang);
                escribir_log_debug("🔍 Resultado de búsqueda SKU={$arr['referencia']}-{$lang} → ". print_r($productExists, true));

                if ($productExists) {
                    // Solo mostrar mensaje de traza (si quieres ver qué pasa)
                    escribir_log_debug("✅ Producto ya existente");
                    continue;
                }else {
                    escribir_log_debug("🔍producto no existente SKU={$arr['referencia']}-{$lang} →  y es de tipo {$key0} ". print_r($arr['codigos_asociados'], true));

                    // Determinar tipo de producto
                    if ($arr['codigos_asociados'] and $key0 == 'parent') {
                        $new_product = new WC_Product_Variable();

                         escribir_log_debug("🔍 Creación de prodcuto de tipo variable ");
                    } elseif (!$arr['codigos_asociados'] and $key0 == 'parent') {
                        $new_product = new WC_Product_Simple();
                         escribir_log_debug("🔍 Creación de prodcuto de tipo simple ");
                    } elseif ($arr['codigos_asociados'] and $key0 == 'child') {
                        $new_product = new WC_Product_Variation();
                         escribir_log_debug("🔍 Creación de prodcuto de tipo variacion ");
                    } else {
                        escribir_log_debug("No se pudo determinar el tipo de producto para la referencia: {$arr['referencia']} LANG: $lang");
                    }

                    // Preparar el SKU
                    $sku_final = trim($arr['referencia']);
                    if (empty($sku_final)) {
                        escribir_log_debug("SKU vacío o no válido en producto LANG: $lang");
                    }

                    $sku_final_lang = $sku_final . '-' . strtoupper($lang);

                    // Verificar si el SKU ya existe (control de duplicado)
                    $existing_id = wc_get_product_id_by_sku($sku_final_lang);

                    if ($existing_id) {
                        // Logueamos para trazabilidad
                        escribir_log_debug("⚠️ SKU duplicado detectado: $sku_final_lang → ID existente: $existing_id");

                        // En vez de lanzar excepción, lo ignoramos y pasamos al siguiente
                        continue;
                    }

                    // Si el producto es válido, le asignamos el SKU
                    if ($new_product && is_object($new_product)) {
                        $new_product->set_sku($sku_final_lang);
                    } else {
                        escribir_log_debug("Error: No se pudo inicializar el objeto WC_Product para referencia: {$arr['referencia']} LANG: $lang");
                    }
                }

                    
                if ($key0 == 'parent') {

                    if ($arr['filtros_descripcion_'.$lang]) {
                        $new_product->set_name($arr['filtros_descripcion_'.$lang]);
                    } else {
                        $new_product->set_name($arr['descripcion_'.$lang]);
                    }
                        
                    $new_product->set_description($arr['descripcion_larga_'.$lang]);
                        
                    if ($arr['subcategoria']) {
                        $subcatArr = explode(' ', $arr['subcategoria']);
                            
                        foreach($subcatArr as $mainSubcat) {

                            if ($mainSubcat) {
                               $parts = explode('_', $mainSubcat);
                                $categoria = $parts[0] ?? '';
                                $subcategorias = isset($parts[1]) ? explode('/', $parts[1]) : [];
                                $subcategorias2 = isset($parts[2]) ? explode('/', $parts[2]) : [];
                            } else {
                                continue;
                            }

                            $cats_arr = [];
                            $cart_arr_names = [];
                    
                            if ($categoria) {
                                $categoria = $lang == 'es' ? $categoria : $categoria.'-'.$lang;

                                $category_id = get_category_id_by_slug($categoria);
                
                                if ($category_id) {
                                    $cats_arr[] = $category_id;
                                    $cart_arr_names[] = $categoria;
                                    
                                }else{
                                    $category_slug = $lang == 'es' ? $categoria : $categoria.'-'.$lang;
                                    $category_name =$categoria;



                                    $id_new_category = wp_insert_term($category_name, 'product_cat', array(
                                        'slug' => $category_slug ,
                                    ));

                                    $cats_arr[] = $id_new_category;
                                    $cart_arr_names[] = $category_slug;



                                }
                                
                            }
                    
                            if ($subcategorias) {
                
                                foreach($subcategorias as $subcat) {
                                    if ($subcat) {
                                        $subcat = $lang == 'es' ? $subcat : $subcat.'-'.$lang;

                                        $subcategory_id = get_subcategory_id_by_slug($subcat);
                                    
                                        if ($subcategory_id) {
                                            $cats_arr[] = $subcategory_id;
                                            $cart_arr_names[] = $subcat;
                                        }
                                    }
                                }
                
                            }else{

                                    $subcat_slug = $lang == 'es' ? $subcat : $subcat.'-'.$lang;
                                    $subcat_name =$subcat;



                                    $idNewSubCategory = wp_insert_term($subcat_name, 'product_cat', array(
                                        'slug' => $subcat_slug ,
                                    ));

                                    if (!is_wp_error($idNewSubCategory)) {
                                        $cats_arr[] = $idNewSubCategory['term_id'];
                                        $cart_arr_names[] = $subcat_slug;
                                    }

                                   
                            }
                
                            if ($subcategorias2) {
                
                                foreach($subcategorias2 as $subcat) {
                                    if ($subcat) {
                                        $subcat = $lang == 'es' ? $subcat : $subcat.'-'.$lang;

                                        $subcategory_id = get_subcategory_id_by_slug($subcat);
                                    
                                        if ($subcategory_id) {
                                            $cats_arr[] = $subcategory_id;
                                            $cart_arr_names[] = $subcat;
                                        }
                                    }else{


                                        $subcat2_slug = $lang == 'es' ? $subcat : $subcat.'-'.$lang;
                                        $subcat2_name =$subcat;



                                        $idNewSubCategory2 = wp_insert_term($subcat2_name, 'product_cat', array(
                                            'slug' => $subcat2_slug ,
                                        ));

                                        if (!is_wp_error($idNewSubCategory2)) {

                                            $cats_arr[] = $idNewSubCategory2;
                                            $cart_arr_names[] = $subcat2_slug;
                                        }



                                        


                                    }
                                }
                
                            }


                                // asignación de categorías visible_en

                            $visible_en = [];

                                
                              

                            /*foreach ($cart_arr_names as $category_string) {
                                $parts = explode('_', $category_string, 2); // Separa en dos partes máximo
                                
                                if (count($parts) == 2) {
                                    $categoria = $parts[0]; 
                                    $subcategoria_part = $parts[1]; // Todo lo que viene después de '_'
                            
                                    // Verificar si hay varias subcategorías separadas por "/"
                                    if (strpos($subcategoria_part, '/') !== false) {
                                        $subcategorias = explode('/', $subcategoria_part);
                                        foreach ($subcategorias as $subcategoria) {
                                            $visible_en[] = [
                                                'categoria'    => $categoria,
                                                'subcategoria' => $subcategoria
                                            ];
                                        }
                                    } else {
                                        // Solo una subcategoría
                                        $visible_en[] = [
                                            'categoria'    => $categoria,
                                            'subcategoria' => $subcategoria_part
                                        ];
                                    }
                                }
                            }  */

                                 /* 
                                    EJEMPLO DE SALIDA ESPERADA:
                                    
                                    Si tenemos:
                                    
                                    aves_incubadoras-y-nacedoras varios_complementos_calefaccion
                                    
                                    El resultado debería ser:
                                
                                    $visible_en = [
                                        ['categoria' => 'aves', 'subcategoria' => 'incubadoras-y-nacedoras'],
                                        ['categoria' => 'varios', 'subcategoria' => 'complementos'],
                                        ['categoria' => 'varios', 'subcategoria' => 'calefaccion']
                                    ];
                                */

                                // end asignación de categorías visible_en


                        }
                    }

                        
                        
                    $new_product->set_category_ids($cats_arr);

                    $idNewProduct = $new_product->get_id();
                    //update_post_meta($idNewProduct, 'visible_en', $visible_en);

                    $ii++;
                    $result = $new_product->save();

                      escribir_log_debug("💾 Guardado producto parent : ref={$arr['referencia']} lang={$lang} → resultado: " . print_r($result, true));

                } elseif ($key0 == 'child') {
                        
                       if (!isset($addedprods[$lang]) || !is_array($addedprods[$lang]) || !in_array(explode('-', $arr['codigos_asociados'])[0], array_keys((array)$addedprods[$lang]))) continue;

                        
                        $new_product->set_name($arr['descripcion_'.$lang]);
                        $new_product->set_description($arr['descripcion_larga_'.$lang]);
                        
                        $new_product->set_parent_id( $addedprods[$lang][explode('-', $arr['codigos_asociados'])[0]] );
                    
                        $new_product->set_regular_price( 50 );
                        
                    }

                    if ($key0 == 'parent') {
                        if ($attr_arr[$key0][$arr['referencia']]) {
                            $new_product->set_attributes($attr_arr[$key0][$arr['referencia']]);
                        }
                    } else {
                        if ($attr_arr[$key0][$lang][$arr['referencia']]) {
                            $new_product->set_attributes($attr_arr[$key0][$lang][$arr['referencia']]);
                        }
                    }

                    if (!isset($arr['referencia']) || empty($arr['referencia'])) {
                        escribir_log_debug("❌ Fila sin referencia en línea $i: " . print_r($arr, true));
                        continue;
                    }
                    if (!isset($arr['codigos_asociados']) || empty($arr['codigos_asociados'])) {
                        escribir_log_debug("❌ Fila sin codigos_asociados en línea $i: ref=" . $arr['referencia']);
                        continue;
                    }
                    
                    try {


                        $result = $new_product->save();

                        escribir_log_debug("💾 Guardado producto child: ref={$arr['referencia']} lang={$lang} → resultado: " . print_r($result, true));


                        if (!$result) {
                            escribir_log_debug("❌ Fallo al guardar producto ref={$arr['referencia']} lang={$lang}. Objeto: " . print_r($new_product, true));
                            
                        }

                        if (!empty($arr['productos_complementarios'])) {
                            update_post_meta($new_product->get_id(), 'productos_complementarios', $arr['productos_complementarios']);
                        }

                        if ($key0 == 'parent') {
                            $addedprods[$lang][$arr['referencia']] = $new_product->get_id();
                            $post_type = 'post_product';
                        } else {
                            $addedvars[$lang][$arr['referencia']] = $new_product->get_id();
                            $post_type = 'post_product_variation';
                        }

                        $baseItem = $key0 == 'parent' ? $addedprods : $addedvars;

                        if ($lang != 'es') {
                            if (!$productExists && isset($baseItem[$lang][$arr['referencia']])) {
                                do_action('wpml_set_element_language_details_interface', [
                                    'element_id' => $baseItem[$lang][$arr['referencia']],
                                    'element_type' => $post_type,
                                    'trid' => wpml_get_content_trid($post_type, $baseItem['es'][$arr['referencia']]),
                                    'language_code' => $lang,
                                    'source_language_code' => 'es'
                                ]);
                            }
                        }
                    } catch (\Throwable $e) {
                            escribir_log_debug("🛑 Excepción atrapada en lang={$lang}, ref={$arr['referencia']}: " . $e->getMessage());
                            return new \WP_Error('error_general', '🛑 Excepción: ' . $e->getMessage() . ' Línea: ' . $e->getLine());

                    }
                    
                    
                    if ($key0 == 'parent') {

                         if (!isset($addedprods[$lang]) || !is_array($addedprods[$lang]) || !in_array($arr['referencia'], array_keys((array)$addedprods[$lang]))) {
                            continue;
                        }

                        $variant_sku_final = $arr['referencia'].'-VARIANT-'.strtoupper($lang);

                        // Comprobar si la variante ya existe
                        $variantExists = get_product_id_by_sku_and_language_interface($variant_sku_final, $lang);

                        if ($variantExists) {
                            $new_variation = wc_get_product($variantExists);

                            if (!$new_variation) {
                                // Variante corrupta o inválida → la tratamos como NO EXISTENTE
                                $variantExists = false;
                            }
                        }

                        if (!$variantExists) {
                            // Verificar si el SKU ya existe realmente antes de crear
                            $existing_variant_id = wc_get_product_id_by_sku($variant_sku_final);

                            if ($existing_variant_id) {
                                // Logueamos para trazabilidad
                                error_log("⚠️ SKU duplicado detectado en VARIANTE: $variant_sku_final → ID existente: $existing_variant_id");

                                // Lanzamos excepción para detener el proceso
                                throw new \Exception("SKU duplicado detectado al crear VARIANTE: $variant_sku_final (ID existente: $existing_variant_id)");
                            }

                            // Si no existe, creamos la variante
                            $new_variation = new WC_Product_Variation();
                            $new_variation->set_sku($variant_sku_final);
                        }

                        // Asignar los datos a la variante
                        $new_variation->set_name($arr['descripcion_'.$lang]);
                        $new_variation->set_description($arr['descripcion_larga_'.$lang]);
                        $new_variation->set_parent_id( $addedprods[$lang][$arr['referencia']] );
                        $new_variation->set_regular_price( 50 );

                        // Asignar atributos a la variante
                        if ($attr_arr['child'][$lang][$arr['referencia']]) {
                            $new_variation->set_attributes($attr_arr['child'][$lang][$arr['referencia']]);
                        }

                        // Guardar la variante
                        $id_new = $new_variation->save();

                        // Registrar en el array de añadidos
                        $addedvars[$lang][$arr['referencia']] = $new_variation->get_id();

                        // Si es nueva, registrar en WPML
                        if (!$variantExists) {
                            if ($lang != 'es') {

                                if ($addedvars[$lang][$arr['referencia']]) {
                                    do_action('wpml_set_element_language_details_interface', [
                                        'element_id' => $new_variation->get_id(),
                                        'element_type' => 'post_product_variation',
                                        'trid' => wpml_get_content_trid('post_product_variation', $addedvars['es'][$arr['referencia']]),
                                        'language_code' => $lang,
                                        'source_language_code' => 'es'
                                    ]);
                                }

                            }
                        }

                        // Guardar características técnicas en la variante
                        if ($caracteristicas_arr['child'][$arr['referencia']]) {
                            foreach ($caracteristicas_arr['child'][$arr['referencia']] as $k => $v) {
                                if (strpos($k, '_'.$lang) === false) continue;

                                if ($v) {
                                    update_post_meta($new_variation->get_id(), $k, $v);
                                }
                            }
                        }

                        // Guardar productos complementarios si hay
                        if ($arr['productos_complementarios']) {
                            update_post_meta($new_variation->get_id(), 'productos_complementarios', $arr['productos_complementarios']);
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


        


       wp_send_json_success([
            'type' => 'success',
            'message' => 'Sincronización finalizada correctamente.',
            'added_products' => $addedprods
        ]);
        

    } catch (\Throwable $e) {
        escribir_log_debug("🛑 Excepción global atrapada: " . $e->getMessage());
    wp_send_json_error([
        'type' => 'error',
        'message' => '🛑 Error en read_excel_to_array_interface(): ' . $e->getMessage() . ' (Línea ' . $e->getLine() . ')'
    ]);
    return;
    } 
} 