<?php
/**
 * Plugin Name: RinggitPay for WooCommerce
 * Plugin URI: https://ringgitpay.biz
 * Description: Accept payments via FPX, Credit Card
 * Author: RinggitPay
 * Author URI: https://ringgitpay.biz
 * Version: 1.0.3
 *
 * @package RinggitPay
 */

add_filter( 'woocommerce_payment_gateways', 'ringgitpay_add_gateway_class' );
function ringgitpay_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_RinggitPay_Gateway';
	return $gateways;
}

add_action( 'plugins_loaded', 'ringgitpay_init_gateway_class' );
function ringgitpay_init_gateway_class() {
	class WC_RinggitPay_Gateway extends WC_Payment_Gateway {

		public function __construct() {
			try {
				$this->id                 = 'ringgitpay';
				$this->icon               = plugins_url( 'assets/rp-logo.png', __FILE__ );
				$this->has_fields         = true;
				$this->method_title       = 'RinggitPay for WooCommerce';
				$this->method_description = 'Redirects customers to RinggitPay Payment Gateway to enter their payment information and make payment.'; // will be displayed on the options page.
				$this->init_form_fields(); // All the options fields.
				// Load the settings.
				$this->init_settings();
				$this->title           = $this->get_option( 'title' );
				$this->description     = $this->get_option( 'description' );
				$this->enabled         = $this->get_option( 'enabled' );
				$this->appid           = $this->get_option( 'appid' );
				$this->requestkey      = $this->get_option( 'requestkey' );
				$this->responsekey     = $this->get_option( 'responsekey' );
				$this->uatorproduction = $this->get_option( 'uatorproduction' );
				$this->defaultPaymentStatus = $this->get_option( 'defaultpaymentstatus' );

				add_action(
					'woocommerce_receipt_' . $this->id,
					array(
						$this,
						'pay_for_order',
					)
				);
				add_action(
					'woocommerce_api_rpresponse',
					array(
						$this,
						'webhook',
					)
				);
				// This action hook saves the settings.
				add_action(
					'woocommerce_update_options_payment_gateways_' . $this->id,
					array(
						$this,
						'process_admin_options',
					)
				);

			} catch ( Exception $exe ) {
				throw new Exception( 'Something went wrong while setting payment gateway.' );
			}
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'         => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable RinggitPay',
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no',
				),
				'title'           => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'text'        => 'RinggitPay',
					'required'    => true,
					'desc_tip'    => true,
				),
				'description'     => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Pay securely with RinggitPay.',
					'css'         => 'max-width:400px;',
					'required'    => true,
				),
				'appId'           => array(
					'title'    => 'AppId',
					'type'     => 'text',
					'required' => true,
				),
				'requestkey'      => array(
					'title'    => 'RequestKey',
					'type'     => 'text',
					'required' => true,
				),
				'responsekey'     => array(
					'title'    => 'ResponseKey',
					'type'     => 'text',
					'required' => true,
				),
				'uatorproduction' => array(
					'title'   => 'UAT/Production',
					'type'    => 'select',
					'options' => array(
						'uat'        => __( 'UAT' ),
						'production' => __( 'Production' ),
					),
				),
                'defaultpaymentstatus' => array(
                    'title'   => 'Default order status on success payment',
                    'type'    => 'select',
                    'options' => array(
                        'processing'        => __( 'Processing' ),
                        'completed' => __( 'Completed' )
                    ),
                    'default' => 'completed',
                ),
			);
		}

		public function process_payment( $order_id ) {
			$order = new WC_Order( $order_id );
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			);

		}

		public function pay_for_order( $order_id ) {
			try {
				$order = new WC_Order( $order_id );
				// Here i take some data and put it inside $a.
				$currency        = $order->get_currency();
				$amount          = $order->get_total();
				$app_id          = $this->get_option( 'appId' );
				$request_key     = $this->get_option( 'requestkey' );
				$response_key    = $this->get_option( 'responsekey' );
				$uatorproduction = $this->get_option( 'uatorproduction' );
				$defaultPaymentStatus = $this->get_option( 'defaultpaymentstatus' );
				if ( $uatorproduction === 'uat' ) {
					$payment_url = 'https://ringgitpay.co/payment';
				}
				if ( $uatorproduction === 'production' ) {
					$payment_url = 'https://ringgitpay.com/payment';
				}
				$check_sum = hash( 'sha256', "{$app_id}|{$currency}|{$amount}|{$order_id}|{$request_key}" );
				if ( ! empty( $app_id ) && ! empty( $currency ) && ! empty( $amount ) && ! empty( $order_id ) && ! empty( $request_key ) && ! empty( $response_key ) && ! empty( $check_sum ) ) {
					echo '<p>' . __( 'Redirecting to payment provider.', 'ringgitpay' ) . '</p>';
					$order->add_order_note( __( 'Order placed and user redirected.', 'ringgitpay' ) );
					$order->update_status( 'On hold', __( 'Awaiting payment.', 'ringgitpay' ) );

					echo '<form id="paymentForm" name="paymentForm" action=' . esc_url( $payment_url ) . ' method="POST" target="_top">
                    <input type="hidden" name="appId" value=' . esc_attr( $app_id ) . '>
                    <input type="hidden" name="currency" value=' . esc_attr( $currency ) . '>
                    <input type="hidden" name="amount" value=' . esc_attr( $amount ) . '>
                    <input type="hidden" name="orderId" value=' . esc_attr( $order_id ) . '>
                    <input type="hidden" name="checkSum" value=' . esc_attr( $check_sum ) . '>
                </form>
                <script type="text/JavaScript">
                  document.paymentForm.submit();
                </script>';
					WC()->cart->empty_cart();
				} else {
					if ( empty( $app_id ) ) {
						wc_add_notice( 'App Id is required.', 'error' );
					}
					if ( empty( $request_key ) ) {
						wc_add_notice( 'Request key is required.', 'error' );
					}
					if ( empty( $response_key ) ) {
						wc_add_notice( 'Response key is required.', 'error' );
					}
					if ( empty( $currency ) ) {
						wc_add_notice( 'Currency is required.', 'error' );
					}
					if ( empty( $amount ) ) {
						wc_add_notice( 'Amount is required.', 'error' );
					}
					if ( empty( $order_id ) ) {
						wc_add_notice( 'Order Id is required.', 'error' );
					}
					if ( empty( $check_sum ) ) {
						wc_add_notice( 'CheckSum is required.', 'error' );
					}

					wp_safe_redirect( wc_get_checkout_url() );
					exit;

				}
			} catch ( Exception $exe ) {
				throw new Exception( 'Something went wrong while processing payment.' );
			}

		}

		public function webhook() {
			header( 'HTTP/1.1 200 OK' );

			if ( isset( $_REQUEST['rp_orderId'] ) ) {
				$order_id = sanitize_text_field( wp_unslash( $_REQUEST['rp_orderId'] ) );
			}
			if ( isset( $_REQUEST['rp_statusCode'] ) ) {
				$rp_status_code = sanitize_text_field( wp_unslash( $_REQUEST['rp_statusCode'] ) );
			}
			$response_key = $this->get_option( 'responsekey' );
			if ( isset( $_REQUEST['rp_amount'] ) ) {
				$rp_amount = sanitize_text_field( wp_unslash( $_REQUEST['rp_amount'] ) );
			}
			if ( isset( $_REQUEST['rp_currency'] ) ) {
				$rp_currency = sanitize_text_field( wp_unslash( $_REQUEST['rp_currency'] ) );
			}
			if ( isset( $_REQUEST['rp_appId'] ) ) {
				$rp_app_id = sanitize_text_field( wp_unslash( $_REQUEST['rp_appId'] ) );
			}
			if ( isset( $_REQUEST['rp_transactionRef'] ) ) {
				$rp_transaction_ref = sanitize_text_field( wp_unslash( $_REQUEST['rp_transactionRef'] ) );
			}
			if ( isset( $_REQUEST['rp_txnTime'] ) ) {
				$rp_txn_time = sanitize_text_field( wp_unslash( $_REQUEST['rp_txnTime'] ) );
			}
			if ( isset( $_REQUEST['rp_paymentMode'] ) ) {
				$rp_payment_mode = sanitize_text_field( wp_unslash( $_REQUEST['rp_paymentMode'] ) );
			}
			if ( isset( $_REQUEST['rp_checkSum'] ) ) {
				$rp_check_sum = sanitize_text_field( wp_unslash( $_REQUEST['rp_checkSum'] ) );
			}

            if ( isset( $_REQUEST['rp_remarks'] ) ) {
                $rp_remarks = sanitize_text_field( wp_unslash( $_REQUEST['rp_remarks'] ) );
            }

			$rp_check_sum_hash = hash( 'sha256', "{$rp_app_id}|{$rp_currency}|{$rp_amount}|{$rp_status_code}|{$order_id}|{$rp_transaction_ref}|{$response_key}" );
			$order             = new WC_Order( $order_id );
            $note              = 'RinggitPay - ' . $rp_status_code . '-' . $rp_remarks;

			if ( strtolower( $rp_check_sum ) === $rp_check_sum_hash ) {
				if ( empty( $rp_status_code ) ) {
					throw new Exception( __( 'We are currently experiencing problems trying to connect to this payment gateway. Sorry for the inconvenience.', 'spyr-authorizenet-aim' ) );
				} else {
					if ( $rp_status_code === 'RP00' ) {
                        //$order = wc_get_order( $order_id );
                        wc_reduce_stock_levels( $order );
                        //$order->payment_complete();

                        $defaultPaymentStatus = $this->get_option( 'defaultpaymentstatus' );
                        $successStatus  = "completed";
                        if(!empty($defaultPaymentStatus) && $defaultPaymentStatus != '') { $successStatus = $defaultPaymentStatus;  }
						$order->update_status( $successStatus, __( 'Payment Successful', 'ringgitpay' ) );
						$order->add_order_note( __( $note , 'ringgitpay' ) );
						wp_safe_redirect( $this->get_return_url( $order ) );
						exit;
					} else if( $rp_status_code === 'RP09' ) {
                        $order->update_status( 'on hold', __( 'Awaiting payment', 'ringgitpay' ) );
                        $order->add_order_note( __( $note , 'ringgitpay' ) );
                        wp_safe_redirect($order->get_checkout_order_received_url());
                        exit;
                    } else {
						$order->update_status( 'failed', __( 'Transaction failed', 'ringgitpay' ) );
                        $order->add_order_note( __( $note , 'ringgitpay' ) );
                        wp_safe_redirect($order->get_checkout_order_received_url());
                        exit;
					}
				}
			} else {
					$order->update_status( 'failed', __( 'Payment has been cancelled.', 'ringgitpay' ) );
                    $order->add_order_note( __( $note , 'ringgitpay' ) );
                    wp_safe_redirect($order->get_checkout_order_received_url());
                    exit;
			}
		}
	}

}

