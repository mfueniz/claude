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

/**
 * De dónde salen las publicaciones. Tres modos:
 *
 *   'api'     WordPress le pregunta directamente a LinkedIn.
 *             Gratis y permanente, sin intermediarios. Requiere
 *             aprobación de la Community Management API (2 a 4 semanas).
 *
 *   'ingesta' Un servicio externo (Make, n8n, lo que sea) empuja las
 *             publicaciones a WordPress. No dependes de ningún proveedor
 *             en particular: si cambias de puente, aquí no tocas nada.
 *
 *   'feed'    WordPress lee una URL de feed RSS o JSON.
 */
define( 'RCEC_LI_MODO', 'ingesta' );

/** Minutos que se conserva la respuesta en caché antes de volver a consultar. */
define( 'RCEC_LI_CACHE_MINUTOS', 30 );

/** true = además crea entradas reales de WordPress. false = solo panel. */
define( 'RCEC_LI_CREAR_ENTRADAS', false );

/** Con qué estado se crean esas entradas: 'publish' o 'draft'. */
define( 'RCEC_LI_ESTADO_ENTRADAS', 'draft' );

/* ---- Modo 'feed' ---------------------------------------- */

/** URL del feed generado por el puente. */
define( 'RCEC_LI_FEED', '' );

/* ---- Modo 'api' ----------------------------------------- */

/**
 * Solo el número de tu organización. Lo ves en la URL del panel de
 * administración de tu página: linkedin.com/company/12345678/admin/
 */
define( 'RCEC_LI_ORG_ID', '' );

/** Client ID y Client Secret de tu app en developer.linkedin.com. */
define( 'RCEC_LI_CLIENT_ID', '' );
define( 'RCEC_LI_CLIENT_SECRET', '' );

/**
 * Versión de la API de LinkedIn, en formato AAAAMM. LinkedIn la exige
 * y rechaza la petición si falta. Súbela una o dos veces al año.
 */
define( 'RCEC_LI_API_VERSION', '202506' );

/* ---- Modo 'ingesta' -------------------------------------- */

/**
 * Contraseña compartida con el servicio que empuja las publicaciones.
 * Invéntate una larga y aleatoria. Si queda vacía, la ingesta se
 * rechaza siempre: nadie puede escribir sin ella.
 */
define( 'RCEC_LI_TOKEN_INGESTA', '' );

/* =========================================================
   A PARTIR DE AQUÍ NO NECESITAS MODIFICAR NADA
========================================================== */

const RCEC_LI_CPT            = 'noticia_linkedin';
const RCEC_LI_META_GUID      = '_rcec_li_guid';
const RCEC_LI_CACHE_KEY      = 'rcec_li_feed_cache';
const RCEC_LI_CRON_HOOK      = 'rcec_li_sincronizar';
const RCEC_LI_OPCION_TOKEN   = 'rcec_li_token_linkedin';
const RCEC_LI_OPCION_INGESTA = 'rcec_li_ingestadas';

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

	// El modo 'ingesta' no consulta nada: las publicaciones ya llegaron
	// empujadas y están guardadas. Se sirven tal cual.
	if ( 'ingesta' === RCEC_LI_MODO ) {
		$guardadas = get_option( RCEC_LI_OPCION_INGESTA );

		if ( ! is_array( $guardadas ) || empty( $guardadas['items'] ) ) {
			return new WP_Error(
				'rcec_li_sin_ingesta',
				__( 'Todavía no ha llegado ninguna publicación. Revisa que el servicio externo esté enviando al endpoint de ingesta.', 'rcec-li' ),
				array( 'status' => 404 )
			);
		}

		return $guardadas;
	}

	if ( 'api' === RCEC_LI_MODO ) {
		$items = rcec_li_obtener_desde_api();

		if ( is_wp_error( $items ) ) {
			return rcec_li_respaldo_o_error( $items );
		}

		return rcec_li_guardar_cache( $items );
	}

	$feed = RCEC_LI_FEED;

	if ( empty( $feed ) ) {
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
		return rcec_li_respaldo_o_error(
			new WP_Error(
				'rcec_li_feed_inaccesible',
				__( 'No se pudo leer el feed de LinkedIn.', 'rcec-li' ),
				array( 'status' => 502 )
			)
		);
	}

	$items = rcec_li_parsear( wp_remote_retrieve_body( $respuesta ) );

	if ( empty( $items ) ) {
		return new WP_Error(
			'rcec_li_feed_vacio',
			__( 'El feed respondió, pero no contiene publicaciones.', 'rcec-li' ),
			array( 'status' => 502 )
		);
	}

	return rcec_li_guardar_cache( $items );
}

/**
 * Guarda en caché y deja un respaldo permanente.
 *
 * El respaldo es deliberado: sobrevive al vaciado de caché y permite
 * seguir mostrando noticias cuando el origen deja de responder. Un panel
 * desactualizado es mejor que uno vacío.
 *
 * @param array $items Publicaciones normalizadas.
 * @return array
 */
function rcec_li_guardar_cache( $items ) {

	$datos = array(
		'actualizado' => time(),
		'items'       => $items,
	);

	set_transient( RCEC_LI_CACHE_KEY, $datos, RCEC_LI_CACHE_MINUTOS * MINUTE_IN_SECONDS );
	update_option( RCEC_LI_CACHE_KEY . '_respaldo', $datos, false );

	return $datos;
}

/**
 * Devuelve la última copia buena si existe; si no, el error recibido.
 *
 * @param WP_Error $error Error que motivó el respaldo.
 * @return array|WP_Error
 */
function rcec_li_respaldo_o_error( $error ) {

	$respaldo = get_option( RCEC_LI_CACHE_KEY . '_respaldo' );

	if ( is_array( $respaldo ) && ! empty( $respaldo['items'] ) ) {
		$respaldo['obsoleto'] = true;
		return $respaldo;
	}

	return $error;
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
   MODO 'api' — WordPress le pregunta directamente a LinkedIn

   Sin intermediarios y sin costo recurrente. A cambio, hay que
   pedirle acceso a LinkedIn una vez (Community Management API).
========================================================== */

/**
 * Trae las publicaciones de la organización desde la API oficial.
 *
 * @return array|WP_Error
 */
function rcec_li_obtener_desde_api() {

	if ( ! RCEC_LI_ORG_ID ) {
		return new WP_Error(
			'rcec_li_sin_org',
			__( 'Falta configurar RCEC_LI_ORG_ID.', 'rcec-li' ),
			array( 'status' => 500 )
		);
	}

	$token = rcec_li_token_valido();

	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$url = add_query_arg(
		array(
			'q'      => 'author',
			'author' => rawurlencode( 'urn:li:organization:' . RCEC_LI_ORG_ID ),
			'count'  => 20,
			'sortBy' => 'LAST_MODIFIED',
		),
		'https://api.linkedin.com/rest/posts'
	);

	$respuesta = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => rcec_li_cabeceras_api( $token ) ) );

	// Un 401 casi siempre significa token vencido. Lo renovamos y reintentamos
	// una sola vez, para no entrar en un bucle si la credencial ya no sirve.
	if ( ! is_wp_error( $respuesta ) && 401 === wp_remote_retrieve_response_code( $respuesta ) ) {
		$token = rcec_li_renovar_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$respuesta = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => rcec_li_cabeceras_api( $token ) ) );
	}

	if ( is_wp_error( $respuesta ) ) {
		return new WP_Error( 'rcec_li_api_red', $respuesta->get_error_message(), array( 'status' => 502 ) );
	}

	$codigo = wp_remote_retrieve_response_code( $respuesta );

	if ( 200 !== $codigo ) {
		return new WP_Error(
			'rcec_li_api_error',
			/* translators: %d: código HTTP devuelto por LinkedIn. */
			sprintf( __( 'LinkedIn respondió %d. Revisa los permisos de la app y que el token siga vigente.', 'rcec-li' ), $codigo ),
			array( 'status' => 502 )
		);
	}

	$datos = json_decode( wp_remote_retrieve_body( $respuesta ), true );

	if ( empty( $datos['elements'] ) ) {
		return new WP_Error(
			'rcec_li_api_vacio',
			__( 'LinkedIn respondió sin publicaciones.', 'rcec-li' ),
			array( 'status' => 502 )
		);
	}

	$items = array();

	foreach ( $datos['elements'] as $elemento ) {

		if ( empty( $elemento['id'] ) ) {
			continue;
		}

		// createdAt viene en milisegundos desde epoch.
		$marca = isset( $elemento['createdAt'] ) ? (int) round( $elemento['createdAt'] / 1000 ) : 0;

		$item = rcec_li_construir(
			'',
			isset( $elemento['commentary'] ) ? $elemento['commentary'] : '',
			rcec_li_url_publicacion( $elemento['id'] ),
			$marca ? gmdate( 'c', $marca ) : '',
			rcec_li_imagen_desde_api( $elemento ),
			$elemento['id']
		);

		if ( $item ) {
			$items[] = $item;
		}
	}

	if ( empty( $items ) ) {
		return new WP_Error(
			'rcec_li_api_sin_texto',
			__( 'Las publicaciones recibidas no tienen texto utilizable.', 'rcec-li' ),
			array( 'status' => 502 )
		);
	}

	return $items;
}

/**
 * @param string $token Token de acceso vigente.
 * @return array Cabeceras que exige la API versionada de LinkedIn.
 */
function rcec_li_cabeceras_api( $token ) {
	return array(
		'Authorization'             => 'Bearer ' . $token,
		'LinkedIn-Version'          => RCEC_LI_API_VERSION,
		'X-Restli-Protocol-Version' => '2.0.0',
	);
}

/**
 * Construye el enlace público a partir del URN de la publicación.
 *
 * @param string $urn Por ejemplo urn:li:share:7123456789.
 * @return string
 */
function rcec_li_url_publicacion( $urn ) {
	// Sin codificar: los dos puntos son legales en un segmento de ruta, es la
	// forma canónica de LinkedIn, y así el modo «embed» del bloque puede
	// extraer el identificador numérico del enlace.
	return 'https://www.linkedin.com/feed/update/' . $urn . '/';
}

/**
 * Resuelve la imagen de una publicación.
 *
 * La API no entrega una URL directa: entrega el URN del recurso, y hay
 * que pedirle a otro endpoint la dirección de descarga. Como esa
 * dirección caduca, se guarda en caché por poco tiempo.
 *
 * @param array $elemento Publicación tal como la devuelve la API.
 * @return string
 */
function rcec_li_imagen_desde_api( $elemento ) {

	$urn = '';

	if ( ! empty( $elemento['content']['media']['id'] ) ) {
		$urn = $elemento['content']['media']['id'];
	} elseif ( ! empty( $elemento['content']['multiImage']['images'][0]['id'] ) ) {
		$urn = $elemento['content']['multiImage']['images'][0]['id'];
	} elseif ( ! empty( $elemento['content']['article']['thumbnail'] ) ) {
		$urn = $elemento['content']['article']['thumbnail'];
	}

	if ( ! $urn || 0 !== strpos( $urn, 'urn:li:image:' ) ) {
		return '';
	}

	$clave  = 'rcec_li_img_' . md5( $urn );
	$cache  = get_transient( $clave );

	if ( is_string( $cache ) ) {
		return $cache;
	}

	$token = rcec_li_token_valido();

	if ( is_wp_error( $token ) ) {
		return '';
	}

	$respuesta = wp_remote_get(
		'https://api.linkedin.com/rest/images/' . rawurlencode( $urn ),
		array( 'timeout' => 15, 'headers' => rcec_li_cabeceras_api( $token ) )
	);

	if ( is_wp_error( $respuesta ) || 200 !== wp_remote_retrieve_response_code( $respuesta ) ) {
		return '';
	}

	$datos = json_decode( wp_remote_retrieve_body( $respuesta ), true );
	$url   = isset( $datos['downloadUrl'] ) ? $datos['downloadUrl'] : '';

	set_transient( $clave, $url, 6 * HOUR_IN_SECONDS );

	return $url;
}

/**
 * Devuelve un token de acceso utilizable, renovándolo si está por vencer.
 *
 * @return string|WP_Error
 */
function rcec_li_token_valido() {

	$guardado = get_option( RCEC_LI_OPCION_TOKEN );

	if ( ! is_array( $guardado ) || empty( $guardado['access'] ) ) {
		return new WP_Error(
			'rcec_li_sin_token',
			__( 'No hay conexión con LinkedIn. Ve a Ajustes → Noticias LinkedIn y pulsa «Conectar con LinkedIn».', 'rcec-li' ),
			array( 'status' => 500 )
		);
	}

	// Margen de un día: renovamos antes de que caduque, no después de fallar.
	if ( ! empty( $guardado['expira'] ) && $guardado['expira'] < time() + DAY_IN_SECONDS ) {
		$renovado = rcec_li_renovar_token();

		if ( ! is_wp_error( $renovado ) ) {
			return $renovado;
		}
	}

	return $guardado['access'];
}

/**
 * Canjea el refresh token por uno nuevo de acceso.
 *
 * @return string|WP_Error
 */
function rcec_li_renovar_token() {

	$guardado = get_option( RCEC_LI_OPCION_TOKEN );

	if ( ! is_array( $guardado ) || empty( $guardado['refresh'] ) ) {
		return new WP_Error(
			'rcec_li_sin_refresh',
			__( 'La conexión con LinkedIn caducó y hay que rehacerla desde Ajustes → Noticias LinkedIn.', 'rcec-li' ),
			array( 'status' => 500 )
		);
	}

	$respuesta = wp_remote_post(
		'https://www.linkedin.com/oauth/v2/accessToken',
		array(
			'timeout' => 20,
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $guardado['refresh'],
				'client_id'     => RCEC_LI_CLIENT_ID,
				'client_secret' => RCEC_LI_CLIENT_SECRET,
			),
		)
	);

	if ( is_wp_error( $respuesta ) || 200 !== wp_remote_retrieve_response_code( $respuesta ) ) {
		return new WP_Error(
			'rcec_li_refresh_fallido',
			__( 'LinkedIn rechazó la renovación del token. Vuelve a conectar desde Ajustes.', 'rcec-li' ),
			array( 'status' => 502 )
		);
	}

	$datos = json_decode( wp_remote_retrieve_body( $respuesta ), true );

	if ( empty( $datos['access_token'] ) ) {
		return new WP_Error( 'rcec_li_refresh_vacio', __( 'Respuesta inesperada de LinkedIn.', 'rcec-li' ), array( 'status' => 502 ) );
	}

	rcec_li_guardar_token( $datos, $guardado['refresh'] );

	return $datos['access_token'];
}

/**
 * @param array  $datos             Respuesta del endpoint de tokens.
 * @param string $refresh_anterior  Se conserva si la respuesta no trae uno nuevo.
 */
function rcec_li_guardar_token( $datos, $refresh_anterior = '' ) {

	update_option(
		RCEC_LI_OPCION_TOKEN,
		array(
			'access'  => $datos['access_token'],
			'refresh' => ! empty( $datos['refresh_token'] ) ? $datos['refresh_token'] : $refresh_anterior,
			'expira'  => time() + ( ! empty( $datos['expires_in'] ) ? (int) $datos['expires_in'] : 60 * DAY_IN_SECONDS ),
		),
		false
	);
}

/* =========================================================
   MODO 'ingesta' — un servicio externo empuja las publicaciones

   Deliberadamente genérico: acepta lo que le mande cualquier
   herramienta. Si mañana cambias de proveedor, aquí no tocas nada.
========================================================== */

/**
 * Registra una publicación recibida desde fuera.
 *
 * @param array $entrante Datos crudos enviados por el servicio externo.
 * @return array|WP_Error Estado de la operación.
 */
function rcec_li_ingerir( $entrante ) {

	$item = rcec_li_construir(
		isset( $entrante['titulo'] ) ? $entrante['titulo'] : '',
		isset( $entrante['texto'] ) ? $entrante['texto'] : '',
		isset( $entrante['enlace'] ) ? $entrante['enlace'] : '',
		isset( $entrante['fecha'] ) ? $entrante['fecha'] : '',
		isset( $entrante['imagen'] ) ? $entrante['imagen'] : '',
		isset( $entrante['id'] ) ? $entrante['id'] : ''
	);

	if ( ! $item ) {
		return new WP_Error(
			'rcec_li_ingesta_vacia',
			__( 'La publicación no trae texto utilizable.', 'rcec-li' ),
			array( 'status' => 400 )
		);
	}

	$guardadas = get_option( RCEC_LI_OPCION_INGESTA );
	$items     = ( is_array( $guardadas ) && ! empty( $guardadas['items'] ) ) ? $guardadas['items'] : array();

	// Reenviar la misma publicación actualiza la existente en vez de duplicarla.
	$nuevo = true;

	foreach ( $items as $indice => $existente ) {
		if ( $existente['guid'] === $item['guid'] ) {
			$items[ $indice ] = $item;
			$nuevo            = false;
			break;
		}
	}

	if ( $nuevo ) {
		$items[] = $item;
	}

	usort(
		$items,
		static function ( $a, $b ) {
			return $b['marca'] <=> $a['marca'];
		}
	);

	$items = array_slice( $items, 0, 30 );

	update_option(
		RCEC_LI_OPCION_INGESTA,
		array(
			'actualizado' => time(),
			'items'       => $items,
		),
		false
	);

	delete_transient( RCEC_LI_CACHE_KEY );

	return array(
		'estado'  => $nuevo ? 'creada' : 'actualizada',
		'titulo'  => $item['titulo'],
		'guardadas' => count( $items ),
	);
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

		// Entrada de publicaciones empujadas desde fuera (modo 'ingesta').
		register_rest_route(
			'rcec/v1',
			'/linkedin/ingest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => 'rcec_li_permiso_ingesta',
				'callback'            => static function ( WP_REST_Request $peticion ) {

					$cuerpo = $peticion->get_json_params();

					if ( ! is_array( $cuerpo ) ) {
						return new WP_Error(
							'rcec_li_cuerpo_invalido',
							__( 'Se esperaba un cuerpo JSON.', 'rcec-li' ),
							array( 'status' => 400 )
						);
					}

					// Acepta una publicación suelta o un lote.
					$lote      = isset( $cuerpo[0] ) ? $cuerpo : array( $cuerpo );
					$resultado = array();

					foreach ( $lote as $entrante ) {
						if ( ! is_array( $entrante ) ) {
							continue;
						}

						$estado = rcec_li_ingerir( $entrante );

						$resultado[] = is_wp_error( $estado )
							? array( 'estado' => 'rechazada', 'motivo' => $estado->get_error_message() )
							: $estado;
					}

					if ( RCEC_LI_CREAR_ENTRADAS ) {
						rcec_li_sincronizar_entradas();
					}

					return rest_ensure_response( array( 'recibidas' => $resultado ) );
				},
			)
		);
	}
);

/**
 * Autoriza la ingesta comparando la contraseña compartida.
 *
 * Se acepta por cabecera (preferido) o por parámetro, porque no todos los
 * servicios permiten definir cabeceras personalizadas.
 *
 * @param WP_REST_Request $peticion Petición entrante.
 * @return bool|WP_Error
 */
function rcec_li_permiso_ingesta( WP_REST_Request $peticion ) {

	if ( ! RCEC_LI_TOKEN_INGESTA ) {
		return new WP_Error(
			'rcec_li_ingesta_cerrada',
			__( 'La ingesta está deshabilitada: falta definir RCEC_LI_TOKEN_INGESTA.', 'rcec-li' ),
			array( 'status' => 403 )
		);
	}

	$recibido = $peticion->get_header( 'x_rcec_token' );

	if ( ! $recibido ) {
		$recibido = $peticion->get_param( 'token' );
	}

	// hash_equals evita filtrar el token por diferencias de tiempo.
	if ( ! is_string( $recibido ) || ! hash_equals( RCEC_LI_TOKEN_INGESTA, $recibido ) ) {
		return new WP_Error(
			'rcec_li_token_invalido',
			__( 'Token de ingesta incorrecto.', 'rcec-li' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

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

/* =========================================================
   PANTALLA DE ADMINISTRACIÓN
   Ajustes → Noticias LinkedIn
========================================================== */

add_action(
	'admin_menu',
	static function () {
		add_options_page(
			__( 'Noticias LinkedIn', 'rcec-li' ),
			__( 'Noticias LinkedIn', 'rcec-li' ),
			'manage_options',
			'rcec-li',
			'rcec_li_pantalla_ajustes'
		);
	}
);

/**
 * @return string URL a la que LinkedIn devuelve al usuario tras autorizar.
 */
function rcec_li_url_retorno() {
	return admin_url( 'options-general.php?page=rcec-li' );
}

/**
 * Atiende el retorno de LinkedIn y las acciones de la pantalla.
 */
add_action(
	'admin_init',
	static function () {

		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pagina = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'rcec-li' !== $pagina ) {
			return;
		}

		// Vuelta desde LinkedIn con el código de autorización.
		if ( isset( $_GET['code'], $_GET['state'] ) ) {

			$estado = sanitize_text_field( wp_unslash( $_GET['state'] ) );

			// El state impide que un tercero nos haga canjear un código ajeno.
			if ( ! wp_verify_nonce( $estado, 'rcec_li_oauth' ) ) {
				add_settings_error( 'rcec-li', 'estado', __( 'La respuesta de LinkedIn no es válida. Intenta conectar de nuevo.', 'rcec-li' ) );
				return;
			}

			$respuesta = wp_remote_post(
				'https://www.linkedin.com/oauth/v2/accessToken',
				array(
					'timeout' => 20,
					'body'    => array(
						'grant_type'    => 'authorization_code',
						'code'          => sanitize_text_field( wp_unslash( $_GET['code'] ) ),
						'redirect_uri'  => rcec_li_url_retorno(),
						'client_id'     => RCEC_LI_CLIENT_ID,
						'client_secret' => RCEC_LI_CLIENT_SECRET,
					),
				)
			);

			$datos = is_wp_error( $respuesta )
				? array()
				: json_decode( wp_remote_retrieve_body( $respuesta ), true );

			if ( empty( $datos['access_token'] ) ) {
				add_settings_error( 'rcec-li', 'token', __( 'LinkedIn no entregó el token. Revisa el Client ID, el Client Secret y que la URL de retorno esté registrada tal cual en la app.', 'rcec-li' ) );
				return;
			}

			rcec_li_guardar_token( $datos );
			add_settings_error( 'rcec-li', 'ok', __( 'Conexión con LinkedIn establecida.', 'rcec-li' ), 'success' );
			return;
		}

		// Botón «Actualizar ahora».
		if ( isset( $_GET['rcec_li_accion'] ) && 'sincronizar' === $_GET['rcec_li_accion'] ) {

			check_admin_referer( 'rcec_li_sincronizar_ahora' );

			$datos = rcec_li_obtener_feed( true );

			if ( is_wp_error( $datos ) ) {
				add_settings_error( 'rcec-li', 'sync', $datos->get_error_message() );
			} else {
				$creadas = RCEC_LI_CREAR_ENTRADAS ? rcec_li_sincronizar_entradas() : 0;

				add_settings_error(
					'rcec-li',
					'sync',
					sprintf(
						/* translators: 1: publicaciones leídas, 2: entradas creadas. */
						__( 'Listo: %1$d publicaciones disponibles, %2$d entradas nuevas.', 'rcec-li' ),
						count( $datos['items'] ),
						$creadas
					),
					'success'
				);
			}
		}
	}
);

/**
 * Dibuja la pantalla de ajustes.
 */
function rcec_li_pantalla_ajustes() {

	$datos    = rcec_li_obtener_feed();
	$token    = get_option( RCEC_LI_OPCION_TOKEN );
	$conectado = is_array( $token ) && ! empty( $token['access'] );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Noticias LinkedIn', 'rcec-li' ); ?></h1>

		<?php settings_errors( 'rcec-li' ); ?>

		<h2><?php esc_html_e( 'Estado', 'rcec-li' ); ?></h2>
		<table class="widefat striped" style="max-width:760px">
			<tbody>
				<tr>
					<th style="width:220px"><?php esc_html_e( 'Modo', 'rcec-li' ); ?></th>
					<td><code><?php echo esc_html( RCEC_LI_MODO ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Publicaciones disponibles', 'rcec-li' ); ?></th>
					<td>
						<?php
						if ( is_wp_error( $datos ) ) {
							echo '<span style="color:#b32d2e">' . esc_html( $datos->get_error_message() ) . '</span>';
						} else {
							printf(
								/* translators: 1: cantidad, 2: fecha de actualización. */
								esc_html__( '%1$d, actualizadas el %2$s', 'rcec-li' ),
								count( $datos['items'] ),
								esc_html( wp_date( 'j \d\e F, H:i', $datos['actualizado'] ) )
							);

							if ( ! empty( $datos['obsoleto'] ) ) {
								echo ' <strong>' . esc_html__( '(copia de respaldo: el origen no responde)', 'rcec-li' ) . '</strong>';
							}
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Entradas reales', 'rcec-li' ); ?></th>
					<td>
						<?php
						echo RCEC_LI_CREAR_ENTRADAS
							? esc_html( sprintf( __( 'Activadas, se crean como «%s»', 'rcec-li' ), RCEC_LI_ESTADO_ENTRADAS ) )
							: esc_html__( 'Desactivadas (solo panel)', 'rcec-li' );
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<p>
			<a class="button button-primary"
			   href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'rcec_li_accion', 'sincronizar', rcec_li_url_retorno() ), 'rcec_li_sincronizar_ahora' ) ); ?>">
				<?php esc_html_e( 'Actualizar ahora', 'rcec-li' ); ?>
			</a>
		</p>

		<?php if ( 'api' === RCEC_LI_MODO ) : ?>
			<h2><?php esc_html_e( 'Conexión con LinkedIn', 'rcec-li' ); ?></h2>

			<?php if ( ! RCEC_LI_CLIENT_ID || ! RCEC_LI_CLIENT_SECRET ) : ?>
				<p><?php esc_html_e( 'Falta definir RCEC_LI_CLIENT_ID y RCEC_LI_CLIENT_SECRET en el archivo del plugin.', 'rcec-li' ); ?></p>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'Registra esta URL de retorno en tu app de LinkedIn, exactamente así:', 'rcec-li' ); ?><br>
					<code><?php echo esc_html( rcec_li_url_retorno() ); ?></code>
				</p>

				<?php if ( $conectado ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: fecha de caducidad del token. */
							esc_html__( 'Conectado. El permiso vence el %s y se renueva solo.', 'rcec-li' ),
							esc_html( wp_date( 'j \d\e F, Y', $token['expira'] ) )
						);
						?>
					</p>
				<?php endif; ?>

				<p>
					<a class="button"
					   href="<?php echo esc_url(
							add_query_arg(
								array(
									'response_type' => 'code',
									'client_id'     => RCEC_LI_CLIENT_ID,
									'redirect_uri'  => rawurlencode( rcec_li_url_retorno() ),
									'state'         => wp_create_nonce( 'rcec_li_oauth' ),
									'scope'         => rawurlencode( 'r_organization_social' ),
								),
								'https://www.linkedin.com/oauth/v2/authorization'
							)
						); ?>">
						<?php
						echo $conectado
							? esc_html__( 'Reconectar con LinkedIn', 'rcec-li' )
							: esc_html__( 'Conectar con LinkedIn', 'rcec-li' );
						?>
					</a>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( 'ingesta' === RCEC_LI_MODO ) : ?>
			<h2><?php esc_html_e( 'Recepción de publicaciones', 'rcec-li' ); ?></h2>

			<?php if ( ! RCEC_LI_TOKEN_INGESTA ) : ?>
				<p><?php esc_html_e( 'Falta definir RCEC_LI_TOKEN_INGESTA en el archivo del plugin. Sin eso, la recepción está cerrada.', 'rcec-li' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Configura el servicio externo para que envíe una petición POST a:', 'rcec-li' ); ?></p>
				<p><code><?php echo esc_html( rest_url( 'rcec/v1/linkedin/ingest' ) ); ?></code></p>

				<p><?php esc_html_e( 'Con la cabecera X-RCEC-Token y este cuerpo JSON:', 'rcec-li' ); ?></p>
				<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;max-width:760px;overflow:auto">{
  "id":     "urn:li:share:7123456789",
  "texto":  "El texto completo de la publicación",
  "enlace": "https://www.linkedin.com/feed/update/urn:li:share:7123456789/",
  "fecha":  "2026-09-12T14:30:00Z",
  "imagen": "https://media.licdn.com/..."
}</pre>

				<p>
					<?php esc_html_e( 'Solo «texto» es obligatorio. Reenviar el mismo «id» actualiza la publicación en vez de duplicarla.', 'rcec-li' ); ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Cómo mostrarlas', 'rcec-li' ); ?></h2>
		<p>
			<?php esc_html_e( 'Bloque HTML personalizado con el archivo linkedin-noticias.html, o bien este shortcode:', 'rcec-li' ); ?>
			<code>[linkedin_noticias cantidad="6" columnas="3"]</code>
		</p>
	</div>
	<?php
}
