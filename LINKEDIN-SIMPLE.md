# Noticias de LinkedIn — versión simple

Para publicar una noticia, pegas el enlace de la publicación. Nada más.

Sin plugins, sin instalar nada, sin cuentas en otros servicios, sin
configuración. Un solo archivo pegado en un bloque de WordPress.

---

## Por qué esta versión sí es simple

LinkedIn permite **incrustar oficialmente** una publicación a partir de su
enlace. No hace falta API, ni clave, ni permiso, ni intermediario.

Y como la publicación la dibuja **LinkedIn**, se ve exactamente como el
original: el texto con sus saltos de línea, la imagen, el nombre de la página,
la fecha. El problema del principio —que el formato se veía mal y no se
distinguía el título— deja de existir, porque ya no hay que adivinar nada.

---

## Instalación (una sola vez)

1. Edita la página de WordPress donde quieres las noticias.
2. Agrega un bloque **HTML personalizado**.
3. Pega el contenido completo de `linkedin-noticias-simple.html`.
4. Guarda.

Eso es todo. No hay paso 5.

---

## Publicar una noticia (cada vez)

1. En LinkedIn, abre la publicación que quieres mostrar.
2. Pulsa los **tres puntos (···)** arriba a la derecha de la publicación.
3. Elige **«Copiar enlace de la publicación»**.
4. En WordPress, edita el bloque y pega el enlace en la lista, **en una línea
   nueva**. La lista está arriba del todo, marcada con flechas:

```
⬇⬇⬇  PEGA AQUÍ LOS ENLACES, UNO POR LÍNEA  ⬇⬇⬇

https://www.linkedin.com/posts/...primera-publicacion...
https://www.linkedin.com/posts/...segunda-publicacion...
https://www.linkedin.com/posts/...tercera-publicacion...

⬆⬆⬆  FIN DE LA LISTA DE ENLACES  ⬆⬆⬆
```

5. Guarda la página.

**Para quitar una noticia:** borra su línea.
**Para cambiar el orden:** mueve las líneas. Se muestran en el orden de la lista.

### Sin miedo a romper nada

Los enlaces van dentro de una zona que el navegador **no interpreta como
código**. Aunque pegues algo mal, no puedes romper la página: como mucho, esa
línea no se mostrará y aparecerá un aviso diciendo cuál fue.

Tampoco importa si el enlace trae cosas pegadas al final como
`?utm_source=share&utm_medium=member_desktop`. Se entiende igual.

---

## Qué enlaces acepta

Cualquiera de estos tres, para que ninguno falle:

| De dónde sale | Ejemplo |
|---|---|
| «Copiar enlace de la publicación» | `linkedin.com/posts/empresa_texto-activity-7123…-AbCd/` |
| La barra de direcciones del navegador | `linkedin.com/feed/update/urn:li:activity:7123…/` |
| «Insertar esta publicación» (el código completo) | `<iframe src="linkedin.com/embed/…"></iframe>` |

Si alguna publicación no se ve, usa el tercero: en LinkedIn, tres puntos →
**«Insertar esta publicación»**, copia el código completo que te muestra y
pégalo como una línea más. Es el que da el propio LinkedIn, así que funciona
siempre.

---

## Ajustes

Están arriba del bloque `<style>`, en las primeras líneas. Cambia el número y
listo.

| Ajuste | Qué hace |
|---|---|
| `--rcec-lis-columnas` | Cuántas por fila. En pantallas angostas se apilan solas. |
| `--rcec-lis-alto` | Alto de cada publicación. Súbelo si las tuyas son largas. |
| `--rcec-lis-separacion` | Espacio entre tarjetas. |
| `--rcec-lis-acento` | Color de los enlaces y el botón. |
| `--rcec-lis-radio` | Cuánto se redondean las esquinas. |

Más abajo, en `CONFIG`, puedes cambiar los textos y el enlace del botón final.
Si dejas `paginaLinkedIn` en `""`, el botón desaparece.

---

## Qué esperar

**Se actualiza solo, pero no se agrega solo.** Si editas la publicación en
LinkedIn, el cambio se ve en tu sitio sin que hagas nada. Lo que sí es manual
es *agregar* una noticia nueva: pegar su enlace.

**El visitante no necesita cuenta de LinkedIn** para ver las publicaciones.

**Una advertencia honesta:** algunos visitantes usan bloqueadores de publicidad
o rastreo que impiden cargar contenido de LinkedIn. A ellos el recuadro les
puede quedar vacío. Por eso cada tarjeta lleva abajo un enlace
**«Ver publicación en LinkedIn»** siempre visible: aunque el recuadro falle,
nadie se queda sin poder llegar a la publicación.

---

## Si algo no se ve

| Síntoma | Qué hacer |
|---|---|
| Aparece «No se reconoció este enlace» | Ese enlace no es de una publicación. Usa «Copiar enlace de la publicación», no el de la página de empresa. |
| Aparece «Todavía no hay publicaciones» | La lista está vacía, o solo tiene el enlace de ejemplo que viene de fábrica. |
| Un recuadro sale en blanco | Esa publicación puede estar restringida, o el enlace apunta a otro tipo de contenido. Prueba con «Insertar esta publicación». |
| Se ve el código como texto | Usaste un bloque de *Párrafo* en vez de *HTML personalizado*. |
| Queda mucho espacio vacío bajo el texto | Baja `--rcec-lis-alto`. |
| La publicación aparece cortada | Sube `--rcec-lis-alto`. Mientras tanto se puede desplazar dentro del recuadro. |

---

## Si más adelante quieres que sea automático

Esto queda guardado y no estorba. Los otros archivos del repositorio
(`linkedin-noticias.html`, `linkedin-noticias-proxy.php`) hacen que las
publicaciones aparezcan solas, sin pegar enlaces, pero exigen montar un puente
con LinkedIn y mantenerlo. Puedes cambiar cuando quieras, o nunca.

Los dos sistemas son independientes: no se estorban entre sí.
