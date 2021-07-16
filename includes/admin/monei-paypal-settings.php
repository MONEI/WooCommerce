<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Money Paypal Gateway Settings.
 */
return apply_filters(
	'wc_monei_paypal_settings',
	array(
		'enabled'        => array(
			'title'   => __( 'Enable/Disable', 'monei' ),
			'type'    => 'checkbox',
			'label'   => __( 'Enable PayPal by MONEI', 'monei' ),
			'default' => 'no',
		),
		'title'          => array(
			'title'       => __( 'Title', 'monei' ),
			'type'        => 'text',
			'description' => __( 'This controls the title which the user sees during checkout.', 'monei' ),
			'default'     => __( 'PayPal (MONEI)', 'monei' ),
			'desc_tip'    => true,
		),
		'description'    => array(
			'title'       => __( 'Description', 'monei' ),
			'type'        => 'textarea',
			'description' => __( 'This controls the description which the user sees during checkout.', 'monei' ),
			'default'     => __( 'Pay via PayPal; you will be redirected to MONEI to pay.', 'monei' ),
		),
		'hide_logo'        => array(
			'title'   => __( 'Hide Logo', 'monei' ),
			'type'    => 'checkbox',
			'label'   => __( 'Hide Logo in checkout', 'monei' ),
			'default' => 'no',
			'description' => __( 'Hide Logo in checkout.', 'monei' ),
			'desc_tip'    => true,
		),
		'apikey'       => array(
			'title'       => __( 'API Key', 'monei' ),
			'type'        => 'text',
			'description' => __( 'Account ID and API key in the test mode are different from the live (production) mode and can only be used for testing purposes. Lean more" and a link to https://docs.monei.com/docs/testing/', 'monei' ),
			'desc_tip'    => true,
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
		'debug'          => array(
			'title'       => __( 'Debug Log', 'monei' ),
			'type'        => 'checkbox',
			'label'       => __( 'Enable logging', 'monei' ),
			'default'     => 'no',
			'description' => __( 'Log MONEY events, such as notifications requests, inside <code>WooCommerce > Status > Logs > Select MONEI Logs</code>', 'monei' ),
		),
	)
);

