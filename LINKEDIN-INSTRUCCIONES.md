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

### Opción A — Zapier o Make *(recomendada)*

La vía oficial. Usa el conector **LinkedIn Pages**, que accede con permiso real
a tu página. Requiere ser **administrador** de la página de empresa.

1. Crea una cuenta en [Make](https://www.make.com) o [Zapier](https://zapier.com).
2. Conecta tu cuenta de LinkedIn y autoriza la página de la Red Chilena.
3. Disparador: *New Post on Company Page* / *Nueva publicación*.
4. Acción: enviar los datos a WordPress, a una hoja de cálculo publicada como
   JSON, o a cualquier destino que entregue una URL fija.

Ventaja: es estable y no se rompe. Ambos tienen plan gratuito suficiente para
revisar cada hora.

### Opción B — RSS.app o similar *(la más rápida de montar)*

Servicios que generan un feed RSS/JSON a partir de una página de LinkedIn.

1. Entra a [rss.app](https://rss.app) → *New Feed* → **LinkedIn**.
2. Pega la URL de la página:
   `https://www.linkedin.com/company/red-chilena-por-la-educación-del-carácter/`
3. Copia la URL del feed que te entrega (termina en `.json` o `.xml`).

Ventaja: cinco minutos y funciona. Desventaja: es de pago (~USD 10/mes) y
depende de que el servicio mantenga su acceso.

### Opción C — API oficial de LinkedIn

La **Community Management API** entrega las publicaciones de tu propia página.
Requiere postular al programa de desarrolladores y que aprueben la solicitud
(semanas). Solo vale la pena si el sitio es de alto tráfico.

> Cualquiera de las tres termina igual: **una URL de feed**. Eso es lo único
> que necesitas para el paso 2.

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

### 2.2 El puente de servidor (opcional, recomendado)

Sube `linkedin-noticias-proxy.php` a `wp-content/mu-plugins/` (crea la carpeta
si no existe). Se activa solo. Edita arriba del archivo:

```php
define( 'RCEC_LI_FEED', 'PEGA-AQUI-LA-URL-DE-TU-FEED' );
```

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
