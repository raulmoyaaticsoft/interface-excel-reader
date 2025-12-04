<?php

/* ============================================================
 * 🔐 FORZAR HTTPS EN TODAS LAS URLS DE IMÁGENES
 * ============================================================ */
function forzar_https($url) {
    if (!$url) return $url;
    return preg_replace('#^http://#i', 'https://', $url);
}

add_filter('wp_get_attachment_url', function($url){
    return forzar_https($url);
});


/* ============================================================
 * 🧹 ELIMINAR IMÁGENES DUPLICADAS POR NOMBRE
 * ============================================================ */
function eliminar_imagenes_duplicadas($imagenes) {
    $filtradas = [];
    $nombres_vistos = [];

    foreach ($imagenes as $url) {
        $nombre = basename($url); 
        if (!in_array($nombre, $nombres_vistos)) {
            $filtradas[] = $url;
            $nombres_vistos[] = $nombre;
        }
    }
    return $filtradas;
}


/* ============================================================
 * 🖼 OBTENER SRCSET DESDE URL
 * ============================================================ */
function obtener_srcset_por_url($url) {
    $attachment_id = attachment_url_to_postid($url);
    if ($attachment_id) {
        return wp_get_attachment_image_srcset($attachment_id, 'full');
    }
    return false;
}



/* ============================================================
 * 📥 IMPORTAR IMAGEN PARA PRODUCTO SIMPLE O VARIABLE
 *  ⛔ Opción A → Siempre borrar galería y regenerarla
 * ============================================================ */
function obtener_o_importar_imagenes_por_referencia($referencia, $id_producto) {

    $dir_logs = __DIR__ . '/logs/';
    if (!file_exists($dir_logs)) mkdir($dir_logs, 0777, true);

    $log = $dir_logs . 'logs_imagenes_sync.txt';
    $fecha = date('Y-m-d H:i:s');
    $log_write = function($msg) use ($log, $fecha) {
        file_put_contents($log, "[$fecha] $msg\n", FILE_APPEND);
    };

    $log_write("=== 🔎 Procesando imagen para REF={$referencia}, post_id={$id_producto} ===");

    /* --- QUITAR GALERÍA EXISTENTE (Opción A) --- */
    update_post_meta($id_producto, '_product_image_gallery', '');
    $log_write("🧹 Galería limpiada antes de importar imágenes.");

    /* --- IMAGEN DESTACADA ACTUAL --- */
    $current_id   = get_post_thumbnail_id($id_producto);
    $current_path = $current_id ? get_attached_file($current_id) : null;
    $current_hash = ($current_path && file_exists($current_path)) ? @md5_file($current_path) : null;

    /* --- DESCARGA DESDE FTP --- */
    if (!function_exists("ftp_ssl_connect")) return [];

    $ftp = @ftp_ssl_connect('datos.copele.com', 21, 10);
    if (!$ftp) return [];
    if (!@ftp_login($ftp, 'copele', 'cZfNauaZjdm225x')) { ftp_close($ftp); return []; }

    ftp_pasv($ftp, true);

    $upload = wp_upload_dir();
    $exts = ['jpg','jpeg','png','webp'];
    $tmp_path = null;

    foreach ($exts as $ext) {
        $remote = "Product Images/{$referencia}.{$ext}";
        $local  = $upload['path'] . "/{$referencia}-sync.{$ext}";
        wp_mkdir_p($upload['path']);

        if (@ftp_get($ftp, $local, $remote, FTP_BINARY)) {
            $tmp_path = $local;
            break;
        }
    }

    ftp_close($ftp);

    /* --- SI NO EXISTE → FALLBACK --- */
    if (!$tmp_path || !file_exists($tmp_path)) {

        $log_write("⚠️ No existe imagen en FTP para {$referencia}");

        $fallback_id = 232506;
        $log_write("🖼 Imagen estándar asignada (fallback).");

        set_post_thumbnail($id_producto, $fallback_id);

        return [
            'destacada_id' => $fallback_id,
            'destacada'    => wp_get_attachment_url($fallback_id),
            'galeria_ids'  => []
        ];
    }

    /* --- COMPARAR HASH PARA EVITAR TRABAJO INNECESARIO --- */
    $ftp_hash = @md5_file($tmp_path);

    if ($current_hash && $ftp_hash === $current_hash) {
        $log_write("✔ Imagen sin cambios, se mantiene destacada actual.");
        return [
            'destacada_id' => $current_id,
            'destacada'    => wp_get_attachment_url($current_id),
            'galeria_ids'  => []
        ];
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');

    /* --- REEMPLAZAR DESTACADA SI EXISTE --- */
    if ($current_id && $current_path && file_exists($current_path)) {

        @copy($tmp_path, $current_path);

        $attach_data = wp_generate_attachment_metadata($current_id, $current_path);
        wp_update_attachment_metadata($current_id, $attach_data);

        $log_write("🔄 Imagen destacada reemplazada manteniendo attachment ID.");

        return [
            'destacada_id' => $current_id,
            'destacada'    => wp_get_attachment_url($current_id),
            'galeria_ids'  => []
        ];
    }

    /* --- SI NO EXISTE: CREAR NUEVO ATTACHMENT --- */
    $filetype = wp_check_filetype(basename($tmp_path), null);

    $attachment = [
        'post_mime_type' => $filetype['type'],
        'post_title'     => $referencia,
        'post_status'    => 'inherit',
    ];

    $new_id = wp_insert_attachment($attachment, $tmp_path);
    $meta   = wp_generate_attachment_metadata($new_id, $tmp_path);
    wp_update_attachment_metadata($new_id, $meta);

    set_post_thumbnail($id_producto, $new_id);

    $log_write("🆕 Nueva imagen destacada creada: ID={$new_id}");

    return [
        'destacada_id' => $new_id,
        'destacada'    => wp_get_attachment_url($new_id),
        'galeria_ids'  => []
    ];
}



/* ============================================================
 * 📥 IMPORTAR IMÁGENES PARA UNA VARIACIÓN
 * ============================================================ */
function obtener_imagen_variacion_fast($referencia, $variation_id) {

    $dir_logs = __DIR__ . '/logs/';
    if (!file_exists($dir_logs)) mkdir($dir_logs, 0777, true);

    $log = $dir_logs . 'logs_imagenes_variaciones.txt';
    $fecha = date('Y-m-d H:i:s');
    $log_write = function($msg) use ($log, $fecha) {
        file_put_contents($log, "[$fecha] $msg\n", FILE_APPEND);
    };


    /* --- FTP --- */
    if (!function_exists("ftp_ssl_connect")) return [];
    $ftp = @ftp_ssl_connect('datos.copele.com', 21, 10);
    if (!$ftp) return [];
    if (!@ftp_login($ftp, 'copele', 'cZfNauaZjdm225x')) { ftp_close($ftp); return []; }

    ftp_pasv($ftp, true);
    $upload = wp_upload_dir();


    /* --- DESTACADA REF.jpg --- */
    $remote_destacada = "Product Images/{$referencia}.jpg";
    $local_destacada  = $upload['path'] . "/{$referencia}-V{$variation_id}.jpg";

    wp_mkdir_p($upload['path']);

    $destacada_id = null;

    if (@ftp_get($ftp, $local_destacada, $remote_destacada, FTP_BINARY)) {

        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $filetype = wp_check_filetype(basename($local_destacada), null);
        $attachment = [
            'post_mime_type' => $filetype['type'],
            'post_title'     => "{$referencia} (Variación {$variation_id})",
            'post_status'    => 'inherit',
        ];

        $destacada_id = wp_insert_attachment($attachment, $local_destacada);
        $meta = wp_generate_attachment_metadata($destacada_id, $local_destacada);
        wp_update_attachment_metadata($destacada_id, $meta);

        update_post_meta($variation_id, '_thumbnail_id', $destacada_id);
    }


    /* --- GALERÍA REF-1.jpg... REF-10.jpg --- */
    $galeria_ids = [];
    update_post_meta($variation_id, '_product_image_gallery', '');

    for ($i = 1; $i <= 10; $i++) {

        $remote_gal = "Product Images/{$referencia}-{$i}.jpg";
        $local_gal  = $upload['path'] . "/{$referencia}-{$i}-V{$variation_id}.jpg";

        if (@ftp_get($ftp, $local_gal, $remote_gal, FTP_BINARY)) {

            $filetype = wp_check_filetype(basename($local_gal), null);
            $attachment = [
                'post_mime_type' => $filetype['type'],
                'post_title'     => "{$referencia}-{$i} (Variación {$variation_id})",
                'post_status'    => 'inherit',
            ];

            $img_id = wp_insert_attachment($attachment, $local_gal);
            $meta   = wp_generate_attachment_metadata($img_id, $local_gal);
            wp_update_attachment_metadata($img_id, $meta);

            $galeria_ids[] = $img_id;
        }
    }

    ftp_close($ftp);


    /* --- GUARDAR GALERÍA --- */
    if (!empty($galeria_ids)) {
        update_post_meta($variation_id, '_product_image_gallery', implode(',', $galeria_ids));
    }

    return [
        'destacada_id' =>  $destacada_id,
        'galeria_ids'  => $galeria_ids
    ];
}

