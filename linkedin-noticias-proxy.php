<?php
/**
 * Plugin Name: Noticias LinkedIn — Puente y sincronización
 * Description: Lee el feed de publicaciones de LinkedIn desde el servidor (sin bloqueo CORS), lo guarda en caché y, opcionalmente, lo convierte en entradas reales de WordPress.
 * Version:     1.0.0
 * Author:      Red Chilena por la Educación del Carácter
 * License:     GPL-2.0-or-later
 *
 * INSTALACIÓN
 *   Opción A (recomendada): sube este archivo a  wp-content/mu-plugins/
 *                           Se activa solo, nadie puede desactivarlo por error.
 *   Opción B:               súbelo a wp-content/plugins/ y actívalo desde el panel.
 *
 * CONFIGURACIÓN
 *   Edita las constantes del bloque siguiente. Es lo único necesario.
 *
 * QUÉ DEJA DISPONIBLE
 *   1. Endpoint JSON:  /wp-json/rcec/v1/linkedin
 *      Lo consume automáticamente el bloque HTML si el navegador
 *      bloquea el feed por CORS.
 *   2. Shortcode:      [linkedin_noticias cantidad="6"]
 *      Alternativa por si un plugin de seguridad elimina los <script>
 *      de los bloques HTML personalizados.
 *   3. Sincronización: crea entradas reales en el tipo de contenido
 *      "Noticias LinkedIn" para que aparezcan en buscadores,
 *      archivos y el buscador interno del sitio.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =========================================================
   CONFIGURACIÓN — EDITA SOLO ESTE BLOQUE
========================================================== */

/** URL del feed generado por el puente (RSS.app, Make, Zapier...). */
define( 'RCEC_LI_FEED', 'PEGA-AQUI-LA-URL-DE-TU-FEED' );

/** Minutos que se conserva la respuesta en caché antes de volver a consultar. */
define( 'RCEC_LI_CACHE_MINUTOS', 30 );

/** true = además crea entradas reales de WordPress. false = solo panel. */
define( 'RCEC_LI_CREAR_ENTRADAS', false );

/** Con qué estado se crean esas entradas: 'publish' o 'draft'. */
define( 'RCEC_LI_ESTADO_ENTRADAS', 'draft' );

/* =========================================================
   A PARTIR DE AQUÍ NO NECESITAS MODIFICAR NADA
========================================================== */

const RCEC_LI_CPT       = 'noticia_linkedin';
const RCEC_LI_META_GUID = '_rcec_li_guid';
const RCEC_LI_CACHE_KEY = 'rcec_li_feed_cache';
const RCEC_LI_CRON_HOOK = 'rcec_li_sincronizar';

/**
 * Descarga el feed y lo devuelve ya normalizado.
 *
 * La caché es deliberadamente tolerante a fallos: si el puente no
 * responde, se devuelve la última copia buena en lugar de un error.
 * Un panel de noticias desactualizado es mejor que uno vacío.
 *
 * @param bool $forzar Ignora la caché vigente y vuelve a consultar.
 * @return array|WP_Error
 */
function rcec_li_obtener_feed( $forzar = false ) {

	if ( ! $forzar ) {
		$cache = get_transient( RCEC_LI_CACHE_KEY );
		if ( is_array( $cache ) && ! empty( $cache['items'] ) ) {
			return $cache;
		}
	}

	$feed = RCEC_LI_FEED;

	if ( empty( $feed ) || 0 === strpos( $feed, 'PEGA-AQUI' ) ) {
		return new WP_Error(
			'rcec_li_sin_feed',
			__( 'Falta configurar RCEC_LI_FEED en el archivo del plugin.', 'rcec-li' ),
			array( 'status' => 500 )
		);
	}

	$respuesta = wp_remote_get(
		$feed,
		array(
			'timeout'    => 15,
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			'headers'    => array( 'Accept' => 'application/json, application/rss+xml, */*' ),
		)
	);

	if ( is_wp_error( $respuesta ) || 200 !== wp_remote_retrieve_response_code( $respuesta ) ) {
		// El feed falló. Servimos la última copia guardada, sin importar su edad.
		$respaldo = get_option( RCEC_LI_CACHE_KEY . '_respaldo' );
		if ( is_array( $respaldo ) && ! empty( $respaldo['items'] ) ) {
			$respaldo['obsoleto'] = true;
			return $respaldo;
		}

		return new WP_Error(
			'rcec_li_feed_inaccesible',
			__( 'No se pudo leer el feed de LinkedIn.', 'rcec-li' ),
			array( 'status' => 502 )
		);
	}

	$cuerpo = wp_remote_retrieve_body( $respuesta );
	$items  = rcec_li_parsear( $cuerpo );

	if ( empty( $items ) ) {
		return new WP_Error(
			'rcec_li_feed_vacio',
			__( 'El feed respondió, pero no contiene publicaciones.', 'rcec-li' ),
			array( 'status' => 502 )
		);
	}

	$datos = array(
		'actualizado' => time(),
		'items'       => $items,
	);

	set_transient( RCEC_LI_CACHE_KEY, $datos, RCEC_LI_CACHE_MINUTOS * MINUTE_IN_SECONDS );

	// Respaldo permanente: sobrevive al vaciado de caché y a los fallos del feed.
	update_option( RCEC_LI_CACHE_KEY . '_respaldo', $datos, false );

	return $datos;
}

/**
 * Convierte JSON o RSS en una lista uniforme.
 *
 * @param string $cuerpo Respuesta cruda del feed.
 * @return array
 */
function rcec_li_parsear( $cuerpo ) {

	$cuerpo = trim( $cuerpo );

	// ¿JSON?
	if ( '' !== $cuerpo && ( '{' === $cuerpo[0] || '[' === $cuerpo[0] ) ) {
		$datos = json_decode( $cuerpo, true );

		if ( JSON_ERROR_NONE === json_last_error() ) {
			$lista = array();

			if ( isset( $datos['items'] ) && is_array( $datos['items'] ) ) {
				$lista = $datos['items'];
			} elseif ( isset( $datos['entries'] ) && is_array( $datos['entries'] ) ) {
				$lista = $datos['entries'];
			} elseif ( is_array( $datos ) && isset( $datos[0] ) ) {
				$lista = $datos;
			}

			return array_values( array_filter( array_map( 'rcec_li_normalizar_json', $lista ) ) );
		}
	}

	return rcec_li_parsear_rss( $cuerpo );
}

/**
 * Normaliza un elemento proveniente de un feed JSON.
 *
 * @param array $item Elemento crudo.
 * @return array|null
 */
function rcec_li_normalizar_json( $item ) {

	if ( ! is_array( $item ) ) {
		return null;
	}

	$contenido = rcec_li_primer_valor( $item, array( 'content_html', 'description', 'content_text', 'content', 'summary' ) );
	$enlace    = rcec_li_primer_valor( $item, array( 'url', 'link', 'permalink' ) );
	$fecha     = rcec_li_primer_valor( $item, array( 'date_published', 'pubDate', 'published', 'date', 'created_at' ) );
	$imagen    = rcec_li_primer_valor( $item, array( 'image', 'thumbnail', 'banner_image', 'image_url' ) );

	if ( ! $imagen && isset( $item['enclosure'] ) && is_array( $item['enclosure'] ) ) {
		$imagen = rcec_li_primer_valor( $item['enclosure'], array( 'link', 'url' ) );
	}

	if ( ! $imagen ) {
		$imagen = rcec_li_imagen_desde_html( $contenido );
	}

	return rcec_li_construir(
		rcec_li_primer_valor( $item, array( 'title', 'titulo' ) ),
		$contenido,
		$enlace,
		$fecha,
		$imagen,
		rcec_li_primer_valor( $item, array( 'id', 'guid' ) )
	);
}

/**
 * Normaliza un feed RSS o Atom.
 *
 * @param string $xml Documento XML.
 * @return array
 */
function rcec_li_parsear_rss( $xml ) {

	$anterior = libxml_use_internal_errors( true );
	$doc      = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $anterior );

	if ( false === $doc ) {
		return array();
	}

	$nodos = isset( $doc->channel->item ) ? $doc->channel->item
		: ( isset( $doc->item ) ? $doc->item
		: ( isset( $doc->entry ) ? $doc->entry : array() ) );

	$items = array();

	foreach ( $nodos as $nodo ) {

		$contenido = (string) $nodo->description;

		$content = $nodo->children( 'http://purl.org/rss/1.0/modules/content/' );
		if ( isset( $content->encoded ) && (string) $content->encoded ) {
			$contenido = (string) $content->encoded;
		} elseif ( ! $contenido && isset( $nodo->content ) ) {
			$contenido = (string) $nodo->content;
		} elseif ( ! $contenido && isset( $nodo->summary ) ) {
			$contenido = (string) $nodo->summary;
		}

		$enlace = (string) $nodo->link;
		if ( ! $enlace && isset( $nodo->link['href'] ) ) {
			$enlace = (string) $nodo->link['href'];
		}

		// La imagen puede venir en media:content, media:thumbnail,
		// <enclosure> o incrustada en el HTML del contenido.
		$imagen = '';
		$media  = $nodo->children( 'http://search.yahoo.com/mrss/' );

		if ( isset( $media->content ) && isset( $media->content->attributes()->url ) ) {
			$imagen = (string) $media->content->attributes()->url;
		} elseif ( isset( $media->thumbnail ) && isset( $media->thumbnail->attributes()->url ) ) {
			$imagen = (string) $media->thumbnail->attributes()->url;
		} elseif ( isset( $nodo->enclosure ) && isset( $nodo->enclosure['url'] ) ) {
			$imagen = (string) $nodo->enclosure['url'];
		} else {
			$imagen = rcec_li_imagen_desde_html( $contenido );
		}

		$fecha = (string) $nodo->pubDate;
		if ( ! $fecha && isset( $nodo->published ) ) {
			$fecha = (string) $nodo->published;
		}
		if ( ! $fecha && isset( $nodo->updated ) ) {
			$fecha = (string) $nodo->updated;
		}

		$item = rcec_li_construir(
			(string) $nodo->title,
			$contenido,
			$enlace,
			$fecha,
			$imagen,
			isset( $nodo->guid ) ? (string) $nodo->guid : ''
		);

		if ( $item ) {
			$items[] = $item;
		}
	}

	return $items;
}

/**
 * Arma el registro final: deduce título, resumen, hashtags e identificador.
 *
 * @param string $titulo    Título declarado por el feed (suele faltar o venir sucio).
 * @param string $contenido HTML del cuerpo de la publicación.
 * @param string $enlace    URL de la publicación en LinkedIn.
 * @param string $fecha     Fecha en cualquier formato reconocible.
 * @param string $imagen    URL de la imagen destacada.
 * @param string $guid      Identificador único informado por el feed.
 * @return array|null
 */
function rcec_li_construir( $titulo, $contenido, $enlace, $fecha, $imagen, $guid = '' ) {

	$plano = rcec_li_limpiar( wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />', '</p>' ), "\n", $contenido ) ) );

	$titulo_final = rcec_li_deducir_titulo( $titulo, $plano );

	if ( ! $titulo_final ) {
		return null;
	}

	$marca = $fecha ? strtotime( $fecha ) : 0;

	return array(
		'titulo'    => $titulo_final,
		'resumen'   => rcec_li_deducir_resumen( $plano, $titulo_final ),
		'texto'     => $plano,
		'hashtags'  => rcec_li_hashtags( $plano ),
		'enlace'    => esc_url_raw( $enlace ),
		'fecha'     => $marca ? gmdate( 'c', $marca ) : '',
		'marca'     => $marca,
		'imagen'    => esc_url_raw( $imagen ),
		'guid'      => $guid ? $guid : md5( $enlace . $titulo_final ),
	);
}

/**
 * Elimina los artefactos habituales del texto exportado de LinkedIn.
 *
 * @param string $texto Texto plano.
 * @return string
 */
function rcec_li_limpiar( $texto ) {

	$texto = html_entity_decode( $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$texto = str_ireplace( 'hashtag#', '#', $texto );
	$texto = preg_replace( '#https?://\S+#u', ' ', $texto );
	$texto = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $texto );
	$texto = preg_replace( '/[ \t]+/u', ' ', $texto );
	$texto = preg_replace( '/\n{2,}/u', "\n", $texto );

	return trim( $texto );
}

/**
 * Deduce un título legible: la publicación de LinkedIn no trae uno.
 *
 * @param string $titulo_feed Título informado por el feed.
 * @param string $plano       Texto completo ya limpio.
 * @return string
 */
function rcec_li_deducir_titulo( $titulo_feed, $plano ) {

	$limite    = 90;
	$candidato = rcec_li_quitar_adorno( rcec_li_limpiar( $titulo_feed ) );

	$generico = (bool) preg_match( '/^(sin t[ií]tulo|untitled|linkedin|publicaci[oó]n)$/iu', $candidato );

	if ( $candidato && ! $generico && mb_strlen( $candidato ) <= $limite * 1.6 && ! rcec_li_solo_hashtags( $candidato ) ) {
		return rcec_li_cortar( $candidato, $limite );
	}

	foreach ( explode( "\n", $plano ) as $linea ) {
		$linea = rcec_li_quitar_adorno( $linea );

		if ( mb_strlen( $linea ) < 12 || rcec_li_solo_hashtags( $linea ) ) {
			continue;
		}

		if ( mb_strlen( $linea ) <= $limite ) {
			return rtrim( $linea, " .:;," );
		}

		// La línea es larga: nos quedamos con su primera frase.
		$frases = preg_split( '/(?<=[.!?…])\s+/u', $linea );
		if ( ! empty( $frases[0] ) && mb_strlen( $frases[0] ) <= $limite ) {
			return rtrim( $frases[0], ' .' );
		}

		return rcec_li_cortar( $linea, $limite );
	}

	return '';
}

/**
 * Resumen sin repetir el título ni arrastrar los hashtags.
 *
 * @param string $plano  Texto completo.
 * @param string $titulo Título ya deducido.
 * @return string
 */
function rcec_li_deducir_resumen( $plano, $titulo ) {

	// Primero descartamos las líneas de puro hashtag y el adorno inicial.
	// Si no, el texto empieza por "🎓 " o por "#Comunicado" y deja de
	// coincidir con el título, que sí venía limpio: el resumen acabaría
	// repitiendo el título entero.
	$lineas = array_values(
		array_filter(
			explode( "\n", $plano ),
			static function ( $linea ) {
				return ! rcec_li_solo_hashtags( $linea );
			}
		)
	);

	if ( isset( $lineas[0] ) ) {
		$lineas[0] = rcec_li_quitar_adorno( $lineas[0] );
	}

	$cuerpo = implode( ' ', $lineas );
	$base   = rtrim( $titulo, '…' );

	if ( $base && 0 === mb_stripos( $cuerpo, $base ) ) {
		$cuerpo = mb_substr( $cuerpo, mb_strlen( $base ) );
	}

	$resumen = $cuerpo;
	$resumen = preg_replace( '/#[\p{L}\p{N}_]+/u', '', $resumen );
	$resumen = preg_replace( '/\s{2,}/u', ' ', $resumen );
	$resumen = ltrim( $resumen, " .:;,-–—" );

	return rcec_li_cortar( trim( $resumen ), 200 );
}

/**
 * @param string $texto Texto a evaluar.
 * @return bool Verdadero si la línea solo contiene etiquetas o menciones.
 */
function rcec_li_solo_hashtags( $texto ) {

	$texto = trim( $texto );

	if ( '' === $texto ) {
		return true;
	}

	foreach ( preg_split( '/\s+/u', $texto ) as $palabra ) {
		if ( '#' !== mb_substr( $palabra, 0, 1 ) && '@' !== mb_substr( $palabra, 0, 1 ) ) {
			return false;
		}
	}

	return true;
}

/**
 * @param string $texto Línea de texto.
 * @return string Sin emojis ni viñetas iniciales.
 */
function rcec_li_quitar_adorno( $texto ) {
	return trim( preg_replace( '/^[\s\p{So}\p{Cn}•·▶►\-–—]+/u', '', $texto ) );
}

/**
 * Corta respetando palabras completas.
 *
 * @param string $texto  Texto original.
 * @param int    $limite Máximo de caracteres.
 * @return string
 */
function rcec_li_cortar( $texto, $limite ) {

	if ( mb_strlen( $texto ) <= $limite ) {
		return $texto;
	}

	$recorte = mb_substr( $texto, 0, $limite );
	$espacio = mb_strrpos( $recorte, ' ' );

	if ( false !== $espacio && $espacio > $limite * 0.6 ) {
		$recorte = mb_substr( $recorte, 0, $espacio );
	}

	return rtrim( $recorte, " ,;:.-–—" ) . '…';
}

/**
 * @param string $texto Texto completo.
 * @return array Hasta cuatro hashtags únicos.
 */
function rcec_li_hashtags( $texto ) {

	preg_match_all( '/#[\p{L}\p{N}_]+/u', $texto, $coincidencias );

	if ( empty( $coincidencias[0] ) ) {
		return array();
	}

	$unicos = array();

	foreach ( $coincidencias[0] as $etiqueta ) {
		$clave = mb_strtolower( $etiqueta );
		if ( ! isset( $unicos[ $clave ] ) ) {
			$unicos[ $clave ] = $etiqueta;
		}
	}

	return array_slice( array_values( $unicos ), 0, 4 );
}

/**
 * @param string $html Fragmento HTML.
 * @return string URL de la primera imagen utilizable.
 */
function rcec_li_imagen_desde_html( $html ) {

	if ( ! $html || ! preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $coincidencia ) ) {
		return '';
	}

	$src = $coincidencia[1];

	// Los píxeles de seguimiento no sirven como portada.
	if ( preg_match( '/1x1|pixel|spacer|tracking/i', $src ) ) {
		return '';
	}

	return $src;
}

/**
 * @param array $datos  Arreglo de origen.
 * @param array $claves Claves a probar en orden.
 * @return string Primer valor no vacío.
 */
function rcec_li_primer_valor( $datos, $claves ) {

	foreach ( $claves as $clave ) {
		if ( ! empty( $datos[ $clave ] ) && is_string( $datos[ $clave ] ) ) {
			return $datos[ $clave ];
		}
	}

	return '';
}

/* =========================================================
   ENDPOINT REST — lo usa el bloque HTML como respaldo
========================================================== */

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'rcec/v1',
			'/linkedin',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true', // Contenido público, igual que el panel.
				'callback'            => static function ( WP_REST_Request $peticion ) {

					$datos = rcec_li_obtener_feed( (bool) $peticion->get_param( 'forzar' ) );

					if ( is_wp_error( $datos ) ) {
						return $datos;
					}

					$cantidad = (int) $peticion->get_param( 'cantidad' );
					$items    = $datos['items'];

					if ( $cantidad > 0 ) {
						$items = array_slice( $items, 0, $cantidad );
					}

					$respuesta = rest_ensure_response(
						array(
							'actualizado' => $datos['actualizado'],
							'obsoleto'    => ! empty( $datos['obsoleto'] ),
							'items'       => $items,
						)
					);

					// Deja que el CDN y el navegador también cacheen.
					$respuesta->header( 'Cache-Control', 'public, max-age=' . ( RCEC_LI_CACHE_MINUTOS * MINUTE_IN_SECONDS ) );

					return $respuesta;
				},
				'args'                => array(
					'cantidad' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'forzar'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}
);

/* =========================================================
   SHORTCODE — [linkedin_noticias cantidad="6"]
   Render en servidor: funciona aunque el sitio bloquee <script>.
========================================================== */

add_shortcode(
	'linkedin_noticias',
	static function ( $atributos ) {

		$atributos = shortcode_atts(
			array(
				'cantidad' => 6,
				'columnas' => 3,
			),
			$atributos,
			'linkedin_noticias'
		);

		$datos = rcec_li_obtener_feed();

		if ( is_wp_error( $datos ) ) {
			return current_user_can( 'manage_options' )
				? '<p><em>' . esc_html( $datos->get_error_message() ) . '</em></p>'
				: '';
		}

		$items = array_slice( $datos['items'], 0, (int) $atributos['cantidad'] );

		ob_start();
		?>
		<div class="rcec-li" data-columnas="<?php echo esc_attr( (int) $atributos['columnas'] ); ?>">
			<div class="rcec-li__grid">
				<?php foreach ( $items as $item ) : ?>
					<article class="rcec-li__item">
						<?php if ( $item['imagen'] ) : ?>
							<div class="rcec-li__media">
								<img src="<?php echo esc_url( $item['imagen'] ); ?>" alt="" loading="lazy" decoding="async">
							</div>
						<?php else : ?>
							<div class="rcec-li__media rcec-li__media--vacia">
								<span class="rcec-li__marca"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
							</div>
						<?php endif; ?>

						<div class="rcec-li__cuerpo">
							<p class="rcec-li__meta">
								<span>LinkedIn</span>
								<?php if ( $item['marca'] ) : ?>
									<span class="rcec-li__punto"></span>
									<time datetime="<?php echo esc_attr( $item['fecha'] ); ?>">
										<?php echo esc_html( wp_date( 'j \d\e F, Y', $item['marca'] ) ); ?>
									</time>
								<?php endif; ?>
							</p>

							<h3 class="rcec-li__titulo">
								<a href="<?php echo esc_url( $item['enlace'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( $item['titulo'] ); ?>
								</a>
							</h3>

							<?php if ( $item['resumen'] ) : ?>
								<p class="rcec-li__resumen"><?php echo esc_html( $item['resumen'] ); ?></p>
							<?php endif; ?>

							<?php if ( ! empty( $item['hashtags'] ) ) : ?>
								<ul class="rcec-li__tags">
									<?php foreach ( $item['hashtags'] as $etiqueta ) : ?>
										<li class="rcec-li__tag"><?php echo esc_html( $etiqueta ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
);

/* =========================================================
   ENTRADAS REALES (opcional)
   Convierte cada publicación en contenido propio del sitio:
   indexable, buscable y con archivo cronológico.
========================================================== */

add_action(
	'init',
	static function () {

		if ( ! RCEC_LI_CREAR_ENTRADAS ) {
			return;
		}

		register_post_type(
			RCEC_LI_CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Noticias LinkedIn', 'rcec-li' ),
					'singular_name' => __( 'Noticia LinkedIn', 'rcec-li' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'show_in_rest' => true,
				'menu_icon'    => 'dashicons-megaphone',
				'rewrite'      => array( 'slug' => 'noticias-linkedin' ),
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
			)
		);
	}
);

/**
 * Crea o actualiza las entradas a partir del feed.
 *
 * Cada publicación se identifica por su GUID, así que ejecutar
 * esto varias veces no genera duplicados.
 *
 * @return int Cantidad de entradas nuevas.
 */
function rcec_li_sincronizar_entradas() {

	if ( ! RCEC_LI_CREAR_ENTRADAS ) {
		return 0;
	}

	$datos = rcec_li_obtener_feed( true );

	if ( is_wp_error( $datos ) ) {
		return 0;
	}

	$creadas = 0;

	foreach ( $datos['items'] as $item ) {

		$existentes = get_posts(
			array(
				'post_type'      => RCEC_LI_CPT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => RCEC_LI_META_GUID,
				'meta_value'     => $item['guid'],
			)
		);

		if ( ! empty( $existentes ) ) {
			continue;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => RCEC_LI_CPT,
				'post_status'  => RCEC_LI_ESTADO_ENTRADAS,
				'post_title'   => $item['titulo'],
				'post_excerpt' => $item['resumen'],
				'post_content' => wpautop( $item['texto'] ) . sprintf(
					'<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
					esc_url( $item['enlace'] ),
					esc_html__( 'Ver publicación original en LinkedIn', 'rcec-li' )
				),
				'post_date'    => $item['marca'] ? wp_date( 'Y-m-d H:i:s', $item['marca'] ) : current_time( 'mysql' ),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			continue;
		}

		update_post_meta( $id, RCEC_LI_META_GUID, $item['guid'] );
		update_post_meta( $id, '_rcec_li_enlace', $item['enlace'] );

		if ( $item['imagen'] ) {
			update_post_meta( $id, '_rcec_li_imagen', $item['imagen'] );
		}

		++$creadas;
	}

	return $creadas;
}

add_action( RCEC_LI_CRON_HOOK, 'rcec_li_sincronizar_entradas' );

// Programa la revisión horaria la primera vez que se carga el plugin.
add_action(
	'init',
	static function () {
		if ( RCEC_LI_CREAR_ENTRADAS && ! wp_next_scheduled( RCEC_LI_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', RCEC_LI_CRON_HOOK );
		}
	}
);
