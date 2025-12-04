# Interface Excel Reader

Plugin de WordPress que proporciona una interfaz externa para subir archivos Excel y lanzar una sincronización con otro plugin que ya contiene la lógica.

## Funcionalidades

- Subida manual de archivos Excel
- Lanzamiento manual de sincronización
- Registro de logs y errores
- Preparado para actualizaciones automáticas desde GitHub
- Estructura modular siguiendo buenas prácticas de WordPress

## Estructura

- `interface-excel-reader.php` – Plugin principal
- `sync-interface.php` – Interfaz de usuario externa protegida
- `uploads/` – Carpeta para respaldos, logs y último Excel
- `assets/` – CSS y JS personalizados para la interfaz
- `includes/` – Funciones auxiliares y soporte modular

## Registro de sincronizaciones

Cada vez que se lanza una sincronización, se guarda un log en `uploads/sync.log` con la fecha y hora de ejecución para seguimiento manual.

## Seguridad

El archivo externo está protegido mediante autenticación HTTP básica.

## Actualizaciones automáticas

Este plugin está preparado para ser actualizado automáticamente desde GitHub configurando la URI en `Update URI` del header del plugin.
