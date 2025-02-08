<?php
/**
 * Plugin Name: Separate bKash, Rocket, Nagad Payment Gateways
 * Description: Separate payment gateways for bKash, Rocket, and Nagad for WooCommerce.
 * Version: 1.0
 * Author: Yasir Arafat
 * Author URI: https://proarafat.github.io/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: separate-bkash-rocket-nagad-payment-gateways
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.5
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

add_action('plugins_loaded', 'init_separate_payment_gateways');

function init_separate_payment_gateways() {
    if (!class_exists('WC_Payment_Gateway')) return;

    // Common base class for shared functionality
    abstract class WC_Manual_Payment_Gateway extends WC_Payment_Gateway {
        protected $account_number;
        
        public function payment_fields() {
            if ($this->description) {
                $cart_contains_mystery_box = false;
                foreach (WC()->cart->get_cart() as $cart_item) {
                    if (has_term('mystery-box', 'product_cat', $cart_item['product_id'])) {
                        $cart_contains_mystery_box = true;
                        break;
                    }
                }

                if ($cart_contains_mystery_box) {
                    echo wpautop(esc_html__('মিস্ট্রি-বক্স অর্ডার করার জন্য সম্পূর্ণ টাকা অগ্রিম পেমেন্ট করুন।', 'separate-bkash-rocket-nagad-payment-gateways'));
                } else {
                    echo wpautop(wp_kses_post($this->description));
                }
            }
            
            $this->show_payment_instructions();
        }

        abstract protected function show_payment_instructions();

        public function validate_fields() {
            if (!isset($_POST['payment_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['payment_nonce'])), 'process_payment')) {
                wc_add_notice(esc_html__('Payment verification failed. Please try again.', 'separate-bkash-rocket-nagad-payment-gateways'), 'error');
                return false;
            }

            if (empty($_POST['payment_trx_id']) || empty($_POST['payment_account_number'])) {
                wc_add_notice(esc_html__('Please enter your account number and transaction ID.', 'separate-bkash-rocket-nagad-payment-gateways'), 'error');
                return false;
            }
            return true;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $trx_id = sanitize_text_field($_POST['payment_trx_id']);
            $account_number = sanitize_text_field($_POST['payment_account_number']);

            $order->update_meta_data('_payment_trx_id', $trx_id);
            $order->update_meta_data('_payment_account_number', $account_number);
            $order->update_meta_data('_payment_method', $this->method_title);
            $order->update_status('on-hold', esc_html__('Awaiting payment confirmation.', 'separate-bkash-rocket-nagad-payment-gateways'));
            
            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        public function display_payment_details_in_admin($order) {
            $trx_id = $order->get_meta('_payment_trx_id');
            $account_number = $order->get_meta('_payment_account_number');
            $payment_method = $order->get_meta('_payment_method');

            if ($trx_id && $account_number && $payment_method) {
                echo '<p><strong>' . esc_html__('Payment Method:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html($payment_method) . '</p>';
                echo '<p><strong>' . esc_html__('Account Number:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html($account_number) . '</p>';
                echo '<p><strong>' . esc_html__('Transaction ID:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html($trx_id) . '</p>';
            }
        }
    }

    // bKash Payment Gateway
    class WC_Gateway_bKash extends WC_Manual_Payment_Gateway {
        public function __construct() {
            $this->id = 'bkash';
            $this->method_title = 'bKash Payment';
            $this->method_description = 'Accept payments via bKash';
            $this->has_fields = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->bkash_personal_number = $this->get_option('bkash_personal_number');
            $this->bkash_merchant_number = $this->get_option('bkash_merchant_number');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_payment_details_in_admin'), 10, 1);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable bKash Payment',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'Payment method title',
                    'default' => 'bKash Payment',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'Payment method description',
                    'default' => 'বিকাশের সেন্ডমানি/পেমেন্ট থেকে সর্বনিম্ন ৮৫ টাকা অথবা সম্পূর্ণ টাকা পাঠাতে পারেন',
                ),
                'bkash_personal_number' => array(
                    'title' => 'Personal Number',
                    'type' => 'text',
                    'default' => '01810571737',
                ),
                'bkash_merchant_number' => array(
                    'title' => 'Merchant Number',
                    'type' => 'text',
                    'default' => '01758300737',
                )
            );
        }

        protected function show_payment_instructions() {
            wp_nonce_field('process_payment', 'payment_nonce');
            echo '<p><strong>' . esc_html__('Account Type:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html__('Personal (Send Money)', 'separate-bkash-rocket-nagad-payment-gateways') . '<br>
                <strong>' . esc_html__('bKash Account Number:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html($this->bkash_personal_number) . '</p>
                <p><strong>' . esc_html__('Account Type:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html__('Merchant (Payment)', 'separate-bkash-rocket-nagad-payment-gateways') . '<br>
                <strong>' . esc_html__('bKash Account Number:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html($this->bkash_merchant_number) . '</p>
                <p>
                    <label for="payment_account_number">' . esc_html__('Your bKash Number', 'separate-bkash-rocket-nagad-payment-gateways') . ' <span class="required">*</span></label>
                    <input type="text" id="payment_account_number" name="payment_account_number" class="input-text" placeholder="01XXXXXXXXX" required />
                </p>
                <p>
                    <label for="payment_trx_id">' . esc_html__('Transaction ID', 'separate-bkash-rocket-nagad-payment-gateways') . ' <span class="required">*</span></label>
                    <input type="text" id="payment_trx_id" name="payment_trx_id" class="input-text" placeholder="' . esc_attr__('Enter transaction ID', 'separate-bkash-rocket-nagad-payment-gateways') . '" required />
                </p>';
        }
    }

    // Rocket Payment Gateway
    class WC_Gateway_Rocket extends WC_Manual_Payment_Gateway {
        public function __construct() {
            $this->id = 'rocket';
            $this->method_title = 'Rocket Payment';
            $this->method_description = 'Accept payments via Rocket';
            $this->has_fields = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->rocket_number = $this->get_option('rocket_number');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_payment_details_in_admin'), 10, 1);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Rocket Payment',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'Payment method title',
                    'default' => 'Rocket Payment',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'Payment method description',
                    'default' => 'রকেট থেকে সর্বনিম্ন ৮৫ টাকা অথবা সম্পূর্ণ টাকা পাঠাতে পারেন',
                ),
                'rocket_number' => array(
                    'title' => 'Rocket Number',
                    'type' => 'text',
                    'default' => '01785373233',
                )
            );
        }

        protected function show_payment_instructions() {
            wp_nonce_field('process_payment', 'payment_nonce');
            echo '<p><strong>' . esc_html__('Account Type:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html__('Personal (Send Money)', 'separate-bkash-rocket-nagad-payment-gateways') . '<br>
                <strong>' . esc_html__('Rocket Account Number:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html($this->rocket_number) . '</p>
                <p>
                    <label for="payment_account_number">' . esc_html__('Your Rocket Number', 'separate-bkash-rocket-nagad-payment-gateways') . ' <span class="required">*</span></label>
                    <input type="text" id="payment_account_number" name="payment_account_number" class="input-text" placeholder="01XXXXXXXXX" required />
                </p>
                <p>
                    <label for="payment_trx_id">' . esc_html__('Transaction ID', 'separate-bkash-rocket-nagad-payment-gateways') . ' <span class="required">*</span></label>
                    <input type="text" id="payment_trx_id" name="payment_trx_id" class="input-text" placeholder="' . esc_attr__('Enter transaction ID', 'separate-bkash-rocket-nagad-payment-gateways') . '" required />
                </p>';
        }
    }

    // Nagad Payment Gateway
    class WC_Gateway_Nagad extends WC_Manual_Payment_Gateway {
        public function __construct() {
            $this->id = 'nagad';
            $this->method_title = 'Nagad Payment';
            $this->method_description = 'Accept payments via Nagad';
            $this->has_fields = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->nagad_number = $this->get_option('nagad_number');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_payment_details_in_admin'), 10, 1);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Nagad Payment',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'Payment method title',
                    'default' => 'Nagad Payment',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'Payment method description',
                    'default' => 'নগদ থেকে সর্বনিম্ন ৮৫ টাকা অথবা সম্পূর্ণ টাকা পাঠাতে পারেন',
                ),
                'nagad_number' => array(
                    'title' => 'Nagad Number',
                    'type' => 'text',
                    'default' => '01810571737',
                )
            );
        }

        protected function show_payment_instructions() {
            wp_nonce_field('process_payment', 'payment_nonce');
            echo '<p><strong>' . esc_html__('Account Type:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html__('Personal (Send Money)', 'separate-bkash-rocket-nagad-payment-gateways') . '<br>
                <strong>' . esc_html__('Nagad Account Number:', 'separate-bkash-rocket-nagad-payment-gateways') . '</strong> ' . esc_html($this->nagad_number) . '</p>
                <p>
                    <label for="payment_account_number">' . esc_html__('Your Nagad Number', 'separate-bkash-rocket-nagad-payment-gateways') . ' <span class="required">*</span></label>
                    <input type="text" id="payment_account_number" name="payment_account_number" class="input-text" placeholder="01XXXXXXXXX" required />
                </p>
                <p>
                    <label for="payment_trx_id">' . esc_html__('Transaction ID', 'separate-bkash-rocket-nagad-payment-gateways') . ' <span class="required">*</span></label>
                    <input type="text" id="payment_trx_id" name="payment_trx_id" class="input-text" placeholder="' . esc_attr__('Enter transaction ID', 'separate-bkash-rocket-nagad-payment-gateways') . '" required />
                </p>';
        }
    }

    // Add gateways to WooCommerce
    add_filter('woocommerce_payment_gateways', function($methods) {
        $methods[] = 'WC_Gateway_bKash';
        $methods[] = 'WC_Gateway_Rocket';
        $methods[] = 'WC_Gateway_Nagad';
        return $methods;
    });


    // Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});




wc_get_logger()->info('Custom log message', array('source' => 'separate-payment-gateways'));

}