<?php
/**
 * Plugin Name: Interface Excel Reader
 * Plugin URI: https://github.com/raulmoyaaticsoft/interface-excel-reader
 * Description: Interfaz externa para subir archivos Excel y lanzar sincronización.
 * Version: 1.0.8.0
 * Author: Atic Soft
 * Author URI: https://aticsoft.com
 * License: GPL2
 * GitHub Plugin URI: raulmoyaaticsoft/interface-excel-reader
 * GitHub Branch: main
 */





defined('ABSPATH') or die('No script kiddies please!');
require_once plugin_dir_path(__FILE__) . 'sync-interface.php';
require_once plugin_dir_path(__FILE__) . 'process-sync.php';
add_action('wp_ajax_lanzar_sincronizacion_excel', 'ajax_lanzar_sincronizacion_excel');
add_action('wp_ajax_nopriv_lanzar_sincronizacion_excel', 'ajax_lanzar_sincronizacion_excel');

     

add_action('wp_enqueue_scripts', 'docriluc_cargar_js_variaciones', 20);
function docriluc_cargar_js_variaciones() {

    if (!is_product()) return;

    global $product;
    if (!$product instanceof WC_Product) return;
    if (!$product->is_type('variable')) return;

    // ✅ Ruta correcta para plugin
    $script_url  = plugins_url('assets/js/variaciones-front.js', __FILE__);
    $script_path = plugin_dir_path(__FILE__) . 'assets/js/variaciones-front.js';

    // ✅ Evita caída si el JS no existe
    if (!file_exists($script_path)) return;

    $variaciones = [];

    $available = $product->get_available_variations();
    if (!is_array($available)) $available = [];

    foreach ($available as $v) {
        $id = $v['variation_id'];
        $variaciones[$id] = [
            'attributes' => $v['attributes'],
            'price_html' => $v['price_html'] ?? '',
            'image'      => $v['image'] ?? '',
            'description'   => $variation ? $variation->get_description() : '',
        ];
    }

    wp_enqueue_script(
        'docriluc-variaciones-front',
        $script_url,
        ['jquery'],
        '1.0',
        true
    );

    wp_localize_script(
        'docriluc-variaciones-front',
        'variacionesDeProducto',
        $variaciones
    );
}




if (!function_exists('log_msg')) {
    function log_msg($msg) {
        try {
            // 📁 Ruta absoluta al directorio de logs dentro del plugin actual
            $log_dir  = __DIR__ . '/logs/';
            $log_file = $log_dir . 'logs-v3.txt';

            // Crear el directorio si no existe
            if (!file_exists($log_dir)) {
                mkdir($log_dir, 0777, true);
            }

            // Normalizar mensaje
            if (is_array($msg) || is_object($msg)) {
                $msg = print_r($msg, true);
            }

            $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;

            // Escribir en el archivo
            $written = @file_put_contents($log_file, $line, FILE_APPEND);

            // Si falla, escribir al log de PHP
            if ($written === false) {
                error_log("⚠️ No se pudo escribir en $log_file — Mensaje: " . $msg);
            }
        } catch (Throwable $e) {
            error_log("❌ Error en log_msg(): " . $e->getMessage());
        }
    }
}


add_action('wp_ajax_interface_excel_reader_get_progress', function() {
    $progress = get_option('interface_excel_reader_progress', [
        'total' => 1,
        'current' => 0,
        'start_time' => time(),
    ]);

    wp_send_json_success($progress);
});



function permitir_mime_excel($mimes) {
    $mimes['xlsx'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $mimes['xls']  = 'application/vnd.ms-excel';
    return $mimes;
}
add_filter('upload_mimes', 'permitir_mime_excel');

// Crear carpeta de uploads al activar el plugin
register_activation_hook(__FILE__, function () {
    $upload_dir = plugin_dir_path(__FILE__) . 'uploads';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $upload_dir2 = plugin_dir_path(__FILE__) . 'uploads/last-update';
    if (!file_exists($upload_dir2)) {
        mkdir($upload_dir2, 0755, true);
    }
});

// Encolar CSS y JS del plugin
function interface_excel_enqueue_assets() {
     if (is_page_template('sync-excel-template.php')) {
        $plugin_url = plugin_dir_url(__FILE__);
        wp_enqueue_style('interface_excel_style', $plugin_url . 'assets/css/style.css');

        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], null, true);

        wp_enqueue_script('interface_excel_script', $plugin_url . 'assets/js/script.js', [], false, true);

        wp_localize_script('interface_excel_script', 'ajax_object', [
            'ajax_url' => admin_url('admin-ajax.php')
        ]);
    }
}
add_action('wp_enqueue_scripts', 'interface_excel_enqueue_assets');

// Shortcode
function interface_excel_reader_shortcode() {
    $upload_dir = __DIR__ . '/uploads/';
    $upload_dir2 = __DIR__ . '/uploads/last-update/';
    $log_txt = $upload_dir . 'sync-records.txt';
    $log_general = $upload_dir . 'sync.log';
    $lock_file = $upload_dir . 'sync.lock';
    $message = '';
    $message_type = '';
    


    $last_info = '';
    if (file_exists($log_txt)) {
        $lines = file($log_txt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $last_upload = '';
        $last_sync = '';

        foreach (array_reverse($lines) as $line) {
            if (str_contains($line, 'Archivo guardado')) {
                $last_upload = $line;
            } elseif (str_contains($line, 'Sincronización ejecutada')) {
                $last_sync = $line;
                break;
            }
        }

        if ($last_upload) {
            preg_match('/\[(.*?)\] Archivo guardado: (.*?) => (.*?)$/', $last_upload, $match);
            $fecha_hora_raw = $match[1] ?? '';
            $fecha_hora = '';

            if (!empty($fecha_hora_raw)) {
                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fecha_hora_raw);
                if ($dt) {
                    $fecha_hora = $dt->format('d-m-Y');
                }
            }

            $nombre_original = $match[2] ?? '';
            $nombre_backup = $match[3] ?? '';
            $sync_ejecutada = ($last_sync && str_contains($last_sync, $nombre_backup)) ? 'Sí' : 'No';

            $last_info = "<div class='last-upload-box elementor-column-gap-default'>
                <div class='seccion_datos elementor-col-70'>
                    <strong>Último archivo subido:</strong><br>
                    Nombre original: <code>$nombre_original</code><br>
                    Fecha: <code>$fecha_hora</code><br>
                    ¿Sincronización ejecutada?: <strong>$sync_ejecutada</strong>
                </div>
                <div class='seccion_fichero elementor-col-30'></div>


            </div>";
        }
    }

   if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['interface_excel_nonce']) && wp_verify_nonce($_POST['interface_excel_nonce'], 'interface_excel_action')) {



        // Verificar si ya hay una sincronización en curso
        if (file_exists($lock_file)) {
            // Mostrar mensaje si ya hay una sincronización en curso
            echo "<script>
                document.addEventListener('DOMContentLoaded', function () {
                    Swal.fire({
                        title: 'Sincronización en curso',
                        text: 'Ya hay una sincronización ejecutándose. Espera a que finalice antes de lanzar otra.',
                        icon: 'warning',
                        confirmButtonText: 'Aceptar'
                    });
                });
            </script>";
        } else {
            // Validar si el archivo ha sido subido
            if (empty($_FILES['excel_file']['name'])) {
                echo "<script>
                    document.addEventListener('DOMContentLoaded', function () {
                        Swal.fire({
                            title: 'Error',
                            text: 'Por favor, selecciona un archivo Excel antes de proceder.',
                            icon: 'error',
                            confirmButtonText: 'Aceptar'
                        });
                    });
                </script>";
            } else {
                // Subir el archivo
                $filename_original = $_FILES['excel_file']['name'];
                $filename_para_back = $_FILES['excel_file']['name'];

                $backup_name = basename($filename_original);
                $backup_path = $upload_dir . $backup_name;

                // Validar la extensión
                $allowed_extensions = ['xls', 'xlsx'];
                $extension = pathinfo($filename_original, PATHINFO_EXTENSION);

                if (!in_array(strtolower($extension), $allowed_extensions)) {
                    echo "<script>
                        document.addEventListener('DOMContentLoaded', function () {
                            Swal.fire({
                                title: 'Error',
                                text: 'Formato de archivo no permitido. Solo se permiten archivos .xls o .xlsx.',
                                icon: 'error',
                                confirmButtonText: 'Aceptar'
                            });
                        });
                    </script>";
                } elseif ($_FILES['excel_file']['size'] > 5 * 1024 * 1024) {
                    // Validar el tamaño del archivo
                    echo "<script>
                        document.addEventListener('DOMContentLoaded', function () {
                            Swal.fire({
                                title: 'Error',
                                text: 'El archivo es demasiado grande. El tamaño máximo permitido es 5MB.',
                                icon: 'error',
                                confirmButtonText: 'Aceptar'
                            });
                        });
                    </script>";
                } else {
                    // Guardar el archivo en el directorio del plugin
                    $destino_actual = $upload_dir . 'ultimo.xlsx';
                    update_option('url-excel-sincro', $destino_actual);

                    if (move_uploaded_file($_FILES['excel_file']['tmp_name'], $backup_path)) {
                        
                        // Registrar la subida en el log
                        $upload_time = date('Y-m-d H:i:s');
                        file_put_contents($log_txt, "[{$upload_time}] Archivo guardado: {$filename_original} => {$backup_name}\n", FILE_APPEND);

                        // Actualizar el mensaje en el frontend
                        echo "<script>
                            document.addEventListener('DOMContentLoaded', function () {
                                Swal.fire({
                                    title: 'Archivo guardado',
                                    text: 'El archivo se ha guardado correctamente. Puedes ejecutarlo más tarde desde esta misma página.',
                                    icon: 'success',
                                    confirmButtonText: 'Aceptar'
                                });
                            });
                        </script>";

                        // Comprobar si solo se quiere guardar o ejecutar la sincronización
                        if (isset($_POST['guardar_solo'])) {
                            // Solo guardar sin ejecutar sincronización
                           
                            file_put_contents($log_general, "{$upload_time} - Archivo guardado sin ejecutar sincronización: {$backup_name}\n", FILE_APPEND);

                         
                        } else {
                            // Ejecutar sincronización
                            file_put_contents($lock_file, time()); // Crear archivo .lock

                            file_put_contents($log_general, "{$upload_time} - Lanzando sincronización desde interfaz externa\n", FILE_APPEND);
                            ob_start();

                            try {
                                file_put_contents(__DIR__ . '/debug_log.txt', "[" . date('H:i:s') . "] Voy a llamar a read_excel_to_array\n", FILE_APPEND);

                                $response = read_excel_to_array_interface();

                                
                                if (!is_wp_error($response)) {
                                    
                                }



                                $sync_time = date('Y-m-d H:i:s');
                                file_put_contents($log_txt, "[{$sync_time}] Sincronización ejecutada con: {$backup_name}\n\n", FILE_APPEND);

                                echo "<script>
                                    document.addEventListener('DOMContentLoaded', function () {
                                        Swal.fire({
                                            title: 'Sincronización exitosa',
                                            html: 'Archivo subido y sincronización ejecutada correctamente.',
                                            icon: 'success',
                                            confirmButtonText: 'Aceptar'
                                        });
                                    });
                                </script>";
                            } catch (Throwable $e) {
                                // Manejar errores de sincronización
                                $error_message = $e->getMessage();
                               file_put_contents($log_general, "ERROR: $error_message\n");


                                echo "<script>
                                    document.addEventListener('DOMContentLoaded', function () {
                                        Swal.fire({
                                            title: 'Error en la sincronización',
                                            text: '" . htmlspecialchars($error_message) . "',
                                            icon: 'error',
                                            confirmButtonText: 'Aceptar'
                                        });
                                    });
                                </script>";
                            } finally {
                                // Eliminar archivo .lock al finalizar
                                if (file_exists($lock_file)) {
                                    unlink($lock_file);
                                }
                            }
                        }
                    } else {
                        // Error al guardar el archivo
                        echo "<script>
                            document.addEventListener('DOMContentLoaded', function () {
                                Swal.fire({
                                    title: 'Atención',
                                    text: 'Revise el fichero.. hay un error en una de las filas',
                                    icon: 'success',
                                    confirmButtonText: 'Aceptar'
                                });
                            });
                        </script>";
                    }
                }
            }
        }
    }

    ob_start(); 

   


    ?>
   

    <div class="container">
        <?php if ( is_user_logged_in() ) : ?>

            <span style="float: right;"><a href="<?php echo wp_logout_url( home_url() ); ?>">Cerrar sesión</a></span>
        <?php endif; ?>

        <h2 class="texto-cabecera">Subir Excel para sincronización</h2>
        <div class="contenedor">
            <div class="mini-preloader">
              <div class="loader-dots">
                <span></span><span></span><span></span>
              </div>
            </div>
        </div>

        <div  class="box-last-update" id="last-upload-info">
            
        </div>

        <div class="alert d-none" id="form-message"></div>

        <form method="post" enctype="multipart/form-data" id="excel_form">
            <?php wp_nonce_field('interface_excel_action', 'interface_excel_nonce'); ?>
            <input type="file" id="excel_file" name="excel_file" accept=".xls,.xlsx" required>
              <button type="button" name="ejecutar_sync" id="submit_button" >Ejecutar sincronización</button>
              <button type="button" name="guardar_solo" id="guardar_button" >Guardar fichero</button>
        </form>
    </div>

    <style>
        .last-upload-box {
            background: #f0f8ff;
            padding: 15px;
            border-left: 4px solid #007cba;
            margin-bottom: 20px;
            font-size: 15px;
            line-height: 1.5;
        }
        .last-upload-box code {
            background: #eee;
            padding: 2px 4px;
            border-radius: 3px;
        }
    </style>

    <?php return ob_get_clean();
}
add_shortcode('sincronizacion_excel', 'interface_excel_reader_shortcode');



add_action('wp_ajax_get_last_upload_info', 'interface_excel_get_last_upload_info');
add_action('wp_ajax_nopriv_get_last_upload_info', 'interface_excel_get_last_upload_info');

function interface_excel_get_last_upload_info() {
    $upload_dir = __DIR__ . '/uploads/';
    $log_txt = $upload_dir . 'sync-records.txt';
    $nombre_backup="";
    $last_info = '';


    if (file_exists($log_txt)) {
        $lines = file($log_txt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $last_upload = '';
        $last_sync = '';

        foreach (array_reverse($lines) as $line) {
            if (str_contains($line, 'Archivo subido')) {  // Cambié el texto para que coincida con lo que tienes en tu log
                $last_upload = $line;
            } elseif (str_contains($line, 'Sincronización ejecutada')) {
                $last_sync = $line;
                break;
            }
        }

        if ($last_upload) {
            // Usamos una expresión regular para extraer la fecha, el nombre original y el nombre de backup
            preg_match('/\[(.*?)\] Archivo subido: (.*?) => (.*?)$/', $last_upload, $match);
            $fecha_hora_raw = $match[1] ?? '';
            $fecha_hora = '';

            if (!empty($fecha_hora_raw)) {
                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fecha_hora_raw);
                if ($dt) {
                    $fecha = $dt->format('d-m-Y');
                    $hora=$dt->format(' H:i');
                }
            }

            $nombre_original = $match[2] ?? '';
            $nombre_backup = $match[3] ?? '';
            $sync_ejecutada = ($last_sync && str_contains($last_sync, $nombre_backup)) ? 'Sí' : 'No';
            
        }

        if($last_sync){
            preg_match('/Sincronización ejecutada con: (.*?)$/', $last_sync, $match_sync);
            $nombre_original = $match_sync[1] ?? '';
           preg_match('/\[(.*?)\] Sincronización ejecutada con: (.*?)$/', $last_sync, $match_sync);
            $fecha_hora_sync = $match_sync[1] ?? '';
            
            $sync_backup = $match_sync[2] ?? '';
            if (!$nombre_backup) {
                $nombre_backup = $sync_backup;
            }
             $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fecha_hora_sync);
             $fecha = $dt->format('d-m-Y');
             $hora = $dt->format('H:i');
            $sync_ejecutada = ($last_sync && str_contains($last_sync, $nombre_backup)) ? 'Sí' : 'No';
        }
    }

    echo "<div class='last-upload-box' style='display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem; border: 1px solid #ccc; border-radius: 8px; background: #f9f9f9; margin-top: 2rem;'>
                <div style='flex: 0 0 80%;'>
                    <strong>Último archivo subido:</strong><br>
                    Nombre fichero: <code>$nombre_original</code><br>
                    Fecha: <code>$fecha a las $hora</code><br>
                    ¿Sincronización ejecutada?: <strong>$sync_ejecutada</strong>
                </div>
            </div>";

            $is_ready = ($sync_ejecutada === 'No') ? 'true' : 'false';

        echo "<script>
            window.excelSyncReady = $is_ready;
        </script>";

            wp_die();
        }


add_action('wp_ajax_check_if_sync_in_progress', 'check_if_sync_in_progress');
add_action('wp_ajax_nopriv_check_if_sync_in_progress', 'check_if_sync_in_progress');


function check_if_sync_in_progress(){

    $plugin_dir = plugin_dir_path(__FILE__);  
    
    $lock_file = $plugin_dir . 'uploads/sync.lock';

    if (file_exists($lock_file)) {
        $existe= 'lock_exists';
    }else {
        $existe='no_existe';
    }
    echo $existe;
    wp_die();

}







add_action('wp_ajax_interface_excel_reader_procesar_imagenes', 'interface_excel_reader_procesar_imagenes');
add_action('wp_ajax_nopriv_interface_excel_reader_procesar_imagenes', 'interface_excel_reader_procesar_imagenes');
function interface_excel_reader_procesar_imagenes() {

    // Opcional: seguridad (si pasas nonce desde JS)
    // check_ajax_referer('interface_excel_action', 'nonce', false);

    // Cargar helper de imágenes y/o process-sync
    require_once plugin_dir_path(__FILE__) . 'process-sync.php';

    $log_file = plugin_dir_path(__FILE__) . 'logs/log_procesar_imagenes_ajax.txt';
    $fecha    = date('Y-m-d H:i:s');

    $log = function($msg) use ($log_file, $fecha) {
        file_put_contents($log_file, "[$fecha] $msg\n", FILE_APPEND);
    };

    // Recuperar cola
    $cola = get_option('interface_excel_cola_imagenes', []);

    if (empty($cola) || !is_array($cola)) {
        $log("Cola vacía o no existente");
        wp_send_json_success([
            'remaining' => 0,
            'processed' => 0,
            'message'   => 'No hay imágenes pendientes de procesar.'
        ]);
    }

    // Cuántos procesamos por llamada
    $batch_size = 5;

    // Sacar primer lote
    $lote = array_splice($cola, 0, $batch_size);

    // Guardar cola actualizada
    update_option('interface_excel_cola_imagenes', $cola);

    $procesadas_ok = 0;

    foreach ($lote as $item) {
        $ref     = $item['ref']     ?? '';
        $post_id = (int)($item['post_id'] ?? 0);

        if (!$ref || !$post_id) {
            $log("Ítem inválido en cola: " . print_r($item, true));
            continue;
        }

        try {
            // Usamos tu helper rápido: obtener_imagen_variacion_fast
            if (function_exists('obtener_imagen_variacion_fast')) {
                $res = obtener_imagen_variacion_fast($ref, $post_id);
                $procesadas_ok++;
                $log("Procesada imagen para ref={$ref}, post_id={$post_id}");
            } elseif (function_exists('obtener_o_importar_imagenes_por_referencia')) {
                // Fallback a tu helper antiguo (por si lo usas también en simples/variables)
                $res = obtener_o_importar_imagenes_por_referencia($ref, $post_id);
                $procesadas_ok++;
                $log("Procesada imagen (fallback) para ref={$ref}, post_id={$post_id}");
            } else {
                $log("⚠ No existe ninguna función de procesamiento de imagen para ref={$ref}");
            }
        } catch (\Throwable $e) {
            $log("💥 Error procesando ref={$ref}, post_id={$post_id}: " . $e->getMessage());
            // NO hacemos throw para no romper el AJAX
            continue;
        }
    }

    wp_send_json_success([
        'remaining' => count($cola),
        'processed' => $procesadas_ok,
        'message'   => "Procesado lote de imágenes. Procesadas ahora: {$procesadas_ok}. Restantes: " . count($cola),
    ]);
}



add_action('wp_ajax_procesar_imagenes_variaciones', 'procesar_imagenes_variaciones_callback');
add_action('wp_ajax_nopriv_procesar_imagenes_variaciones', 'procesar_imagenes_variaciones_callback');
function procesar_imagenes_variaciones_callback() {

    global $wpdb;

    $batch_size = 5;

    // Progreso de ejecución
    $offset = intval( get_option('variaciones_img_offset', 0) );

    // 1️⃣ TOTAL de prodcutos, simples, variables y  variaciones (sin cargar ninguna)
   $total = intval($wpdb->get_var("
    SELECT COUNT(ID)
    FROM {$wpdb->posts}
    WHERE post_type IN ('product','product_variation')
    AND post_status = 'publish'
"));


    if ($total == 0) {
        wp_send_json_success([
            'finished'  => true,
            'total'     => 0,
            'remaining' => 0,
            'message'   => 'No hay variaciones.',
            'details'   => []
        ]);
    }

    // 2️⃣ Obtener SOLO el lote (sin cargar todo)
    $lote = $wpdb->get_results($wpdb->prepare("
        SELECT ID, post_type
        FROM {$wpdb->posts}
        WHERE post_type IN ('product', 'product_variation')
        AND post_status = 'publish'
        ORDER BY ID ASC
        LIMIT %d OFFSET %d
    ", $batch_size, $offset));

    if (empty($lote)) {
        delete_option('variaciones_img_offset');
        wp_send_json_success([
            'finished'  => true,
            'total'     => $total,
            'remaining' => 0,
            'message'   => 'Todas las imágenes procesadas.',
            'details'   => []
        ]);
    }

    // 3️⃣ Procesar el lote
    $procesadas = 0;
    $details = [];

    foreach ($lote as $post_id) {

        $tipo = get_post_type($post_id); // <- Saber si es product o product_variation
        $sku  = get_post_meta($post_id, '_sku', true);

        if (!$sku) continue;

        // Extraer referencia según el tipo
        if ($tipo === 'product_variation') {
            $ref = explode('-VAR', $sku)[0];
        } else {
            $ref = explode('-', $sku)[0];
        }

        $ref = trim($ref);
        if (!$ref) continue;

        $img_id = null;

        /* ==========================================================
         * 🔥 1️⃣ SI ES VARIACIÓN → usar función de variaciones
         * ========================================================== */
        if ($tipo === 'product_variation') {

            $img = obtener_imagen_variacion_fast($ref, $post_id);

            if (!empty($img['destacada_id'])) {
                $img_id = $img['destacada_id'];

                update_post_meta($post_id, '_thumbnail_id', $img_id);

                $var = new WC_Product_Variation($post_id);
                $var->set_image_id($img_id);
                $var->save();
            }

            $parent_id = wp_get_post_parent_id($post_id);
        }

        /* ==========================================================
         * 🔥 2️⃣ SI ES PRODUCTO SIMPLE O VARIABLE → usar función padre
         * ========================================================== */
        elseif ($tipo === 'product') {

            $img = obtener_o_importar_imagenes_por_referencia($ref, $post_id);

            if (!empty($img['destacada_id'])) {
                $img_id = $img['destacada_id'];

                // Asignar imagen destacada al producto
                set_post_thumbnail($post_id, $img_id);
            }

            $parent_id = null;
        }

        // Registrar info para debug
        $details[] = [
            'id'        => $post_id,
            'tipo'      => $tipo,
            'sku'       => $sku,
            'ref'       => $ref,
            'image_id'  => $img_id,
            'parent_id' => $parent_id
        ];

        $procesadas++;
    }

    // 4️⃣ Guardar progreso
    $offset += $batch_size;
    update_option('variaciones_img_offset', $offset);

    $remaining = max(0, $total - $offset);
    $finished  = ($remaining === 0);
    if ( $finished ) {
        delete_option('variaciones_img_offset');
    }
    wp_send_json_success([
        'finished'   => $finished,
        'processed'  => $procesadas,
        'remaining'  => $remaining,
        'total'      => $total,
        'message'    => $finished ? 'Todas las imágenes procesadas.' : 'Lote procesado.',
        'details'    => $details
    ]);
}



