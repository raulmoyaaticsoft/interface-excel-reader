<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuración
$ftp_host = "datos.copele.com";
$ftp_user = "copele";
$ftp_pass = "cZfNauaZjdm225x";

echo "<h2>Pruebas de conexión FTP/FTPS/SFTP</h2>";

// 1) FTP simple (21, sin cifrado)
echo "<h3>1) FTP simple (puerto 21)</h3>";
if (function_exists("ftp_connect")) {
    $conn = @ftp_connect($ftp_host, 21, 15);
    if ($conn) {
        if (@ftp_login($conn, $ftp_user, $ftp_pass)) {
            ftp_pasv($conn, true);
            echo "✅ Conexión y login correctos en FTP simple<br>";
        } else {
            echo "❌ No se pudo iniciar sesión en FTP simple<br>";
        }
        ftp_close($conn);
    } else {
        echo "❌ No se pudo establecer conexión al servidor en FTP simple<br>";
    }
} else {
    echo "❌ La función ftp_connect no está disponible en PHP<br>";
}

// 2) FTPS explícito (21 con TLS)
echo "<h3>2) FTPS explícito (puerto 21)</h3>";
if (function_exists("ftp_ssl_connect")) {
    $conn = @ftp_ssl_connect($ftp_host, 21, 15);
    if ($conn) {
        if (@ftp_login($conn, $ftp_user, $ftp_pass)) {
            ftp_pasv($conn, true);
            echo "✅ Conexión y login correctos en FTPS explícito<br>";
        } else {
            echo "❌ No se pudo iniciar sesión en FTPS explícito<br>";
        }
        ftp_close($conn);
    } else {
        echo "❌ No se pudo establecer conexión al servidor en FTPS explícito<br>";
    }
} else {
    echo "❌ La función ftp_ssl_connect no está disponible en PHP<br>";
}

// 3) FTPS implícito (990 con TLS)
echo "<h3>3) FTPS implícito (puerto 990)</h3>";
if (function_exists("ftp_ssl_connect")) {
    $conn = @ftp_ssl_connect($ftp_host, 990, 15);
    if ($conn) {
        if (@ftp_login($conn, $ftp_user, $ftp_pass)) {
            ftp_pasv($conn, true);
            echo "✅ Conexión y login correctos en FTPS implícito<br>";
        } else {
            echo "❌ No se pudo iniciar sesión en FTPS implícito<br>";
        }
        ftp_close($conn);
    } else {
        echo "❌ No se pudo establecer conexión al servidor en FTPS implícito<br>";
    }
} else {
    echo "❌ La función ftp_ssl_connect no está disponible en PHP<br>";
}

// 4) SFTP (22, SSH)
echo "<h3>4) SFTP (puerto 22)</h3>";
if (function_exists("ssh2_connect")) {
    $connection = @ssh2_connect($ftp_host, 22);
    if ($connection) {
        if (@ssh2_auth_password($connection, $ftp_user, $ftp_pass)) {
            $sftp = @ssh2_sftp($connection);
            if ($sftp) {
                echo "✅ Conexión y login correctos en SFTP<br>";
            } else {
                echo "❌ Conexión SSH correcta, pero fallo al iniciar SFTP<br>";
            }
        } else {
            echo "❌ No se pudo iniciar sesión en SFTP<br>";
        }
    } else {
        echo "❌ No se pudo establecer conexión al servidor en SFTP<br>";
    }
} else {
    echo "❌ La extensión ssh2 no está disponible en PHP<br>";
}

echo "<hr><strong>Pruebas completadas.</strong><br>";
