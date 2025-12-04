// 🔒 Bloqueo del comportamiento nativo de WooCommerce
jQuery(document).on("click", ".opciones_variacion", function (e) {
  console.log("🛑 Click bloqueado en .opciones_variacion");
  e.preventDefault();
  e.stopImmediatePropagation();
  return false;
});


jQuery(function ($) {

  window.cargasPendientes = 0;

function iniciarCarga() {
    window.cargasPendientes++;
    activarLoaderGlobal();
}

function finalizarCarga() {
    window.cargasPendientes--;
    if (window.cargasPendientes <= 0) {
        window.cargasPendientes = 0;
        $(".fullpage-loader").remove();
    }
}

  var $gallery = $('.woocommerce-product-gallery');

    if ($gallery.length > 0) {
        $gallery.each(function () {
            $(this).wc_product_gallery();
        });
    }

  // 🆕 PRELOADER SOLO PARA LA IMAGEN PRINCIPAL
  function activarLoaderImagen() {
    const $gal = $(".woocommerce-product-gallery");
    if (!$gal.length) return;

    if (!$gal.find(".img-loader-overlay").length) {
      $gal.css("position", "relative");
      $gal.append(`
        <div class="img-loader-overlay" style="
          position:absolute;inset:0;
          display:flex;align-items:center;justify-content:center;
          background:rgba(255,255,255,0.7);
          z-index:9;">
          <div style="
            width:24px;height:24px;
            border-radius:50%;
            border:2px solid #ccc;
            border-top-color:#333;
            animation:spinImgLoader .5s linear infinite;">
          </div>
        </div>
        <style>
          @keyframes spinImgLoader{to{transform:rotate(360deg)}}
        </style>
      `);
    }
  }

  function desactivarLoaderImagen() {
    $(".img-loader-overlay").remove();
  }

  // 🆕 GLOBAL: PRELOADER A PANTALLA COMPLETA PARA RECARGAR AL PADRE
  function activarLoaderGlobal() {
    if ($(".fullpage-loader").length) return;

    $("body").append(`
      <div class="fullpage-loader" style="
        position:fixed;
        inset:0;
        background:rgba(255,255,255,0.85);
        z-index:99999;
        display:flex;
        align-items:center;
        justify-content:center;">
        <div style="
          width:32px;height:32px;
          border-radius:50%;
          border:3px solid #ccc;
          border-top-color:#333;
          animation:spinFullLoader .6s linear infinite;">
        </div>
      </div>
      <style>
        @keyframes spinFullLoader{to{transform:rotate(360deg)}}
      </style>
    `);
  }

  function refrescarPestanasProducto() {
    console.log("🔁 Re-render pestañas de producto (Woo + Elementor)");
    $(document.body).trigger("init_wc_product_tabs");
    $(document.body).trigger("wc-init-tabbed-panels");

    $(".woocommerce-tabs, .wc-tabs-wrapper, .elementor-widget-woocommerce-product-data-tabs").show();
  }

  function mostrarPestanaDescripcionCuandoEsteLista(intentos = 0) {
    const $el = $(".pestana_descripcion");

    if ($el.length && $el.html().trim() !== "") {
      console.log("📄 Pestaña descripción lista → MOSTRANDO");
      $el.show().css({ opacity: 1, visibility: "visible" });
      return;
    }

    if (intentos < 20) {
      setTimeout(() => {
        mostrarPestanaDescripcionCuandoEsteLista(intentos + 1);
      }, 30);
    } else {
      console.warn("⚠️ No se encontró la pestaña de descripción tras esperar");
    }
  }

  console.group("🚀 INICIO variaciones-front");

  // 🧱 Guardamos los valores base (producto padre) en data() y en objeto global
  console.log("🔍 Buscando datos de producto padre...");

  window.productoPadre = {
    titulo: $(".titulo_de_producto").text(),
    precio_html: $(".summary .price, .product .price").html(),
    descripcion: $(".descripcion-de-producto").html(),
    imagen: {
      src: $(".woocommerce-product-gallery__image img").first().attr("src") || "",
      srcset: $(".woocommerce-product-gallery__image img").first().attr("srcset") || "",
      alt: $(".woocommerce-product-gallery__image img").first().attr("alt") || "",
      ficha_tecnica_url: window.fichaTecnicaPadre,
      caracteristicas_tecnicas_html: $(".caracteristicas-tecnicas-list").html() || "",
      otras_caracteristicas_html: $(".otras-caracteristicas-tecnicas-list").html() || ""
    }
  };

  window.productoPadre.caracteristicas_tecnicas_html = window.productoPadre.imagen.caracteristicas_tecnicas_html;
  window.productoPadre.otras_caracteristicas_html = window.productoPadre.imagen.otras_caracteristicas_html;
  window.productoPadre.ficha_tecnica_url = window.productoPadre.imagen.ficha_tecnica_url;

  $(".titulo_de_producto").data("base-title", window.productoPadre.titulo);
  $(".summary .price, .product .price").data("base-price", window.productoPadre.precio_html);
  $(".woocommerce-product-gallery__image img").each(function () {
    $(this).data("base-src", $(this).attr("src"));
  });
  $(".descripcion-de-producto").data("base-desc", window.productoPadre.descripcion);
  $(".otras-caracteristicas-tecnicas-list").data("base-html", window.productoPadre.otras_caracteristicas_html);
  $(".caracteristicas-tecnicas-list").data("base-html", window.productoPadre.caracteristicas_tecnicas_html);

  console.log("💾 Producto padre almacenado:", window.productoPadre);
  console.log("🔎 .titulo_de_producto length:", $(".titulo_de_producto").length);
  console.log("🔎 .product_title length:", $(".product_title").length);
  console.log("🔎 Imagen padre src:", window.productoPadre.imagen.src);

  console.log("[variaciones-front] ⚡ Sistema de selección optimizado");

  // 1️⃣ Carga de datos base
  const VARS = Object.entries(window.variacionesDeProducto || {}).map(([id, data]) => ({
    id,
    attributes: data.attributes || {},
  }));

  console.log("📦 Variaciones cargadas:", VARS.length);
  if (VARS.length) {
    console.log("👀 Primera variación de ejemplo:", VARS[0]);
  }

  if (!VARS.length) {
    console.warn("⚠️ No hay variaciones disponibles");
    console.groupEnd();
    return;
  }

  const $checkboxes = $("input.form-check-filters");
  console.log("📌 Checkboxes encontrados:", $checkboxes.length);

  if (!$checkboxes.length) {
    console.warn("⚠️ No se encontraron checkboxes de atributos");
    console.groupEnd();
    return;
  }

  const seleccion = {};
  const urlParams = new URLSearchParams(window.location.search);
  const vParam = urlParams.get("v");
  let haRedirigido = false;

  // 2️⃣ Utilidades
  const normalizarClave = (k) => {
    if (!k) return "";
    if (k.startsWith("attribute_")) return k;
    if (k.startsWith("pa_")) return "attribute_" + k;
    return "attribute_pa_" + k;
  };
  const isEmpty = (v) => !v || v === "";

  // 3️⃣ ⚡️ Construye un índice rápido de combinaciones
  console.group("🧮 Construyendo MAPA_VARIACIONES");
  const MAPA_VARIACIONES = {};
  for (const [id, v] of Object.entries(window.variacionesDeProducto || {})) {
    const attrs = v.attributes || {};
    console.log(`   ➕ Variación ${id} attrs:`, attrs);
    for (const [k, val] of Object.entries(attrs)) {
      if (!val) continue;
      MAPA_VARIACIONES[k] = MAPA_VARIACIONES[k] || {};
      MAPA_VARIACIONES[k][val] = MAPA_VARIACIONES[k][val] || new Set();
      MAPA_VARIACIONES[k][val].add(id);
    }
  }
  console.log("🗺 MAPA_VARIACIONES completo:", MAPA_VARIACIONES);
  console.groupEnd();
  // 4️⃣ Motor rápido de búsqueda: intersección de sets
  function obtenerCompatibles() {
    console.group("🔎 obtenerCompatibles()");
    console.log("🎯 Selección actual antes de calcular:", seleccion);

    let sets = [];

    for (const [attr, valSel] of Object.entries(seleccion)) {
      if (!valSel) continue;
      const setAtrib = MAPA_VARIACIONES[attr]?.[valSel];
      console.log(`   ➕ Atributo seleccionado ${attr}=${valSel} →`, setAtrib ? Array.from(setAtrib) : "(sin coincidencias)");
      if (setAtrib) sets.push(setAtrib);
    }

    if (sets.length === 0) {
      console.log("📂 Sin filtros → devolvemos todas las variaciones.");
      console.groupEnd();
      return VARS;
    }

    let inter = new Set(sets[0]);
    for (let i = 1; i < sets.length; i++) {
      inter = new Set([...inter].filter((x) => sets[i].has(x)));
    }

    const compatibles = VARS.filter((v) => inter.has(v.id));
    console.log("✅ IDs compatibles:", Array.from(inter));
    console.groupEnd();
    return compatibles;
  }

  // 5️⃣ Extrae valores válidos para cada atributo
  function extraerValoresValidos(variaciones, attr) {
    const set = new Set();
    for (const v of variaciones) {
      const val = v.attributes[attr];
      if (!isEmpty(val)) set.add(val);
    }
    console.log(`🔐 Valores válidos para ${attr}:`, Array.from(set));
    return set;
  }

  // 6️⃣ Aplica habilitación/deshabilitación visual
  function aplicarEstado(attr, validSet) {
    if (!attr || typeof attr !== "string") return;
    const nombre = attr.replace("attribute_", "");
    const $grupo = $(`input.form-check-filters[data-tax="${nombre}"]`);

    console.log(`🎨 aplicarEstado() para attr=${attr} (data-tax=${nombre}) → checkboxes:`, $grupo.length);

    $grupo.each(function () {
      const $chk = $(this);
      const val = $chk.val();

      if (validSet.has(val) || Object.keys(seleccion).length === 0) {
        $chk.prop("disabled", false)
          .closest(".checkbox-line")
          .removeClass("disabled invalid-option")
          .addClass("valid-option");
      } else {
        $chk.prop("disabled", true)
          .prop("checked", false)
          .closest(".checkbox-line")
          .removeClass("valid-option")
          .addClass("disabled invalid-option");

        if (seleccion[attr] === val) {
          console.log(`   ❌ Quitando de selección ${attr}=${val} por inválido`);
          delete seleccion[attr];
        }
      }
    });
  }

  // 7️⃣ Actualiza la URL sin recargar
  function actualizarURLsinRecargar() {
    const baseURL = window.location.href.split("?")[0];
    const params = new URLSearchParams();

    const vActual = urlParams.get("v");
    if (vActual) params.set("v", vActual);

    for (const [attr, val] of Object.entries(seleccion)) {
      if (val) params.set(attr, val);
    }

    const nuevaURL = `${baseURL}?${params.toString()}`;
    const actual = window.location.href.split("#")[0];

    if (decodeURIComponent(actual) !== decodeURIComponent(nuevaURL)) {
      window.history.replaceState({}, "", nuevaURL);
      console.log("🔗 URL actualizada:", nuevaURL);
    } else {
      console.log("🔗 URL sin cambios:", nuevaURL);
    }
  }

  // 🔧 Normaliza y fuerza la galería a mostrarse
  function fixGaleriaWoo() {
    console.group("🖼 fixGaleriaWoo()");
    const $gal = $(".woocommerce-product-gallery");
    const $img = $gal.find(".woocommerce-product-gallery__image img").first();

    console.log("📌 .woocommerce-product-gallery encontrados:", $gal.length);

    if (!$img.length) {
      console.warn("⚠️ No hay imagen en la galería");
      console.groupEnd();
      return;
    }

    let src = $img.attr("src");
    if (!src || src === "" || src.startsWith("data:")) {
      src = $img.attr("data-large_image") || $img.attr("data-src") || $img.closest("a").attr("href");
      if (src) $img.attr("src", src);
    }

    const srcset = $img.attr("srcset");
    if (!srcset || srcset === "false" || srcset === "") {
      $img.removeAttr("srcset").removeAttr("sizes");
    }

    $img.removeAttr("loading").removeAttr("decoding")
      .removeClass("lazyload lazyloading lazyloaded");

    $img.css({ opacity: 1, visibility: "visible" });

    if ($.fn.wc_product_gallery) {
      console.log("🔁 Re-inicializando wc_product_gallery");
      $gal.each(function () { $(this).wc_product_gallery(); });
    } else {
      console.warn("⚠️ $.fn.wc_product_gallery no disponible, disparando resize");
      $(window).trigger("resize");
    }

    console.groupEnd();
  }



function actualizarGaleria(vID) {
    iniciarCarga();

    $.post(copele_ajax.ajaxurl, {
        action: "galeria_variacion",
        pid: vID
    }, function (response) {

        if (!response.success) {
            finalizarCarga();
            console.warn("❌ Error en AJAX galería:", response);
            return;
        }

        console.log("🖼 Recibida galería de variación", vID);

        const $nuevaGaleria = $(response.data.html);
        const $galeriaActual = $(".woocommerce-product-gallery");

        // 🟥 1) ELIMINAR listeners, zoom y flexslider previos
        try {
            $galeriaActual.find("*").off();
            $galeriaActual.removeData();
        } catch (e) {}

        $galeriaActual.find(".zoomImg").remove();
        $galeriaActual.find(".flexslider, .flex-viewport").removeClass();
        $galeriaActual.replaceWith($nuevaGaleria);

        // 🔁 2) REINICIALIZAR WooCommerce Product Gallery DESDE CERO
        setTimeout(() => {

            const $gal = $(".woocommerce-product-gallery");

            // Inicializar galería nativa WooCommerce
            if ($.fn.wc_product_gallery) {
                console.log("🔁 wc_product_gallery() inicializado");
                $gal.each(function () {
                    $(this).wc_product_gallery();
                });
            }

            // Inicializar FlexSlider
            if ($.fn.flexslider) {
                console.log("🔄 flexslider reinicializado");
                $gal.find(".woocommerce-product-gallery__wrapper").flexslider({
                    animation: "slide",
                    controlNav: "thumbnails",
                    slideshow: false,
                    animationLoop: false,
                    smoothHeight: true
                });
                

            }
            // 🟩 FIX CRÍTICO: recalcular alturas del viewport
                setTimeout(() => {

                    const $gal = $(".woocommerce-product-gallery");

                    const $img = $gal.find(".woocommerce-product-gallery__image img").first();
                    const $viewport = $gal.find(".flex-viewport");

                    if ($img.length && $viewport.length) {

                        const altura = $img.height();

                        if (altura > 0) {
                            console.log("✔ FIX aplicado: altura viewport = ", altura);
                            $viewport.css("height", altura + "px");
                        } else {
                            console.warn("⚠ La imagen aún no tiene altura, reintentando…");

                            setTimeout(() => {
                                const altura2 = $img.height();
                                if (altura2 > 0) {
                                    console.log("✔ Segundo intento correcto → altura =", altura2);
                                    $viewport.css("height", altura2 + "px");
                                }
                            }, 120);
                        }
                    }

                    // Forzar refresco del zoom
                    $gal.trigger('woocommerce_gallery_init_zoom');
                    $(window).trigger('resize');

                }, 100);
            // Inspirar actualización
            $(document.body).trigger('wc-product-gallery-after-load');

        }, 80); // delay breve para asegurar que el DOM existe

        finalizarCarga();
    });
}


function actualizarGaleria_org(vID) {
  iniciarCarga();
  jQuery.post(copele_ajax.ajaxurl, {
      action: "galeria_variacion",
      pid: vID
  }, function (response) {

      if (!response.success) {
             finalizarCarga();
          console.warn("Error en AJAX galería:", response);
          return;
      }

      const html = response.data.html;

      // Reemplazar la galería completa
      const $nueva = jQuery(html);
      const $galeriaActual = jQuery(".woocommerce-product-gallery");

      $galeriaActual.replaceWith($nueva);

      // 🔄 REINICIALIZAR SLIDER Y GALERÍA DE WOOCOMMERCE
      setTimeout(() => {

          if (jQuery.fn.wc_product_gallery) {
              console.log("🔁 Inicializando wc_product_gallery()");
              jQuery(".woocommerce-product-gallery").each(function () {
                  jQuery(this).wc_product_gallery();
              });
          }

          if (jQuery.fn.flexslider) {
              console.log("🔁 Reinicializando FlexSlider");
              jQuery(".woocommerce-product-gallery").find(".woocommerce-product-gallery__wrapper").flexslider({
                  animation: "slide",
                  controlNav: "thumbnails",
                  slideshow: false,
                  animationLoop: false
              });


          }
          // 🟩 FIX CRÍTICO: recalcular alturas del viewport
              setTimeout(() => {

                  const $gal = $(".woocommerce-product-gallery");

                  const $img = $gal.find(".woocommerce-product-gallery__image img").first();
                  const $viewport = $gal.find(".flex-viewport");

                  if ($img.length && $viewport.length) {

                      const altura = $img.height();

                      if (altura > 0) {
                          console.log("✔ FIX aplicado: altura viewport = ", altura);
                          $viewport.css("height", altura + "px");
                      } else {
                          console.warn("⚠ La imagen aún no tiene altura, reintentando…");

                          setTimeout(() => {
                              const altura2 = $img.height();
                              if (altura2 > 0) {
                                  console.log("✔ Segundo intento correcto → altura =", altura2);
                                  $viewport.css("height", altura2 + "px");
                              }
                          }, 120);
                      }
                  }

                  // Forzar refresco del zoom
                  $gal.trigger('woocommerce_gallery_init_zoom');
                  $(window).trigger('resize');

              }, 100);

          jQuery(document.body).trigger("wc-product-gallery-after-load");

      }, 200);
      finalizarCarga();
  });
}




function fixFlexslider() {

    setTimeout(() => {

        const $viewport = $(".woocommerce-product-gallery .flex-viewport");

        if ($viewport.length) {

            const altura = $viewport.find(".woocommerce-product-gallery__image").first().height();

            if (altura > 0) {
                console.log("✔ Altura flexslider corregida:", altura);
                $viewport.css("height", altura + "px");
            } else {
                console.warn("⚠ No se pudo medir altura correcta de slide");
            }
        }

    }, 150);
}


  function restaurarProductoPadre() {
    console.group("♻️ restaurarProductoPadre()");
    const v = window.productoPadre || null;

    const baseTitleData = $(".product_title").data("base-title");
    const baseTitleText = $(".product_title").text();
    const baseTitle = baseTitleData || baseTitleText || v?.titulo || "";

    console.log("🔙 Restaurando título:", baseTitle);
    $(".product_title, .titulo_de_producto").text(baseTitle);

    const precioOriginal = $(".summary .price, .product .price").data("base-price");
    console.log("💶 Restaurando precio:", precioOriginal);
    if (precioOriginal) {
      $(".summary .price, .product .price").html(precioOriginal);
    }

    const $img = $(".woocommerce-product-gallery__image img");
    const srcOriginal = $img.data("base-src") || (v && v.imagen && v.imagen.src);
    console.log("🖼 Restaurando imagen:", srcOriginal);
    if (srcOriginal) {
      console.log("🖼 Restaurando imagen padre + zoom");

      activarLoaderImagen();
      $img.off("load.restaurar").on("load.restaurar", function () {
        console.log("✅ Imagen padre cargada");
        desactivarLoaderImagen();
      });
      setTimeout(desactivarLoaderImagen, 2000);

      $img.attr("src", srcOriginal);
      $img.attr("srcset", "");
      $img.attr("alt", window.productoPadre.imagen.alt || "");

      $img.attr("data-src", srcOriginal);
      $img.attr("data-large_image", srcOriginal);
      $img.removeAttr("data-large_image_width");
      $img.removeAttr("data-large_image_height");

      const $link = $img.closest("a");
      $link.attr("href", srcOriginal);
      $link.attr("title", window.productoPadre.imagen.alt || "");

      $(".zoomImg").remove();

      const $galleryDiv = $(".woocommerce-product-gallery__image").first();
    

      //fixGaleriaWoo();
      fixFlexslider();
      console.log("🔁 Re-render WooCommerce Product Tabs");
      $(document.body).trigger("init_wc_product_tabs");
      $(document.body).trigger("wc-init-tabbed-panels");
    }

    const descOriginal = $(".descripcion-de-producto").data("base-desc") || v?.descripcion;
    console.log("📄 Restaurando descripción:", descOriginal ? descOriginal.substring(0, 80) + "..." : "(vacía)");
    if (descOriginal) {
      $(".descripcion-de-producto").html(descOriginal);
    }

    if (window.productoPadre.caracteristicas_tecnicas_html) {
      console.log("⚙️ Restaurando características técnicas padre");
      $(".caracteristicas-tecnicas-list").html(window.productoPadre.caracteristicas_tecnicas_html);
    }

    if (window.productoPadre.otras_caracteristicas_html) {
      console.log("🧩 Restaurando otras características padre");
      $(".otras-caracteristicas-tecnicas-list").html(window.productoPadre.otras_caracteristicas_html);
    }

    $(".woocommerce-product-gallery__image img").each(function () {
      $(this).css("opacity", "1").css("visibility", "visible");
    });

    console.log("🎯 Volvemos al padre → mostramos complementarios y ocultamos descripción");
    $("#contenedor_complementarios").show();
    $(".pestana_descripcion").hide();
    $(".referencia_de_producto").hide();

    console.groupEnd();
  }
  // 🔁 Helper: actualizar referencia por AJAX en base a ID de producto/variación
 function actualizarReferenciaDesdeJS(vID) {
    const v = window.variacionesDeProducto[vID];
    if (!v || !v.sku) {
        jQuery("#referencia_producto_contenedor").html("");
        return;
    }

    // SKU base
    let skuBase = v.sku.split("-")[0];

    // Detectar bodegón
    let esBodegon = skuBase.startsWith("94");
   
    if (esBodegon) {
        jQuery("#referencia_producto_contenedor").html(`
            <p class="referencia_de_bodegon"><b></b></p>
        `);
    } else {
        jQuery("#referencia_producto_contenedor").html(`
            <p class="referencia_de_producto"><b>Ref: ${skuBase}</b></p>
        `);
    }
}
function actualizarImagenesAdicionales(vID) {
    if (!vID || !window.copele_ajax || !window.copele_ajax.ajaxurl) {
        console.warn("❌ No hay ajaxurl o vID");
        return;
    }
    iniciarCarga();
    jQuery.post(window.copele_ajax.ajaxurl, {
        action: "imagenes_variacion",
        pid: vID
    }, function (response) {

        if (!response.success) {
            console.warn("⚠️ No hay imágenes adicionales:", response);
            return;
        }

        // Reemplazar contenido del shortcode
        jQuery("#contenedor_imagenes_adicionales").html(response.data.html);
        finalizarCarga();
    });
}


  function intentarRedirigirSiUnica(compatibles) {
    console.group("🧠 intentarRedirigirSiUnica()");
    console.log("📊 Compatibles recibidos:", compatibles.map(v => v.id));





    const baseURL = window.location.href.split("?")[0];

    if (!Object.keys(seleccion).length) {
      console.log("⭕ Sin selección → volver al producto padre");

      const hayVEnURL = !!vParam || window.location.search.indexOf("v=") !== -1;

      if (hayVEnURL) {
        console.log("🔄 Estamos en variación (v en URL) → recarga completa al padre");
        activarLoaderGlobal();
        window.location.href = baseURL;
      } else {
        console.log("ℹ️ No hay v en URL → solo restaurar padre por JS");
        window.history.replaceState({}, "", baseURL);
        restaurarProductoPadre();
        // y dejamos la referencia que ya pintó el shortcode
      }

      console.groupEnd();
      return;
    }

    if (compatibles.length !== 1) {
      console.log("⚠️ No hay una única variación, hay:", compatibles.length);
      console.groupEnd();
      return;
    }

    const variacion = compatibles[0];
    const vID = variacion.id;
    console.log("✅ Única variación encontrada:", vID);
    console.log("🎯 Variación seleccionada → ocultamos complementarios y mostramos descripción");

    $("#contenedor_complementarios").hide();
    mostrarPestanaDescripcionCuandoEsteLista();
    actualizarGaleria(vID);


    if (!vID) {
      console.warn("❌ vID vacío");
      console.groupEnd();
      return;
    }

    const params = new URLSearchParams();
    params.set("v", vID);
    for (const [attr, val] of Object.entries(seleccion)) {
      if (val) params.set(attr, val);
    }
    const nuevaURL = `${baseURL}?${params.toString()}`;
    const actual = window.location.href.split("#")[0];

    console.log("🌐 actual:", actual);
    console.log("🌐 nuevaURL:", nuevaURL);

    if (decodeURIComponent(actual) === decodeURIComponent(nuevaURL)) {
      console.log("🔁 URL ya coincide, no cambio");
    } else {
      window.history.replaceState({}, "", nuevaURL);
      console.log("🔗 URL cambiada a:", nuevaURL);
    }

    const $product = $(".product").first();
    if ($product.length && !$(".instaload").length) {
      $product.css("position", "relative");
      const overlay = $(`
        <div class="instaload" style="
          position:absolute;inset:0;
          background:rgba(255,255,255,0.6);
          display:flex;align-items:center;justify-content:center;
          z-index:9999;backdrop-filter:blur(1px);">
            <div style="
              width:20px;height:20px;
              border:2px solid #bbb;
              border-top:2px solid #222;
              border-radius:50%;
              animation:spin .4s linear infinite;">
            </div>
        </div>
        <style>@keyframes spin{to{transform:rotate(360deg)}}</style>`);
      $product.append(overlay);
    }

    setTimeout(() => {
      console.groupCollapsed(`⚡ APLICAR variación [ID ${vID}]`);
      try {
        $(".instaload").remove();

        const v = window.variacionesDeProducto[vID];
        console.log("📦 Datos crudos de la variación:", v);
        if (!v) {
          console.warn("❌ No se encontró window.variacionesDeProducto[", vID, "]");
          console.groupEnd();
          console.groupEnd();
          return;
        }

        const tituloVar = v.titulo_variacion || "";
        if (tituloVar) {
          $(".titulo_de_producto, .product_title").text(tituloVar);
        }
        console.log("🧾 Título →", tituloVar || "(vacío)");

        // 🧾 Referencia: pedimos al servidor que decida (producto vs bodegón)
        actualizarReferenciaDesdeJS(vID);

        const desc = v.descripcion || "";
        if (desc) $(".descripcion-de-producto").html(desc);
        console.log("📄 Descripción →", desc ? `${desc.substring(0, 120)}...` : "(vacía)");

        const precio = v.price_html || "";
        if (precio) $(".summary .price, .product .price").html(precio);
        console.log("💲 Precio →", precio || "(sin precio)");

        //fixGaleriaWoo();
        refrescarPestanasProducto();
        fixFlexslider();
        console.log("🖼 Imagen actualizada →", v.image?.src);

        if (v.caracteristicas_tecnicas && Object.keys(v.caracteristicas_tecnicas).length) {
          let html = "";
          console.groupCollapsed("⚙️ Características técnicas");
          for (const [campo, valor] of Object.entries(v.caracteristicas_tecnicas)) {
            if (!valor) continue;
            const nombreLimpio = campo.replace(/_/g, " ");
            console.log(`${nombreLimpio}:`, valor);
            html += `
              <div class="caracteristicas-tecnicas-item">
                <div class="col-1"><p style=" text-transform: capitalize;"><b>${nombreLimpio}</b></p></div>
                <div class="col-2"><p>${valor}</p></div>
              </div>
              <hr>`;
          }
          console.groupEnd();
          $(".caracteristicas-tecnicas-list").html(html);
        } else {
          console.log("⚙️ Características técnicas → (ninguna)");
          $(".caracteristicas-tecnicas-list").empty();
        }

        console.log("🧩 RAW otros_caracteristicas:", v.otros_caracteristicas, "tipo:", typeof v.otros_caracteristicas);

        if (v.otros_caracteristicas) {
          let htmlOtros = "";
          console.groupCollapsed("🧩 Otras características procesadas");

          if (typeof v.otros_caracteristicas === "object" && !Array.isArray(v.otros_caracteristicas)) {
            Object.values(v.otros_caracteristicas).forEach((texto, i) => {
              if (!texto) return;
              console.log(`   ✔ item ${i + 1} =>`, texto);
              htmlOtros += `
                <div class="otras-caracteristicas-tecnicas-item">
                  <p style=" text-transform: capitalize;">${texto}</p>
                </div>
                <hr>
              `;
            });
          }

          if (Array.isArray(v.otros_caracteristicas)) {
            v.otros_caracteristicas.forEach((texto, i) => {
              if (!texto) return;
              console.log(`   ✔ item ${i + 1} =>`, texto);
              htmlOtros += `
                <div class="otras-caracteristicas-tecnicas-item">
                  <p style=" text-transform: capitalize;">>${texto}</p>
                </div>
                <hr>
              `;
            });
          }

          console.groupEnd();

          if (htmlOtros.trim() !== "") {
            $(".otras-caracteristicas-tecnicas-list").html(htmlOtros);
          } else {
            $(".otras-caracteristicas-tecnicas-list").empty();
          }

        } else {
          $(".otras-caracteristicas-tecnicas-list").empty();
        }

        if (v.video_datos && v.video_datos.length) {
          const contenedorVideo = $(".video-de-producto");
          if (contenedorVideo.length) {
            const videoHTML = v.video_datos
              .map((vid) => `<iframe width="560" height="315" src="${vid}" frameborder="0" allowfullscreen></iframe>`)
              .join("");
            contenedorVideo.html(videoHTML);
          }
          console.log("🎥 Videos →", v.video_datos);
        } else {
          console.log("🎥 Videos → (ninguno)");
          $(".video-de-producto").empty();
        }

        const urlFicha = v.ficha_tecnica_url;
        console.log("📄 urlFicha variación:", urlFicha);

        if (urlFicha) {
          const safeUrl = encodeURI(urlFicha);
          $("#ficha_tecnica_contenedor").html(`
            <iframe src="${safeUrl}" 
                    style="position:absolute;top:0;left:0;width:100%;height:100%;" 
                    frameborder="0" allowfullscreen></iframe>
          `);
          console.log("📄 Ficha técnica actualizada →", safeUrl);
        } else {
          const fichaPadre = window.productoPadre.ficha_tecnica_url;
          if (fichaPadre) {
            const safePadre = encodeURI(fichaPadre);
            $("#ficha_tecnica_contenedor").html(`
              <iframe src="${safePadre}" 
                      style="position:absolute;top:0;left:0;width:100%;height:100%;" 
                      frameborder="0" allowfullscreen></iframe>
            `);
            console.log("📄 Ficha técnica restaurada (Padre)");
          } else {
            $("#ficha_tecnica_contenedor").html(`
              <div style="padding:20px; text-align:center; color:#666;">
                  <p>No hay ficha técnica disponible para esta referencia.</p>
              </div>
            `);
            console.warn("❌ Sin ficha técnica disponible");
          }
         
        }

         // 🟩 Refuerzo AJAX para buscar la ficha técnica real actualizada
            actualizarFichaTecnica(vID, v);
            actualizarImagenesAdicionales(vID);

           // 🟪 Refuerzo AJAX para el video
          actualizarContenidoVideo(vID, v);


        console.log("✨ Variación mostrada sin recarga");
      } catch (e) {
        console.error("💥 ERROR al aplicar variación:", e);
      }

      console.groupEnd(); // APLICAR variación
      console.groupEnd(); // intentarRedirigirSiUnica
    }, 800);
  }

  // 9️⃣ Recalcula y actualiza interfaz
  function recalcular() {
    console.group("🔄 recalcular()");
    const compatibles = obtenerCompatibles();

    console.log("📊 Compatibles (IDs):", compatibles.map(v => v.id));

    const atributos = [
      ...new Set($checkboxes.map((_, el) => $(el).data("tax")).get()),
    ];
    console.log("📚 Atributos detectados por data-tax:", atributos);

    for (const tax of atributos) {
      const attr = normalizarClave(tax);
      const validSet = extraerValoresValidos(compatibles, attr);
      aplicarEstado(attr, validSet);
    }

    actualizarURLsinRecargar();
    intentarRedirigirSiUnica(compatibles);
    console.groupEnd();
  }
  // 🔟 Eventos de usuario
  $checkboxes.on("change", function () {
    haRedirigido = false;
    const $chk = $(this);
    const tax = $chk.data("tax");
    const attr = normalizarClave(tax);
    const val = $chk.val();

    console.group("🖱 change checkbox");
    console.log("🔘 Checkbox pulsado:", { tax, attr, val, checked: $chk.is(":checked") });

    $(`input.form-check-filters[data-tax="${tax}"]`).not($chk).prop("checked", false);

    if ($chk.is(":checked")) {
      seleccion[attr] = val;
    } else {
      delete seleccion[attr];
    }

    console.log("🧭 Selección actual:", seleccion);
    console.groupEnd();

    recalcular();
  });

  // 🔁 Preselección desde la URL (?v= y atributos)
  function preseleccionar(vID) {
    console.group("🎯 preseleccionar()");
    console.log("🔑 vParam recibido:", vID);

    if (vID) {
      const v = window.variacionesDeProducto[vID];
      console.log("🧩 Variación de URL:", v);
      if (v && v.attributes) {
        for (const [k, vval] of Object.entries(v.attributes)) {
          if (vval) seleccion[k] = vval;
        }
      }
    }

    for (const [key, value] of new URLSearchParams(window.location.search)) {
      if (key === "v") continue;
      if (key.startsWith("pa_") || key.startsWith("attribute_pa_")) {
        seleccion[normalizarClave(key)] = value;
      }
    }

    console.log("🧭 Selección después de leer URL:", seleccion);

    for (const [k, v] of Object.entries(seleccion)) {
      const nombre = k.replace("attribute_", "");
      console.log(`   👉 Marcando checkbox data-tax="${nombre}" value="${v}"`);
      $(`input.form-check-filters[data-tax="${nombre}"][value="${v}"]`).prop("checked", true);
    }

    console.groupEnd();
  }

  // 🚀 Inicialización
  console.log("🏁 Lanzando preseleccionar() con vParam:", vParam);
  preseleccionar(vParam);
  //fixGaleriaWoo();
  fixFlexslider();

  if (vParam) {
    // Si entramos ya con variación → ocultar complementarios y mostrar descripción
    $("#contenedor_complementarios").hide();
    mostrarPestanaDescripcionCuandoEsteLista();
    refrescarPestanasProducto();

    // 🔥 CARGA INICIAL de la referencia según ?v= (cuando se entra directamente en una variación)
    actualizarReferenciaDesdeJS(vParam);


  }
  // Si NO hay vParam, dejamos la referencia que ya pintó el shortcode [referencia_producto] para el padre

  haRedirigido = false;
  console.log("🏁 Lanzando recalcular() inicial");
  recalcular();

  console.groupEnd(); // INICIO variaciones-front


  // 🟩 ACTUALIZAR FICHA TÉCNICA (AJAX)
function actualizarFichaTecnica(vID, v) {

    if (!v || !v.sku) {
        console.warn("⚠ No se pudo obtener SKU para ficha técnica.");
        return;
    }

    // SKU base (sin sufijos de variación)
    let skuBase = v.sku.split("-")[0];

    console.log("📄 Buscando ficha técnica para SKU:", skuBase);

    // Loader visual opcional
    $("#ficha_tecnica_contenedor").html(`
        <div style="padding:20px;text-align:center;">
            <div style="
                width:26px;height:26px;
                border-radius:50%;
                border:3px solid #ccc;
                border-top-color:#333;
                animation:spinFT .6s linear infinite;
                margin:auto;
            "></div>
        </div>
        <style>
        @keyframes spinFT {to {transform:rotate(360deg)}}
        </style>
    `);
        iniciarCarga();

    $.post(copele_ajax.ajaxurl, {
        action: "copele_ficha_tecnica",
        sku: skuBase,
        product_id: vID
    }, function(response){
        
        if (!response.success) {
            console.warn("⚠ Error AJAX ficha técnica:", response);
            $("#ficha_tecnica_productor").html(
                "<p>No se pudo cargar la ficha técnica.</p>"
            );
            return;
        }

        console.log("📄 Ficha técnica recibida:", response);

        $("#ficha_tecnica_producto").html(response.data.html);

        $(document.body).trigger("init_wc_product_tabs");
        $(document.body).trigger("wc-init-tabbed-panels");
      finalizarCarga();

    });
}

// 🟪 ACTUALIZAR VIDEO AJAX
function actualizarContenidoVideo(vID, v) {

    if (!v || !v.sku) {
        console.warn("⚠ No se pudo obtener SKU para video.");
        return;
    }

    let skuBase = v.sku.split("-")[0];
    let lang = (document.documentElement.lang || "es").split("-")[0];

    console.log("🎥 Buscando video para SKU:", skuBase, "Idioma:", lang);

    $(".video-de-producto").html(`
        <div style="padding:20px;text-align:center;">
            <div style="
                width:26px;height:26px;
                border-radius:50%;
                border:3px solid #ccc;
                border-top-color:#333;
                animation:spinVideo .6s linear infinite;
                margin:auto;
            "></div>
        </div>
        <style>@keyframes spinVideo {to {transform:rotate(360deg)}}</style>
    `);
    iniciarCarga();
    $.post(copele_ajax.ajaxurl, {
        action: "copele_video_producto",
        product_id: vID,
        sku: skuBase,
        lang: lang   // ⬅ MANDAMOS IDIOMA
    }, function(response){

        if (!response.success) {
            console.warn("⚠ Error AJAX video:", response);
            $(".video-de-producto").html(
                "<p>No se pudo cargar el video.</p>"
            );
            return;
        }

        $(".video-de-producto").html(response.data.html);

        $(document.body).trigger("init_wc_product_tabs");
        $(document.body).trigger("wc-init-tabbed-panels");
        finalizarCarga();
    });
}



});
