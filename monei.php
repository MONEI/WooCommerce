<?php

/**
 * WooCommerce MONEI Gateway
 *
 * @package WooCommerce MONEI Gateway
 * @author José Conti
 * @copyright 2020-2020 MONEI Conti
 * @license GPL-3.0+
 *
 * Plugin Name: WooCommerce MONEI Gateway
 * Plugin URI: https://wordpress.org/plugins/monei/
 * Description: Extends WooCommerce with a MONEI gateway. Best payment gateway rates. The perfect solution to manage your digital payments.
 * Version: 4.2.1
 * Author: MONEI
 * Author URI: https://www.monei.net/
 * Tested up to: 5.7
 * WC requires at least: 3.0
 * WC tested up to: 5.3
 * Text Domain: monei
 * Domain Path: /languages/
 * Copyright: (C) 2017 MONEI.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

define( 'MONEI_VERSION', '4.2.1' );
define( 'MONEI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MONEI_SIGNUP', 'https://dashboard.monei.net/?action=signUp' );
define( 'MONEI_WEB', 'https://monei.net/' );
define( 'MONEI_REVIEW', 'https://wordpress.org/support/plugin/monei/reviews/?rate=5#new-post' );
define( 'MONEI_SUPPORT', 'https://support.monei.net/' );

add_action( 'plugins_loaded', 'woocommerce_gateway_monei_init', 0 );

require_once 'includes/vendor/autoload.php';


function woocommerce_gateway_monei_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	/**
	 * Localisation
	 */
	load_plugin_textdomain( 'monei', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	/**
	 * Gateway class
	 */
	class WC_Gateway_Monei extends WC_Payment_Gateway {
		var $notify_url;
		/**
		 * Constructor for the gateway.
		 *
		 * @access public
		 * @return void
		 */
		public function __construct() {
			global $woocommerce;
			$this->id = 'monei';
			$logo_url = $this->get_option( 'logo' );
			if ( ! empty( $logo_url ) ) {
				$logo_url   = $this->get_option( 'logo' );
				$this->icon = apply_filters( 'woocommerce_monei_icon', $logo_url );
			} else {
				$this->icon = apply_filters( 'woocommerce_monei_icon', plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) . '/assets/images/MONEI-logo.png' );
			}
			$this->has_fields           = true;
			$this->liveurl              = 'https://pay.monei.com/checkout';
			$this->refund_url           = 'https://api.monei.com/v1/refund';
			$this->charge_url           = 'https://api.monei.com/v1/charge';
			$this->testmode             = $this->get_option( 'testmode' );
			$this->method_title         = __( 'MONEI', 'monei' );
			$this->notify_url           = add_query_arg( 'wc-api', 'WC_Gateway_monei', home_url( '/' ) );
			$this->notify_url_not_https = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_monei', home_url( '/' ) ) );
			// Define user set variables.
			$this->title                = $this->get_option( 'title' );
			$this->description          = $this->get_option( 'description' );
			$this->logo                 = $this->get_option( 'logo' );
			$this->orderdo              = $this->get_option( 'orderdo' );
			$this->accountid            = $this->get_option( 'accountid' );
			$this->apikey               = $this->get_option( 'apikey' );
			$this->commercename         = $this->get_option( 'commercename' );
			$this->secret               = $this->get_option( 'secret' );
			$this->password             = $this->get_option( 'password' );
			$this->tokenization         = $this->get_option( 'tokenization' );
			$this->debug                = $this->get_option( 'debug' );
			$this->log                  = new WC_Logger();			
			$this->supports             = array(
				'products',
				'tokenization',
				'refunds',
				'subscriptions',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'subscription_amount_changes',
				'subscription_date_changes',
				'subscription_payment_method_change',
				'subscription_payment_method_change_customer',
				'subscription_payment_method_change_admin',
				'multiple_subscriptions',
			);
			$this->init_form_fields();
			$this->init_settings();
			// Actions.
			add_action( 'valid_monei_standard_ipn_request', array( $this, 'successful_request' ) );
			add_action( 'woocommerce_receipt_monei', array( $this, 'receipt_page' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			// Payment listener/API hook.
			add_action( 'woocommerce_api_wc_gateway_' . $this->id, array( $this, 'check_ipn_response' ) );
			
			if ( class_exists( 'WC_Subscriptions_Order' ) ) {
				add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'doing_scheduled_subscription_payment' ), 10, 2 );
			}
			if ( ! $this->is_valid_for_use() ) {
				$this->enabled = false;
			}
		}

		/**
		 * Check if this gateway is enabled and available in the user's country
		 *
		 * @access public
		 * @return bool
		 */
		function is_valid_for_use() {

			if ( ! in_array( get_woocommerce_currency(), array( 'EUR', 'USD', 'GBP' ), true ) ) {
				return false;
			} else {
				return true;
			}
		}
		function product_description( $order ) {

			$product_id = '';
			$name       = '';
			$sku        = '';
			foreach ( $order->get_items() as $item ) {
				$product_id .= $item->get_product_id() . ', ';
				$name       .= $item->get_name() . ', ';
				$sku        .= get_post_meta( $item->get_product_id(), '_sku', true) . ', ';
			}
			// Can be order, id, name or sku
			$description_type = 'name';
			
			if ( 'id' === $description_type ) {
				$description = $product_id;
			} elseif ( 'name' === $description_type ) {
				$description = $name;
			} elseif ( 'sku' === $description_type ) {
				$description = $sku;
			} else {
				$description = __( 'Order', 'monei' ) . ' ' . $order->get_order_number();
			}
			return $description;
		}
		/**
		 * Admin Panel Options
		 *
		 * @since 1.0.0
		 */
		public function admin_options() {
			?>
			<h3><?php esc_html_e( 'MONEI', 'monei' ); ?></h3>
			<p><?php esc_html_e( 'Best payment gateway rates. The perfect solution to manage your digital payments.', 'monei' ); ?></p>
			<?php if ( $this->is_valid_for_use() ) : ?>
				<table class="form-table">
				<?php
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
				?>
				</table><!--/.form-table-->
			<?php else : ?>
				<div class="inline error"><p><strong><?php esc_html_e( 'Gateway Disabled', 'monei' ); ?></strong>: <?php esc_html_e( 'MONEI only support EUROS, USD & GBP currencies.', 'monei' ); ?></p></div>
				<?php
			endif;
		}
		/**
		 * Initialise Gateway Settings Form Fields
		 *
		 * @access public
		 * @return void
		 */
		function init_form_fields() {
			$this->form_fields = array(
				'enabled'        => array(
					'title'   => __( 'Enable/Disable', 'monei' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable MONEI', 'monei' ),
					'default' => 'no',
				),
				'title'          => array(
					'title'       => __( 'Title', 'monei' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'monei' ),
					'default'     => __( 'MONEI', 'monei' ),
					'desc_tip'    => true,
				),
				'description'    => array(
					'title'       => __( 'Description', 'monei' ),
					'type'        => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'monei' ),
					'default'     => __( 'Pay via MONEI; you can pay with your credit card.', 'monei' ),
				),
				'logo'           => array(
					'title'       => __( 'Logo', 'monei' ),
					'type'        => 'text',
					'description' => __( 'Add link to image logo.', 'monei' ),
					'desc_tip'    => true,
				),
				'commercename'   => array(
					'title'       => __( 'Shop Name', 'monei' ),
					'type'        => 'text',
					'description' => __( 'Shop Name', 'monei' ),
					'desc_tip'    => true,
				),
				'accountid'       => array(
					'title'       => __( 'Account ID', 'monei' ),
					'type'        => 'text',
					'description' => __( 'Account ID', 'monei' ),
					'desc_tip'    => true,
				),
				'apikey'       => array(
    				'title'       => __( 'API Key', 'monei' ),
    				'type'        => 'text',
    				'description' => __( 'API Key', 'monei' ),
    			),
				'password'   => array(
					'title'       => __( 'Password', 'monei' ),
					'type'        => 'text',
					'description' => __( 'MONEI Password', 'monei' ),
					'desc_tip'    => true,
				),
				'tokenization'        => array(
					'title'   => __( 'Enable/Disable', 'monei' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Tokenization', 'monei' ),
					'default' => 'no',
				),
				'orderdo'     => array(
					'title'       => __( 'What to do after payment?', 'monei' ),
					'type'        => 'select',
					'description' => __( 'Chose what to do after the customer pay the order.', 'monei' ),
					'default'     => 'processing',
					'options'     => array(
						'processing' => __( 'Mark as Processing (default & recommended)', 'monei' ),
						'completed'  => __( 'Mark as Complete', 'monei' ),
					),
				),
				'testmode'       => array(
					'title'       => __( 'Running in test mode', 'monei' ),
					'type'        => 'checkbox',
					'label'       => __( 'Running in test mode', 'monei' ),
					'default'     => 'yes',
					'description' => sprintf( __( 'Select this option for the initial testing required by MONEI, deselect this option once you pass the required test phase and your production environment is active.', 'monei' ) ),
				),
				'debug'          => array(
					'title'       => __( 'Debug Log', 'monei' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable logging', 'monei' ),
					'default'     => 'no',
					'description' => __( 'Log MONEY events, such as notifications requests, inside <code>WooCommerce > Status > Logs > Select MONEI Logs</code>', 'monei' ),
				),
			);
		}
		
		function amount_format( $total ) {
		
			if ( 0 == $total || 0.00 == $total ) {
				return 0;
			}
			
			$order_total_sign = number_format( $total, 2, '', '' );
			return $order_total_sign;
		}
		
		function test_mode() {
			if ( 'yes' === $this->testmode ) {
				$test = 'true';
			} else {
				$test = 'false';
			}
			return $test;
		}
	
		function get_monei_args( $order ) {
			global $woocommerce;

			$order_id      = $order->get_id();
			$url_challenge = get_transient( 'monei_url_challenge_' . sanitize_title( $order_id ) );
			$param_md      = get_transient( 'monei_param_md_challenge_' . sanitize_title( $order_id ) );
			$param_pareq   = get_transient( 'monei_param_pareq_challenge_' . sanitize_title( $order_id ) );
			$param_termurl = get_transient( 'monei_param_termurl_challenge_' . sanitize_title( $$order_id ) );
			
			if ( $url_challenge ) {

				$monei_args = array();

			} else {

				$currency           = get_woocommerce_currency();
				$account_id         = $this->accountid;
				$transaction_id     = str_pad( $order_id, 12, '0', STR_PAD_LEFT );
				$transaction_id1    = wp_rand( 1, 999 ); // lets to create a random number.
				$transaction_id2    = substr_replace( $transaction_id, $transaction_id1, 0, -9 ); // new order number.
				$amount             = $order->get_total();
				$country            = new WC_Countries();
				$shop_country       = $country->get_base_country();
				$shop_name          = $this->commercename;
				$url_callback       = $this->notify_url;
				$url_cancel         = html_entity_decode( $order->get_cancel_order_url() );
				$url_complete       = utf8_encode( add_query_arg( 'utm_nooverride', '1', $this->get_return_url( $order ) ) );
				$transaction_type   = 'sale';
				$password           = $this->password;
				$test               = $this->test_mode();
				
				$message = 'account_id' . $account_id . 'amount' . $amount . 'currency' . $currency . 'order_id' . $transaction_id2 . 'shop_name' . $shop_name . 'test' . $test . 'transaction_type' . $transaction_type . 'url_callback' . $url_callback . 'url_cancel' . $url_cancel . 'url_complete' . $url_complete;
				
				$sign = hash_hmac('sha256', $message, $password );
				
				if ( 'yes' === $this->debug ) {
					$this->log->add( 'monei', 'Generating payment form for order ' . $order->get_order_number() );
					$this->log->add( 'monei', 'Helping to understand the encrypted code: ' );
					$this->log->add( 'monei', 'account_id: ' . $account_id );
					$this->log->add( 'monei', 'amount: ' . $amount );
					$this->log->add( 'monei', 'currency: ' . $currency );
					$this->log->add( 'monei', 'order_id: ' . $transaction_id2 );
					$this->log->add( 'monei', 'shop_name: ' . $shop_name );
					$this->log->add( 'monei', 'test: ' . $test );
					$this->log->add( 'monei', 'url_callback: ' . $url_callback );
					$this->log->add( 'monei', 'url_cancel: ' . $url_cancel );
					$this->log->add( 'monei', 'url_complete: ' . $url_complete );
					$this->log->add( 'monei', 'Password: ' . $password );
					$this->log->add( 'monei', 'Shop country: ' . $shop_country );
					$this->log->add( 'monei', 'concatenated: ' . $message );
					$this->log->add( 'monei', 'sign: ' . $sign );
				}
				$monei_args = array(
					'account_id'       => $account_id,
					'amount'           => $amount,
					'currency'         => $currency,
					'order_id'         => $transaction_id2,
					'shop_name'        => $shop_name,
					'test'             => $test,
					'transaction_type' => $transaction_type,
					'url_callback'     => $url_callback,
					'url_cancel'       => $url_cancel,
					'url_complete'     => $url_complete,
					'signature'        => $sign,
				);
			}
			$monei_args = apply_filters( 'woocommerce_monei_args', $monei_args );
			return $monei_args;
		}

		/**
		 * Generate the monei form
		 *
		 * @access public
		 * @param mixed $order_id
		 * @return string
		 */
		function generate_monei_form( $order_id ) {
			global $woocommerce;
				
				$order       = new WC_Order( $order_id );
				$monei_args  = $this->get_monei_args( $order );
				$form_inputs = '';
				$url_challenge = get_transient( 'monei_url_challenge_' . sanitize_title( $order_id ) );
				if ( $url_challenge ) {
					$monei_adr = $url_challenge;
				} else {
					$monei_adr   = $this->liveurl . '?';
				}
				
				foreach ( $monei_args as $key => $value ) {
					$form_inputs .= '<input type="hidden" name="' . $key . '" value="' . esc_attr( $value ) . '" />';
				}
				wc_enqueue_js( '
				$("body").block({
					message: "<img src=\"' . esc_url( apply_filters( 'woocommerce_ajax_loader_url', $woocommerce->plugin_url() . '/assets/images/select2-spinner.gif' ) ) . '\" alt=\"Redirecting&hellip;\" style=\"float:left; margin-right: 10px;\" />' . __( 'Thank you for your order. We are now redirecting you to MONEI to make the payment.', 'monei' ) . '",
					overlayCSS:
					{
						background: "#fff",
						opacity: 1.0
					},
					css: {
						padding:		20,
						textAlign:		"center",
						color:			"#555",
						border:			"3px solid #aaa",
						backgroundColor:"#fff",
						cursor:			"wait",
						lineHeight:		"32px"
					}
				});
			jQuery("#submit_monei_payment_form").click();
			' );
				return '<form action="' . esc_url( $monei_adr ) . '" method="post" id="monei_payment_form" target="_top">
				' . $form_inputs . '
				<input type="submit" class="button-alt" id="submit_monei_payment_form" value="' . __( 'Pay with Credit Card via MONEI', 'monei' ) . '" /> <a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Cancel order &amp; restore cart', 'monei' ) . '</a>
			</form>';
		}

		function get_monei_users_token() {
			$customer_token = null;
			if ( is_user_logged_in() ) {
				$tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), 'monei' );
				foreach ( $tokens as $token ) {
					if ( $token->get_gateway_id() === 'monei' ) {
						$customer_token = $token->get_token();
					}
				}
			}
			return $customer_token;
		}
		
		function get_users_token_bulk( $user_id ) {
			$customer_token = null;
			$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'monei' );
			foreach ( $tokens as $token ) {
				if ( $token->get_gateway_id() === 'monei' ) {
					$customer_token = $token->get_token();
				}
			}
			return $customer_token;
		}
		
		protected function order_contains_subscription( $order_id ) {
			if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
				return false;
			} elseif ( wcs_order_contains_subscription( $order_id ) ) {
				return true;
			} elseif ( wcs_order_contains_resubscribe( $order_id ) ) {
				return true;
			} elseif ( wcs_order_contains_renewal( $order_id ) ) {
				return true;
			} else {
				return false;
			}
		}
		
		/**
		 * Process the payment and return the result
		 *
		 * @access public
		 * @param int $order_id
		 * @return array
		 */
		function process_payment( $order_id ) {

			$order            = new WC_Order( $order_id );
			$descripcion      = $this->product_description( $order );
			$transaction_type = 'sale';
			$currency         = get_woocommerce_currency();
			$order_id         = $order->get_id();
			$user_id          = $order->get_user_id();
			$currency         = get_woocommerce_currency();
			$account_id       = $this->accountid;
			$transaction_id   = str_pad( $order_id, 12, '0', STR_PAD_LEFT );
			$transaction_id1  = wp_rand( 1, 999 ); // lets to create a random number.
			$transaction_id2  = substr_replace( $transaction_id, $transaction_id1, 0, -9 ); // new order number.
			$shop_name        = $this->commercename;
			$test             = $this->test_mode();
			$url_callback     = $this->notify_url;
			$url_cancel       = html_entity_decode( $order->get_cancel_order_url() );
			$url_complete     = utf8_encode( add_query_arg( 'utm_nooverride', '1', $this->get_return_url( $order ) ) );
			$userip           = WC_Geolocation::get_ip_address();
			$useragent        = wc_get_user_agent();
			$monei_adr        = $this->charge_url;
			$amount           = $this->amount_format( $order->get_total() );
			$apikey           = $this->apikey;
			$customer_emial   = $order->billing_email;
			$token            = false;
			$token_post_id    = false;
			
			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', '$url_callback: ' . $url_callback );
				$this->log->add( 'monei', '$url_cancel: ' . $url_cancel );
				$this->log->add( 'monei', '$url_complete: ' . $url_complete );
			}
			
			if ( isset( $_POST['moneitoken'] ) ) {
				$token_post_id = sanitize_text_field( $_POST['moneitoken'] );
			}

			if ( $token_post_id && ( 'no' !== $token_post_id && 'yes' !== $token_post_id ) ) {
				$token_ob = WC_Payment_Tokens::get( $token_post_id );
				$token    = $token_ob->get_token();
			}
			
			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', '$token_post_id: ' . $token_post_id );
				$this->log->add( 'monei', '$token: ' . $token );
				//$this->log->add( 'monei', '$token_ob: ' . print_r( $token_ob, true ) );
			}
			
			if ( ( $this->order_contains_subscription( $order_id ) && ! $token ) || ( 'yes' === $this->tokenization && 'yes' === $token_post_id ) ) {
				update_post_meta( $order_id, 'get_token', 'yes' );
				$get_token = get_post_meta( $order_id, 'get_token', true );
				if ( 'yes' === $this->debug ) {
					$this->log->add( 'monei', '$get_token: ' . $get_token );
				}
				$body = array(
					'amount'              => $amount,
					'currency'            => $currency,
					'orderId'             => $transaction_id2,
					'description'         => $descripcion,
					'customer'            => array(
						'email' => $customer_emial,
					),
					'callbackUrl'          => $url_callback,
					'completeUrl'          => $url_complete,
					'cancelUrl'            => $url_cancel,
					'failUrl'              => $url_cancel,
					'generatePaymentToken' => 'true',
				);
			} elseif ( ( $this->order_contains_subscription( $order_id ) ) || ( 'yes' === $this->tokenization && $token ) ) {
				$body = array(
					'amount'       => $amount,
					'currency'     => $currency,
					'orderId'      => $transaction_id2,
					'description'  => $descripcion,
					'customer'     => array(
						'email' => $customer_emial,
					),
					'callbackUrl'  => $url_callback,
					'completeUrl'  => $url_complete,
					'cancelUrl'    => $url_cancel,
					'failUrl'      => $url_cancel,
					'paymentToken' => $token,
				);
			} else {
				update_post_meta( $order_id, 'get_token', 'no' );
				$get_token = get_post_meta( $order_id, 'get_token', true );
				if ( 'yes' === $this->debug ) {
					$this->log->add( 'monei', '$get_token: ' . $get_token );
				}
				$body = array(
					'amount'      => $amount,
					'currency'    => $currency,
					'orderId'     => $transaction_id2,
					'description' => $descripcion,
					'customer'    => array(
					'email' => $customer_emial,
					),
					'callbackUrl' => $url_callback,
					'completeUrl' => $url_complete,
					'cancelUrl'   => $url_cancel,
					'failUrl'     => $url_cancel,
				);	
			}

			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', '$body: ' . print_r( json_decode( $body ), true ) );
			}

			$data_string = json_encode( $body );
			$options     = array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => $apikey,
					),
				'body'    => $data_string,
				);
			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', print_r( $options, true ) );
			}
			$monei_adr         = 'https://api.monei.com/v1/payments';
			$response          = wp_remote_post( $monei_adr, $options );
			$response_code     = wp_remote_retrieve_response_code( $response );
			$response_body     = wp_remote_retrieve_body( $response );
			$result            = json_decode( $response_body );
			$urlchallenge      = $result->redirect_url;
			$refultmonei       = $result->result;
			$status            = $result->status;
			$authorizationCode = $result->authorizationCode;
			$id                = $result->id;

			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', '$response_body: ' . print_r( $response, true ) );
				$this->log->add( 'monei', 'URL: ' . print_r( $result, true ) );
				$this->log->add( 'monei', '/*************************/');
				$this->log->add( 'monei', '     Get URL To redirect     ');
				$this->log->add( 'monei', '/*************************/');
				$this->log->add( 'monei', '$response_body: ' . $response_body );
				$this->log->add( 'monei', 'URL: ' . $result->nextAction->redirectUrl );
				$this->log->add( 'monei', '$status: ' . $status );
				$this->log->add( 'monei', '$authorizationCode: ' . $authorizationCode );
				$this->log->add( 'monei', '$id: ' . $id );
			}

			return array(
				'result'   => 'success',
				'redirect' => $result->nextAction->redirectUrl,
			);
		}
		/**
		* Output for the order received page.
		*
		* @access public
		* @return void
		*/
		function receipt_page( $order ) {
			echo '<p>' . esc_html__( 'Thank you for your order, please click the button below to pay with Credit Card via MONEI.', 'monei' ) . '</p>';
			echo $this->generate_monei_form( $order );
		}

		/**
		* Check for Monei HTTP Notification
		*
		* @access public
		* @return void
		*/
		function check_ipn_response() {
			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', ' ' );
				$this->log->add( 'monei', '/****************************/' );
				$this->log->add( 'monei', '      check_ipn_response      ' );
				$this->log->add( 'monei', '/****************************/' );
				$this->log->add( 'monei', ' ' );
			}
			@ob_clean();
			$json   = file_get_contents( 'php://input' );
			$data   = json_decode( $json );
			$result = $data->status;
			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', $json );
				$this->log->add( 'monei', '$result: ' . $result  );
			}
			
			if ( 'SUCCEEDED' === $result ) {
				header( 'HTTP/1.1 200 OK' );
				do_action( 'valid_monei_standard_ipn_request', $data );
			} else {
				wp_die( 'MONEI Notification Request Failure' );
			}
		}
		function is_paid( $order_id ){

			$order       = wc_get_order( $order_id );
			$status      = $order->get_status();
			$status_paid = array(
				'pending',
			);
			if ( $status_paid ) {
				foreach ( $status_paid as $spaid ) {
					if ( ( string ) $status === ( string ) $spaid ) {
						if ( 'yes' === $this->debug ) {
							$this->log->add( 'monei', '$status: ' . $status );
							$this->log->add( 'monei', '$spaid: ' . $spaid );
							$this->log->add( 'monei', 'Returning false' );
						}
						return false;
					}
					continue;
				}
				if ( 'yes' === $this->debug ) {
					$this->log->add( 'monei', 'Returning true' );
				}
				return true;
			} else {
				if ( 'yes' === $this->debug ) {
					$this->log->add( 'monei', 'Returning false' );
				}
				return false;
			}
		}
		/**
		* Successful Payment!
		*
		* @access public
		* @param array $posted
		* @return void
		*/
		function successful_request( $data ) {
			global $woocommerce;

			$monei_order_id   = sanitize_text_field( $data->id );
			$order_id         = sanitize_text_field( $data->orderId );
			$message          = sanitize_text_field( $data->message );
			$order2           = substr( $order_id, 3 ); // cojo los 9 digitos del final.
			$order            = $this->get_monei_order( (int) $order2 );
			$status           = sanitize_text_field( $data->status );
			$amount           = floatval( $data->amount ) / 100;
			$json             = file_get_contents( 'php://input' );
			$data             = json_decode( $json );
			
			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', '$monei_order_id: ' . $monei_order_id );
				$this->log->add( 'monei', '$order_id: ' . $order_id );
				$this->log->add( 'monei', '$status: ' . $status );
				$this->log->add( 'monei', '$message: ' . $message );
			}

			if ( 'SUCCEEDED' === $status ) {
				// authorized.
				$order2    = substr( $order_id, 3 ); //cojo los 9 digitos del final
				$order     = new WC_Order( $order2 );
				$amountwoo = floatval($order->get_total());

				if ( $amountwoo !== $amount ) {
					// amount does not match.
					if ( 'yes' === $this->debug ) {
						$this->log->add( 'monei', 'Payment error: Amounts do not match (order: ' . $amountwoo . ' - received: ' . $amount . ')' );
					}
					// Put this order on-hold for manual checking.
					/* translators: order an received are the amount */
					$order->update_status( 'on-hold', sprintf( __( 'Validation error: Order vs. Notification amounts do not match (order: %1$s - received: %2&s).', 'monei' ), $amountwoo, $amount ) );
					exit;
				}

				if ( ! empty( $monei_order_id ) ) {
					update_post_meta( $order->get_id(), '_payment_order_number_monei', $monei_order_id );
				}

				if ( ! empty( $order_id ) ) {
					update_post_meta( $order->get_id(), '_payment_wc_order_id_monei', $order_id );
				}

				// Payment completed.
				$order->add_order_note( __( 'HTTP Notification received - payment completed', 'monei' ) );
				$order->add_order_note( __( 'MONEI Order Number: ', 'monei' ) . $monei_order_id );
				$is_paid = $this->is_paid( $order2 );
				if ( $is_paid ) {
					return;
				}
				$order->payment_complete();
				if ( 'completed' === $this->orderdo ) {
					$order->update_status( 'completed', __( 'Order Completed by MONEI', 'monei' ) );
				}
				
				$get_token = get_post_meta( $order->get_id(), 'get_token', true );
				
				if ( 'yes' === $this->debug ) {
					$this->log->add( 'monei', '$get_token: ' . $get_token );
				}
				
				if ( 'yes' === $get_token ) {
					$monei        = new Monei\MoneiClient( $this->apikey );
					$data_payment = $monei->payments->get( $monei_order_id );
					
					$data_array   = json_decode( $data_payment );
					
					if ( isset( $data_array->paymentToken ) ) {
						if ( 'yes' === $this->debug ) {
							$this->log->add( 'monei', '$token: ' . $data_array->paymentToken );
							$this->log->add( 'monei', '$brand: ' .$data->paymentMethod->card->brand );
							$this->log->add( 'monei', '$lastfour: ' . $data->paymentMethod->card->last4 );
						}
						$token_n  = $data_array->paymentToken;
						$brand    = $data->paymentMethod->card->brand;
						$lastfour = $data->paymentMethod->card->last4;
						$token    = new WC_Payment_Token_CC();
						$token->set_token( $token_n );
						$token->set_gateway_id( 'monei' );
						$token->set_user_id( $order->get_user_id() );
						$token->set_card_type( $brand );
						$token->set_last4( $lastfour );
						$token->set_expiry_month( '12' );
						$token->set_expiry_year( '2040' );
						$token->set_default( true );
						$token->save();
					}
				}

				if ( 'yes' === $this->debug ) {
					$this->log->add( 'monei', '$data_payment: ' . $data_payment );
				}

				if ( 'yes' === $this->debug ) {
					$this->log->add( 'monei', 'Payment complete.' );
				}
			} else {
				// Tarjeta caducada.
				if ( 'yes' === $this->debug ) {
					$this->log->add( 'monei', 'Order cancelled by MONEI: ' . $message );
				}
				// Order cancelled.
				$order->update_status( 'cancelled', 'Cancelled by MONEI: ' . $message);
				$order->add_order_note( 'Order cancelled by MONEI: ' . $message );
				WC()->cart->empty_cart();
			}
		}
		
		/**
		 * get_monei_order function.
		 *
		 * @access public
		 * @param mixed $order_id
		 * @return void
		 */
		function get_monei_order( $order_id ) {
			$order = new WC_Order( $order_id );
			return $order;
		}
		
		function payment_fields() {

			if ( is_user_logged_in() && 'yes' === $this->tokenization ) {
				$user_id = get_current_user_id();
				$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $this->id );
				if ( ! empty( $tokens ) ) {
					echo '<h4>Select a Credit Card</h4>';
					echo '<div class="credit-cards-monei">';
					foreach ( $tokens as $token ) {
						$is_default = $token->is_default();
						if ( $is_default ) {
							$checked = 'checked="checked"';
						} else {
							$checked = '';
						}
						echo '<div class="moneicreditcards">';
							echo '<input id="' . $token->get_id() . '" name="moneitoken" type="radio" ' . $checked . ' value="' . $token->get_id() . '"/>';
							echo '<label for="' . $token->get_id() . '">' . $token->get_card_type() . ' ended in ' . $token->get_last4() . ' ' . $token->get_expiry_month() . '/' . $token->get_expiry_year() . '</label>';
						echo '</div>';
						continue;
					}
						echo '<div class="moneicreditcards">';
							echo '<input id="yes" name="moneitoken" type="radio" value="yes"/>';
							echo '<label for="yes">Add new Credit Card</label>';
						echo '</div>';
						echo '<div class="moneicreditcards">';
							echo '<input id="no" name="moneitoken" type="radio" value="no"/>';
							echo '<label for="no">Do not use any Credit Card</label>';
						echo '</div>';
					echo '</div>';
				} else {
					echo '<div class="credit-cards-monei">
							<h4>Do we save your credit card?</h4>
							<p>We won\'t keep your card, we\'ll keep a token that MONEI will provide. It\'s totally safe.</p>
							<div class="moneicreditcards">
							<input id="yes" name="moneitoken" type="radio" value="yes"/>
							<label for="yes">Yes</label>
						</div>
						<div class="moneicreditcards">
							<input id="no" name="moneitoken" type="radio" value="no"/>
							<label for="no">No</label>
						</div>
					</div>';
				}
			} else {
				echo $this->description;
			}
		}
		/**
		 * Copyright: (C) 2013 - 2021 José Conti
		 */
		public function doing_scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {

			$order_id    = $renewal_order->get_id();
			$order       = $renewal_order;
			$amount      = $amount_to_charge;
			$user_id     = $order->get_user_id();
			$descripcion = $this->product_description( $order );

			$transaction_type = 'sale';
			$currency         = get_woocommerce_currency();
			$currency         = get_woocommerce_currency();
			$account_id       = $this->accountid;
			$transaction_id   = str_pad( $order_id, 12, '0', STR_PAD_LEFT );
			$transaction_id1  = wp_rand( 1, 999 ); // lets to create a random number.
			$transaction_id2  = substr_replace( $transaction_id, $transaction_id1, 0, -9 ); // new order number.
			$shop_name        = $this->commercename;
			$test             = $this->test_mode();
			$url_callback     = $this->notify_url;
			$url_cancel       = html_entity_decode( $order->get_cancel_order_url() );
			$url_complete     = utf8_encode( add_query_arg( 'utm_nooverride', '1', $this->get_return_url( $order ) ) );
			$monei_adr        = $this->charge_url;
			$amount           = $this->amount_format( $order->get_total() );
			$apikey           = $this->apikey;
			$customer_emial   = $order->billing_email;
			$token            = false;
			$token_post_id    = false;
			
			$token =  $this->get_users_token_bulk( $user_id );
			
			$body             = array(
				'amount'       => $amount,
				'currency'     => $currency,
				'orderId'      => $transaction_id2,
				'description'  => $descripcion,
				'customer'     => array(
					'email' => $customer_emial,
				),
				'callbackUrl'  => $url_callback,
				'completeUrl'  => $url_complete,
				'cancelUrl'    => $url_cancel,
				'paymentToken' => $token,
			);
		
			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', '$body: ' . print_r( json_decode( $body ), true ) );
			}

			$data_string = json_encode( $body );
			$options     = array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => $apikey,
					),
				'body'    => $data_string,
			);
			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', print_r( $options, true ) );
			}
			$monei_adr         = 'https://api.monei.com/v1/payments';
			$response          = wp_remote_post( $monei_adr, $options );
			$response_code     = wp_remote_retrieve_response_code( $response );
			$response_body     = wp_remote_retrieve_body( $response );
			$result            = json_decode( $response_body );
			$urlchallenge      = $result->redirect_url;
			$refultmonei       = $result->result;
			$status            = $result->status;
			$authorizationCode = $result->authorizationCode;
			$id                = $result->id;

			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', '$response_body: ' . print_r( $response, true ) );
				$this->log->add( 'monei', 'URL: ' . print_r( $result, true ) );
				$this->log->add( 'monei', '/*************************/');
				$this->log->add( 'monei', '     Get URL To redirect     ');
				$this->log->add( 'monei', '/*************************/');
				$this->log->add( 'monei', '$response_body: ' . $response_body );
				$this->log->add( 'monei', 'URL: ' . $result->nextAction->redirectUrl );
				$this->log->add( 'monei', '$status: ' . $status );
				$this->log->add( 'monei', '$authorizationCode: ' . $authorizationCode );
				$this->log->add( 'monei', '$id: ' . $id );
			}
		}
		/**
		 * Refund
		 */
		
		function ask_for_refund( $order_id, $transaction_id, $amount ) {

			//post code to MONEI
			$order2             = get_post_meta( $order_id, '_payment_wc_order_id_monei', true );
			$monei_order_number = $transaction_id;
			$currency_codes     = get_woocommerce_currency();
			$account_id         = $this->accountid;
			$test               = $this->test_mode();
			$transaction_type   = 'refund';
			$shop_name          = $this->commercename;
			$password           = $this->password;
			$country            = new WC_Countries();
			$shop_country       = $country->get_base_country();
			$monei_adr          = $this->refund_url;
			$api_apssword       = $this->apikey;
			
			$amount = $this->amount_format( $amount );
			
			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', ' ' );
				$this->log->add( 'monei', '$api_apssword ' . $api_apssword );
				$this->log->add( 'monei', '$monei_order_number ' . $monei_order_number );
				$this->log->add( 'monei', '$amount: ' . $amount );
			}
			
			$monei   = new Monei\MoneiClient( $api_apssword );
			$message = $monei->payments->refund(
			  $monei_order_number,
			  ['amount' => (int)$amount,
			  'refundReason' => 'requested_by_customer']
			);
			$json   = json_decode( $message, true );
			$status = $json['status'];
			
			//$sign = hash_hmac('sha256', $message, $password );
			
			if ( 'yes' === $this->debug ) {
				$this->log->add( 'monei', ' ' );
				$this->log->add( 'monei', '$message ' . $message );
				$this->log->add( 'monei', '$status ' . $status );
				$this->log->add( 'monei', __( 'Order Number MONEI : ', 'monei' ) . $monei_order_number );
			}

			if ( 'REFUNDED' === $status ||  'PARTIALLY_REFUNDED' === $status ) {
				return true;
			} else {
				return $status;
			}
		}
		
		public function process_refund( $order_id, $amount = null, $reason = '' ) {
			
			// Do your refund here. Refund $amount for the order with ID $order_id _transaction_id
			set_time_limit( 0 );
			$order              = $this->get_monei_order( $order_id );
			$order2             = get_post_meta( $order_id, '_payment_wc_order_id_monei', true );
			$monei_order_number = get_post_meta( $order_id, '_payment_order_number_monei', true );
			
			
			if ( ! $amount ) {
				$order_total_sign  = $order->get_total();
			} else {
				$order_total_sign = $amount;
			}
	
			if ( ! empty( $order2 ) ) {
				if ( 'yes' === $this->debug ) {
					$this->log->add( 'monei', ' ' );
					$this->log->add( 'monei', '/****************************/' );
					$this->log->add( 'monei', '       Once upon a time       ' );
					$this->log->add( 'monei', '/****************************/' );
					$this->log->add( 'monei', ' ' );
					$this->log->add( 'monei', __( 'check_monei_refund Asking for order #: ', 'monei' ) . $order_id );
				}
	
				$refund_asked = $this->ask_for_refund( $order_id, $monei_order_number, $order_total_sign );
				
				if ( $refund_asked ) {
					if ( 'yes' === $this->debug && $result ) {
						$this->log->add( 'monei', __( 'check_monei_refund = true ', 'monei' ) );
						$this->log->add( 'monei', ' ' );
						$this->log->add( 'monei', '/********************************/' );
						$this->log->add( 'monei', '  Refund complete by MONEI   ' );
						$this->log->add( 'monei', '/********************************/' );
						$this->log->add( 'monei', ' ' );
						$this->log->add( 'monei', '/******************************************/' );
						$this->log->add( 'monei', '  The final has come, this story has ended  ' );
						$this->log->add( 'monei', '/******************************************/' );
						$this->log->add( 'monei', ' ' );
					}
					return true;
				} else {
					if ( is_wp_error( $refund_asked ) ) {
						if ( 'yes' === $this->debug ) {
							$this->log->add( 'monei', __( 'Refund Failed: ', 'monei' ) . $refund_asked->get_error_message() );
						}
						return new WP_Error( 'error', $refund_asked->get_error_message() );
					}
				}
	
				if ( is_wp_error( $refund_asked ) ) {
					if ( 'yes' === $this->debug ) {
						$this->log->add( 'monei', __( 'Refund Failed: ', 'monei' ) . $refund_asked->get_error_message() );
					}
					return new WP_Error( 'error', $refund_asked->get_error_message() );
				}
			} else {
				if ( 'yes' === $this->debug && $result ) {
					$this->log->add( 'monei', __( 'Refund Failed: No transaction ID', 'monei' ) );
				}
				return new WP_Error( 'monei', __( 'Refund Failed: No transaction ID', 'monei' ) );
			}
		}
	}

	function monei_add_notice_new_version() {

		$version = get_option( 'hide-new-version-monei-notice' );

		if ( ! $version ) {
			if ( isset( $_REQUEST['monei-hide-new-version'] ) &&  'hide-new-version-monei' === $_REQUEST['monei-hide-new-version'] ) {
				$nonce = sanitize_text_field( $_REQUEST['_monei_hide_new_version_nonce'] );
				if ( wp_verify_nonce( $nonce, 'monei_hide_new_version_nonce' ) ) {
					update_option( 'hide-new-version-monei-notice', MONEI_VERSION );
				}
			} else {
				?>
				<div id="message" class="updated woocommerce-message woocommerce-monei-messages">
					<div class="contenido-monei-notice">
						<a class="woocommerce-message-close notice-dismiss" style="top:0;" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'monei-hide-new-version', 'hide-new-version-monei' ), 'monei_hide_new_version_nonce', '_monei_hide_new_version_nonce' ) ); ?>"><?php esc_html_e( 'Dismiss', 'monei' ); ?></a>
						<p>
							<h3>
								<?php esc_html_e( 'Thank you for install MONEI for WooCommerce. Version: ', 'monei' ) . ' ' . esc_html_e( MONEI_VERSION ); ?>
							</h3>
						</p>
						<p>
							<?php esc_html_e( 'The best payment gateway rates. The perfect solution to manage your digital payments.,', 'monei' ); ?>
						</p>
						<p class="submit">
							<a href="<?php esc_html_e( MONEI_SIGNUP ); ?>" class="button-primary" target="_blank"><?php esc_html_e( 'Signup', 'monei' );  ?></a>
							<a href="<?php esc_html_e( MONEI_WEB ); ?>" class="button-primary" target="_blank"><?php esc_html_e( 'MONEI website', 'monei' );  ?></a>
							<a href="<?php esc_html_e( MONEI_REVIEW ); ?>" class="button-primary" target="_blank"><?php esc_html_e( 'Leave a review', 'monei' );  ?></a>
							<a href="<?php esc_html_e( MONEI_SUPPORT ); ?>" class="button-primary" target="_blank"><?php esc_html_e( 'Support', 'monei' );  ?></a>
						</p>
					</div>
				</div>
			<?php }
		}
	}
	add_action( 'admin_notices', 'monei_add_notice_new_version' );

	function monei_notice_style() {
		wp_register_style( 'monei_notice_css', MONEI_PLUGIN_URL . 'assets/css/monei-notice.css', false, MONEI_VERSION );
		wp_enqueue_style( 'monei_notice_css' );
	}
	add_action( 'admin_enqueue_scripts', 'monei_notice_style' );
	
	function monei_style_checkout() {
		wp_register_style( 'monei_checkput_css', MONEI_PLUGIN_URL . 'assets/css/monei-checkout-card.css', false, MONEI_VERSION );
		wp_enqueue_style( 'monei_checkput_css' );
	}
	//add_action( 'wp_enqueue_scripts', 'monei_style_checkout' );

	function woocommerce_add_gateway_monei_gateway( $methods ) {
		$methods[] = 'WC_Gateway_monei';
		return $methods;
	}
	add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_gateway_monei_gateway' );

	function add_monei_meta_box() {
		$date_decoded = get_post_meta( get_the_ID(), '_payment_date_monei', true );
		$hour_decoded = get_post_meta( get_the_ID(), '_payment_hour_monei', true );
		echo '<h4>' . esc_html__( 'Payment Details', 'monei' ) . '</h4>';
		echo '<p><strong>' . esc_html__( 'MONEI Date', 'monei' ) . ': </strong><br />' . esc_html( $date_decoded ) . '</p>';
		echo '<p><strong>' . esc_html__( 'MONEI Hour', 'monei' ) . ': </strong><br />' . esc_html( $hour_decoded ) . '</p>';
		echo '<p><strong>' . esc_html__( 'MONEI Order Number', 'monei' ) . ': </strong><br />' . esc_attr( get_post_meta( get_the_ID(), '_payment_order_number_monei', true ) ) . '</p>';
	}
	add_action( 'woocommerce_admin_order_data_after_billing_address', 'add_monei_meta_box' );
}
