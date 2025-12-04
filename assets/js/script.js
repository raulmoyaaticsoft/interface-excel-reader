document.addEventListener("DOMContentLoaded", () => {


    const form          = document.getElementById("excel_form");
    const fileInput     = document.getElementById("excel_file");
    const submitButton  = document.getElementById("submit_button");
    const guardarButton = document.getElementById("guardar_button");
    const messageBox    = document.getElementById("form-message");

    let clickedButtonName = "";
    let isSubmitting      = false;
    window.excelSyncReady = false;

    // -------------------------------------------------------
    // Utilidades
    // -------------------------------------------------------
    function showMessage(type, text) {
        messageBox.classList.remove("d-none", "alert-success", "alert-danger", "alert-warning");
        messageBox.classList.add("alert", "alert-" + type);
        messageBox.textContent = text;
        messageBox.style.display = "block";
    }

    function hideMessage() {
        messageBox.style.display = "none";
    }

    // -------------------------------------------------------
    // Botones
    // -------------------------------------------------------
    submitButton.addEventListener("click", () => {
        clickedButtonName = "ejecutar_sync";
        form.dispatchEvent(new Event("submit"));
    });

    guardarButton.addEventListener("click", () => {
        clickedButtonName = "guardar_solo";
        form.dispatchEvent(new Event("submit"));
    });

    // -------------------------------------------------------
    // Carga inicial
    // -------------------------------------------------------
    actualizarInfoUltimaSubida(false);

    // -------------------------------------------------------
    // Validación de fichero
    // -------------------------------------------------------
    fileInput.addEventListener("change", () => {
        hideMessage();
        const file = fileInput.files[0];

        if (!file) {
            submitButton.disabled  = !window.excelSyncReady;
            guardarButton.disabled = !window.excelSyncReady;
            return;
        }

        const ext = file.name.split(".").pop().toLowerCase();
        if (!["xls", "xlsx"].includes(ext)) {
            showMessage("danger", "Formato no permitido. Solo .xls o .xlsx.");
            submitButton.disabled = true;
            guardarButton.disabled = true;
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            showMessage("danger", "Archivo demasiado grande (máximo 5MB).");
            submitButton.disabled = true;
            guardarButton.disabled = true;
            return;
        }

        showMessage("success", "Archivo cargado correctamente.");
        submitButton.disabled = false;
        guardarButton.disabled = false;
    });

    // -------------------------------------------------------
    // Submit del formulario
    // -------------------------------------------------------
    form.addEventListener("submit", (e) => {
        e.preventDefault();
        if (isSubmitting) return;

        const file = fileInput.files[0];

        if (!file && !window.excelSyncReady) {
            Swal.fire({
                icon: "error",
                title: "Error",
                text: "Selecciona un archivo Excel antes de continuar"
            });
            return;
        }

        isSubmitting = true;

        enviarFormularioAjax(prepararFormData(file), clickedButtonName === "ejecutar_sync");
    });

    // -------------------------------------------------------
    // FormData
    // -------------------------------------------------------
    function prepararFormData(file) {
        const fd = new FormData();
        fd.append("action", "interface_excel_reader_submit");
        fd.append("boton_accion", clickedButtonName);
        if (file) fd.append("excel_file", file);
        else fd.append("usar_archivo_existente", "si");
        return fd;
    }

    // -------------------------------------------------------
    // Enviar AJAX principal
    // -------------------------------------------------------
    function enviarFormularioAjax(formData, conSync) {

        const loaderText = conSync
            ? '<span class="loader"></span> Sincronizando…'
            : '<span class="loader"></span> Guardando…';

        if (conSync) submitButton.innerHTML = loaderText;
        else guardarButton.innerHTML = loaderText;

        submitButton.disabled = true;
        guardarButton.disabled = true;

        if (conSync) {
            Swal.fire({
                icon: "info",
                title: "Procesando fichero…",
                html: '<div class="loader"></div><p>Procesando, por favor espera…</p>',
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false
            });
        }

        // -------------------------------------------------------
        //  🔥 FIX IMPORTANTE → NO concatenar ?action
        // -------------------------------------------------------
        fetch(ajax_object.ajax_url, {
            method: "POST",
            body: formData,
            credentials: "omit"
        })
        .then(r => r.json())
        .then(json => {

            const type    = json?.data?.type    ?? "info";
            const message = json?.data?.message ?? "Operación completada.";

            if (!conSync) {
                Swal.fire({
                    icon: type,
                    title: "Resultado",
                    text: message
                }).then(() => actualizarInfoUltimaSubida(false));
                return;
            }

            Swal.fire({
                icon: type,
                title: "Sincronización completada",
                html: `<p>${message}</p>`,
                showCancelButton: true,
                confirmButtonText: "Procesar imágenes",
                cancelButtonText: "Cerrar"
            }).then(res => {
                actualizarInfoUltimaSubida(true);
                if (res.isConfirmed) procesarImagenesVariaciones();
            });
        })
        .catch(() => {
            Swal.fire({
                icon: "error",
                title: "Error",
                text: "Error al enviar el formulario."
            });
        })
        .finally(() => {
            submitButton.innerHTML  = "Ejecutar sincronización";
            guardarButton.innerHTML = "Guardar sin ejecutar";
            submitButton.disabled  = false;
            guardarButton.disabled = false;
            isSubmitting = false;
        });
    }

    // -------------------------------------------------------
    // Actualizar info última subida
    // -------------------------------------------------------
    function actualizarInfoUltimaSubida(lanzar) {
        hideMessage();
        document.querySelector(".contenedor").style.display = "block";

        fetch(ajax_object.ajax_url + "?action=get_last_upload_info", {
            method: "POST",
            credentials: "omit"
        })
        .then(r => r.text())
        .then(resp => {
            if (resp === "no_existe") {
                window.excelSyncReady = false;

                fetch(ajax_object.ajax_url + "?action=lanzar_sincronizacion_excel", {
                    method: "POST",
                    credentials: "omit"
                })
                .then(r => r.json())
                .then(json => {
                    messageBox.innerHTML = json.message;
                    messageBox.classList.add(json.success ? "success" : "error");
                    messageBox.style.display = "block";
                });

            } else {
                window.excelSyncReady = true;
            }

            document.getElementById("last-upload-info").innerHTML = resp;
            document.querySelector(".contenedor").style.display = "none";
            messageBox.style.display = "block";
        });
    }

    // -------------------------------------------------------
    // Procesar imágenes en lotes
    // -------------------------------------------------------
    function procesarImagenesVariaciones() {

        let total = 0;
        let first = true;

        Swal.fire({
            icon: "info",
            title: "Procesando imágenes…",
            html: `
                <p id="iesq-img-texto">Inicializando…</p>
                <div style="width:100%; background:#eee; border-radius:4px;">
                    <div id="iesq-img-barra" style="width:0%; height:10px; background:#3498db"></div>
                </div>
            `,
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false
        });

        const procesar = () => {
            fetch(ajax_object.ajax_url + "?action=procesar_imagenes_variaciones", {
                method: "POST",
                credentials: "omit",
                headers: { "Content-Type": "application/x-www-form-urlencoded" }
            })
            .then(r => r.json())
            .then(resp => {

                if (!resp?.success) {
                    Swal.update({
                        icon: "error",
                        title: "Error",
                        html: "<p>Error procesando imágenes.</p>",
                        showConfirmButton: true
                    });
                    return;
                }

                const data = resp.data;
                if (first) {
                    total = data.total || 0;
                    first = false;
                }

                const remaining = data.remaining || 0;
                const processed = total - remaining;
                const percent   = Math.round(processed / total * 100);

                document.getElementById("iesq-img-texto").textContent =
                    `Procesando imagen ${processed} de ${total} (${percent}%)`;

                document.getElementById("iesq-img-barra").style.width = percent + "%";

                if (remaining <= 0) {
                    Swal.update({
                        icon: "success",
                        title: "Imágenes procesadas",
                        html: `<p>${data.message}</p>`,
                        showConfirmButton: true
                    });
                } else {
                    setTimeout(procesar, 300);
                }
            })
            .catch(() => {
                Swal.update({
                    icon: "error",
                    title: "Error",
                    html: "<p>Error de comunicación.</p>",
                    showConfirmButton: true
                });
            });
        };

        procesar();
    }

});
