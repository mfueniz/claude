# Noticias de LinkedIn en WordPress

Sistema para mostrar las últimas publicaciones de LinkedIn dentro del sitio,
con formato propio y actualización automática.

---

## Antes que nada: por qué fallan los plugins

LinkedIn **no publica un feed abierto** de las publicaciones de una página de
empresa. La URL `linkedin.com/company/.../posts/` está detrás de un muro de
autenticación: si no hay sesión iniciada, LinkedIn no entrega el contenido.

De ahí vienen los tres problemas que describiste:

| Lo que ves | Por qué pasa |
|---|---|
| El formato se ve mal | El plugin recibe HTML de LinkedIn y lo inyecta tal cual, con sus clases y estilos, que chocan con el tema. |
| No distingue título ni fecha | Una publicación de LinkedIn **no tiene título**: es un bloque de texto. El plugin no tiene de dónde sacarlo, así que muestra un párrafo entero o "Sin título". |
| No es automático | Sin acceso legítimo, el plugin raspa la página pública; LinkedIn la bloquea y el feed se congela. |

**No existe forma de saltarse esto.** Cualquier solución real necesita una
fuente de datos autorizada. Lo que sigue resuelve el resto: el formato, el
título, la fecha, la imagen y la actualización.

---

## Arquitectura

```
LinkedIn ──► Puente (genera un feed) ──► WordPress ──► Panel en la página
             paso 1, lo configuras tú     paso 2, lo hace este código
```

- **Paso 1 — el puente**: un servicio que tiene acceso autorizado a tu página y
  publica un feed. Lo eliges tú (opciones más abajo). Es el único punto que
  puede tener costo.
- **Paso 2 — la presentación**: el código de este repositorio. Lee ese feed,
  deduce el título, normaliza la fecha, extrae la imagen y lo dibuja.

---

## Paso 1: elegir el puente

### Zapier no sirve para esto

Conviene descartarlo de entrada, y no por el precio: **Zapier no tiene ningún
disparador para LinkedIn**. Su integración solo ofrece acciones para *publicar
en* LinkedIn; no puede *leer de* LinkedIn. No existe un "cuando aparezca una
publicación nueva en mi página".

Aparte, su plan gratuito tampoco daría: son Zaps de dos pasos, 100 tareas al
mes, y los webhooks son función de pago.

Zapier sí serviría para el camino inverso —escribir la noticia en WordPress y
que se publique sola en LinkedIn—, que es gratis y automático. Si algún día
prefieres que el sitio sea el original y LinkedIn la copia, ese camino está
abierto.

### Modo `api` — WordPress le pregunta directo a LinkedIn *(el único gratis para siempre)*

Sin intermediarios ni suscripciones. WordPress consulta la API oficial con tus
propias credenciales.

1. Crea una app en [developer.linkedin.com](https://developer.linkedin.com),
   asociada a la página de la Red Chilena.
2. Agrega el producto **Community Management API** y completa el formulario de
   acceso. Piden nombre legal, dirección, sitio web y política de privacidad.
3. Espera la aprobación: entre dos y cuatro semanas.
4. En el plugin, pon `RCEC_LI_MODO` en `'api'` y completa `RCEC_LI_ORG_ID`,
   `RCEC_LI_CLIENT_ID` y `RCEC_LI_CLIENT_SECRET`.
5. Entra a **Ajustes → Noticias LinkedIn** y pulsa *Conectar con LinkedIn*.

Quien autorice debe ser **administrador** de la página. El permiso se renueva
solo; si alguna vez caduca del todo, la pantalla te avisa y basta reconectar.

- **A favor:** gratis y permanente, sin depender de terceros.
- **En contra:** el trámite de aprobación, que puede demorar o ser rechazado.

### Modo `ingesta` — que un servicio externo empuje las publicaciones

WordPress abre una dirección de entrada y **cualquier** herramienta puede
enviarle publicaciones. No quedas atado a ningún proveedor: si mañana cambias
de servicio, en WordPress no tocas nada.

1. En el plugin, pon `RCEC_LI_MODO` en `'ingesta'` e inventa una contraseña
   larga para `RCEC_LI_TOKEN_INGESTA`.
2. Entra a **Ajustes → Noticias LinkedIn**: ahí aparece la dirección exacta y
   el formato del envío.
3. Configura el servicio para que envíe un POST a esa dirección con la cabecera
   `X-RCEC-Token` cada vez que publiques algo.

El servicio que tiene el disparador que necesitas es **[Make](https://www.make.com)**,
con el módulo *Watch Company Posts* de LinkedIn. Su plan gratuito **no lo pude
verificar** —su página de precios está bloqueada desde donde trabajo y las
fuentes secundarias se contradicen entre sí sobre cuántas operaciones consume
una revisión que no encuentra nada—. Compruébalo tú al registrarte: si el plan
gratuito no alcanza, revisa cada hora en vez de cada 15 minutos, que para un
panel de noticias es de sobra.

Reenviar la misma publicación **la actualiza en vez de duplicarla**, así que no
pasa nada si el servicio manda algo dos veces.

### Modo `feed` — leer una URL de RSS o JSON

Para servicios tipo [rss.app](https://rss.app), que generan un feed a partir de
la página de LinkedIn. Cinco minutos de configuración, pero es de pago
(~USD 10/mes) y depende de que mantengan su acceso.

Pon `RCEC_LI_MODO` en `'feed'` y la URL en `RCEC_LI_FEED`.

### En resumen

| Modo | Costo | Esfuerzo inicial | Depende de |
|---|---|---|---|
| `api` | Gratis, permanente | Alto (aprobación de LinkedIn) | Nadie |
| `ingesta` | Según el servicio | Medio | El puente que elijas |
| `feed` | ~USD 10/mes | Bajo | El proveedor del feed |

> **Sugerencia:** parte en `ingesta` para tener el panel funcionando pronto, y
> postula en paralelo a la API. Cuando te aprueben, cambias una línea y te
> desconectas del intermediario para siempre.

---

## Paso 2: instalar la presentación

### 2.1 El panel (bloque HTML personalizado)

1. En WordPress, edita la página y agrega un bloque **HTML personalizado**.
2. Pega el contenido completo de `linkedin-noticias.html`.
3. Dentro del bloque, busca `CONFIG` y reemplaza:

```js
feed: "PEGA-AQUI-LA-URL-DE-TU-FEED",
```

por la URL del paso 1. Guarda y listo.

**Ajustes disponibles** (todos dentro de `CONFIG`):

| Opción | Qué hace |
|---|---|
| `modo` | `"tarjeta"` diseño propio integrado al sitio · `"embed"` iframe oficial de LinkedIn |
| `maximo` | Cuántas publicaciones mostrar |
| `columnas` | `1`, `2` o `3` |
| `largoTitulo` | Caracteres antes de cortar el título |
| `cacheMinutos` | Cada cuánto vuelve a consultar el feed |
| `mostrarImagen` | Mostrar u ocultar la portada |
| `mostrarHashtags` | Mostrar los hashtags como etiquetas |
| `paginaLinkedIn` | Enlace del botón inferior (vacío `""` lo oculta) |

Los colores se cambian en el bloque `<style>`, en las variables que empiezan
con `--rcec-li-`.

### 2.2 El plugin de servidor

Necesario en los modos `api` e `ingesta`; opcional pero recomendado en `feed`.

Sube `linkedin-noticias-proxy.php` a `wp-content/mu-plugins/` (crea la carpeta
si no existe). Se activa solo. Edita el bloque de configuración de arriba según
el modo que hayas elegido en el paso 1, y revisa **Ajustes → Noticias LinkedIn**,
que te muestra el estado, la dirección de entrada y un botón para actualizar a
mano.

Qué agrega:

- **Resuelve el bloqueo CORS.** Algunos feeds no permiten que el navegador los
  lea directamente. El bloque HTML lo detecta y reintenta por aquí solo.
- **Acelera la carga.** El feed se consulta una vez cada 30 minutos para todo
  el sitio, no una vez por visitante.
- **Tolera caídas.** Si el puente deja de responder, se sigue mostrando la
  última copia buena en lugar de un panel vacío.
- **Shortcode `[linkedin_noticias cantidad="6"]`.** Úsalo si algún plugin de
  seguridad elimina los `<script>` de los bloques HTML.

### 2.3 Convertirlas en entradas reales (opcional)

Para que las publicaciones aparezcan en el buscador interno, en los archivos y
en Google, activa en el mismo archivo PHP:

```php
define( 'RCEC_LI_CREAR_ENTRADAS', true );
define( 'RCEC_LI_ESTADO_ENTRADAS', 'draft' );  // 'publish' para publicar solas
```

Crea el tipo de contenido **Noticias LinkedIn** y revisa el feed cada hora. Cada
publicación se identifica por su GUID, así que **no se duplican** aunque el
proceso corra muchas veces.

Sugerencia: déjalo en `'draft'` las primeras semanas. Así revisas cómo quedan
los títulos deducidos antes de que se publiquen solos.

---

## Cómo se deduce el título

Es el punto donde fallan los plugins genéricos. El orden es:

1. Si el feed trae un título limpio y corto, se usa.
2. Si no, la **primera línea con contenido real** — saltando emojis, viñetas y
   líneas de puros hashtags.
3. Si esa línea es muy larga, su **primera frase completa**.
4. Si no hay nada, `"Publicación del 12 de septiembre de 2026"`.

Además se limpian los artefactos típicos del feed: `hashtag#Educación` vuelve a
ser `#Educación`, las URLs sueltas y los enlaces `lnkd.in` se eliminan del
texto, y los hashtags se separan para mostrarse como etiquetas.

Ejemplo real:

```
Publicación:  🎓 Cerramos un nuevo ciclo de formación docente en educación
              del carácter.

              Durante tres jornadas trabajamos con más de 120 profesores...

              hashtag#EducacionDelCaracter hashtag#FormacionDocente

Título:       Cerramos un nuevo ciclo de formación docente en educación del carácter
Resumen:      Durante tres jornadas trabajamos con más de 120 profesores...
Etiquetas:    #EducacionDelCaracter  #FormacionDocente
```

---

## Si algo no funciona

| Síntoma | Causa probable |
|---|---|
| "Falta configurar la URL del feed" | No reemplazaste el valor de `feed` en `CONFIG`. |
| El panel queda cargando o vacío | El feed no permite lectura desde el navegador. Instala el archivo PHP (2.2). |
| Se ve el HTML como texto plano | Usaste un bloque de *Párrafo* en vez de *HTML personalizado*. |
| Nada se muestra y la consola no dice nada | Un plugin de seguridad eliminó los `<script>`. Usa el shortcode (2.2). |
| Las fechas salen en inglés | El idioma del sitio no es español. El PHP usa el de WordPress; el bloque fuerza `es-CL`. |
| Los títulos salen cortados raro | Sube `largoTitulo` en `CONFIG` a 110 o 120. |

Para diagnosticar, abre la consola del navegador (F12): los errores del bloque
aparecen con el prefijo `[rcec-li]`.

---

## Archivos

| Archivo | Dónde va |
|---|---|
| `linkedin-noticias.html` | Bloque *HTML personalizado* en la página |
| `linkedin-noticias-proxy.php` | `wp-content/mu-plugins/` |
| `LINKEDIN-INSTRUCCIONES.md` | Referencia, no se sube a WordPress |
| `MAKE-CONFIGURACION.md` | Guía paso a paso del modo `ingesta` con Make |
