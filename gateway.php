<?php

/**
 * @package M-Pesa For WooCommerce
 * @subpackage WooCommerce Mpesa Gateway
 * @author Osen Concepts < hi@osen.co.ke >
 * @since 0.18.01
 */

use Osen\Woocommerce\Mpesa\C2B;
use Osen\Woocommerce\Mpesa\STK;

/**
 * Handle a custom 'mpesa_request_id' query var to get orders with the 'mpesa_request_id' or
 * 'mpesa_phone' meta.
 * 
 * @param array $query - Args for WP_Query.
 * @param array $query_vars - Query vars from WC_Order_Query.
 * @return array modified $query
 * 
 * TODO: Currently, the plugin's language is English. Some parts are ready for translation
 *       using the WordPress __() function style to allow for static texts to be translated.
 *       However, not all static text is handled like this. For example static HTML and the
 *       return of some error codes.
 */
add_filter('woocommerce_order_data_store_cpt_get_orders_query', function ($query, $query_vars) {
    if (!empty($query_vars['mpesa_request_id'])) {
        $query['meta_query'][] = array(
            'key'   => 'mpesa_request_id',
            'value' => esc_attr($query_vars['mpesa_request_id']),
        );
    }

    if (!empty($query_vars['mpesa_phone'])) {
        $query['meta_query'][] = array(
            'key'   => 'mpesa_phone',
            'value' => esc_attr($query_vars['mpesa_phone']),
        );
    }

    return $query;
}, 10, 2);

function wc_mpesa_post_id_by_meta_key_and_value($key, $value)
{
    $orders = wc_get_orders(array($key => $value));

    # If $orders is empty, return false, otherwise return the
    # ID for the first order in the array of orders found.
    return empty($orders) ? false : reset($orders)->get_id();
}

/**
 * Register our gateway with woocommerce
 */
add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_MPESA_Gateway';
    return $gateways;
}, 9);

add_action('plugins_loaded', function () {
    if (class_exists('WC_Payment_Gateway')) {
        /**
         * @class WC_Gateway_MPesa
         * @extends WC_Payment_Gateway
         */
        class WC_MPESA_Gateway extends WC_Payment_Gateway
        {
            public $sign;
            public $debug           = false;
            public $enable_c2b      = false;
            public $enable_bonga    = false;
            public $enable_reversal = false;
            public $enable_for_methods;
            public $enable_for_virtual;
            public $instructions;
            public $shortcode;
            public $env;
            public $type;

            /**
             * Constructor for the gateway.
             */
            public function __construct()
            {
                $this->id           = 'mpesa';
                $this->icon         = apply_filters('woocommerce_mpesa_icon', plugins_url('assets/mpesa.png', __FILE__));
                $this->method_title = __('Lipa Na M-Pesa', 'woocommerce');
                $this->has_fields   = true;

                // Load the settings.
                $this->init_form_fields();
                $this->init_settings();

                // Define user set variables.
                $this->title              = $this->get_option('title');
                $this->description        = $this->get_option('description');
                $this->instructions       = $this->get_option('instructions');
                $this->enable_for_methods = $this->get_option('enable_for_methods', array());
                $this->enable_for_virtual = $this->get_option('enable_for_virtual', 'yes') === 'yes';
                $this->sign               = $this->get_option('signature', md5(rand(12, 999)));
                $this->enable_reversal    = $this->get_option('enable_reversal', 'no') === 'yes';
                $this->enable_c2b         = $this->get_option('enable_c2b', 'no') === 'yes';
                $this->enable_bonga       = $this->get_option('enable_bonga', 'no') === 'yes';
                $this->debug              = $this->get_option('debug', 'no') === 'yes';
                $this->shortcode          = $this->get_option('shortcode');
                $this->type               = $this->get_option('type', 4);
                $this->env                = $this->get_option('env', 'sandbox');

                $this->method_description = (($this->env === 'live')
                    ? __('Receive payments via M-PESA', 'woocommerce')
                    : __('This plugin comes preconfigured so you can test it out of the box. Afterwards, you can view instructions on <a href="' . admin_url('admin.php?page=wc_mpesa_go_live') . '">how to Go Live</a>', 'woocommerce'));

                # Adding actions for various hooks. Note that the functions to be called
                # are passed as array values instead of function names because we cannot
                # directly refer to an object's function. Hence, we pass an array pointing
                # to the object and the name of the object's function. For example, in the
                # first statement below, we are telling WordPress to call 'thankyou_page'
                # as a function of '$this' object whenever the hook 'woocommerce_thankyou_mpesa'
                # is triggered.
                add_action('woocommerce_thankyou_mpesa', array($this, 'thankyou_page'));
                add_action('woocommerce_thankyou_mpesa', array($this, 'request_body'), 1);
                add_action('woocommerce_receipt_mpesa', array($this, 'validate_payment'), 2);

                add_filter('woocommerce_payment_complete_order_status', array($this, 'change_payment_complete_order_status'), 10, 3);
                add_action('woocommerce_email_before_order_table', array($this, 'email_mpesa_receipt'), 10, 4);

                add_action('woocommerce_api_lipwa', array($this, 'webhook'));
                add_action('woocommerce_api_lipwa_reconcile', array($this, 'webhook_reconcile'));
                // add_action('woocommerce_api_lipwa_confirm', array($this, 'webhook_confirm'));
                // add_action('woocommerce_api_lipwa_validate', array($this, 'webhook_validate'));
                add_action('woocommerce_api_lipwa_receipt', array($this, 'get_transaction_id'));
                add_action('woocommerce_api_lipwa_request', array($this, 'resend_request'));

                $statuses = $this->get_option('statuses', array());
                foreach ((array) $statuses as $status) {
                    $status_array = explode('-', $status);
                    $status       = array_pop($status_array);

                    add_action("woocommerce_order_status_{$status}", array($this, 'process_mpesa_reversal'), 1);
                }

                add_action('admin_notices', array($this, 'callback_urls_registration_response'));
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

                add_filter('wc_mpesa_settings', array($this, 'set_default_options'), 1, 1);
            }

            public function set_default_options()
            {
                return array(
                    'env'        => $this->get_option('env', 'sandbox'),
                    'appkey'     => $this->get_option('key', ''),
                    'appsecret'  => $this->get_option('secret', ''),
                    'headoffice' => $this->get_option('headoffice', 0),
                    'shortcode'  => $this->get_option('shortcode', 0),
                    'initiator'  => $this->get_option('initiator', ''),
                    'password'   => $this->get_option('password', ''),
                    'type'       => (int) ($this->get_option('idtype', 4)), # 4 = Paybill number 
                    'passkey'    => $this->get_option('passkey', ''),
                    'reference'  => $this->get_option('reference', ''),
                    'signature'  => $this->get_option('signature', md5(rand(12, 999))),
                );
            }

            public function callback_urls_registration_response()
            {
                echo isset($_GET['mpesa-urls-registered'])
                    ? '<div class="updated ' . (sanitize_text_field($_GET['reg-state']) ?? 'notice') . ' is-dismissible">
                            <h4>Callback URLs Registration</h4>
                            <p>' . sanitize_text_field($_GET['mpesa-urls-registered']) . '</p>
                        </div>'
                    : '';
            }

            /**
             * Initialise Gateway Settings Form Fields.
             */
            public function init_form_fields()
            {
                $shipping_methods = array();
                foreach (WC()->shipping()->load_shipping_methods() as $method) {
                    $shipping_methods[$method->id] = $method->get_method_title();
                }

                $this->sign        = $this->get_option('signature', md5(rand(12, 999)));
                $this->debug       = $this->get_option('debug', 'no') === 'yes';
                $this->enable_c2b  = $this->get_option('enable_c2b', 'no') === 'yes';
                $this->form_fields = array(
                    'enabled'            => array(
                        'title'       => __('Enable/Disable', 'woocommerce'),
                        'label'       => __('Enable ' . $this->method_title, 'woocommerce'),
                        'type'        => 'checkbox',
                        'description' => '',
                        'default'     => 'yes',
                    ),
                    'title'              => array(
                        'title'       => __('Method Title', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Payment method name that the customer will see on your checkout.', 'woocommerce'),
                        'default'     => __('Lipa Na M-Pesa', 'woocommerce'),
                        'desc_tip'    => true,
                    ),
                    'env'                => array(
                        'title'       => __('Environment', 'woocommerce'),
                        'type'        => 'select',
                        'options'     => array(
                            'sandbox' => __('Sandbox', 'woocommerce'),
                            'live'    => __('Live', 'woocommerce'),
                        ),
                        'description' => __('M-Pesa Environment', 'woocommerce'),
                        'desc_tip'    => true,
                        'class'       => 'select2 wc-enhanced-select',
                    ),
                    'idtype'             => array(
                        'title'       => __('Identifier Type', 'woocommerce'),
                        'type'        => 'select',
                        'options'     => array(
                            /**1 => __('MSISDN', 'woocommerce'),*/
                            4 => __('Paybill Number', 'woocommerce'),
                            2 => __('Till Number', 'woocommerce'),
                        ),
                        'description' => __('M-Pesa Identifier Type', 'woocommerce'),
                        'desc_tip'    => true,
                        'class'       => 'select2 wc-enhanced-select',
                    ),
                    'headoffice'         => array(
                        'title'       => __('Store Number', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Your Store Number. Use "Online Shortcode" in Sandbox', 'woocommerce'),
                        'default'     => __(0, 'woocommerce'),
                        'desc_tip'    => true,
                    ),
                    'shortcode'          => array(
                        'title'       => __('Business Shortcode', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Your M-Pesa Business Till/Paybill Number. Use "Online Shortcode" in Sandbox', 'woocommerce'),
                        'default'     => __(0, 'woocommerce'),
                        'desc_tip'    => true,
                    ),
                    'key'                => array(
                        'title'       => __('App Consumer Key', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Your App Consumer Key From M-PESA.', 'woocommerce'),
                        'default'     => '',
                        'desc_tip'    => true,
                    ),
                    'secret'             => array(
                        'title'       => __('App Consumer Secret', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Your App Consumer Secret From M-PESA.', 'woocommerce'),
                        'default'     => '',
                        'desc_tip'    => true,
                    ),
                    'passkey'            => array(
                        'title'       => __('Online Pass Key', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Used to create a password for use when making a Lipa Na M-Pesa Online Payment API call.', 'woocommerce'),
                        'default'     => '',
                        'desc_tip'    => true,
                        'class'       => 'wide-input',
                        'css'         => 'min-width: 55%;',
                    ),
                    'reference'          => array(
                        'title'             => __('Account Reference', 'woocommerce'),
                        'type'              => 'text',
                        'description'       => __('Account number for transactions. Leave blank to use order ID/Number.', 'woocommerce'),
                        'default'           => '',
                        'desc_tip'          => true,
                        'custom_attributes' => array(
                            'autocomplete' => 'off',
                        ),
                    ),
                    'signature'          => array(
                        'title'       => __('Encryption Signature', 'woocommerce'),
                        'type'        => 'password',
                        'description' => __('Random string for Callback Endpoint Encryption Signature', 'woocommerce'),
                        'default'     => $this->sign,
                        'desc_tip'    => true,
                        'css'         => 'display: none;',
                    ),
                    'resend'             => array(
                        'title'       => __('Resend STK Button Text', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Text description for resend STK prompt button', 'woocommerce'),
                        'default'     => __('Resend STK Push', 'woocommerce'),
                        'desc_tip'    => true,
                    ),
                    'description'        => array(
                        'title'       => __('Method Description', 'woocommerce'),
                        'type'        => 'textarea',
                        'description' => __('Payment method description that the customer will see during checkout.', 'woocommerce'),
                        'default'     => __("Cross-check your details before pressing the button below.\nYour phone number MUST be registered with M-Pesa(and ON) for this to work.\nYou will get a pop-up on your phone asking you to confirm the payment.\nEnter your service (M-Pesa) PIN to proceed.\nIn case you don't see the pop up on your phone, please upgrade your SIM card by dialing *234*1*6#.", 'woocommerce'),
                        'desc_tip'    => true,
                        'css'         => 'height:150px',
                    ),
                    'instructions'       => array(
                        'title'       => __('Instructions', 'woocommerce'),
                        'type'        => 'textarea',
                        'description' => __('Instructions that will be added to the thank you page.', 'woocommerce'),
                        'default'     => __('Thank you for shopping with us.', 'woocommerce'),
                        'desc_tip'    => true,
                    ),
                    'completion'         => array(
                        'title'       => __('Order Status on Payment', 'woocommerce'),
                        'type'        => 'select',
                        'options'     => array(
                            'completed'  => __('Mark order as completed', 'woocommerce'),
                            'on-hold'    => __('Mark order as on hold', 'woocommerce'),
                            'processing' => __('Mark order as processing', 'woocommerce'),
                        ),
                        'description' => __('What status to set the order after Mpesa payment has been received', 'woocommerce'),
                        'desc_tip'    => true,
                        'class'       => 'select2 wc-enhanced-select',
                    ),
                    'enable_for_methods' => array(
                        'title'             => __('Enable for shipping methods', 'woocommerce'),
                        'type'              => 'multiselect',
                        'class'             => 'wc-enhanced-select',
                        'css'               => 'width: 400px;',
                        'default'           => '',
                        'description'       => __('If M-Pesa is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'woocommerce'),
                        'options'           => $shipping_methods,
                        'desc_tip'          => true,
                        'custom_attributes' => array(
                            'data-placeholder' => __('Select shipping methods', 'woocommerce'),
                        ),
                    ),
                    'enable_for_virtual' => array(
                        'title'   => __('Accept for virtual orders', 'woocommerce'),
                        'label'   => __('Accept M-Pesa if the order is virtual', 'woocommerce'),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                    ),
                    'debug'              => array(
                        'title'       => __('Debug Mode', 'woocommerce'),
                        'label'       => __('Enable debug mode and show request body', 'woocommerce'),
                        'type'        => 'checkbox',
                        'default'     => 'no',
                    ),
                    'debug_title'              => array(
                        'title'       => __('You can send the following URLs to M-PESA team on request', 'woocommerce'),
                        'type'        => 'title',
                        'description' => '<ul class="woocommerce_mpesa_debug_text">
                        <li>Validation URL for C2B: <a href="' . home_url('wc-api/lipwa?action=validate&sign=' . $this->sign) . '">' . home_url('wc-api/lipwa?action=validate&sign=' . $this->sign) . '</a></li>
                        <li>Confirmation URL for C2B: <a href="' . home_url('wc-api/lipwa?action=confirm&sign=' . $this->sign) . '">' . home_url('wc-api/lipwa?action=confirm&sign=' . $this->sign) . '</a></li>
                        <li>Reconciliation URL for STK Push: <a href="' . home_url('wc-api/lipwa?action=reconcile&sign=' . $this->sign) . '">' . home_url('wc-api/lipwa?action=reconcile&sign=' . $this->sign) . '</a></li>
                        </ul>',
                        'css' => 'margin-top: -10px;'
                    ),
                    'c2b_section'        => array(
                        'title'       => __('M-Pesa Manual Payments', 'woocommerce'),
                        'description' => __('Enable C2B API(Offline Payments and Lipa Na Bonga Points)', 'woocommerce'),
                        'type'        => 'title',
                    ),
                    'enable_c2b'         => array(
                        'title'       => __('Enable Manual Payments', 'woocommerce'),
                        'label'       => __('Enable C2B API(Offline Payments)', 'woocommerce'),
                        'type'        => 'checkbox',
                        'description' => '<small>This requires C2B Validation, which is an optional feature that needs to be activated on M-Pesa. <br>Request for activation by sending an email to <a href="mailto:apisupport@safaricom.co.ke">apisupport@safaricom.co.ke</a>, or through a chat on the <a href="https://developer.safaricom.co.ke/">developer portal.</a><br><br> <a class="page-title-action" href="' . home_url('wc-api/lipwa?action=register') . '">Once enabled, click here to register confirmation & validation URLs</a><br><i>Kindly note that if this is disabled, the user can still resend an STK push if the first one fails.</i></small>',
                        'default'     => 'no',
                    ),
                    'enable_bonga'       => array(
                        'title'       => __('Bonga Points', 'woocommerce'),
                        'label'       => __('Enable Lipa Na Bonga Points', 'woocommerce'),
                        'type'        => 'checkbox',
                        'description' => $this->enable_c2b ? '<small>This requires C2B Validation, which is an optional feature that needs to be activated on M-Pesa. <br>Request for activation by sending an email to <a href="mailto:apisupport@safaricom.co.ke">apisupport@safaricom.co.ke</a>, or through a chat on the <a href="https://developer.safaricom.co.ke/">developer portal.</a></small>' : '',
                        'default'     => 'no',
                        'desc_tip'    => true,
                    ),
                    'reversal_section'   => array(
                        'title'       => __('M-Pesa Transaction Reversal', 'woocommerce'),
                        'description' => __('Enable reversal API(On status change)', 'woocommerce'),
                        'type'        => 'title',
                    ),
                    'enable_reversal'    => array(
                        'title'       => __('Reversals', 'woocommerce'),
                        'label'       => __('Enable Reversal on Status change', 'woocommerce'),
                        'type'        => 'checkbox',
                        'description' => $this->enable_reversal ? '<small>This requires a user with Transaction Reversal Change</small>' : '',
                        'default'     => 'no',
                        'desc_tip'    => true,
                    ),
                    'initiator'          => array(
                        'title'       => __('Initiator Username', 'woocommerce'),
                        'type'        => 'text',
                        'description' => __('Username for user with Reversal Role.', 'woocommerce'),
                        'default'     => __('test', 'woocommerce'),
                        'desc_tip'    => true,
                    ),
                    'password'           => array(
                        'title'       => __('Initiator Password', 'woocommerce'),
                        'type'        => 'password',
                        'description' => __('Password for user with Reversal Role.', 'woocommerce'),
                        'desc_tip'    => true,
                    ),
                    'statuses'           => array(
                        'title'             => __('Order Statuses', 'woocommerce'),
                        'type'              => 'multiselect',
                        'options'           => wc_get_order_statuses(),
                        'placeholder'       => __('Select statuses', 'woocommerce'),
                        'description'       => __('Status changes for which to reverse transactions.', 'woocommerce'),
                        'desc_tip'          => true,
                        'class'             => 'select2 wc-enhanced-select',
                        'custom_attributes' => array(
                            'data-placeholder' => __('Select order statuses to reverse', 'woocommerce'),
                        ),
                    ),
                );
            }

            /**
             * Check If The Gateway Is Available For Use.
             *
             * @return bool
             */
            public function is_available()
            {
                $order          = null;
                $needs_shipping = false;

                if (WC()->cart && WC()->cart->needs_shipping()) {
                    $needs_shipping = true;
                } elseif (is_page(wc_get_page_id('checkout')) && 0 < get_query_var('order-pay')) {
                    $order_id = absint(get_query_var('order-pay'));
                    $order    = wc_get_order($order_id);

                    if (0 < sizeof($order->get_items())) {
                        foreach ($order->get_items() as $item) {
                            $_product = wc_get_product($item['product_id']);
                            if ($_product && $_product->needs_shipping()) {
                                $needs_shipping = true;
                                break;
                            }
                        }
                    }
                }

                /**
                 * Apply all functions registered with the 'woocommerce_cart_needs_shipping' hook.
                 * This hook checks if any of the items in the shopping cart need shipping.
                 * 
                 * https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-cart.html#source-view.1538
                 */
                $needs_shipping = apply_filters('woocommerce_cart_needs_shipping', $needs_shipping);

                // Virtual order, with virtual disabled
                if (!$this->enable_for_virtual && !$needs_shipping) {
                    return false;
                }

                // Only apply if all packages are being shipped via chosen method, or order is virtual.
                if (!empty($this->enable_for_methods) && $needs_shipping) {
                    $chosen_shipping_methods = array();

                    if (is_object($order)) {
                        $chosen_shipping_methods = array_unique(array_map('wc_get_string_before_colon', $order->get_shipping_methods()));
                    } elseif ($chosen_shipping_methods_session = WC()->session->get('chosen_shipping_methods')) {
                        $chosen_shipping_methods = array_unique(array_map('wc_get_string_before_colon', $chosen_shipping_methods_session));
                    }

                    if (0 < count(array_diff($chosen_shipping_methods, $this->enable_for_methods))) {
                        return false;
                    }
                }

                // Call the 'is_available' function from the parent object.
                return parent::is_available();
            }

            /**
             *
             */
            public function payment_fields()
            {
                if ($description = $this->get_description()) {
                    echo wpautop(wptexturize($description));
                }

                woocommerce_form_field(
                    'billing_mpesa_phone',
                    array(
                        'type'        => 'tel',
                        'class'       => array('form-row-wide', 'wc-mpesa-phone-field'),
                        'label'       => 'Confirm M-PESA Phone Number',
                        'label_class' => 'wc-mpesa-label',
                        'placeholder' => 'Confirm M-PESA Phone Number',
                        'required'    => true,
                    )
                );
            }

            /**
             *
             */
            public function validate_fields()
            {
                if (empty($_POST['billing_mpesa_phone'])) {
                    wc_add_notice('M-PESA phone number is required!', 'error');
                    return false;
                }

                return true;
            }

            /**
             * Check for current vendor ID
             *
             * @param WC_Order $order
             * @return int|null
             * 
             * This plugin supports multiple marketplaces with multiple vendors:
             * 
             * - Dokan Multivendor (https://wedevs.com/dokan/)
             * - WCFM Marketplace (https://wclovers.com/)
             * - WooCommerce Product Vendors (https://woocommerce.com/products/product-vendors/)
             * 
             * Because each vendor will have to be paid individually, it is important to identify
             * which vendor needs to be paid when an order is submitted by a customer.
             */
            public function check_vendor(WC_Order $order)
            {
                /**
                 * @var int $vendor_id
                 * @var WC_Order_Item[] $items
                 * 
                 * Default vendor ID is 0, so if there is no marketplace, the M-PESA
                 * settings from this vendor will apply.
                 */
                $vendor_id = 0;
                $items     = $order->get_items('line_item');

                // Dokan Multivendor
                if (function_exists('dokan_get_seller_id_by_order')) {
                    $vendor_id = dokan_get_seller_id_by_order($order->get_id());
                }

                // WCFM Marketplace
                if (function_exists('wcfm_get_vendor_id_by_post') && !empty($items)) {
                    // TODO: This code does not take into account that there could be
                    //       multiple vendors. Instead, the code just iterates over
                    //       each order item and whichever item is last in the array
                    //       that will determine both the product and vendor IDs.
                    foreach ($items as $item) {
                        $line_item  = new WC_Order_Item_Product($item);
                        $product_id = $line_item->get_product_id();
                        $vendor_id  = wcfm_get_vendor_id_by_post($product_id);
                    }
                }

                // WooCommerce Product Vendors
                if (class_exists('WC_Product_Vendors_Utils')) {
                    // TODO: This code does not take into account that there could be
                    //       multiple vendors. Instead, the code just iterates over
                    //       each order item and whichever item is last in the array
                    //       that will determine both the product and vendor IDs.
                    foreach ($items as $item) {
                        $line_item  = new WC_Order_Item_Product($item);
                        $product_id = $line_item->get_product_id();
                        $vendor_id  = call_user_func_array(
                            array('WC_Product_Vendors_Utils', 'get_vendor_id_from_product'),
                            array($product_id)
                        );
                    }
                }

                // Use the applicable M-PESA configuration for this order
                add_filter('wc_mpesa_settings', function () use ($vendor_id) {
                    return array(
                        'env'        => get_user_meta($vendor_id, 'mpesa_env', true) ?? 'sandbox',
                        'appkey'     => get_user_meta($vendor_id, 'mpesa_key', true) ?? '',
                        'appsecret'  => get_user_meta($vendor_id, 'mpesa_secret', true) ?? '',
                        'headoffice' => get_user_meta($vendor_id, 'mpesa_store', true) ?? 0,
                        'shortcode'  => get_user_meta($vendor_id, 'mpesa_shortcode', true) ?? 0,
                        'initiator'  => get_user_meta($vendor_id, 'mpesa_initiator', true) ?? '',
                        'password'   => get_user_meta($vendor_id, 'mpesa_password', true) ?? '',
                        'type'       => (int) (get_user_meta($vendor_id, 'mpesa_type', true) ?? 4),
                        'passkey'    => get_user_meta($vendor_id, 'mpesa_passkey', true) ?? '',
                        'reference'  => get_user_meta($vendor_id, 'mpesa_account', true) ?? '',
                        'signature'  => get_user_meta($vendor_id, 'mpesa_signature', true) ?? md5(rand(12, 999)),
                    );
                }, 10);

                return $vendor_id;
            }

            /**
             * Process the payment and return the result.
             *
             * @param int $order_id
             * @return array
             */
            public function process_payment($order_id)
            {
                $order = new WC_Order($order_id);
                $total = $order->get_total();
                $phone = sanitize_text_field($_POST['billing_mpesa_phone'] ?? $order->get_billing_phone());
                // Get the site title
                $sign  = get_bloginfo('name');
                /** Instantiate a new SIM ToolKit object
                 * TODO: Create an abstract base class for the STK object and implement
                 *       country specific classes. The current implementation works with
                 *       M-PESA in Kenya. Add another for Vodacom in Tanzania and perhaps
                 *       other countries such as Lesotho, Zambia. Whatever is supported
                 *       by the M-PESA API from Vodacom.
                 */ 
                $stk   = new STK();
                // Get the applicable M-PESA settings
                $this->check_vendor($order);

                // Check if debug mode has been activated for this plugin
                if ($this->debug) {
                    $result = $stk->authorize(get_transient('mpesa_token'))
                        ->request($phone, $total, $order_id, $sign . ' Purchase', 'WCMPesa', true);
                    // Return the result from the authorization request as an HTTP session
                    // variable.
                    $payload = wp_json_encode($result['requested']);
                    WC()->session->set('mpesa_request', $payload);
                } else {
                    $result = $stk->authorize(get_transient('mpesa_token'))
                        ->request($phone, $total, $order_id, $sign . ' Purchase', 'WCMPesa');
                }

                // Check the result from the authorization request
                if ($result) {
                    // Check if an error code was returned
                    if (isset($result['errorCode'])) {
                        // Yes, return the error to the customer
                        wc_add_notice(__("(M-Pesa Error) {$result['errorCode']}: {$result['errorMessage']}.", 'woocommerce'), 'error');

                        // If debug mode is enabled, also add the result from the request stored earlier
                        // in a session variable.
                        if ($this->debug && WC()->session->get('mpesa_request')) {
                            wc_add_notice(__('Request: ' . WC()->session->get('mpesa_request'), 'woocommerce'), 'error');
                        }

                        return array(
                            'result'   => 'fail',
                            'redirect' => '',
                        );
                    }

                    // Check if a merchant request ID is present in the response
                    if (isset($result['MerchantRequestID'])) {
                        /**
                         * If so update the order details
                         *
                         * TODO: Phone number currently hard-coded for Kenya. Perhaps the $phone
                         *       number can be used instead but that depends on the M-PESA number
                         *       entered. Otherwise, we'll use the country code associated with
                         *       the STK request or request that users also enter the country code
                         *       as part of the M-PESA number.
                         */
                        update_post_meta($order_id, 'mpesa_phone', "254" . substr($phone, -9));
                        update_post_meta($order_id, 'mpesa_request_id', $result['MerchantRequestID']);
                        /**
                         * NOTE: The payment has not yet been completely processed at this point.
                         *       This will be done after the callback URL has been called by the
                         *       M-PESA payment provider.
                         */
                        $order->add_order_note(
                            __("Awaiting M-Pesa confirmation of payment from {$phone} for request {$result['MerchantRequestID']}.", 'woocommerce')
                        );

                        /**
                         * Remove contents from cart as the order has been submitted. In case,
                         * for whatever reason, the payment confirmation is not received, the
                         * next page will allow a customer to reinitiate payment for the order.
                         */
                        WC()->cart->empty_cart();

                        // Return thankyou redirect
                        return array(
                            'result'   => 'success',
                            'redirect' => $order->get_checkout_payment_url(true)
                        );
                    }
                }

                wc_add_notice(__('Failed! Could not connect to M-PESA', 'woocommerce'), 'error');

                return array(
                    'result'   => 'fail',
                    'redirect' => '',
                );
            }

            //Create Payment Page
            public function payment_page($order_id)
            {
                if (wc_get_order($order_id)) {
                    $order = new \WC_Order($order_id);
                    $total = $order->get_total();
                }
            }

            /**
             * Validate the payment on thank you page.
             *
             * @param int $order_id
             * @return array
             */
            public function validate_payment($order_id)
            {
                if (wc_get_order($order_id)) {
                    $order = new \WC_Order($order_id);
                    $total = $order->get_total();
                    $stk   = new STK();
                    $type  = ($stk->type === 4) ? 'Pay Bill' : 'Buy Goods and Services';
                    $return_url = $order->get_checkout_order_received_url();

                    /**
                     * Allow a customer to verify the payment status for their order. In
                     * case the process failed, they can reinitiate the STK process to
                     * pay for the order.
                     * 
                     * Possible improvement: After a 1 minute timeout, reinitiate the STK
                     *                       automatically.
                     */
                    echo
                    '<section class="woocommerce-order-details" id="resend_stk">
                        <input type="hidden" id="current_order" value="' . $order_id . '">
                        <input type="hidden" id="return_url" value="' . $return_url . '">
                        <input type="hidden" id="payment_method" value="' . $order->get_payment_method() . '">
                        <p class="checking" id="mpesa_receipt">Confirming receipt, please wait</p>
                        <table class="woocommerce-table woocommerce-table--order-details shop_table order_details" id="reinitiate-mpesa-table">
                            <tbody>
                                <tr class="woocommerce-table__line-item order_item">
                                    <td class="woocommerce-table__product-name product-name">
                                        <form action="' . home_url("wc-api/lipwa_request") . '" method="POST" id="reinitiate-mpesa-form">
                                            <input type="hidden" name="order" value="' . $order_id . '">
                                            <button id="reinitiate-mpesa-button" class="button alt" type="submit">' . ($this->settings['resend'] ?? 'Resend STK Push') . '</button>
                                        </form>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>';

                    /**
                     * In case Consumer To Business is active, display instructions to pay
                     * manually.
                     */
                    if ($this->enable_c2b) {
                        echo
                        '<section class="woocommerce-order-details" id="missed_stk">
                            <table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
                                <thead>
                                    <tr>
                                        <th class="woocommerce-table__product-name product-name">
                                            ' . __("STK Push didn't work? Pay manually via M-PESA", "woocommerce") . '
                                        </th>'
                            . ($this->enable_bonga ?
                                '<th>&nbsp;</th>' : '') . '
                                    </tr>
                                </thead>

                                <tbody>
                                    <tr class="woocommerce-table__line-item order_item">
                                        <td class="woocommerce-table__product-name product-name">
                                            <ol>
                                                <li>Select <b>Lipa na M-PESA</b>.</li>
                                                <li>Select <b>' . $type . '</b>.</li>
                                                ' . (($stk->type === 4) ? "<li>Enter <b>{$stk->shortcode}</b> as business no.</li><li>Enter <b>{$order_id}</b> as Account no.</li>" : "<li>Enter <b>{$stk->shortcode}</b> as till no.</li>") . '
                                                <li>Enter Amount <b>' . round($total) . '</b>.</li>
                                                <li>Enter your M-PESA PIN</li>
                                                <li>Confirm your details and press OK.</li>
                                                <li>Wait for a confirmation message from M-PESA.</li>
                                            </ol>
                                        </td>'
                            . ($this->enable_bonga ?
                                '<td class="woocommerce-table__product-name product-name">
                                            <ol>
                                                <li>Dial *236# and select <b>Lipa na Bonga Points</b>.</li>
                                                <li>Select <b>' . $type . '</b>.</li>
                                                ' . (($stk->type === 4) ? "<li>Enter <b>{$stk->shortcode}</b> as business no.</li><li>Enter <b>{$order_id}</b> as Account no.</li>" : "<li>Enter <b>{$stk->shortcode}</b> as till no.</li>") . '
                                                <li>Enter Amount <b>' . round($total) . '</b>.</li>
                                                <li>Enter your M-PESA PIN</li>
                                                <li>Confirm your details and press OK.</li>
                                                <li>Wait for a confirmation message from M-PESA.</li>
                                            </ol>
                                        </td>' : '') . '
                                    </tr>
                                </tbody>
                            </table>
                        </section>';
                    }
                }
            }

            /**
             * Display M-PESA request results if debug mode has been enabled.
             * 
             * @since 1.20.79
             */
            public function request_body()
            {
                if ($this->debug) {
                    echo '<section class="woocommerce-order-details" id="mpesa_request_output">
                            <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
                                <li class="woocommerce-order-overview__order order">
                                <p>Mpesa request body:</p>
                                <strong>' . WC()->session->get('mpesa_request') . '</strong>
                                </li>
                            </ul>
                        </section>';
                }
            }

            /**
             * Add content to the WC completed email.
             *
             * @since 3.0.0
             * @access public
             * @param \WC_Order $order
             * @param bool $sent_to_admin
             * @param bool $plain_text
             * @param \WC_Email $email
             */
            public function email_mpesa_receipt($order, $sent_to_admin = false, $plain_text = false, $email = null)
            {
                if ($email->id === 'customer_completed_order' && $order->get_transaction_id() && $order->get_payment_method() === 'mpesa') {
                    $receipt = $order->get_transaction_id();

                    echo '<dl><dt>Payment received via M-Pesa</dt><dd>Transaction ID: ' . $receipt . '</dd></dl>';
                }
            }

            /**
             * Resend STK push to customer as part of the reinitiate request. This function is
             * called when the initial request has failed, the customer was notified and reinitiated
             * the payment process.
             */
            public function resend_request()
            {
                $order_id = sanitize_text_field($_POST['order']);
                $order    = new \WC_Order($order_id);
                $total    = $order->get_total();
                $phone    = get_post_meta($order_id, 'mpesa_phone', true) ?? $order->get_billing_phone();
                $result   = (new STK())->authorize(get_transient('mpesa_token'))
                    ->request($phone, $total, $order_id, get_bloginfo('name') . ' Purchase', 'WCMPesa');

                if (isset($result['MerchantRequestID'])) {
                    $order->add_order_note(
                        __("STK push resent. Awaiting M-Pesa confirmation of payment for request {$result['MerchantRequestID']}.", 'woocommerce')
                    );
                    update_post_meta($order_id, 'mpesa_request_id', $result['MerchantRequestID']);
                }

                wp_send_json($result);
            }

            /**
             * Process webhook information such as IPN (Instant Payment Notification)
             * 
             * This function handles the following actions:
             * 
             * 1. Request  : Resend payment request (STK push message) to customer
             * 2. Validate : Validate the response data, received from M-PESA
             * 3. Reconcile: Reconcile response and order data and change status if the response
             *               data indicates success. 
             * 4. Confirm  : Update the order status based on the amount received from a customer:
             *               amountPaid >= amountDue: completed
             *               amountPaid < amountDue : on-hold
             * 5. Register : Register C2B validation and confirmation callback URLs:
             *               - Confirmation URL: wc-api/lipwa?action=confirm
             *               - Validation URL  : wc-api/lipwa?action=validate
             * 6. Status   : Request the status of a transaction. Two end-points are defined
             *               - Result URL       : wc-api/lipwa?action=result
             *               - Queue timeout URL: wc-api/lipwa?action=timeout
             * 7. Result   : Parse the result of a transaction status request
             *               Note: It appears that a transaction status request is only used in
             *                     the context of a customer refund. After verifying that the
             *                     transaction was successfully reversed, the order status is
             *                     changed to 'refunded'.
             * 8. Timeout  : Handles a timeout of a transaction status request
             *
             * @since 2.3.1
             */
            public function webhook()
            {
                // Default action is 'validate'
                $action = sanitize_text_field($_GET['action']) ?? 'validate';
                $stk    = new STK();
                $c2b    = new C2B();

                switch ($action) {
                    case "request":
                        $order_id = sanitize_text_field($_POST['order']);
                        $order    = new \WC_Order($order_id);
                        $total    = $order->get_total();
                        $phone    = get_post_meta($order_id, 'mpesa_phone', true) ?? $order->get_billing_phone();
                        $result   = $stk->authorize(get_transient('mpesa_token'))
                            ->request($phone, $total, $order_id, get_bloginfo('name') . ' Purchase', 'WCMPesa');

                        if (isset($result['MerchantRequestID'])) {
                            $order->add_order_note(
                                __("STK push resent. Awaiting M-Pesa confirmation of payment for request {$result['MerchantRequestID']}.", 'woocommerce')
                            );
                            update_post_meta($order_id, 'mpesa_request_id', $result['MerchantRequestID']);
                        }

                        wp_send_json($result);
                        break;

                    case "validate":
                        // TODO: Calling the validate function without a callback function
                        //       to check the M-PESA response data, will always return
                        //       SUCCESS. Perhaps this cannot be implemented yet. Check the
                        //       documentation to see if validation for M-PESA (Tanzania)
                        //       can be enhanced.
                        wp_send_json($stk->validate());
                        break;

                    case "reconcile":
                        /**
                         * Get the site title from the HTTP GET variables. Probably necessary
                         * for multi-vendor support as a single webshop's site title would be
                         * fairly static.
                         */
                        $sign = sanitize_text_field($_GET['sign']);

                        /**
                         * Call 'reconcile' with a callback function using $response as a normal
                         * variable but alway use the $sign (Site Title) value created when the
                         * anonymous callback function was instantiated. The anonymous callback
                         * function returns a boolean:
                         * 
                         * False: Reconciliation failed: Reasons:
                         *            - The order is already completed
                         *            - No response body was provided
                         *            - No order could be found
                         * True : Reconciliation successful. Order status was updated based on
                         *        the response body passed to the callback function. Possible
                         *        order states:
                         *            - 'completed': Full payment was received
                         *            - 'on-hold'  : Payment has not yet been confirmed
                         *  
                         * Send back the response as JSON to the initiator of the AJAX request,
                         * (probably) the customer's browser window.
                         */
                        wp_send_json($stk->reconcile(function (array $response) use ($sign) {
                            if (isset($response['Body'])) {
                                $stkCallback       = $response['Body']['stkCallback'];
                                /** 
                                 * CHANGED: $resultCode can have the following values:
                                 * 
                                 * 0   : No error occurred
                                 * 1032: Request cancelled by user
                                 * 
                                 * Any non-zero result should be considered an error.
                                 *
                                 * Source: https://developer.safaricom.co.ke/Documentation 
                                 */
                                $resultCode        = (int) $stkCallback['ResultCode'];
                                $resultDesc        = $stkCallback['ResultDesc'];
                                $merchantRequestID = $stkCallback['MerchantRequestID'];
                                // Get the order ID from the HTTP GET parameters or search for the
                                // order ID by using the M-PESA merchant request ID to search for
                                // the order.
                                $order_id          = sanitize_text_field($_GET['order']) ?? wc_mpesa_post_id_by_meta_key_and_value('mpesa_request_id', $merchantRequestID);

                                if (wc_get_order($order_id)) {
                                    $order = new \WC_Order($order_id);

                                    if ($order->get_status() === 'completed') {
                                        return false;
                                    }

                                    // Check if the response provided metadata to parse AND the
                                    // transaction was successful. (ADDED)
                                    if (isset($stkCallback['CallbackMetadata']) && $resultCode === 0) {
                                        // Parse the metadata so that individual values can be
                                        // accessed by their name.
                                        $parsed = array_column($stkCallback['CallbackMetadata']['Item'], 'Value', 'Name');
                                        // Update the order transaction information and change
                                        // the status to 'completed'.
                                        $order->set_transaction_id($parsed['MpesaReceiptNumber']);
                                        $order->save();
                                        $order->update_status(
                                            $this->get_option('completion', 'completed'),
                                            __("Full M-Pesa Payment Received From {$parsed['PhoneNumber']}. Transaction ID {$parsed['MpesaReceiptNumber']}.")
                                        );

                                        // TODO: Check the purpose of this function. It could be
                                        //       an integration with an external platform or it
                                        //       could be a security problem where order data is
                                        //       leaked.
                                        do_action('send_to_external_api', $order, $parsed, $this->settings);
                                    } else {
                                        $order->update_status(
                                            'on-hold',
                                            __("(M-Pesa Error) {$resultCode}: {$resultDesc}.")
                                        );
                                    }

                                    return true;
                                }
                            }

                            return false;
                        }));
                        break;

                    case "confirm":
                        wp_send_json($stk->confirm(function ($response) {
                            if (empty($response)) {
                                // TODO: Change to 'translatable' string
                                wp_send_json(
                                    ['Error' => 'No response data received']
                                );

                                // ADDED: Exiting the callback now
                                return false;
                            }

                            // Parse the response
                            $mpesaReceiptNumber = $response['TransID'];
                            $transactionDate    = $response['TransTime'];
                            $amount             = (int) $response['TransAmount'];
                            $billRefNumber      = $response['BillRefNumber'];
                            $phoneNumber        = $response['MSISDN'];
                            $firstName          = $response['FirstName'];
                            $middleName         = $response['MiddleName'];
                            $lastName           = $response['LastName'];
                            $parsed             = compact("Amount", "MpesaReceiptNumber", "TransactionDate", "PhoneNumber");
                            $order_id           = $billRefNumber ?? wc_mpesa_post_id_by_meta_key_and_value('mpesa_reference', $BillRefNumber);

                            // Check if this is regarding a valid order
                            if (wc_get_order($order_id)) {
                                $order       = new \WC_Order($order_id);
                                $total       = round($order->get_total());
                                $ipn_balance = $total - round($amount);

                                if ($order->get_status() === 'completed') {
                                    // Exit, order status was already completed.
                                    // ADDED: false
                                    return false;
                                }

                                if ($ipn_balance === 0) {
                                    // Order has been paid in full, update order status
                                    $order->update_status(
                                        $this->get_option('completion', 'completed'),
                                        __("Full M-Pesa Payment Received From {$phoneNumber}. Transaction ID {$mpesaReceiptNumber}")
                                    );
                                    $order->set_transaction_id($mpesaReceiptNumber);
                                    $order->save();

                                    // TODO: Check the purpose of this function. It could be
                                    //       an integration with an external platform or it
                                    //       could be a security problem where order data is
                                    //       leaked.
                                    do_action('send_to_external_api', $order, $parsed, $this->settings);

                                    return true;
                                } elseif ($ipn_balance < 0) {
                                    // Customer paid too much, update order status. (How would
                                    // that be possible with an STK push message which specifies
                                    // how much should be paid?)
                                    $currency = get_woocommerce_currency();
                                    $order->update_status(
                                        $this->get_option('completion', 'completed'),
                                        __("{$phoneNumber} has overpayed by {$currency} " . abs($ipn_balance) . ". Transaction ID {$mpesaReceiptNumber}")
                                    );
                                    $order->set_transaction_id($mpesaReceiptNumber);
                                    $order->save();

                                    // TODO: Check the purpose of this function. It could be
                                    //       an integration with an external platform or it
                                    //       could be a security problem where order data is
                                    //       leaked.
                                    do_action('send_to_external_api', $order, $parsed, $this->settings);

                                    return true;
                                } else {
                                    $order->update_status(
                                        'on-hold',
                                        __("M-Pesa Payment from {$phoneNumber} incomplete")
                                    );
                                }
                            }

                            return false;
                        }));
                        break;

                    case "register":
                        /** 
                         * Hard to read code:
                         * 
                         * - Call the authorize function on the C2B instance with a possibly cached
                         *   result from a previous authorization request. Use the cached result if
                         *   it is still valid. The function returns the C2B instance.
                         * - Call the register function on the C2B instance and provide a callback
                         *   function to parse the results from the register function. The callback
                         *   defined here parses the response and updates the GUI state accordingly.
                         */  
                        $c2b->authorize(get_transient('mpesa_token'))->register(function ($response) {
                            $status = isset($response['ResponseDescription']) ? 'success' : 'fail';
                            if ($status === 'fail') {
                                $message = $response['errorMessage'] ?? 'Could not register M-PESA URLs, try again later.';
                                $state   = 'error';
                            } else {
                                $message = isset($response['ResponseDescription']) ? $response['ResponseDescription'] : 'M-PESA URL registered successfully. You will now receive C2B Payment Notifications.';
                                $state   = 'success';
                            }

                            exit(wp_redirect(
                                add_query_arg(
                                    array(
                                        'mpesa-urls-registered' => $message,
                                        'reg-state'             => $state,
                                    ),
                                    wp_get_referer()
                                )
                            ));
                        });

                        break;

                    case "status":
                        $transaction = sanitize_text_field($_POST['transaction']);
                        wp_send_json($stk->status($transaction));
                        break;

                    case "result":
                        $response = json_decode(file_get_contents('php://input'), true);

                        $result                   = $response['Result'];
                        $ResultType               = $result['ResultType'];
                        $ResultCode               = $result['ResultCode'];
                        $ResultDesc               = $result['ResultDesc'];
                        $OriginatorConversationID = $result['OriginatorConversationID'];
                        $TransactionID            = $result['TransactionID'];

                        $ResultParameters = $result['ResultParameters'];
                        $ResultParameter  = $ResultParameters['ResultParameters']['ResultParameter'];

                        $ReceiptNo         = $ResultParameter[0]['Value'];
                        $ConversationID    = $ResultParameter[0]['Value'];
                        $FinalisedTime     = $ResultParameter[0]['Value'];
                        $amount            = $ResultParameter[0]['Value'];
                        $TransactionStatus = $ResultParameter[0]['Value'];
                        $ReasonType        = $ResultParameter[0]['Value'];
                        $TransactionReason = $ResultParameter[0]['Value'];
                        $DebitPartyCharges = $ResultParameter[0]['Value'];
                        $DebitAccountType  = $ResultParameter[0]['Value'];
                        $InitiatedTime     = $ResultParameter[0]['Value'];
                        $CreditPartyName   = $ResultParameter[0]['Value'];
                        $DebitPartyName    = $ResultParameter[0]['Value'];

                        $ReferenceData = $result['ReferenceData'];
                        $ReferenceItem = $ReferenceData['ReferenceItem'];
                        $Occasion      = $ReferenceItem[0]['Value'];

                        $order_id = wc_mpesa_post_id_by_meta_key_and_value('mpesa_request_id', $OriginatorConversationID);
                        $order    = new \WC_Order($order_id);

                        if (wc_get_order($order_id)) {
                            $order->update_status('refunded', __($ResultDesc, 'woocommerce'));
                            $order->set_transaction_id($TransactionID);
                            $order->save();
                        } else {
                            $order->update_status('processing', __("{$ResultCode}: {$ResultDesc}", 'woocommerce'));
                        }

                        wp_send_json($stk->validate());
                        break;

                    case "timeout":
                        $response = json_decode(file_get_contents('php://input'), true);

                        if (!isset($response['Body'])) {
                            exit(wp_send_json(['Error' => 'No response data received']));
                        }

                        $stkCallback       = $response['Body']['stkCallback'];
                        $resultCode        = $stkCallback['ResultCode'];
                        $resultDesc        = $stkCallback['ResultDesc'];
                        $merchantRequestID = $stkCallback['MerchantRequestID'];

                        $order_id = wc_mpesa_post_id_by_meta_key_and_value('mpesa_request_id', $merchantRequestID);
                        if (wc_get_order($order_id)) {
                            $order = new \WC_Order($order_id);

                            $order->update_status(
                                'pending',
                                __("M-Pesa Payment Timed Out", 'woocommerce')
                            );
                        }

                        wp_send_json($stk->timeout());
                        break;
                    default:
                        wp_send_json($c2b->validate());
                }
            }

            public function webhook_reconcile()
            {
                $sign = sanitize_text_field($_GET['sign']);

                wp_send_json((new STK())->reconcile(function ($response) use ($sign) {
                    if (isset($sign) && $sign === $this->get_option('signature')) {
                        if (isset($response['Body'])) {
                            $stkCallback       = $response['Body']['stkCallback'];
                            $resultCode        = $stkCallback['ResultCode'];
                            $resultDesc        = $stkCallback['ResultDesc'];
                            $merchantRequestID = $stkCallback['MerchantRequestID'];
                            $order_id          = sanitize_text_field($_GET['order']) ?? wc_mpesa_post_id_by_meta_key_and_value('mpesa_request_id', $merchantRequestID);

                            if (wc_get_order($order_id)) {
                                $order = new \WC_Order($order_id);

                                if ($order->get_status() === 'completed') {
                                    return false;
                                }

                                if (isset($stkCallback['CallbackMetadata'])) {
                                    $parsed = array_column($stkCallback['CallbackMetadata']['Item'], 'Value', 'Name');

                                    $order->set_transaction_id($parsed['MpesaReceiptNumber']);
                                    $order->save();
                                    $order->update_status(
                                        $this->get_option('completion', 'completed'),
                                        __("Full M-Pesa Payment Received From {$parsed['PhoneNumber']}. Transaction ID {$parsed['MpesaReceiptNumber']}.")
                                    );

                                    // TODO: Check the purpose of this function. It could be
                                    //       an integration with an external platform or it
                                    //       could be a security problem where order data is
                                    //       leaked.
                                    do_action('send_to_external_api', $order, $parsed, $this->settings);
                                } else {
                                    $order->update_status(
                                        'on-hold',
                                        __("(M-Pesa Error) {$resultCode}: {$resultDesc}.")
                                    );
                                }

                                return true;
                            }
                        }
                    }

                    return false;
                }));
            }

            /**
             * Get order's Transaction ID via AJAX
             *
             * @since 2.3.1
             */
            public function get_transaction_id()
            {
                $response = array('receipt' => '');

                if (!empty($_GET['order'])) {
                    $order_id = sanitize_text_field($_GET['order']);
                    $order    = wc_get_order(esc_attr($order_id));
                    $notes    = wc_get_order_notes(array(
                        'post_id' => $order_id,
                        'number'  => 1,
                    ));

                    $response = array(
                        'receipt'                 => $order->get_transaction_id(),
                        'note'                    => $notes[0],
                        'user_token'              => $order->get_meta('user_token'),
                        'user_token_instructions' => $order->get_meta('user_token_instructions'),
                    );
                }

                exit(wp_send_json($response));
            }

            /**
             * Output for the order received page.
             */
            public function thankyou_page()
            {
                if ($this->instructions) {
                    echo wpautop(wptexturize($this->instructions));
                }
            }

            /**
             * Change payment complete order status to completed for M-Pesa orders.
             *
             * @since  3.1.0
             * @param  string         $status Current order status.
             * @param  int            $order_id Order ID.
             * @param  WC_Order|false $order Order object.
             * @return string
             */
            public function change_payment_complete_order_status($status, $order_id = 0, $order = false)
            {
                if ($order && 'mpesa' === $order->get_payment_method()) {
                    $status = $this->get_option('completion', 'completed');
                }

                return $status;
            }

            /**
             * Process Mpesa transaction reversals on slected statuses
             *
             * @since 3.0.0
             * @param int $order_id
             */
            public function process_mpesa_reversal($order_id)
            {
                $order       = wc_get_order($order_id);
                $transaction = $order->get_transaction_id();
                $total       = $order->get_total();
                $phone       = $order->get_billing_phone();
                $amount      = round($total);
                $method      = $order->get_payment_method();

                if ($method === 'mpesa') {
                    $response = (new C2B())
                        ->authorize(get_transient('mpesa_token'))
                        ->reverse($transaction, $amount, $phone);

                    if (isset($response['OriginatorConversationID'])) {
                        update_post_meta($order_id, 'mpesa_request_id', $response['OriginatorConversationID']);
                        $order->update_status('refunded');
                    } elseif (isset($response['errorCode'])) {
                        $order->update_status('failed', $response['errorMessage']);
                    }
                }
            }
        }
    }
}, 11);
