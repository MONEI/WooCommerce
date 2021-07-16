<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class that handle Monei Payment method by default (HOSTED / Form based ) for retro compatibility.
 * Form based: This is where the user must click a button on a form that then redirects them to the payment processor on the gateway’s own website.
 * https://docs.monei.com/docs/integrations/use-prebuilt-payment-page/
 *
 * Class WC_Gateway_Monei_Hosted_CC
 */
class WC_Gateway_Monei_Hosted_CC extends WC_Monei_Payment_Gateway_Hosted {

	const PAYMENT_METHOD = 'card';

	/**
	 * Constructor for the gateway.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {

		$this->id = MONEI_GATEWAY_ID;
		$this->method_title  = __( 'MONEI - Credit Card (redirect)', 'monei' );
		$this->method_description = __( 'Best payment gateway rates. The perfect solution to manage your digital payments.', 'monei' );
		$this->enabled = ( ! empty( $this->get_option( 'enabled' ) && 'yes' === $this->get_option( 'enabled' ) ) && $this->is_valid_for_use() ) ? 'yes' : false;

		// Load the form fields.
		$this->init_form_fields();
		// Load the settings.
		$this->init_settings();

		// Hosted payment with redirect.
		$this->has_fields = false;

		// Settings variable
		$this->hide_logo            = ( ! empty( $this->get_option( 'hide_logo' ) && 'yes' === $this->get_option( 'hide_logo' ) ) ) ? true : false;
		$this->icon                 = ( $this->hide_logo ) ? '' : apply_filters( 'woocommerce_monei_icon', WC_Monei()->image_url( 'monei-logo.svg' ) );
		$this->testmode             = ( ! empty( $this->get_option( 'testmode' ) && 'yes' === $this->get_option( 'testmode' ) ) ) ? true : false;
		$this->title                = ( ! empty( $this->get_option( 'title' ) ) ) ? $this->get_option( 'title' ) : '';
		$this->description          = ( ! empty( $this->get_option( 'description' ) ) ) ? $this->get_option( 'description' ) : '';
		$this->status_after_payment = ( ! empty( $this->get_option( 'orderdo' ) ) ) ? $this->get_option( 'orderdo' ) : '';
		$this->account_id           = ( ! empty( $this->get_option( 'accountid' ) ) ) ? $this->get_option( 'accountid' ) : '';
		$this->api_key              = ( ! empty( $this->get_option( 'apikey' ) ) ) ? $this->get_option( 'apikey' ) : '';
		$this->shop_name            = ( ! empty( $this->get_option( 'commercename' ) ) ) ? $this->get_option( 'commercename' ) : get_bloginfo( 'name' );
		$this->password             = ( ! empty( $this->get_option( 'password' ) ) ) ? $this->get_option( 'password' ) : '';
		$this->tokenization         = ( ! empty( $this->get_option( 'tokenization' ) && 'yes' === $this->get_option( 'tokenization' ) ) ) ? true : false;
		$this->pre_auth             = ( ! empty( $this->get_option( 'pre-authorize' ) && 'yes' === $this->get_option( 'pre-authorize' ) ) ) ? true : false;
		$this->logging              = ( ! empty( $this->get_option( 'debug' ) ) && 'yes' === $this->get_option( 'debug' ) ) ? true : false;

		// IPN callbacks
		$this->notify_url           = WC_Monei()->get_ipn_url();
		new WC_Monei_IPN();

		$this->supports             = array(
			'products',
			'refunds',
		);

		if ( $this->tokenization ) {
			$this->supports[] = 'tokenization';
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @access public
	 * @since 5.0
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields( 'monei-settings.php' );
	}

	/**
	 * Process the payment and return the result
	 *
	 * @access public
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		return parent::process_payment( $order_id, self::PAYMENT_METHOD );
	}

	/**
	 * Add payment method in Account add_payment_method_page.
	 *
	 * @return array
	 */
	public function add_payment_method() {

		if ( ! wp_verify_nonce( $_POST['woocommerce-add-payment-method-nonce'], 'woocommerce-add-payment-method' ) ) {
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		}

		// Since it is a hosted version, we need to create a 0 EUR payment and send customer to MONEI.
		try {
			$zero_payload = $this->create_zero_eur_payload();
			$payment = WC_Monei_API::create_payment( $zero_payload );
			WC_Monei_Logger::log( 'WC_Monei_API::add_payment_method', 'debug' );
			WC_Monei_Logger::log( $zero_payload, 'debug' );
			WC_Monei_Logger::log( $payment, 'debug' );
			do_action( 'wc_gateway_monei_add_payment_method_success', $zero_payload, $payment );
			return array(
				'result'   => 'success',
				'redirect' => $payment->getNextAction()->getRedirectUrl(),
			);
		} catch ( Exception $e ) {
			WC_Monei_Logger::log( $e, 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			return array(
				'result'   => 'failure',
				'redirect' => wc_get_endpoint_url( 'payment-methods' ),
			);
		}
	}

	/**
	 * @return array
	 */
	protected function create_zero_eur_payload() {
		$current_user_id = (string) get_current_user_id();
		/**
		 * Create 0 EUR Payment Payload
		 */
		return [
			'amount'      => 0,
			'currency'    => get_woocommerce_currency(),
			'orderId'     => $current_user_id . 'generatetoken' . rand( 0, 1000000 ),
			'description' => "User $current_user_id creating empty transaction to generate token",
			'callbackUrl' => wp_sanitize_redirect( esc_url_raw( $this->notify_url ) ),
			'completeUrl' => wc_get_endpoint_url( 'payment-methods' ),
			'cancelUrl'   => wc_get_endpoint_url( 'payment-methods' ),
			'failUrl'     => wc_get_endpoint_url( 'payment-methods' ),
			'transactionType' => self::SALE_TRANSACTION_TYPE,
			'sessionDetails'  => [
				'ip'        => WC_Geolocation::get_ip_address(),
				'userAgent' => wc_get_user_agent(),
			],
			'generatePaymentToken' => true,
			'allowedPaymentMethods' => [ self::PAYMENT_METHOD ],
		];
	}

	/**
	 * Payments fields, shown on checkout or payment method page (add payment method).
	 */
	function payment_fields() {
		if ( is_add_payment_method_page() ) {
			_e( 'Pay via MONEI: you can add your payment method for future payments.', 'monei' );
		} else {
			// Checkout screen. We show description, if tokenization available, we show saved cards and checkbox to save.
			echo $this->description;
			if ( $this->tokenization ) {
				$this->tokenization_script();
				$this->saved_payment_methods();
				$this->save_payment_method_checkbox();
			}
		}
	}

}

