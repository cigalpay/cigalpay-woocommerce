<?php
/**
 * Plugin Name:          CigalPay for WooCommerce
 * Plugin URI:           https://cigalpay.com/developers
 * Description:          Accept crypto payments (Bitcoin, USDT, Ethereum, Solana and more) in your WooCommerce store. Prices stay in USD or EUR; customers pick the coin on the CigalPay checkout.
 * Version:              1.0.0
 * Author:               CigalPay
 * Author URI:           https://cigalpay.com
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          cigalpay-for-woocommerce
 * Requires at least:    6.3
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 */

defined( 'ABSPATH' ) || exit;

define( 'CIGALPAY_WC_VERSION', '1.0.0' );
define( 'CIGALPAY_WC_FILE', __FILE__ );
define( 'CIGALPAY_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'CIGALPAY_WC_DIR', plugin_dir_path( __FILE__ ) );

// The signature canonicalizer of the CigalPay PHP SDK, unless the SDK is already loaded.
if ( ! class_exists( '\Cigalpay\Webhook' ) ) {
	require_once CIGALPAY_WC_DIR . 'includes/sdk/Webhook.php';
}

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CIGALPAY_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CIGALPAY_WC_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		require_once CIGALPAY_WC_DIR . 'includes/class-cigalpay-wc-api.php';
		require_once CIGALPAY_WC_DIR . 'includes/class-wc-gateway-cigalpay.php';

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'WC_Gateway_CigalPay';
				return $gateways;
			}
		);
	}
);

add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once CIGALPAY_WC_DIR . 'includes/class-cigalpay-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				$registry->register( new CigalPay_Blocks() );
			}
		);
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=cigalpay' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'cigalpay-for-woocommerce' ) . '</a>' );
		return $links;
	}
);
