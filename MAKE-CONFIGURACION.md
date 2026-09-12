# Configurar Make para alimentar las noticias

Guía paso a paso del modo `ingesta`: Make vigila tu página de LinkedIn y
empuja cada publicación nueva a WordPress.

```
LinkedIn ──► Make (Watch Company Posts) ──► HTTP POST ──► WordPress ──► Panel
```

---

## Antes de empezar: la única dependencia a verificar

El escenario necesita el módulo **HTTP → Make a request**. Al crear tu cuenta,
comprueba que puedas agregarlo: es lo único de todo este montaje que podría no
estar en el plan gratuito, y las fuentes públicas se contradicen al respecto.

**Si está disponible:** sigue esta guía completa.
**Si aparece bloqueado:** avísame y cambiamos de estrategia — no tiene arreglo
desde WordPress.

---

## Paso 1: preparar WordPress

### 1.1 Definir la contraseña de entrada

Abre `linkedin-noticias-proxy.php` y completa estas dos líneas:

```php
define( 'RCEC_LI_MODO', 'ingesta' );
define( 'RCEC_LI_TOKEN_INGESTA', 'aquí-va-tu-contraseña-larga' );
```

La contraseña es lo único que impide que un desconocido publique noticias
falsas en tu sitio. Que sea larga y aleatoria, y **no la compartas por correo
ni la subas a ningún repositorio**.

### 1.2 Subir el plugin

Sube el archivo a `wp-content/mu-plugins/` (crea la carpeta si no existe). Se
activa solo.

### 1.3 Comprobar que WordPress recibe

Antes de tocar Make, verifica el lado de WordPress por separado. Así, si algo
falla después, ya sabes que no es aquí. Desde tu computador:

```bash
curl -X POST "https://TU-SITIO.cl/wp-json/rcec/v1/linkedin/ingest" \
  -H "X-RCEC-Token: TU-CONTRASEÑA" \
  -H "Content-Type: application/json" \
  -d '{
        "id":     "prueba-1",
        "texto":  "Publicación de prueba.\n\nSi ves esto en el panel, la conexión funciona.",
        "enlace": "https://www.linkedin.com/company/red-chilena-por-la-educación-del-carácter/",
        "fecha":  "2026-09-12T10:00:00Z"
      }'
```

Respuesta esperada:

```json
{"recibidas":[{"estado":"creada","titulo":"Publicación de prueba","guardadas":1}]}
```

| Si responde | Significa |
|---|---|
| `403` | La contraseña no coincide, o quedó vacía en el plugin. |
| `404` | El plugin no está activo, o los enlaces permanentes están en «Simple». |
| `"estado":"rechazada"` | Llegó sin texto utilizable. |

Cuando funcione, entra a **Ajustes → Noticias LinkedIn**: debería aparecer «1
publicación disponible». Esa entrada de prueba desaparecerá sola cuando entren
publicaciones reales (se guardan las 30 más recientes).

---

## Paso 2: crear el escenario en Make

En [make.com](https://www.make.com) → **Create a new scenario**.

### Módulo 1 — LinkedIn: Watch Company Posts

1. Busca **LinkedIn** y elige el disparador que vigile las publicaciones de la
   página (*Watch Company Posts* o equivalente).
2. *Create a connection*. Make ofrece **dos métodos de conexión** y aquí se
   pierde mucha gente:

   | Método | Para qué sirve |
   |---|---|
   | **LinkedIn** | El que necesitas. Da acceso a organizaciones y publicaciones. |
   | **LinkedIn (OpenID Connect)** | Solo identifica quién eres. **No sirve**: no da acceso a las publicaciones de la página. |

   Elige **LinkedIn**, el primero.

3. Autoriza con la cuenta que sea **administradora** de la página. Si no lo
   eres, pídeselo a quien administre la página: sin ese rol LinkedIn no entrega
   las publicaciones.

   La documentación de Make solo exige *tener una cuenta de LinkedIn*: no pide
   LinkedIn Premium ni ningún plan de pago de LinkedIn.
3. **Organization / Company Page**: elige Red Chilena por la Educación del Carácter.
4. **Limit**: `5`. Suficiente, y evita gastar operaciones de más.

Pulsa **Run once**. Si aparecen tus publicaciones reales, la parte difícil ya
está resuelta.

### Módulo 2 — HTTP: Make a request

Conéctalo a la salida del módulo 1.

| Campo | Valor |
|---|---|
| **URL** | `https://TU-SITIO.cl/wp-json/rcec/v1/linkedin/ingest` |
| **Method** | `POST` |
| **Headers** | Nombre `X-RCEC-Token`, valor: tu contraseña |
| **Body type** | `Raw` |
| **Content type** | `JSON (application/json)` |
| **Request content** | el bloque de abajo |

```
{
  "id":     {{toJSON(1.id)}},
  "texto":  {{toJSON(1.text)}},
  "enlace": {{toJSON(1.url)}},
  "fecha":  {{toJSON(1.created)}}
}
```

**Fíjate en que no hay comillas alrededor de `{{toJSON(...)}}`.** Esto importa
más de lo que parece: el texto de una publicación trae comillas, saltos de
línea y emojis, y escribirlo como `"texto": "{{1.text}}"` rompe el JSON en
cuanto alguien use una comilla. `toJSON()` lo escapa correctamente y pone las
comillas él mismo.

> Los nombres exactos de los campos (`1.text`, `1.url`, `1.created`) pueden
> variar según la versión del módulo. **Mapea por significado**, no por nombre:
> usa el selector de campos de Make y elige el que contenga el texto de la
> publicación, el que tenga el enlace y el que tenga la fecha. El endpoint
> acepta la fecha en ISO, en segundos o en milisegundos, así que no te
> preocupes por el formato.

Solo **`texto`** es obligatorio. Si el módulo entrega también la imagen, agrega
`"imagen": {{toJSON(1.imagen)}}` con el campo que corresponda.

---

## Paso 3: programar

Pulsa el reloj del primer módulo:

- **Every hour** — recomendado. Son ~720 revisiones al mes.
- **Every 15 minutes** — serían ~2.880 revisiones al mes, sobre las 1.000
  gratuitas si Make cobra las revisiones vacías.

Para un panel de noticias, una hora es de sobra. Activa el escenario con el
interruptor **ON**.

---

## Paso 4: mostrar el panel

Agrega un bloque **HTML personalizado** con `linkedin-noticias.html` y deja la
configuración así:

```js
feed: "",                              // vacío: en modo ingesta no se usa
proxy: "/wp-json/rcec/v1/linkedin",    // de aquí lee el panel
```

O, si prefieres no usar JavaScript, el shortcode:

```
[linkedin_noticias cantidad="6" columnas="3"]
```

---

## Si algo falla

| Síntoma | Dónde mirar |
|---|---|
| Make marca el módulo HTTP en rojo con `403` | La contraseña del header no coincide con la del plugin. |
| Marca `404` | Revisa la URL. Prueba a abrir `https://TU-SITIO.cl/wp-json/` en el navegador: debe devolver JSON. |
| Marca `400` | El JSON quedó mal armado. Casi siempre son comillas sobrantes alrededor de `{{toJSON(...)}}`. |
| Make dice OK pero el panel no cambia | Mira **Ajustes → Noticias LinkedIn**. Si ahí sí aparecen, es la caché del bloque: espera 30 minutos o pulsa «Actualizar ahora». |
| El módulo de LinkedIn no lista tu página | La cuenta conectada no es administradora de la página. |
| Los títulos salen raros | Sube `largoTitulo` en el bloque a 110 o 120. |

Para ver qué envió Make exactamente: en el historial del escenario, abre la
ejecución y despliega el módulo HTTP. Ahí está el cuerpo enviado y la respuesta
de WordPress.

---

## Consumo de operaciones

Cada ejecución con una publicación nueva gasta aproximadamente:

| Concepto | Operaciones |
|---|---|
| Revisión del disparador | 1 |
| Envío HTTP por publicación | 1 por cada una |

Con una revisión por hora y unas 20 publicaciones al mes: ~740 operaciones,
dentro de las 1.000 gratuitas. Si te acercas al límite, baja la frecuencia a
cada dos horas: `Every 2 hours` deja el consumo en ~380.

Si Make **no** cobra las revisiones vacías, el margen es mucho mayor y puedes
subir la frecuencia sin problema. Míralo en tu panel de uso después de la
primera semana y ajusta.
