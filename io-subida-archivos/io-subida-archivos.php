<?php
/**
 * Plugin Name: Imprenta Online - Subida de Archivos (Drive)
 * Description: Gestiona la subida de archivos de clientes post-compra, organizados en carpetas por pedido dentro de Google Drive. Reemplaza al plugin de subida de archivos anterior (WCUF).
 * Version: 1.1.0
 * Author: Imprenta Online
 *
 * REQUIERE en wp-config.php:
 *   define('IO_DRIVE_CLIENT_ID', '...');
 *   define('IO_DRIVE_CLIENT_SECRET', '...');
 *   define('IO_DRIVE_REFRESH_TOKEN', '...');
 *
 * Scope usado: https://www.googleapis.com/auth/drive.file
 * IMPORTANTE: con este scope la app SOLO puede acceder a archivos/carpetas
 * que ella misma creó. Por eso la carpeta raíz "Pedidos" la crea este plugin
 * la primera vez que corre — no la crees manualmente en Drive.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

define( 'IO_DRIVE_ROOT_FOLDER_NAME', 'Pedidos - Imprenta Online' );
define( 'IO_DRIVE_OPTION_ROOT_FOLDER', 'io_drive_root_folder_id' );
define( 'IO_DRIVE_MAX_FILE_SIZE', 50 * 1024 * 1024 ); // 50MB
define(
	'IO_DRIVE_ALLOWED_EXT',
	array( 'pdf', 'jpg', 'jpeg', 'png', 'ai', 'psd', 'eps', 'svg', 'zip', 'cdr', 'tif', 'tiff' )
);

/* ============================================================
 * 1. AUTENTICACIÓN — OAuth refresh_token -> access_token
 * ============================================================ */

/**
 * Devuelve un access_token válido, usando cache (transient) para no
 * pedir uno nuevo en cada request.
 *
 * @return string|WP_Error
 */
function io_drive_get_access_token() {
	$cached = get_transient( 'io_drive_access_token' );
	if ( $cached ) {
		return $cached;
	}

	if ( ! defined( 'IO_DRIVE_CLIENT_ID' ) || ! defined( 'IO_DRIVE_CLIENT_SECRET' ) || ! defined( 'IO_DRIVE_REFRESH_TOKEN' ) ) {
		return new WP_Error( 'io_drive_config', 'Faltan las constantes IO_DRIVE_CLIENT_ID / IO_DRIVE_CLIENT_SECRET / IO_DRIVE_REFRESH_TOKEN en wp-config.php' );
	}

	$response = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 20,
			'body'    => array(
				'client_id'     => IO_DRIVE_CLIENT_ID,
				'client_secret' => IO_DRIVE_CLIENT_SECRET,
				'refresh_token' => IO_DRIVE_REFRESH_TOKEN,
				'grant_type'    => 'refresh_token',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code !== 200 || empty( $body['access_token'] ) ) {
		return new WP_Error(
			'io_drive_token',
			'No se pudo obtener access_token de Google: ' . wp_remote_retrieve_body( $response )
		);
	}

	$expires_in = isset( $body['expires_in'] ) ? intval( $body['expires_in'] ) : 3600;
	// Guardamos con un margen de 60s para evitar usar un token vencido.
	set_transient( 'io_drive_access_token', $body['access_token'], max( 60, $expires_in - 60 ) );

	return $body['access_token'];
}

/**
 * Wrapper genérico para llamadas a la API de Drive con el access_token.
 */
function io_drive_api_request( $method, $url, $args = array() ) {
	$token = io_drive_get_access_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$default_headers = array( 'Authorization' => 'Bearer ' . $token );
	$args['headers']  = isset( $args['headers'] ) ? array_merge( $default_headers, $args['headers'] ) : $default_headers;
	$args['method']   = $method;
	$args['timeout']  = isset( $args['timeout'] ) ? $args['timeout'] : 30;

	$response = wp_remote_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code >= 400 ) {
		return new WP_Error( 'io_drive_api', 'Error Drive API (' . $code . '): ' . wp_remote_retrieve_body( $response ) );
	}

	return $response;
}

/* ============================================================
 * 2. CARPETAS — raíz y por pedido
 * ============================================================ */

/**
 * Devuelve el ID de la carpeta raíz "Pedidos - Imprenta Online",
 * creándola si todavía no existe.
 */
function io_drive_get_or_create_root_folder() {
	$saved = get_option( IO_DRIVE_OPTION_ROOT_FOLDER );
	if ( $saved ) {
		return $saved;
	}

	// Por si la opción se perdió pero la carpeta ya existe, la buscamos primero.
	$q = sprintf(
		"name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
		io_drive_escape_query( IO_DRIVE_ROOT_FOLDER_NAME )
	);
	$search = io_drive_api_request(
		'GET',
		'https://www.googleapis.com/drive/v3/files?' . http_build_query(
			array(
				'q'      => $q,
				'fields' => 'files(id,name)',
			)
		)
	);

	if ( ! is_wp_error( $search ) ) {
		$body = json_decode( wp_remote_retrieve_body( $search ), true );
		if ( ! empty( $body['files'][0]['id'] ) ) {
			update_option( IO_DRIVE_OPTION_ROOT_FOLDER, $body['files'][0]['id'] );
			return $body['files'][0]['id'];
		}
	}

	// No existe, la creamos.
	$create = io_drive_api_request(
		'POST',
		'https://www.googleapis.com/drive/v3/files',
		array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'name'     => IO_DRIVE_ROOT_FOLDER_NAME,
					'mimeType' => 'application/vnd.google-apps.folder',
				)
			),
		)
	);

	if ( is_wp_error( $create ) ) {
		return $create;
	}

	$body = json_decode( wp_remote_retrieve_body( $create ), true );
	if ( empty( $body['id'] ) ) {
		return new WP_Error( 'io_drive_root', 'No se pudo crear la carpeta raíz en Drive.' );
	}

	update_option( IO_DRIVE_OPTION_ROOT_FOLDER, $body['id'] );
	return $body['id'];
}

/**
 * Devuelve [folder_id, webViewLink] de la carpeta del pedido,
 * creándola (dentro de la raíz) si todavía no existe.
 *
 * @param WC_Order $order
 * @return array|WP_Error
 */
function io_drive_get_or_create_order_folder( $order ) {
	$existing_id = $order->get_meta( '_io_drive_folder_id' );
	$existing_link = $order->get_meta( '_io_drive_link' );
	if ( $existing_id && $existing_link ) {
		return array(
			'id'   => $existing_id,
			'link' => $existing_link,
		);
	}

	$root_id = io_drive_get_or_create_root_folder();
	if ( is_wp_error( $root_id ) ) {
		return $root_id;
	}

	$folder_name = io_drive_build_order_folder_name( $order );

	// Buscamos por si ya existe (ej: reintento tras error de red).
	$q = sprintf(
		"name = '%s' and mimeType = 'application/vnd.google-apps.folder' and '%s' in parents and trashed = false",
		io_drive_escape_query( $folder_name ),
		$root_id
	);
	$search = io_drive_api_request(
		'GET',
		'https://www.googleapis.com/drive/v3/files?' . http_build_query(
			array(
				'q'      => $q,
				'fields' => 'files(id,webViewLink)',
			)
		)
	);

	$folder_id   = null;
	$folder_link = null;

	if ( ! is_wp_error( $search ) ) {
		$body = json_decode( wp_remote_retrieve_body( $search ), true );
		if ( ! empty( $body['files'][0]['id'] ) ) {
			$folder_id   = $body['files'][0]['id'];
			$folder_link = $body['files'][0]['webViewLink'];
		}
	}

	if ( ! $folder_id ) {
		$create = io_drive_api_request(
			'POST',
			'https://www.googleapis.com/drive/v3/files?fields=id,webViewLink',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'name'     => $folder_name,
						'mimeType' => 'application/vnd.google-apps.folder',
						'parents'  => array( $root_id ),
					)
				),
			)
		);

		if ( is_wp_error( $create ) ) {
			return $create;
		}

		$body = json_decode( wp_remote_retrieve_body( $create ), true );
		if ( empty( $body['id'] ) ) {
			return new WP_Error( 'io_drive_order_folder', 'No se pudo crear la carpeta del pedido en Drive.' );
		}

		$folder_id   = $body['id'];
		$folder_link = $body['webViewLink'];
	}

	// Guardamos en el pedido — el panel del taller ya lee _io_drive_link.
	$order->update_meta_data( '_io_drive_folder_id', $folder_id );
	$order->update_meta_data( '_io_drive_link', $folder_link );
	$order->save();

	return array(
		'id'   => $folder_id,
		'link' => $folder_link,
	);
}

function io_drive_build_order_folder_name( $order ) {
	$numero = $order->get_order_number();
	$nombre = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	if ( ! $nombre ) {
		$nombre = $order->get_billing_email();
	}
	return sprintf( 'Pedido-%s - %s', $numero, $nombre );
}

function io_drive_escape_query( $string ) {
	return str_replace( "'", "\\'", $string );
}

/**
 * Guarda en el pedido cuántos archivos tiene actualmente en Drive, para
 * poder mostrarlo en el admin (columna del listado y ficha del pedido)
 * sin tener que llamar a la API de Drive en cada carga de esas pantallas.
 *
 * @param WC_Order $order
 * @param array    $archivos
 */
function io_drive_sync_file_count( $order, $archivos ) {
	$count = is_array( $archivos ) ? count( $archivos ) : 0;
	if ( (int) $order->get_meta( '_io_drive_file_count' ) !== $count ) {
		$order->update_meta_data( '_io_drive_file_count', $count );
		$order->save();
	}
}

/* ============================================================
 * 3. ARCHIVOS — subir, listar, borrar
 * ============================================================ */

function io_drive_upload_file( $folder_id, $tmp_path, $filename, $mime_type ) {
	$metadata = wp_json_encode(
		array(
			'name'    => sanitize_file_name( $filename ),
			'parents' => array( $folder_id ),
		)
	);

	$boundary = 'io_drive_' . wp_generate_password( 12, false );
	$content  = file_get_contents( $tmp_path );
	if ( $content === false ) {
		return new WP_Error( 'io_drive_read', 'No se pudo leer el archivo subido.' );
	}

	$body  = "--{$boundary}\r\n";
	$body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
	$body .= $metadata . "\r\n";
	$body .= "--{$boundary}\r\n";
	$body .= "Content-Type: {$mime_type}\r\n\r\n";
	$body .= $content . "\r\n";
	$body .= "--{$boundary}--";

	$response = io_drive_api_request(
		'POST',
		'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType,size,webViewLink,createdTime',
		array(
			'headers' => array( 'Content-Type' => 'multipart/related; boundary=' . $boundary ),
			'body'    => $body,
			'timeout' => 60,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return json_decode( wp_remote_retrieve_body( $response ), true );
}

function io_drive_list_files( $folder_id ) {
	$q = sprintf( "'%s' in parents and trashed = false", $folder_id );
	$response = io_drive_api_request(
		'GET',
		'https://www.googleapis.com/drive/v3/files?' . http_build_query(
			array(
				'q'         => $q,
				'fields'    => 'files(id,name,mimeType,size,webViewLink,createdTime)',
				'orderBy'   => 'createdTime desc',
				'pageSize'  => 100,
			)
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return isset( $body['files'] ) ? $body['files'] : array();
}

function io_drive_trash_file( $file_id ) {
	return io_drive_api_request(
		'PATCH',
		'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ),
		array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'trashed' => true ) ),
		)
	);
}

/* ============================================================
 * 4. VALIDACIÓN DE PEDIDO
 * ============================================================ */

/**
 * Verifica que order_id + order_key correspondan a un pedido real.
 * Guest checkout friendly: no requiere estar logueado.
 *
 * @return WC_Order|WP_Error
 */
function io_drive_validar_pedido( $order_id, $order_key ) {
	$order_id = absint( $order_id );
	$order    = wc_get_order( $order_id );

	if ( ! $order ) {
		return new WP_Error( 'io_drive_no_order', 'Pedido no encontrado.', array( 'status' => 404 ) );
	}

	if ( ! hash_equals( $order->get_order_key(), (string) $order_key ) ) {
		return new WP_Error( 'io_drive_bad_key', 'Clave de pedido inválida.', array( 'status' => 403 ) );
	}

	return $order;
}

/* ============================================================
 * 5. ENDPOINTS REST — namespace io/v1
 * ============================================================ */

add_action( 'rest_api_init', function () {

	register_rest_route(
		'io/v1',
		'/validar-pedido',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => array(
				'order_id'  => array( 'required' => true ),
				'order_key' => array( 'required' => true ),
			),
			'callback'            => function ( WP_REST_Request $req ) {
				$order = io_drive_validar_pedido( $req->get_param( 'order_id' ), $req->get_param( 'order_key' ) );
				if ( is_wp_error( $order ) ) {
					return $order;
				}

				$folder_id = $order->get_meta( '_io_drive_folder_id' );
				$files     = array();
				if ( $folder_id ) {
					$listed = io_drive_list_files( $folder_id );
					$files  = is_wp_error( $listed ) ? array() : $listed;
					io_drive_sync_file_count( $order, $files );
				}

				return array(
					'order_id'     => $order->get_id(),
					'order_number' => $order->get_order_number(),
					'status'       => $order->get_status(),
					'cliente'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'tiene_carpeta'=> (bool) $folder_id,
					'drive_link'   => $order->get_meta( '_io_drive_link' ),
					'archivos'     => $files,
				);
			},
		)
	);

	register_rest_route(
		'io/v1',
		'/subir-archivo',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $req ) {
				$order = io_drive_validar_pedido( $req->get_param( 'order_id' ), $req->get_param( 'order_key' ) );
				if ( is_wp_error( $order ) ) {
					return $order;
				}

				$files = $req->get_file_params();
				if ( empty( $files['file'] ) || $files['file']['error'] !== UPLOAD_ERR_OK ) {
					return new WP_Error( 'io_drive_no_file', 'No se recibió ningún archivo válido.', array( 'status' => 400 ) );
				}

				$file = $files['file'];

				if ( $file['size'] > IO_DRIVE_MAX_FILE_SIZE ) {
					return new WP_Error( 'io_drive_too_big', 'El archivo supera el tamaño máximo permitido (50MB).', array( 'status' => 400 ) );
				}

				$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, IO_DRIVE_ALLOWED_EXT, true ) ) {
					return new WP_Error(
						'io_drive_bad_ext',
						'Tipo de archivo no permitido. Formatos aceptados: ' . implode( ', ', IO_DRIVE_ALLOWED_EXT ),
						array( 'status' => 400 )
					);
				}

				$folder = io_drive_get_or_create_order_folder( $order );
				if ( is_wp_error( $folder ) ) {
					return $folder;
				}

				$mime = $file['type'] ? $file['type'] : 'application/octet-stream';
				$uploaded = io_drive_upload_file( $folder['id'], $file['tmp_name'], $file['name'], $mime );

				if ( is_wp_error( $uploaded ) ) {
					return $uploaded;
				}

				$order->add_order_note(
					sprintf( 'El cliente subió el archivo "%s" a la carpeta de Drive del pedido.', $uploaded['name'] ?? $file['name'] )
				);

				$listed = io_drive_list_files( $folder['id'] );
				io_drive_sync_file_count( $order, is_wp_error( $listed ) ? array() : $listed );

				return array(
					'ok'         => true,
					'drive_link' => $folder['link'],
					'archivos'   => is_wp_error( $listed ) ? array() : $listed,
				);
			},
		)
	);

	register_rest_route(
		'io/v1',
		'/listar-archivos',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => array(
				'order_id'  => array( 'required' => true ),
				'order_key' => array( 'required' => true ),
			),
			'callback'            => function ( WP_REST_Request $req ) {
				$order = io_drive_validar_pedido( $req->get_param( 'order_id' ), $req->get_param( 'order_key' ) );
				if ( is_wp_error( $order ) ) {
					return $order;
				}

				$folder_id = $order->get_meta( '_io_drive_folder_id' );
				if ( ! $folder_id ) {
					return array( 'archivos' => array() );
				}

				$listed = io_drive_list_files( $folder_id );
				if ( is_wp_error( $listed ) ) {
					return $listed;
				}

				io_drive_sync_file_count( $order, $listed );

				return array( 'archivos' => $listed );
			},
		)
	);

	register_rest_route(
		'io/v1',
		'/eliminar-archivo',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'args'                => array(
				'order_id'  => array( 'required' => true ),
				'order_key' => array( 'required' => true ),
				'file_id'   => array( 'required' => true ),
			),
			'callback'            => function ( WP_REST_Request $req ) {
				$order = io_drive_validar_pedido( $req->get_param( 'order_id' ), $req->get_param( 'order_key' ) );
				if ( is_wp_error( $order ) ) {
					return $order;
				}

				$folder_id = $order->get_meta( '_io_drive_folder_id' );
				if ( ! $folder_id ) {
					return new WP_Error( 'io_drive_no_folder', 'Este pedido todavía no tiene archivos.', array( 'status' => 404 ) );
				}

				// Verificamos que el file_id pertenezca a la carpeta de ESTE pedido
				// antes de borrar, para que nadie pueda borrar archivos ajenos.
				$listed = io_drive_list_files( $folder_id );
				if ( is_wp_error( $listed ) ) {
					return $listed;
				}

				$file_id   = $req->get_param( 'file_id' );
				$pertenece = false;
				$nombre    = '';
				foreach ( $listed as $f ) {
					if ( $f['id'] === $file_id ) {
						$pertenece = true;
						$nombre    = $f['name'];
						break;
					}
				}

				if ( ! $pertenece ) {
					return new WP_Error( 'io_drive_not_owner', 'Ese archivo no pertenece a este pedido.', array( 'status' => 403 ) );
				}

				$result = io_drive_trash_file( $file_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$order->add_order_note( sprintf( 'El cliente eliminó el archivo "%s" de la carpeta de Drive del pedido.', $nombre ) );

				$listed_after = io_drive_list_files( $folder_id );
				io_drive_sync_file_count( $order, is_wp_error( $listed_after ) ? array() : $listed_after );

				return array(
					'ok'       => true,
					'archivos' => is_wp_error( $listed_after ) ? array() : $listed_after,
				);
			},
		)
	);
} );

/* ============================================================
 * 6. WIDGET DE FRONTEND — thank-you page + Mi cuenta > Ver pedido
 * ============================================================ */

/**
 * Estados en los que tiene sentido mostrar el widget de subida.
 * Una vez completado/cancelado, no lo mostramos más.
 */
function io_drive_widget_estados_visibles() {
	return array( 'pending', 'on-hold', 'processing' );
}

add_action( 'woocommerce_thankyou', 'io_drive_render_widget', 20 );
add_action( 'woocommerce_view_order', 'io_drive_render_widget', 20 );

function io_drive_render_widget( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	if ( ! in_array( $order->get_status(), io_drive_widget_estados_visibles(), true ) ) {
		return;
	}

	static $css_impreso = false;

	$order_id_attr  = esc_attr( $order->get_id() );
	$order_key_attr = esc_attr( $order->get_order_key() );
	$rest_url       = esc_url_raw( rest_url( 'io/v1' ) );
	$max_mb         = intval( IO_DRIVE_MAX_FILE_SIZE / 1024 / 1024 );
	$ext_list       = implode( ', ', array_map( 'strtoupper', IO_DRIVE_ALLOWED_EXT ) );
	$accept_attr    = '.' . implode( ',.', IO_DRIVE_ALLOWED_EXT );

	if ( ! $css_impreso ) :
		$css_impreso = true;
		?>
		<style>
			.io-drive-overlay{position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px}
			.io-drive-overlay[hidden]{display:none}
			.io-drive-widget{position:relative;box-sizing:border-box;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;margin:0;padding:24px;border:1px solid #e2e8f0;border-radius:12px;background:#f9fafb;font-family:Poppins,-apple-system,sans-serif;color:#0f172a;box-shadow:0 20px 50px rgba(15,23,42,.3)}
			.io-drive-widget *{box-sizing:border-box}
			.io-drive-widget h3{font-family:Montserrat,-apple-system,sans-serif;font-size:18px;margin:0 0 6px;color:#2e509e;padding-right:24px}
			.io-drive-widget p{font-size:14px;line-height:1.5;margin:0 0 14px;color:#334155}
			.io-drive-close{position:absolute;top:14px;right:14px;background:transparent;border:none;font-size:18px;line-height:1;color:#64748b;cursor:pointer;padding:6px}
			.io-drive-close:hover{color:#0f172a}
			.io-drive-dropzone{border:2px dashed #2e509e;border-radius:10px;padding:28px 16px;text-align:center;cursor:pointer;background:#fff;transition:border-color .15s,background .15s}
			.io-drive-dropzone:hover,.io-drive-dropzone.io-drive-drag{border-color:#FF6B00;background:#fff7f0}
			.io-drive-dropzone-text{font-size:14px;color:#2e509e;font-weight:600}
			.io-drive-progress{margin-top:14px;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;display:none}
			.io-drive-progress-bar{height:100%;width:0%;background:#FF6B00;transition:width .2s}
			.io-drive-status{font-size:13px;margin-top:10px;min-height:18px}
			.io-drive-status.io-drive-error{color:#dc2626}
			.io-drive-status.io-drive-ok{color:#16a34a}
			.io-drive-file-list{list-style:none;margin:14px 0 0;padding:0}
			.io-drive-file-list li{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;font-size:13px}
			.io-drive-file-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
			.io-drive-file-name a{color:#2e509e;text-decoration:none}
			.io-drive-file-name a:hover{text-decoration:underline}
			.io-drive-file-remove{border:none;background:transparent;color:#dc2626;cursor:pointer;font-size:16px;line-height:1;padding:4px 6px;flex-shrink:0}
			.io-drive-file-remove:hover{color:#991b1b}
			.io-drive-reopen{position:fixed;bottom:20px;right:20px;z-index:99998;background:#2e509e;color:#fff;border:none;border-radius:999px;padding:12px 18px;font-family:Poppins,-apple-system,sans-serif;font-size:14px;font-weight:600;cursor:pointer;box-shadow:0 4px 14px rgba(15,23,42,.25)}
			.io-drive-reopen:hover{background:#24407e}
			.io-drive-reopen[hidden]{display:none}
			.io-drive-reopen.io-drive-reopen-pending{background:#dc2626;animation:io-drive-pulse 1.6s ease-in-out infinite}
			.io-drive-reopen.io-drive-reopen-pending:hover{background:#b91c1c}
			@keyframes io-drive-pulse{0%,100%{box-shadow:0 4px 14px rgba(220,38,38,.35)}50%{box-shadow:0 4px 22px rgba(220,38,38,.65)}}
			.io-drive-warning{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:10px 12px;border-radius:8px;font-size:13px;line-height:1.5;margin:0 0 14px}
			@media (max-width:480px){.io-drive-dropzone{padding:20px 12px}.io-drive-widget{padding:18px}}
		</style>
		<?php
	endif;
	?>
	<div class="io-drive-container" data-order-id="<?php echo $order_id_attr; ?>" data-order-key="<?php echo $order_key_attr; ?>" data-rest-url="<?php echo esc_url( $rest_url ); ?>" data-max-mb="<?php echo esc_attr( $max_mb ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
		<div class="io-drive-overlay" hidden>
			<div class="io-drive-widget">
				<button type="button" class="io-drive-close" aria-label="Cerrar">✕</button>
				<h3>📎 Subí tus archivos de diseño</h3>
				<p>Arrastrá tus archivos acá o hacé clic para seleccionarlos. Formatos aceptados: <?php echo esc_html( $ext_list ); ?> — hasta <?php echo esc_html( $max_mb ); ?>MB por archivo.</p>
				<p class="io-drive-warning">⚠️ <strong>Importante:</strong> tu pedido no entra en producción hasta que recibamos tu(s) archivo(s) de diseño.</p>
				<div class="io-drive-dropzone" tabindex="0">
					<span class="io-drive-dropzone-text">Soltá los archivos acá o hacé clic para elegirlos</span>
					<input type="file" class="io-drive-file-input" multiple hidden accept="<?php echo esc_attr( $accept_attr ); ?>">
				</div>
				<div class="io-drive-progress"><div class="io-drive-progress-bar"></div></div>
				<div class="io-drive-status" role="status"></div>
				<ul class="io-drive-file-list"></ul>
			</div>
		</div>
		<button type="button" class="io-drive-reopen" hidden>📎 Subir archivos</button>
	</div>
	<script>
	(function(){
		function ioDriveInit(container){
			var orderId  = container.getAttribute('data-order-id');
			var orderKey = container.getAttribute('data-order-key');
			var restUrl  = container.getAttribute('data-rest-url');
			var maxMb    = parseInt(container.getAttribute('data-max-mb'), 10);
			var nonce    = container.getAttribute('data-nonce');

			var overlay   = container.querySelector('.io-drive-overlay');
			var widget    = container.querySelector('.io-drive-widget');
			var closeBtn  = container.querySelector('.io-drive-close');
			var reopenBtn = container.querySelector('.io-drive-reopen');
			var dropzone  = container.querySelector('.io-drive-dropzone');
			var input     = container.querySelector('.io-drive-file-input');
			var status    = container.querySelector('.io-drive-status');
			var list      = container.querySelector('.io-drive-file-list');
			var progWrap  = container.querySelector('.io-drive-progress');
			var progBar   = container.querySelector('.io-drive-progress-bar');

			function abrirModal(){
				overlay.hidden = false;
				reopenBtn.hidden = true;
			}
			function cerrarModal(){
				overlay.hidden = true;
				reopenBtn.hidden = false;
			}

			closeBtn.addEventListener('click', cerrarModal);
			reopenBtn.addEventListener('click', abrirModal);
			overlay.addEventListener('click', function(e){
				if (e.target === overlay) cerrarModal();
			});
			widget.addEventListener('click', function(e){ e.stopPropagation(); });

			function setStatus(msg, tipo){
				status.textContent = msg || '';
				status.className = 'io-drive-status' + (tipo ? ' io-drive-' + tipo : '');
			}

			function renderFiles(archivos){
				list.innerHTML = '';
				(archivos || []).forEach(function(f){
					var li = document.createElement('li');

					var nameWrap = document.createElement('span');
					nameWrap.className = 'io-drive-file-name';
					var a = document.createElement('a');
					a.href = f.webViewLink;
					a.target = '_blank';
					a.rel = 'noopener';
					a.textContent = f.name;
					nameWrap.appendChild(a);

					var removeBtn = document.createElement('button');
					removeBtn.type = 'button';
					removeBtn.className = 'io-drive-file-remove';
					removeBtn.innerHTML = '✕';
					removeBtn.title = 'Eliminar archivo';
					removeBtn.addEventListener('click', function(){
						if (!confirm('¿Eliminar "' + f.name + '"?')) return;
						eliminarArchivo(f.id);
					});

					li.appendChild(nameWrap);
					li.appendChild(removeBtn);
					list.appendChild(li);
				});
			}

			function actualizarBotonReopen(archivos){
				var cantidad = (archivos || []).length;
				if (cantidad === 0) {
					reopenBtn.textContent = '⚠️ Subí tus archivos (pendiente)';
					reopenBtn.classList.add('io-drive-reopen-pending');
				} else {
					reopenBtn.textContent = '📁 Ver mis archivos (' + cantidad + ')';
					reopenBtn.classList.remove('io-drive-reopen-pending');
				}
			}

			function cargarArchivos(esCargaInicial){
				var url = restUrl + '/listar-archivos?order_id=' + encodeURIComponent(orderId) + '&order_key=' + encodeURIComponent(orderKey);
				fetch(url).then(function(r){ return r.json(); }).then(function(data){
					var archivos = (data && data.archivos) ? data.archivos : [];
					renderFiles(archivos);
					actualizarBotonReopen(archivos);
					if (esCargaInicial) {
						if (archivos.length === 0) {
							abrirModal();
						} else {
							reopenBtn.hidden = false;
						}
					}
				}).catch(function(){
					// Si falla la carga inicial, igual mostramos el modal para no dejar al cliente sin poder subir.
					if (esCargaInicial) abrirModal();
				});
			}

			function eliminarArchivo(fileId){
				var body = new FormData();
				body.append('order_id', orderId);
				body.append('order_key', orderKey);
				body.append('file_id', fileId);
				setStatus('Eliminando…');
				fetch(restUrl + '/eliminar-archivo', { method: 'POST', body: body, headers: nonce ? { 'X-WP-Nonce': nonce } : {} })
					.then(function(r){ return r.json(); })
					.then(function(data){
						if (data && data.ok) {
							setStatus('Archivo eliminado.', 'ok');
							renderFiles(data.archivos);
						} else {
							setStatus((data && data.message) || 'No se pudo eliminar el archivo.', 'error');
						}
					})
					.catch(function(){ setStatus('Error de conexión al eliminar.', 'error'); });
			}

			function extensionValida(nombre){
				var ext = nombre.split('.').pop().toLowerCase();
				return widget.getAttribute('data-rest-url') && ext.length > 0 && (
					',pdf,jpg,jpeg,png,ai,psd,eps,svg,zip,cdr,tif,tiff,'.indexOf(',' + ext + ',') !== -1
				);
			}

			function subirArchivos(files){
				var pendientes = Array.prototype.slice.call(files);
				var total = pendientes.length;
				var subidos = 0;
				var errores = [];

				function subirSiguiente(){
					if (pendientes.length === 0) {
						progWrap.style.display = 'none';
						if (errores.length) {
							setStatus('Subidos ' + subidos + ' de ' + total + '. Fallaron: ' + errores.join(', '), subidos > 0 ? 'ok' : 'error');
						} else {
							setStatus('Listo — ' + subidos + ' de ' + total + ' archivo(s) subido(s).', 'ok');
						}
						cargarArchivos(false);
						return;
					}

					var file = pendientes.shift();

					if (!extensionValida(file.name)) {
						errores.push(file.name);
						subirSiguiente();
						return;
					}
					if (file.size > maxMb * 1024 * 1024) {
						errores.push(file.name + ' (muy pesado)');
						subirSiguiente();
						return;
					}

					var body = new FormData();
					body.append('order_id', orderId);
					body.append('order_key', orderKey);
					body.append('file', file);

					var xhr = new XMLHttpRequest();
					xhr.open('POST', restUrl + '/subir-archivo', true);
					if (nonce) xhr.setRequestHeader('X-WP-Nonce', nonce);

					progWrap.style.display = 'block';
					progBar.style.width = '0%';
					setStatus('Subiendo "' + file.name + '"…');

					xhr.upload.onprogress = function(e){
						if (e.lengthComputable) {
							progBar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
						}
					};

					xhr.onload = function(){
						if (xhr.status >= 200 && xhr.status < 300) {
							subidos++;
						} else {
							errores.push(file.name);
						}
						subirSiguiente();
					};

					xhr.onerror = function(){
						errores.push(file.name);
						subirSiguiente();
					};

					xhr.send(body);
				}

				subirSiguiente();
			}

			dropzone.addEventListener('click', function(){ input.click(); });
			dropzone.addEventListener('keydown', function(e){
				if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
			});
			input.addEventListener('change', function(){
				if (input.files.length) subirArchivos(input.files);
				input.value = '';
			});
			['dragenter','dragover'].forEach(function(evt){
				dropzone.addEventListener(evt, function(e){
					e.preventDefault(); e.stopPropagation();
					dropzone.classList.add('io-drive-drag');
				});
			});
			['dragleave','drop'].forEach(function(evt){
				dropzone.addEventListener(evt, function(e){
					e.preventDefault(); e.stopPropagation();
					dropzone.classList.remove('io-drive-drag');
				});
			});
			dropzone.addEventListener('drop', function(e){
				var files = e.dataTransfer.files;
				if (files && files.length) subirArchivos(files);
			});

			cargarArchivos(true);
		}

		document.querySelectorAll('.io-drive-container').forEach(function(c){
			if (!c.getAttribute('data-io-drive-init')) {
				c.setAttribute('data-io-drive-init', '1');
				ioDriveInit(c);
			}
		});
	})();
	</script>
	<?php
}

/* ============================================================
 * 7. PANEL DE ADMIN / TALLER — link de Drive en la ficha y en el
 *    listado de pedidos, para que el taller vea de un vistazo si
 *    el pedido tiene archivos o todavía está pendiente.
 * ============================================================ */

add_action( 'woocommerce_admin_order_data_after_order_details', 'io_drive_render_admin_order_box' );

/**
 * Caja dentro de la ficha del pedido (edición de pedido en el admin)
 * con el estado de los archivos y el link directo a la carpeta de Drive.
 *
 * @param WC_Order $order
 */
function io_drive_render_admin_order_box( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$link  = $order->get_meta( '_io_drive_link' );
	$count = (int) $order->get_meta( '_io_drive_file_count' );
	?>
	<div class="io-drive-admin-box" style="clear:both;margin-top:12px;padding:12px;border:1px solid #dcdcde;border-radius:4px;background:#fff;">
		<p style="margin:0 0 8px;font-weight:600;">📁 Archivos del cliente (Drive)</p>
		<?php if ( $count > 0 ) : ?>
			<p style="margin:0 0 8px;color:#2e7d32;">✅ <?php echo esc_html( $count ); ?> archivo(s) recibido(s).</p>
		<?php else : ?>
			<p style="margin:0 0 8px;color:#b32d2e;font-weight:600;">⚠️ Sin archivos todavía — este pedido no debe pasar a producción.</p>
		<?php endif; ?>
		<?php if ( $link ) : ?>
			<p style="margin:0;"><a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener" class="button">Ver carpeta en Drive</a></p>
		<?php else : ?>
			<p style="margin:0;color:#646970;">La carpeta se crea automáticamente en cuanto el cliente sube el primer archivo.</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Agrega la columna "Archivos" al listado de pedidos, tanto en el
 * listado clásico (CPT shop_order) como en el nuevo (HPOS wc-orders).
 *
 * @param array $columns
 * @return array
 */
function io_drive_add_order_column( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'order_status' === $key ) {
			$new['io_drive_files'] = '📁 Archivos';
		}
	}
	if ( ! isset( $new['io_drive_files'] ) ) {
		$new['io_drive_files'] = '📁 Archivos';
	}
	return $new;
}
add_filter( 'manage_edit-shop_order_columns', 'io_drive_add_order_column' );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'io_drive_add_order_column' );

/**
 * Pinta la columna "Archivos" en el listado de pedidos.
 *
 * @param string          $column
 * @param int|WC_Order    $order_or_post_id En el listado clásico llega el post_id, en HPOS llega el WC_Order.
 */
function io_drive_render_order_column( $column, $order_or_post_id ) {
	if ( 'io_drive_files' !== $column ) {
		return;
	}

	$order = ( $order_or_post_id instanceof WC_Order ) ? $order_or_post_id : wc_get_order( $order_or_post_id );
	if ( ! $order ) {
		return;
	}

	$count = (int) $order->get_meta( '_io_drive_file_count' );
	$link  = $order->get_meta( '_io_drive_link' );

	if ( $count > 0 ) {
		printf(
			'<a href="%s" target="_blank" rel="noopener" title="%s">✅ %d</a>',
			esc_url( $link ),
			esc_attr( sprintf( '%d archivo(s) — ver carpeta en Drive', $count ) ),
			$count
		);
	} else {
		echo '<span style="color:#b32d2e;" title="Sin archivos todavía">⚠️ 0</span>';
	}
}
add_action( 'manage_shop_order_posts_custom_column', 'io_drive_render_order_column', 10, 2 );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'io_drive_render_order_column', 10, 2 );
