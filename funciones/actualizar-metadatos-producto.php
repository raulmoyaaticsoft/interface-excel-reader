<?php
function actualizar_metadatos_producto($product_id, $item, $idioma, $log_file, $fecha) {
    if (!$product_id || empty($item)) return;

    // 🧩 Identificación del producto
    $sku   = $item['referencia'] ?? '(sin ref)';
    $nombre = $item["descripcion_{$idioma}"] ?? '(sin descripción)';

    file_put_contents(
        $log_file,
        "\n[$fecha] 🟦 INICIO actualización metadatos — ID: {$product_id}, SKU: {$sku}, Nombre: {$nombre}\n",
        FILE_APPEND
    );

    // 🔍 Mostrar todas las claves recibidas desde el Excel (modo depuración total)
    file_put_contents($log_file, "   📋 Campos recibidos para SKU {$sku}:\n", FILE_APPEND);
    foreach ($item as $clave => $valor) {
        $valor_log = is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE) : trim((string)$valor);
        if ($valor_log === '') $valor_log = '(vacío)';
        file_put_contents($log_file, "      • {$clave} = {$valor_log}\n", FILE_APPEND);
    }

    // 🧾 Campos base que siempre se actualizan
    $campos = [
        "descripcion_larga_$idioma",
        "video_$idioma",
        "cantidad_minima",
        "largo_producto","ancho_producto","alto_producto","peso_producto",
        "und_caja","largo_caja","ancho_caja","alto_caja","peso_caja",
        "niveles_palet_eur","niveles_palet_usa",
        "cajas_nivel_eur","cajas_nivel_usa",
        "cajas_palet_eur","cajas_palet_usa",
        "altura_palet_eur","altura_palet_usa",
        "caracteristica_material","caracteristica_color",
        "caracteristica_1_$idioma","caracteristica_2_$idioma","caracteristica_3_$idioma",
        "caracteristica_4_$idioma","caracteristica_5_$idioma"
    ];

    file_put_contents($log_file, "   ⚙️ Analizando campos base para SKU {$sku}...\n", FILE_APPEND);
    foreach ($campos as $campo) {
        if (isset($item[$campo]) && trim($item[$campo]) !== '') {
            $valor = trim($item[$campo]);
            update_post_meta($product_id, $campo, $valor);
            file_put_contents($log_file, "      ✅ Meta actualizado: {$campo} = {$valor}\n", FILE_APPEND);
        } else {
            file_put_contents($log_file, "      ⚪ Campo vacío o no definido: {$campo}\n", FILE_APPEND);
        }
    }

    // 📏 Calcular medidas combinadas
    $largo_producto = trim((string)($item['largo_producto'] ?? ''));
    $ancho_producto = trim((string)($item['ancho_producto'] ?? ''));
    $alto_producto  = trim((string)($item['alto_producto'] ?? ''));
    $largo_caja     = trim((string)($item['largo_caja'] ?? ''));
    $ancho_caja     = trim((string)($item['ancho_caja'] ?? ''));
    $alto_caja      = trim((string)($item['alto_caja'] ?? ''));

    $medidas_producto = ($largo_producto && $ancho_producto && $alto_producto)
        ? "{$largo_producto} x{$ancho_producto}x{$alto_producto}"
        : '';
    $medidas_caja = ($largo_caja && $ancho_caja && $alto_caja)
        ? "{$largo_caja} x{$ancho_caja}x{$alto_caja}"
        : '';

    // 🆕 Campos técnicos y logísticos adicionales
    $extra_metas = [
        '_titulo_variacion' . $product_id => $item["descripcion_{$idioma}"] ?? '',
        'medida_unitaria'      => $medidas_producto,
        'peso_unitario'        => $item['peso_producto'] ?? '',
        'capacidad'            => $item['capacidad'] ?? ($item['peso_producto'] ?? ''),
        'material'             => $item['caracteristica_material'] ?? '',
        'color'                => $item['caracteristica_color'] ?? '',
        'medidas_caja'         => $medidas_caja,
        'peso_caja'            => $item['peso_caja'] ?? '',
        'unidades_caja'        => $item['und_caja'] ?? '',
        'unidad_compra_minima' => $item['cantidad_minima'] ?? '',
        'niveles_palet_eur'    => $item['niveles_palet_eur'] ?? '',
        'niveles_palet_usa'    => $item['niveles_palet_usa'] ?? '',
        'niveles_cajas_eur'    => $item['cajas_nivel_eur'] ?? '',
        'niveles_cajas_usa'    => $item['cajas_nivel_usa'] ?? '',
        'altura_palet_eur'     => $item['altura_palet_eur'] ?? '',
        'altura_palet_usa'     => $item['altura_palet_usa'] ?? '',
        'cajas_palet_eur'      => $item['cajas_palet_eur'] ?? '',
        'cajas_palet_usa'      => $item['cajas_palet_usa'] ?? '',
    ];

    file_put_contents($log_file, "   🧮 Metadatos técnicos/logísticos SKU {$sku}:\n", FILE_APPEND);
    foreach ($extra_metas as $meta_key => $valor) {
        $valor = trim((string)$valor);
        if ($valor !== '') {
            update_post_meta($product_id, $meta_key, $valor);
            file_put_contents($log_file, "      🔹 {$meta_key} = {$valor}\n", FILE_APPEND);
        } else {
            file_put_contents($log_file, "      ⚪ {$meta_key} vacío, sin actualizar\n", FILE_APPEND);
        }
    }

    // 📦 Dimensiones físicas WooCommerce
    if ($largo_producto && $ancho_producto && $alto_producto) {
        update_post_meta($product_id, '_length', $largo_producto);
        update_post_meta($product_id, '_width',  $ancho_producto);
        update_post_meta($product_id, '_height', $alto_producto);
        file_put_contents($log_file, "   📏 Dimensiones físicas: {$largo_producto}x{$ancho_producto}x{$alto_producto}\n", FILE_APPEND);
    }

    if (!empty($item['peso_producto'])) {
        update_post_meta($product_id, '_weight', $item['peso_producto']);
        file_put_contents($log_file, "   ⚖️ Peso físico: {$item['peso_producto']}\n", FILE_APPEND);
    }

    file_put_contents(
        $log_file,
        "[$fecha] ✅ FIN actualización metadatos — SKU: {$sku}, ID: {$product_id}\n\n",
        FILE_APPEND
    );
}
