<?php
/**
 * Traza qué productos han quedado como SIMPLES y BODEGONES en $sortedArr.
 * NO crea ni actualiza nada; solo escribe un log para verlos.
 *
 * Log: /funciones/../logs/logs_traza_simples_bodegones.txt
 */
function trazar_simples_y_bodegones(array $rows) {
    // 📁 Ruta del log
    $log_file = __DIR__ . '/../logs/logs_traza_tipos_productos_detallado.txt';

    // 🧹 Crear carpeta si no existe y limpiar contenido previo
    @wp_mkdir_p(dirname($log_file));
    @file_put_contents($log_file, ""); // <— limpia el contenido anterior

    // 🧾 Encabezado del nuevo log
    file_put_contents($log_file, "==============================\n", FILE_APPEND);
    file_put_contents($log_file, "🧪 TRAZA DETALLADA DE TIPOS DE PRODUCTO\n", FILE_APPEND);
    file_put_contents($log_file, "Generado el " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    file_put_contents($log_file, "==============================\n\n", FILE_APPEND);

    // 🔍 1️⃣ Recolectar todos los productos "padres" (variables)
    $padres = [];
    foreach ($rows as $r) {
        $ref = trim($r['referencia'] ?? '');
        if ($ref === '') continue;
        if (isset($r['padre']) && (string)$r['padre'] === '1' && strpos($ref, '94') !== 0) {
            $padres[$ref] = [
                'codigos' => array_filter(array_map('trim', explode('-', (string)($r['codigos_asociados'] ?? '')))),
                'descripcion' => trim($r['descripcion_es'] ?? '')
            ];
        }
    }

    $resumen = ['bodegon'=>0,'simple'=>0,'variable'=>0,'variante'=>0,'sin_ref'=>0];

    // 🔎 2️⃣ Clasificar cada fila según tus reglas
    foreach ($rows as $i => $row) {
        $ref  = trim((string)($row['referencia'] ?? ''));
        $pad  = trim((string)($row['padre'] ?? ''));
        $asoc = trim((string)($row['codigos_asociados'] ?? ''));
        $desc = trim((string)($row['descripcion_es'] ?? ''));
        $tipo = '';
        $motivo = '';

        if ($ref === '') {
            $tipo = 'sin_ref';
            $motivo = '⚠️ sin referencia válida.';
        }
        elseif (strpos($ref, '94') === 0) {
            $tipo = 'bodegon';
            $motivo = 'referencia comienza por 94 → producto simple bodegón.';
        }
        elseif ($pad === '1' && strpos($ref, '94') !== 0) {
            $tipo = 'variable';
            $motivo = 'columna PADRE=1 → producto variable.';
        }
        elseif ($pad !== '1' && $asoc === '') {
            $tipo = 'simple';
            $motivo = 'PADRE ≠ 1 y sin códigos asociados → producto simple.';
        }
        else {
            // 🔄 ¿Es variante? (figura en los códigos asociados de un padre distinto a sí mismo)
            $es_variante = false;
            $padre_encontrado = '';
            $padre_desc = '';

            foreach ($padres as $ref_padre => $datos) {
                if (in_array($ref, $datos['codigos'], true) && $ref_padre !== $ref) {
                    $es_variante = true;
                    $padre_encontrado = $ref_padre;
                    $padre_desc = $datos['descripcion'];
                    break;
                }
            }

            if ($es_variante) {
                $tipo = 'variante';
                $motivo = "incluido en los códigos asociados de su padre {$padre_encontrado} ({$padre_desc}).";
            } else {
                $tipo = 'simple';
                $motivo = 'no cumple condiciones de variable ni bodegón, ni está en códigos asociados de ningún padre.';
            }
        }

        $resumen[$tipo] = ($resumen[$tipo] ?? 0) + 1;

        // 🧾 Log detallado
        $linea = sprintf(
            "🧩 Clasificación fila #%03d: REF=%s | PADRE=%s | ASOCIADOS=%s | → %s\n   ↳ %s\n",
            $i,
            $ref ?: '(sin)',
            $pad ?: '-',
            $asoc ?: '-',
            strtoupper($tipo),
            $motivo
        );

        // Si es variable, listamos sus asociados
        if ($tipo === 'variable' && !empty($asoc)) {
            $linea .= "   🔗 Asociados: {$asoc}\n";
        }

        file_put_contents($log_file, $linea . "\n", FILE_APPEND);
    }

    // 📊 3️⃣ Resumen final
    file_put_contents($log_file, "\n=== RESUMEN FINAL ===\n", FILE_APPEND);
    foreach ($resumen as $tipo => $n) {
        file_put_contents($log_file, sprintf("%-10s: %d\n", ucfirst($tipo), $n), FILE_APPEND);
    }

    return $resumen;
}


