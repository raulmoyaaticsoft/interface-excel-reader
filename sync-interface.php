<?php
/**
 * Sincronización de productos desde el archivo Excel.
 */
add_action('wp_ajax_interface_excel_reader_submit', 'interface_excel_reader_submit_handler');
add_action('wp_ajax_nopriv_interface_excel_reader_submit', 'interface_excel_reader_submit_handler');


add_action('wp_ajax_interface_excel_reader_ejecutar_sincro', 'interface_excel_reader_ejecutar_sincro');
add_action('wp_ajax_nopriv_interface_excel_reader_ejecutar_sincro', 'interface_excel_reader_ejecutar_sincro');


defined('ABSPATH') or die('No script kiddies please!');

function escribir_log_debug($mensaje) {
    static $log_limpiado = false;
    $log_file = __DIR__ . '/debug_sync_log.txt';
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



function interface_excel_reader_submit_handler() {
    require_once plugin_dir_path(__FILE__) . 'process-sync.php';
    $upload_dir = plugin_dir_path(__FILE__) . 'uploads/';
    $log_txt = $upload_dir . 'sync-records.txt';
    $log_general = $upload_dir . 'sync.log';



    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    

    // Verificar si 'usar_archivo_existente' está en la solicitud
    if (isset($_POST['usar_archivo_existente']) && $_POST['usar_archivo_existente'] === 'si') {


        
        // Si el archivo ya existe, no es necesario subirlo nuevamente
        $relative_path = get_option('interface_excel_reader_last_file_url');



        if (!$relative_path) {
            wp_send_json_error(['type' => 'danger', 'message' => 'No se encuentra un archivo previamente cargado.']);
        }


        if (is_wp_error($response)) {
            wp_send_json_error(['type' => 'error', 'message' => 'Error al ejecutar la sincronización del otro plugin.']);
        }else{





            $lanzarSincro=read_excel_to_array_interface();




            wp_send_json_success([
            'type' => 'success',
            'title'=>'Correcto',
            'message' => 'sincronizacion  correcta.',
            'data' => 'sincronizacion correcta.',
            ]);
            return;
        }

        // Realizar cualquier operación que sea necesaria para reusar el archivo
        wp_send_json_success([
            'type' => 'success',
            'message' => 'Archivo existente seleccionado. Se procederá con la sincronización.',
            'file_url' => $relative_path
        ]);
        return;
    }

    // Si no se usa el archivo existente, validar que se haya enviado un archivo
    if (!isset($_FILES['excel_file']) || empty($_FILES['excel_file']['name'])) {
        wp_send_json_error(['type' => 'danger', 'message' => 'Por favor, selecciona un archivo.']);
    }

    // Procesar el archivo subido
    date_default_timezone_set('Europe/Madrid');
    $filename_original = $_FILES['excel_file']['name'];
    $timestamp = date('Ymd_His');
    $backup_name = "ultimo_excel.xlsx";
    $backup_path = $upload_dir . $backup_name;

    $allowed_extensions = ['xls', 'xlsx'];
    $extension = pathinfo($filename_original, PATHINFO_EXTENSION);

    if (!in_array(strtolower($extension), $allowed_extensions)) {
        wp_send_json_error(['type' => 'danger', 'message' => 'Formato de archivo no permitido. Solo se permiten archivos .xls o .xlsx.']);
    }

    if ($_FILES['excel_file']['size'] > 5 * 1024 * 1024) {
        wp_send_json_error(['type' => 'danger', 'message' => 'El archivo es demasiado grande. Máximo 5MB.']);
    }







    if (!move_uploaded_file($_FILES['excel_file']['tmp_name'], $backup_path)) {
        wp_send_json_error(['type' => 'error', 'data' => 'Error al guardar el archivo Excel.']);
    }

    // Guardar registro de subida
    $upload_time = date('Y-m-d H:i:s');

    // Abrir el archivo de log para reemplazar su contenido con la última línea
    file_put_contents($log_txt, "[{$upload_time}] Archivo subido: {$filename_original} => {$backup_name}\n");

    // Guardar la URL del archivo en la tabla de opciones
    $relative_path = 'wp-content/plugins/interface-excel-reader/uploads/' . $backup_name;
    update_option('interface_excel_reader_last_file_url', $relative_path);

    // Determinar acción según el botón presionado
    $accion = isset($_POST['boton_accion']) ? $_POST['boton_accion'] : 'guardar_solo';

    if ($accion === 'ejecutar_sync') {
        file_put_contents($log_general, "{$upload_time} - Lanzando sincronización desde la interfaz externa\n", FILE_APPEND);

        // EJECUTA LA SINCRONIZACIÓN MANUALMENTE VÍA do_action
        try {
            error_log("Ejecutando do_action('interface_excel_reader_lanzar_sincronizacion', {$backup_path})");
            file_put_contents($log_general, "Ejecutando do_action('interface_excel_reader_lanzar_sincronizacion', {$backup_path})\n", FILE_APPEND);

            // Aquí se ejecuta la acción que deberías tener enganchada en otro lugar de tu plugin
            $estado=interface_excel_reader_ejecutar_sincro();

            file_put_contents($log_general, "Sincronización ejecutada mediante do_action correctamente.\n", FILE_APPEND);
            file_put_contents($log_txt, "[{$upload_time}] Sincronización ejecutada con: {$backup_name}\n", FILE_APPEND);

            if ($estado) {
                wp_send_json_success([
                'type' => 'success',
                'message' => 'Sincronización ejecutada correctamente desde la interfaz externa.'
            ]);
            }
            
        } catch (Exception $e) {
            error_log("Excepción durante do_action: " . $e->getMessage());
            file_put_contents($log_general, "Error durante la ejecución de la sincronización: " . $e->getMessage() . "\n", FILE_APPEND);
            wp_send_json_error([
                'type' => 'error',
                'message' => 'Error inesperado al ejecutar la sincronización.'
            ]);
        }
    } else {
        // Si es solo guardar
        wp_send_json_success([
            'type' => 'success',
            'message' => 'Archivo subido correctamente, pero no se ejecutó la sincronización.'
        ]);
    }
}

  

function interface_excel_reader_ejecutar_sincro() {

    $upload_dir = plugin_dir_path(__FILE__) . 'uploads/';
    $log_txt = $upload_dir . 'sync-records.txt';
    $log_general = $upload_dir . 'sync.log';
    $backup_name = "ultimo_excel.xlsx";
    $backup_path = $upload_dir . $backup_name;

    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    date_default_timezone_set('Europe/Madrid');
    $upload_time = date('Y-m-d H:i:s');

    // NO SE SUBE ARCHIVO NUEVO → solo usar el que ya hay
    if (!file_exists($backup_path)) {
        wp_send_json_error(['type' => 'info', 'message' => 'No hay un archivo Excel previamente subido para ejecutar.']);
    }

    // Registrar que se reutiliza el archivo
    file_put_contents($log_general, "[{$upload_time}] Reutilizando archivo existente para la sincronización: {$backup_name}\n", FILE_APPEND);

    // Determinar acción según el botón presionado
    $accion = isset($_POST['boton_accion']) ? $_POST['boton_accion'] : 'guardar_solo';

    if ($accion === 'ejecutar_sync') {
        file_put_contents($log_general, "{$upload_time} - Lanzando sincronización desde la interfaz externa\n", FILE_APPEND);

        ob_start();
        try {
            $url_remote = read_excel_to_array_interface();

            file_put_contents($log_general, "Ejecutada función read_excel_to_array_interface()\n", FILE_APPEND);

            if (is_wp_error($url_remote)) {
                $error_message = $url_remote->get_error_message();
                error_log("Error al ejecutar la sincronización: " . $error_message);
                file_put_contents($log_general, "Error al lanzar la sincronización: {$error_message}\n", FILE_APPEND);
                wp_send_json_error([
                    'type' => 'error',
                    'message' => 'Error en read_excel_to_array_interface(): ' . $error_message
                ]);
            }else {


                 // 🔹 Si la función de sync nos devuelve una cola de imágenes, la guardamos
                if (is_array($url_remote) && !empty($url_remote['cola_imagenes'])) {
                    update_option('interface_excel_cola_imagenes', $url_remote['cola_imagenes']);
                    file_put_contents($log_general, "Cola de imágenes guardada con "
                        . count($url_remote['cola_imagenes']) . " elementos\n", FILE_APPEND);
                }
                
                // Si todo va bien, registrar la sincronización
                file_put_contents($log_txt, "[{$upload_time}] Sincronización ejecutada con: {$backup_name}\n", FILE_APPEND);

              wp_send_json_success($url_remote);
            }
        } catch (Exception $e) {
            error_log("Excepción capturada: " . $e->getMessage());
            file_put_contents($log_general, "Error al ejecutar la sincronización: " . $e->getMessage() . "\n", FILE_APPEND);
            wp_send_json_error([
                'type' => 'error',
                'message' => 'Error inesperado durante la sincronización.'
            ]);
        }
    } else {
        wp_send_json_success([
            'type' => 'success',
            'message' => 'Archivo listo para sincronización, pero no se ejecutó porque no se pidió ejecutar.'
        ]);
    }
}

