<?php
/**
 * CigalPay in the block-based Cart & Checkout (the default checkout since WooCommerce 8.3).
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class CigalPay_Blocks extends AbstractPaymentMethodType {

	protected $name = 'cigalpay';

	/** @var WC_Gateway_CigalPay|null */
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_cigalpay_settings', array() );
		$gateways       = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$this->gateway  = isset( $gateways['cigalpay'] ) ? $gateways['cigalpay'] : null;
	}

	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'cigalpay-blocks',
			CIGALPAY_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			CIGALPAY_WC_VERSION,
			true
		);
		return array( 'cigalpay-blocks' );
	}

	public function get_payment_method_data() {
		$icons = array();
		foreach ( array( 'btc' => 'Bitcoin', 'usdt' => 'USDT', 'eth' => 'Ethereum', 'sol' => 'Solana' ) as $file => $name ) {
			$icons[] = array(
				'src' => CIGALPAY_WC_URL . 'assets/images/' . $file . '.png',
				'alt' => $name,
			);
		}
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'icons'       => $icons,
			'supports'    => $this->gateway ? array_values( $this->gateway->supports ) : array( 'products' ),
		);
	}
}
