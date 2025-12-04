jQuery(document).ready(function ($) {
    const form = $('#excel_form');
    const fileInput = $('#excel_file');
    const submitButton = $('#submit_button');
    const guardarButton = $('#guardar_button');
    const messageBox = $('#form-message');
    let clickedButtonName = '';
    let isSubmitting = false;

    submitButton.on('click', function () {
        clickedButtonName = 'ejecutar_sync';
        form.trigger('submit');
    });

    guardarButton.on('click', function () {
        clickedButtonName = 'guardar_solo';
        form.trigger('submit');
    });

    function showMessage(type, text) {
        messageBox
            .removeClass('d-none alert-success alert-danger alert-warning')
            .addClass('alert alert-' + type)
            .text(text)
            .fadeIn();
    }

    var lanzar=false;

    actualizarInfoUltimaSubida(lanzar);

    fileInput.on('change', function () {
        const file = this.files[0];
        messageBox.addClass('d-none');

        if (file) {
            const allowedExtensions = ['xls', 'xlsx'];
            const fileExtension = file.name.split('.').pop().toLowerCase();

            if (!allowedExtensions.includes(fileExtension)) {
                showMessage('danger', 'Formato no permitido. Solo se permiten archivos .xls y .xlsx.');
                submitButton.prop('disabled', true);
                guardarButton.prop('disabled', true);
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                showMessage('danger', 'Archivo demasiado grande. Máximo permitido: 5MB.');
                submitButton.prop('disabled', true);
                guardarButton.prop('disabled', true);
                return;
            }

            showMessage('success', 'Archivo cargado correctamente. Puedes ejecutar la sincronización o guardarlo.');
            submitButton.prop('disabled', false);
            guardarButton.prop('disabled', false);
        } else {
            // Si no hay archivo cargado, verificar si tenemos uno previo
            if (window.excelSyncReady) {
                submitButton.prop('disabled', false);
                guardarButton.prop('disabled', false);
            } else {
                submitButton.prop('disabled', true);
                guardarButton.prop('disabled', true);
            }
        }
    });

    form.on('submit', function (e) {
        e.preventDefault();

        if (isSubmitting) return;

        const file = fileInput[0].files[0];

        // Solo obligar a cargar archivo si no hay uno previamente disponible
        if (!file && !window.excelSyncReady) {
            Swal.fire({
                title: 'Error',
                text: '¡Por favor, selecciona un archivo Excel antes de continuar!',
                icon: 'error',
                confirmButtonText: 'Aceptar'
            });
            return;
        }

        isSubmitting = true;

        if (clickedButtonName === 'ejecutar_sync') {
            $.ajax({
                url: ajax_object.ajax_url,
                type: 'POST',
                data: {
                    action: 'check_if_sync_in_progress'
                },
                success: function (response) {
                    if (response === 'lock_exists') {
                        showMessage('danger', 'Ya hay una sincronización en curso. Por favor, espera a que termine.');
                        submitButton.prop('disabled', true);
                        guardarButton.prop('disabled', true);
                        isSubmitting = false;
                    } else {
                        enviarFormularioAjax(prepararFormData(file), true);
                    }
                },
                error: function () {
                    showMessage('danger', 'Error al verificar estado de sincronización.');
                    isSubmitting = false;
                }
            });
        } else {
            enviarFormularioAjax(prepararFormData(file), false);
        }
    });

    function prepararFormData(file) {
        const formData = new FormData();
        formData.append('action', 'interface_excel_reader_submit');
        formData.append('boton_accion', clickedButtonName);

        if (file) {
            formData.append('excel_file', file);
        } else {
            
            formData.append('usar_archivo_existente', 'si');
        }

        return formData;
    }

    function enviarFormularioAjax(formData, conSync) {
    const textoCargando = conSync ? '<span class="loader"></span> Sincronizando...' : '<span class="loader"></span> Guardando...';

    if (conSync) {
        submitButton.prop('disabled', true).html(textoCargando);
    } else {
        guardarButton.prop('disabled', true).html(textoCargando);
    }

    formData.forEach((value, key) => {
        console.log(key + ": " + value);
    });

    let interval = null;
    let responseData = null;
    let responseError = false;

    $.ajax({
        url: ajax_object.ajax_url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        beforeSend: function() {
            if (conSync) {
                const minDuration = 136 * 60 * 1000;
                const maxDuration = 569 * 60 * 1000;
                const totalDuration = Math.floor(Math.random() * (maxDuration - minDuration + 1)) + minDuration;

                Swal.fire({
                    icon: 'info',
                    title: 'Procesando fichero ...',
                    html: `
                        <style>
                            .loader {
                                position: relative;
                                width: 60px;
                                height: 60px;
                                margin: 0 auto;
                            }

                            .loader::before {
                                content: "";
                                box-sizing: border-box;
                                position: absolute;
                                top: 0;
                                left: 0;
                                width: 100%;
                                height: 100%;
                                border: 6px solid #f3f3f3;
                                border-top: 6px solid #3498db;
                                border-radius: 50%;
                                animation: spin 1s linear infinite;
                            }

                            .loader span {
                                position: absolute;
                                top: 50%;
                                left: 50%;
                                transform: translate(-50%, -50%);
                                font-weight: bold;
                                font-size: 14px;
                                color: #333;
                            }

                            @keyframes spin {
                                0% { transform: rotate(0deg); }
                                100% { transform: rotate(360deg); }
                            }
                        </style>

                        <div class="loader"><span id="percentText">0%</span></div>
                        <p style="margin-top:10px;">Tiempo estimado de finalización: <span id="countdownText"></span></p>
                    `,
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,

                    didOpen: () => {
                        const percentText = document.getElementById('percentText');
                        const countdownText = document.getElementById('countdownText');
                        const startTime = Date.now();
                        const endTime = startTime + totalDuration;

                        interval = setInterval(() => {
                            const now = Date.now();
                            const elapsed = now - startTime;
                            const remaining = Math.max(0, endTime - now);
                            const progress = Math.min((elapsed / totalDuration) * 100, 100);
                            percentText.textContent = Math.floor(progress) + '%';

                            const seconds = Math.floor(remaining / 1000);
                            const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
                            const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
                            const s = String(seconds % 60).padStart(2, '0');
                            countdownText.textContent = `${h}:${m}:${s}`;

                            if (progress >= 100) {
                                clearInterval(interval);
                                Swal.close();

                                // Al finalizar, mostramos el resultado del Ajax
                                if (responseError) {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: 'Hubo un error al enviar el formulario.',
                                        confirmButtonText: 'Aceptar'
                                    });
                                } else if (responseData) {
                                    const res = responseData;
                                    if (res.data.type == "error" && res.data.message == "Error al guardar el archivo Excela.") {
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Error al ejecutar la sincronización',
                                            text: 'Error en una de las celdas del fichero',
                                            confirmButtonText: 'Aceptar'
                                        });
                                    } else if (res.success && res.data) {
                                        Swal.fire({
                                            icon: res.data.type,
                                            title: res.data.type === 'success' ? 'Correcto' : 'Error',
                                            text: res.data.message,
                                            confirmButtonText: 'Aceptar',
                                            allowOutsideClick: false,
                                            showCancelButton: false,
                                            showConfirmButton: true
                                        }).then(function () {
                                            actualizarInfoUltimaSubida(conSync);
                                        });
                                    } else {
                                        Swal.fire({
                                            icon: res.data.type,
                                            title: res.data.type,
                                            text: res.data.message,
                                            confirmButtonText: 'Aceptar'
                                        });
                                    }
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: 'No se recibió respuesta del servidor.',
                                        confirmButtonText: 'Aceptar'
                                    });
                                }
                            }
                        }, 1000);
                    }
                });
            }
        },
        success: function(response) {
            try {
                responseData = typeof response === 'string' ? JSON.parse(response) : response;

                if (!conSync) {
                    // Si es "Guardar sin ejecutar", mostramos el mensaje aquí directamente
                    Swal.fire({
                        icon: responseData.data.type,
                        title: responseData.data.type === 'success' ? 'Correcto' : 'Error',
                        text: responseData.data.message,
                        confirmButtonText: 'Aceptar',
                        allowOutsideClick: false,
                        showCancelButton: false,
                        showConfirmButton: true
                    }).then(function () {
                        actualizarInfoUltimaSubida(conSync);
                    });
                }
                // Si es conSync, no mostramos el alert aquí, lo hacemos al terminar el preloader (en el intervalo)
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Error inesperado en la respuesta del servidor 2.',
                    confirmButtonText: 'Aceptar'
                });
            }
        },
        error: function() {
            responseError = true;

            if (!conSync) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Hubo un error al enviar el formulario.',
                    confirmButtonText: 'Aceptar'
                });
            }
            // Si es conSync, mostramos el error al terminar el preloader
        },
        complete: function() {
            submitButton.html('Ejecutar sincronización').prop('disabled', false);
            guardarButton.html('Guardar sin ejecutar').prop('disabled', false);
            isSubmitting = false;
        }
    });
}



    if (!messageBox.hasClass('d-none')) {
        setTimeout(() => {
            messageBox.fadeOut('slow');
        }, 5000);
    }

    function actualizarInfoUltimaSubida(lanzarSincronizacion = false) {
        $('#form-message').hide();
        $('.contenedor').show();

        $.ajax({
            url: ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'get_last_upload_info'
            },
            success: function (response) {
                if (response === "no_existe") {
                    window.excelSyncReady = false;

                    $.ajax({
                        url: ajax_object.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'lanzar_sincronizacion_excel'
                        },
                        success: function (response) {
                            if (response.success) {
                                console.log("Sincronización completada: ", response.data);
                                $('#form-message').html(response.message).addClass('success').show();
                            } else {
                                console.error("Error en sincronización: ", response.data);
                                $('#form-message').html(response.data.message).addClass('error').show();
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error("Error AJAX: ", error);
                            $('#form-message').html("Error al lanzar sincronización.").addClass('error').show();
                        }
                    });
                    

                    
                } else if (response === "lock_exists") {
                    // No hacemos nada aquí
                } else {
                    window.excelSyncReady = true;
                }

                $('#last-upload-info').html(response);
                $('.contenedor').hide();
                $('#form-message').show();

                if (lanzarSincronizacion && window.excelSyncReady) {
                    //read_excel_to_array(); // crea esta función si no existe
                }
            },
            error: function (xhr, status, error) {
                console.error('No se pudo obtener la información de subida: ', error);
                if (callback) callback();
            }
        });
    }

    // Habilitar botones si ya había un archivo cargado
    if (window.excelSyncReady) {
        submitButton.prop('disabled', false);
        guardarButton.prop('disabled', false);
    }
});
