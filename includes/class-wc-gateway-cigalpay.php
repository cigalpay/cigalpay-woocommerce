<?php
/**
 * CigalPay payment gateway: sends the customer to the CigalPay hosted
 * checkout and marks the order paid from CigalPay's signed notification.
 */

defined( 'ABSPATH' ) || exit;

class WC_Gateway_CigalPay extends WC_Payment_Gateway {

	const CURRENCIES = array( 'USD', 'EUR' );
	const LANGS      = array( 'en', 'fr', 'es', 'de' );

	/** Order meta: checkouts created for the order (one per attempt), the latest link, payment statuses already handled, CigalPay refunds. */
	const META_CHECKOUTS = '_cigalpay_checkout_ids';
	const META_LINK      = '_cigalpay_checkout_url';
	const META_SEEN      = '_cigalpay_seen';
	const META_REFUNDS   = '_cigalpay_refund_ids';

	public function __construct() {
		$this->id                 = 'cigalpay';
		$this->has_fields         = false;
		$this->method_title       = 'CigalPay';
		$this->method_description = __( 'Accept Bitcoin, USDT, Ethereum, Solana and more. Customers pay on the CigalPay checkout; the order is marked paid once the payment is confirmed on the network.', 'cigalpay-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_cigalpay', array( $this, 'handle_notification' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'cigalpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept crypto payments with CigalPay', 'cigalpay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'cigalpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What customers see at checkout.', 'cigalpay-for-woocommerce' ),
				'default'     => __( 'Crypto (Bitcoin, USDT, Ethereum…)', 'cigalpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'cigalpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the title at checkout.', 'cigalpay-for-woocommerce' ),
				'default'     => __( 'Pay with Bitcoin, USDT, Ethereum, Solana and more. You choose the coin on the next page.', 'cigalpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'account_id'  => array(
				'title'       => __( 'Account ID', 'cigalpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'CigalPay dashboard → Settings → API credentials.', 'cigalpay-for-woocommerce' ),
				'default'     => '',
			),
			'ipn_secret'  => array(
				'title'       => __( 'Notification signing secret', 'cigalpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Same page. Used to check that payment notifications really come from CigalPay.', 'cigalpay-for-woocommerce' ),
				'default'     => '',
			),
			'api_key'     => array(
				'title'       => __( 'API key', 'cigalpay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Same page. Only needed to refund orders in crypto from WooCommerce.', 'cigalpay-for-woocommerce' ),
				'default'     => '',
			),
			'debug'       => array(
				'title'       => __( 'Debug log', 'cigalpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log every notification', 'cigalpay-for-woocommerce' ),
				'description' => __( 'In WooCommerce → Status → Logs, source "cigalpay". Errors are always logged.', 'cigalpay-for-woocommerce' ),
				'default'     => 'no',
			),
		);
	}

	public function admin_options() {
		if ( ! in_array( get_woocommerce_currency(), self::CURRENCIES, true ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: store currency code */
					__( 'CigalPay prices orders in USD or EUR. Your store currency is %s, so CigalPay is hidden at checkout.', 'cigalpay-for-woocommerce' ),
					get_woocommerce_currency()
				)
			) . '</p></div>';
		}
		parent::admin_options();
		echo '<p>' . esc_html__( 'Payment notifications are sent to this address automatically, nothing to set up:', 'cigalpay-for-woocommerce' )
			. ' <code>' . esc_html( $this->notify_url() ) . '</code></p>';
	}

	public function get_icon() {
		$html = '';
		foreach ( array( 'btc' => 'Bitcoin', 'usdt' => 'USDT', 'eth' => 'Ethereum', 'sol' => 'Solana' ) as $file => $name ) {
			$html .= '<img src="' . esc_url( CIGALPAY_WC_URL . 'assets/images/' . $file . '.png' ) . '" alt="' . esc_attr( $name ) . '" width="24" height="24" style="display:inline-block;width:24px;height:24px;margin-left:4px;vertical-align:middle;" />';
		}
		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
	}

	public function is_available() {
		return parent::is_available()
			&& '' !== trim( $this->get_option( 'account_id' ) )
			&& '' !== trim( $this->get_option( 'ipn_secret' ) )
			&& in_array( get_woocommerce_currency(), self::CURRENCIES, true );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$checkout = CigalPay_WC_API::create_checkout(
			array(
				'accountId'   => trim( $this->get_option( 'account_id' ) ),
				'amount'      => round( (float) $order->get_total(), 2 ),
				'currency'    => $order->get_currency(),
				'extraId'     => (string) $order->get_id(),
				'description' => $this->order_description( $order ),
				'lang'        => $this->lang(),
				'callback'    => $this->get_return_url( $order ),
				'ipn'         => $this->notify_url(),
			)
		);
		if ( is_wp_error( $checkout ) ) {
			$this->log( 'error', sprintf( 'Order %d: checkout not created: %s', $order->get_id(), $checkout->get_error_message() ) );
			wc_add_notice( __( 'Crypto payment is unavailable right now. Please try again in a moment or choose another payment method.', 'cigalpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Every checkout made for this order is accepted: a customer can come
		// back and pay an earlier one.
		$ids   = $this->meta_list( $order, self::META_CHECKOUTS );
		$ids[] = (string) $checkout['id'];
		$order->update_meta_data( self::META_CHECKOUTS, array_values( array_unique( $ids ) ) );
		$order->update_meta_data( self::META_LINK, (string) $checkout['chargeLink'] );
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $checkout['chargeLink'],
		);
	}

	/**
	 * CigalPay's payment notification (POST /?wc-api=cigalpay). Trusted only
	 * when the signature checks out and the payment is for a checkout this
	 * store created for that order, at that order's price.
	 */
	public function handle_notification() {
		$raw = file_get_contents( 'php://input' );
		$sig = isset( $_SERVER['HTTP_X_CIGALPAY_SIG'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CIGALPAY_SIG'] ) ) : '';

		if ( ! CigalPay_WC_API::verify_notification( $raw, $sig, (string) $this->get_option( 'ipn_secret' ) ) ) {
			$this->log( 'error', 'Notification rejected: bad or missing signature. Check the notification signing secret in the CigalPay settings.' );
			status_header( 401 );
			exit;
		}

		$notification = json_decode( $raw, true );
		if ( ! is_array( $notification ) ) {
			status_header( 400 );
			exit;
		}
		$this->apply_notification( $notification );
		status_header( 200 );
		exit;
	}

	/** @param array $p a verified notification */
	protected function apply_notification( array $p ) {
		$payment_id = isset( $p['id'] ) ? (string) $p['id'] : '';
		$status     = isset( $p['status'] ) ? (string) $p['status'] : '';
		$order      = isset( $p['extraId'] ) && ctype_digit( (string) $p['extraId'] ) ? wc_get_order( (int) $p['extraId'] ) : false;

		$this->log( 'info', sprintf( 'Notification: payment %s, order %s, status %s', $payment_id, isset( $p['extraId'] ) ? $p['extraId'] : '-', $status ) );

		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			$this->log( 'info', 'Ignored: no CigalPay order with this reference.' );
			return;
		}

		$checkout_id = isset( $p['checkoutId'] ) ? (string) $p['checkoutId'] : '';
		if ( '' === $checkout_id || ! in_array( $checkout_id, $this->meta_list( $order, self::META_CHECKOUTS ), true ) ) {
			// Anyone can create a checkout with this store's account and an order
			// number; only the checkouts this store made count.
			$this->log( 'error', sprintf( 'Order %d: ignored payment %s, it is not for a checkout this store created for the order.', $order->get_id(), $payment_id ) );
			return;
		}

		// Each payment status is handled once (notifications can repeat).
		$seen = $this->meta_list( $order, self::META_SEEN );
		$key  = $payment_id . ':' . $status;
		if ( in_array( $key, $seen, true ) ) {
			return;
		}
		$seen[] = $key;
		$order->update_meta_data( self::META_SEEN, array_slice( $seen, -50 ) );
		$order->save();

		$coin = $this->coin_amount( $p );

		if ( CigalPay_WC_API::is_paid( $status ) ) {
			$this->on_paid( $order, $p, $coin );
			return;
		}
		if ( $order->is_paid() ) {
			return;
		}

		switch ( $status ) {
			case CigalPay_WC_API::STATUS_WAITING:
				$order->add_order_note( sprintf( /* translators: %s: amount and coin, e.g. 0.0012 BTC */ __( 'CigalPay: customer chose to pay %s.', 'cigalpay-for-woocommerce' ), $coin ) );
				break;
			case CigalPay_WC_API::STATUS_CONFIRMING:
				// on-hold, so WooCommerce doesn't cancel the order as unpaid while the network confirms.
				$order->update_status( 'on-hold', sprintf( /* translators: %s: amount and coin */ __( 'CigalPay: payment of %s detected, waiting for network confirmations.', 'cigalpay-for-woocommerce' ), $coin ) );
				break;
			case CigalPay_WC_API::STATUS_PARTIALLY_PAID:
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: amount received, 2: amount expected (with coin) */
						__( 'CigalPay: the customer sent only %1$s of %2$s. Contact them before shipping.', 'cigalpay-for-woocommerce' ),
						$this->format_coin( isset( $p['amountPaid'] ) ? $p['amountPaid'] : 0, $p ),
						$coin
					)
				);
				break;
			case CigalPay_WC_API::STATUS_EXPIRED:
			case CigalPay_WC_API::STATUS_FAILED:
				$order->add_order_note( sprintf( /* translators: %s: amount and coin */ __( 'CigalPay: the payment of %s expired without being paid. The customer can pay again from their order.', 'cigalpay-for-woocommerce' ), $coin ) );
				break;
		}
	}

	protected function on_paid( WC_Order $order, array $p, $coin ) {
		$payment_id = (string) $p['id'];

		if ( $order->is_paid() ) {
			if ( $order->get_transaction_id() !== $payment_id ) {
				$order->add_order_note( sprintf( /* translators: 1: amount and coin, 2: payment id */ __( 'CigalPay: a second payment of %1$s was received for this already paid order (payment %2$s). Refund the customer if it was a mistake.', 'cigalpay-for-woocommerce' ), $coin, $payment_id ) );
			}
			return;
		}

		$paid_fiat = isset( $p['checkoutAmount'] ) ? (float) $p['checkoutAmount'] : -1;
		$paid_ccy  = isset( $p['checkoutCurrency'] ) ? strtoupper( (string) $p['checkoutCurrency'] ) : '';
		if ( $paid_ccy !== $order->get_currency() || abs( $paid_fiat - (float) $order->get_total() ) > 0.01 ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: amount paid, 2: order total */
					__( 'CigalPay: payment received for %1$s, but the order total is now %2$s. Check before shipping.', 'cigalpay-for-woocommerce' ),
					wc_price( $paid_fiat, array( 'currency' => $paid_ccy ) ),
					wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) )
				)
			);
			return;
		}

		$note = sprintf( /* translators: 1: amount and coin, 2: payment id */ __( 'CigalPay: paid %1$s (payment %2$s).', 'cigalpay-for-woocommerce' ), $coin, $payment_id );
		if ( ! empty( $p['swapFromCurrency'] ) ) {
			$note .= ' ' . sprintf( /* translators: 1: amount, 2: coin */ __( 'The customer paid in %1$s %2$s, converted automatically.', 'cigalpay-for-woocommerce' ), isset( $p['swapFromAmount'] ) ? $p['swapFromAmount'] : '', $p['swapFromCurrency'] );
		}
		$order->add_order_note( $note );
		$order->payment_complete( $payment_id );
	}

	/** The "Refund via CigalPay" button: for paid orders, once the API key is set. */
	public function can_refund_order( $order ) {
		return $order && $order->get_transaction_id() && '' !== trim( $this->get_option( 'api_key' ) );
	}

	/**
	 * Refunds in the coin the customer paid, from the CigalPay balance. The
	 * customer gets an email with a link to enter their address; CigalPay
	 * sends the coin once the refund is reviewed. A WP_Error cancels the
	 * WooCommerce refund and shows CigalPay's reason.
	 *
	 * @param int        $order_id
	 * @param float|null $amount in the order currency
	 * @param string     $reason
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->can_refund_order( $order ) ) {
			return new WP_Error( 'cigalpay_refund', __( 'Add your CigalPay API key in the plugin settings to refund in crypto.', 'cigalpay-for-woocommerce' ) );
		}
		if ( null === $amount || (float) $amount <= 0 ) {
			return new WP_Error( 'cigalpay_refund', __( 'Enter the amount to refund.', 'cigalpay-for-woocommerce' ) );
		}

		$body = array(
			'paymentId'      => (string) $order->get_transaction_id(),
			'checkoutAmount' => round( (float) $amount, 2 ),
			'reason'         => function_exists( 'mb_substr' ) ? mb_substr( (string) $reason, 0, 200 ) : substr( (string) $reason, 0, 200 ),
		);
		if ( is_email( $order->get_billing_email() ) ) {
			$body['customerEmail'] = $order->get_billing_email();
		}
		$res = CigalPay_WC_API::create_refund( trim( $this->get_option( 'api_key' ) ), $body );
		if ( is_wp_error( $res ) ) {
			$this->log( 'error', sprintf( 'Order %d: refund of %s refused: %s', $order->get_id(), $amount, $res->get_error_message() ) );
			/* translators: %s: CigalPay's reason */
			return new WP_Error( 'cigalpay_refund', sprintf( __( 'CigalPay: %s', 'cigalpay-for-woocommerce' ), $res->get_error_message() ) );
		}

		$ids   = $this->meta_list( $order, self::META_REFUNDS );
		$ids[] = (string) $res['refund']['id'];
		$order->update_meta_data( self::META_REFUNDS, $ids );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount in the order currency, 2: amount and coin, 3: the customer's link */
				__( 'CigalPay: refund of %1$s started (%2$s). The customer got an email to enter their address: %3$s. CigalPay sends it once reviewed.', 'cigalpay-for-woocommerce' ),
				wc_price( $amount, array( 'currency' => $order->get_currency() ) ),
				$this->format_coin( isset( $res['refund']['amount'] ) ? $res['refund']['amount'] : '', array( 'currency' => isset( $res['refund']['currency'] ) ? $res['refund']['currency'] : '' ) ),
				isset( $res['link'] ) ? esc_url( $res['link'] ) : ''
			)
		);
		return true;
	}

	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		echo '<p>' . esc_html__( 'Your crypto payment is being confirmed on the network. This usually takes a few minutes, and you will get an email once your order is confirmed.', 'cigalpay-for-woocommerce' ) . '</p>';
		$link = $order->get_meta( self::META_LINK );
		if ( $link && $order->has_status( 'pending' ) ) {
			echo '<p><a class="button" href="' . esc_url( $link ) . '">' . esc_html__( 'Not paid yet? Go back to the payment page', 'cigalpay-for-woocommerce' ) . '</a></p>';
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	protected function notify_url() {
		return WC()->api_request_url( 'cigalpay' );
	}

	protected function lang() {
		$lang = strtolower( substr( determine_locale(), 0, 2 ) );
		return in_array( $lang, self::LANGS, true ) ? $lang : 'en';
	}

	protected function order_description( WC_Order $order ) {
		/* translators: 1: order number, 2: site name */
		$text = sprintf( __( 'Order %1$s - %2$s', 'cigalpay-for-woocommerce' ), $order->get_order_number(), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
	}

	/** A list kept in order meta (an order without one has ''). */
	protected function meta_list( WC_Order $order, $key ) {
		$value = $order->get_meta( $key );
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	protected function coin_amount( array $p ) {
		return $this->format_coin( isset( $p['amount'] ) ? $p['amount'] : '', $p );
	}

	protected function format_coin( $amount, array $p ) {
		$coin = isset( $p['currency'] ) ? (string) $p['currency'] : '';
		$coin = 'USDT_TRX' === $coin ? 'USDT (TRC-20)' : str_replace( '_', ' ', $coin );
		return trim( $amount . ' ' . $coin );
	}

	protected function log( $level, $message ) {
		if ( 'info' === $level && 'yes' !== $this->get_option( 'debug' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'cigalpay' ) );
	}
}
