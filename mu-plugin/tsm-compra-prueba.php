<?php
/**
 * Plugin Name: TSM · Compra de prueba (Monitor dorica)
 * Description: dorica.agency 2026-10. Permite al Monitor dorica recorrer la compra completa cada 30 min
 * (carrito → finalizar compra → cálculo del pedido → «Realizar pedido») SIN crear ningún pedido.
 * Solo actúa en peticiones que traen la cabecera X-TSM-Synthetic con la clave secreta. En ellas:
 *  - corta el proceso justo después de validar el checkout y ANTES de crear el pedido (no hay pedido,
 *    ni pendiente ni fallido, ni emails, ni paso por Redsys) y responde OK / errores en JSON;
 *  - no carga PixelYourSite ni el contador de visitas (no ensucia estadísticas);
 *  - borra la sesión/carrito de prueba al terminar.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TSM_SYNTH_KEY', '__TSM_SYNTH_KEY__' );

function tsm_synth_es() {
	$h = isset( $_SERVER['HTTP_X_TSM_SYNTHETIC'] ) ? (string) $_SERVER['HTTP_X_TSM_SYNTHETIC'] : '';
	return $h !== '' && hash_equals( TSM_SYNTH_KEY, $h );
}

if ( ! tsm_synth_es() ) { return; }

if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }

// Sin píxel ni contador de visitas en las visitas de prueba.
add_filter( 'option_active_plugins', function ( $plugins ) {
	return array_values( array_filter( (array) $plugins, function ( $p ) {
		return strpos( $p, 'pixelyoursite/' ) !== 0 && strpos( $p, 'page-views-count/' ) !== 0;
	} ) );
} );

// 1) Datos del producto de prueba: variación más barata disponible de la tarjeta regalo.
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['tsm_synth'] ) || 'info' !== $_GET['tsm_synth'] || ! function_exists( 'wc_get_product' ) ) { return; }
	$prod = wc_get_product( 20985 );
	$best = null;
	if ( $prod ) {
		foreach ( $prod->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && $v->is_purchasable() && $v->is_in_stock() && ( ! $best || (float) $v->get_price() < (float) $best->get_price() ) ) { $best = $v; }
		}
	}
	if ( ! $best ) { wp_send_json( array( 'ok' => false, 'error' => 'Sin variaciones disponibles' ) ); }
	wp_send_json( array( 'ok' => true, 'product_id' => 20985, 'variation_id' => $best->get_id(), 'attributes' => $best->get_variation_attributes(), 'price' => $best->get_price(), 'url' => get_permalink( 20985 ) ) );
}, 1 );

// 2) «Realizar pedido»: se para tras la validación, antes de crear el pedido.
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	$res = array( 'tsm_synthetic' => 'ok' );
	if ( is_wp_error( $errors ) && $errors->has_errors() ) {
		$res = array( 'tsm_synthetic' => 'fail', 'errors' => array_map( 'wp_strip_all_tags', $errors->get_error_messages() ) );
	}
	$gw = WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : array();
	$res['tarjeta_redsys'] = isset( $gw['redsys'] );
	if ( ! $res['tarjeta_redsys'] ) { $res['tsm_synthetic'] = 'fail'; $res['errors'][] = 'El pago con tarjeta (Redsys) no está disponible'; }
	$res['total'] = WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0;
	if ( function_exists( 'wc_clear_notices' ) ) { wc_clear_notices(); }
	if ( WC()->cart ) { WC()->cart->empty_cart( true ); }
	if ( WC()->session ) {
		// WooCommerce vuelve a guardar la sesión al cerrar la petición: se borra después (prioridad máxima).
		$clave = (string) WC()->session->get_customer_id();
		WC()->session->destroy_session();
		add_action( 'shutdown', function () use ( $clave ) {
			global $wpdb;
			// Al destruirla, WooCommerce crea otra sesión con id nuevo y guarda en ella el cliente: se borran las dos.
			$claves = array_unique( array_filter( array( $clave, WC()->session ? (string) WC()->session->get_customer_id() : '' ) ) );
			foreach ( $claves as $k ) {
				$wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => $k ) );
				wp_cache_delete( $k, 'wc_session_id' );
			}
		}, PHP_INT_MAX );
	}
	wp_send_json( $res );
}, PHP_INT_MAX, 2 );
