<?php
/**
 * Plugin Name: WooCommerce Checkout Enhancements
 * Description: Custom functionalities for WooCommerce checkout, including proceeds discount and zero-total payment gateway.
 * Version: 1.0.0
 * Author: RGC Data / @Alex_Seidler
 * Text Domain: my-checkout-enhancements
 * Domain Path: /languages
 *
 * @package My_Checkout_Enhancements
 */


if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Declare compatibility with Cart & Checkout Blocks - MUST BE AT THE TOP
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
});

// GitHub Commit Test - @disregard
// Comment #2 - this is for ron to maybe try to undo

/**
 * Ensure WooCommerce is active before proceeding.
 */
function mce_check_woocommerce_active() {
    if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
        add_action( 'admin_notices', 'mce_woocommerce_inactive_notice' );
        return false;
    }
    return true;
}

function mce_woocommerce_inactive_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e( 'My Custom Checkout Enhancements requires WooCommerce to be installed and active. Please activate WooCommerce to use this plugin.', 'my-checkout-enhancements' ); ?></p>
    </div>
    <?php
}

// Function: Ensure that the ajax population is set to modula
function cpw_add_module_type_to_blocks_gateway_script_tag( $tag, $handle, $src ) {
    // Only apply to our specific script handle
    if ( 'cpw-zero-total-gateway-blocks' === $handle ) { // <--- UPDATED TO ORIGINAL HANDLE
        // Ensure type="module" is added, and it's also async.
        $tag = preg_replace( '/(\s(?:async|data-wp-strategy="async"))/i', '', $tag ); // Remove original attributes
        $tag = str_replace( '<script', '<script type="module" async', $tag ); // Add type="module" and async manually
    }
    return $tag;
}

/**
 * Enqueue the main proceeds checkout script.
 * This function handles 'proceeds-checkout.js' and should be hooked to 'wp_enqueue_scripts'.
 */
function cpw_enqueue_scripts() {
    if ( ! mce_check_woocommerce_active() ) {
        return;
    }

    if ( is_checkout() || is_cart() ) {
wp_enqueue_script(
    'cpw-proceeds-checkout',
    plugin_dir_url( __FILE__ ) . 'assets/js/proceeds-checkout.js', // This path will now correctly point to your plugin
    array( 'jquery', 'wp-dom-ready' ),
    filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/proceeds-checkout.js' ), // This path will also correctly point to your plugin
    true
);
		$proceeds_balance = is_numeric(get_proceeds_info());
        // Only try to get balance if a user is logged in
        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();

            // EXAMPLE: If you store the balance as user meta:
            $balance_from_db = get_user_meta( $user_id, 'cpw_user_proceeds_balance', true ); // Adjust 'cpw_user_proceeds_balance' to your actual meta key
            // Ensure the retrieved balance is numeric and cast it to a float
            $current_user_proceeds_balance = is_numeric( $balance_from_db ) ? (float) $balance_from_db : 0.00;

            }
        }

 wp_localize_script(
    'cpw-proceeds-checkout',
    'cpw_proceeds_ajax',
    array(
        'ajax_url'             => admin_url( 'admin-ajax.php' ),
        'nonce'                => wp_create_nonce( 'cpw_proceeds_nonce' ),

        'max_proceeds_balance' => wc_format_decimal( $current_user_proceeds_balance, wc_get_price_decimals() ),
        // ********************************************
        'currency_symbol'      => get_woocommerce_currency_symbol(),
    )
        );
}

add_action( 'wp_enqueue_scripts', 'cpw_enqueue_scripts' );


// Wrapped to ensure WooCommerce is loaded.
add_action( 'init', 'mce_init_checkout_enhancements' );
function mce_init_checkout_enhancements() {
    if ( ! mce_check_woocommerce_active() ) {
        return;
    }
    
    // --- 3. CUSTOM ZERO-TOTAL PAYMENT GATEWAY ---
    /*****************************************************************************************************************************
    * WC_Gateway_CPW_Zero_Total class definition for custom zero-total payment gateway.
    * Ensure this class is defined only if WC_Payment_Gateway exists.
    */
    if ( class_exists( 'WC_Payment_Gateway' ) && ! class_exists( 'WC_Gateway_CPW_Zero_Total' ) ) :
        // --- wp_register_script and wp_localize_script are now in cpw_enqueue_blocks_gateway_script(). ---

        class WC_Gateway_CPW_Zero_Total extends WC_Payment_Gateway {

            public function __construct() {
                $this->id                   = 'cpw_zero_total_gateway'; // Unique ID for the gateway
                $this->icon                 = ''; // No icon
                $this->has_fields           = false; // No extra fields needed
                $this->method_title         = __( 'Proceeds Balance Payment', 'my-checkout-enhancements' ); // Title in WooCommerce Payments settings
                $this->method_description   = __( 'Allows customers to place orders when the cart total is zero after applying a proceeds balance.', 'my-checkout-enhancements' ); // Description in WooCommerce Payments settings
                $this->supports = array( 'products' ); // This is important for Blocks to know what the gateway supports
                // Title and description shown to the user on checkout
                $this->title                = $this->get_option( 'title', __( 'Proceeds Balance Payment', 'my-checkout-enhancements' ) );
                $this->description          = $this->get_option( 'description', __( 'Your order total is fully covered by your proceeds balance.', 'my-checkout-enhancements' ) );

                // Load the settings API
                $this->init_form_fields();
                $this->init_settings();

                // Actions to save admin options
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            /**
             * Initialize Gateway Settings Form Fields in Admin.
             */
            public function init_form_fields() {
                $this->form_fields = apply_filters( 'woocommerce_cpw_zero_total_form_fields', array(
                    'enabled' => array(
                        'title'   => __( 'Enable/Disable', 'my-checkout-enhancements' ),
                        'type'    => 'checkbox',
                        'label'   => __( 'Enable Proceeds Balance Payment', 'my-checkout-enhancements' ),
                        'default' => 'yes'
                    ),
                    'title' => array(
                        'title'       => __( 'Title', 'my-checkout-enhancements' ),
                        'type'        => 'text',
                        'description' => __( 'This controls the title which the user sees during checkout.', 'my-checkout-enhancements' ),
                        'default'     => __( 'Proceeds Balance Payment', 'my-checkout-enhancements' ),
                        'desc_tip'    => true,
                    ),
                    'description' => array(
                        'title'       => __( 'Description', 'my-checkout-enhancements' ),
                        'type'        => 'textarea',
                        'description' => __( 'This controls the description which the user sees during checkout.', 'my-checkout-enhancements' ),
                        'default'     => __( 'Your order total is fully covered by your proceeds balance.', 'my-checkout-enhancements' ),
                        'desc_tip'    => true,
                    )
                ) );
            }

            /**
             * Check if the gateway is available for use.
             * It's only available if the cart total is exactly 0.
             *
             * @return bool
             */
			
            public function is_available() {
                // First, check if the gateway is enabled in admin settings
                if ( ! parent::is_available() ) {
                    //error_log('DEBUG (CPW - Zero Gateway): is_available() is false because parent is_available() is false (gateway disabled in settings?).');
                    return false;
                }

                // Ensure cart exists
                if ( ! WC()->cart ) {
                    //error_log('DEBUG (CPW - Zero Gateway): is_available() is false because WC()->cart is not initialized.');
                    return false;
                }

                // Get the cart total, ensure it's a float for robust comparison
                $cart_total = floatval( WC()->cart->total );

                // Debug logging for clarity, showing more precision
                //error_log('DEBUG (CPW - Zero Gateway): Raw cart total for is_available(): ' . sprintf('%.10f', $cart_total));

                // Only show this gateway if the cart total is effectively zero.
                // We use loose comparison (==) and an absolute value check with a small epsilon
                // to account for floating point inaccuracies.
                if ( $cart_total == 0.0 || abs($cart_total) < 0.00000001 ) {
                   // error_log('DEBUG (CPW - Zero Gateway): is_available() is TRUE because cart total is effectively 0.');
                    return true;
                }

                //error_log('DEBUG (CPW - Zero Gateway): is_available() is FALSE because cart total is not effectively 0: ' . sprintf('%.10f', $cart_total));
                return false;
            }

 /**
 * Process the payment.
 * For a zero-total order, this simply marks the order as paid/completed.
 *
 * @param int $order_id The ID of the order being processed.
 * @return array Result of the payment process.
 */
public function process_payment( $order_id ) {
    $order = wc_get_order( $order_id );

    // Ensure the order object is valid and the total is indeed zero for this gateway.
    if ( $order && $order->get_total() == 0 ) {
        // Mark the order as paid. This is crucial for WooCommerce internal status.
        $order->payment_complete();

        // Set the order status. 'completed' is appropriate for zero-total orders as no further payment is due.
        $order->update_status( apply_filters( 'cpw_zero_total_order_status', 'completed', $order_id ), __( 'Order placed via Proceeds Balance Payment gateway. Total was zero.', 'my-checkout-enhancements' ) );

        // Retrieve the actual discount applied from order meta.
        // Set by:cpw_save_proceeds_discount_order_meta function

        $actual_discount_applied = $order->get_meta( '_proceeds_discount_amount' );

        if ( $actual_discount_applied && $actual_discount_applied > 0 ) {
			// set vars
            $fm_amount = $actual_discount_applied;
            $fm_orderid = $order->get_id();
            $fm_projectid = get_transient( "ProjectID" . $_COOKIE['fm_user_id'] ); 

        } else {
            // Option to log if no valid discount was found, but DO NOT return here.
            // The order is still validly zero-total, and we want to complete the checkout flow.
        }

        // --- Clear proceeds discount from session after successful order processing ---
        // This is the definitive place to clear the proceeds discount from the user's session
        // after a successful order, preventing unintended application to future carts.
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'cpw_applied_proceeds_discount', 0 );
            WC()->session->set( 'cpw_remaining_proceeds_session', 0 ); // Also clear any remaining proceeds
          //  error_log( 'CPW Debug: Cleared proceeds discount from session in Zero Total Gateway for Order ID: ' . $order_id );
        }
        
        // Empty the cart after successful order.
        WC()->cart->empty_cart();

        // Return success and redirect to the order received page.
        return array(
            'result'    => 'success',
            'redirect'  => $this->get_return_url( $order )
        );
    } else {
        // This 'else' block handles cases where the order total isn't zero,
        // which ideally shouldn't happen if is_available() is working correctly.
        wc_add_notice( __( 'Invalid order total for Proceeds Balance Payment gateway. Please select another payment method.', 'my-checkout-enhancements' ), 'error' );
        return array(
            'result'    => 'fail',
            'redirect'  => '' // Keep redirect empty on failure
        );
    }
}
            
            /**
             * Returns an array of key-value pairs of data to be sent from the PHP to the JS.
             * This data will be available to the Blocks frontend.
             * This method is needed for the wp_localize_script call.
             *
             * @return array
             */
            public function get_payment_method_data() {
                return [
                    'title'         => $this->get_title(),      // Get the configured title
                    'description'   => $this->get_description(), // Get the configured description
                    'icon'          => $this->get_icon(),       // Get the icon URL (might be empty)
                    'supports'      => array_filter( $this->supports, [ $this, 'supports' ] ), // Pass supported features
                    'showDescription' => true, // Indicate that the description should be displayed
                ];
            }
        } // End of WC_Gateway_CPW_Zero_Total class
    endif; // End if class_exists( 'WC_Payment_Gateway' ) AND !class_exists( 'WC_Gateway_CPW_Zero_Total' )

    // --- Blocks Compatibility for Zero-Total Gateway ---
    // This class makes your gateway known to WooCommerce Blocks.
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        // --- REMOVED: wp_register_script and wp_localize_script from here. They are now in cpw_enqueue_blocks_gateway_script(). ---
        
        if ( ! class_exists( 'CPW_Zero_Total_Gateway_Blocks_Integration' ) ) {
            class CPW_Zero_Total_Gateway_Blocks_Integration extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
                /**
                 * The ID of the payment method.
                 *
                 * @var string
                 */
                protected $name = 'cpw_zero_total_gateway'; // IMPORTANT: This MUST match your WC_Gateway_CPW_Zero_Total::$id

                /**
                 * Initializes the payment method integration.
                 */
                public function initialize() {
                    $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
                }

                /**
                 * Returns true if the payment method is active and should be rendered in the checkout.
                 * This logic is based on the cart total being zero.
                 *
                 * @return boolean
                 */
                public function is_active() {
                    // Instantiate your main gateway class to use its is_available method
                    // This is the authoritative check for whether your gateway should be visible.
                    $gateway = new WC_Gateway_CPW_Zero_Total();
                    $is_gateway_available = $gateway->is_available();

                    error_log('DEBUG (CPW Blocks Integration): is_active() called for Blocks. Gateway available (from WC_Gateway_CPW_Zero_Total::is_available()): ' . ($is_gateway_available ? 'TRUE' : 'FALSE') . '. Current cart total: ' . (WC()->cart ? WC()->cart->total : 'N/A'));

                    return $is_gateway_available;
                }

                /**
                 * Returns an array of scripts to enqueue for the Blocks frontend.
                 * We'll add the actual JavaScript file in the next step.
                 * For now, this will just return an empty array if no script is ready.
                 *
                 * @return array
                 */
                public function get_payment_method_script_handles() {
                    return [ 'cpw-zero-total-gateway-blocks' ];
                }

                /**
                 * Returns an array of key-value pairs of data to be sent from the PHP to the JS.
                 * This data will be available to the Blocks frontend.
                 *
                 * @return array
                 */
                public function get_payment_method_data() {
                    $gateway = new WC_Gateway_CPW_Zero_Total(); // Instantiate to get its properties
                    return [
                        'title'         => $gateway->get_title(),
                        'description'   => $gateway->get_description(),
                        'icon'          => $gateway->get_icon(),
                        'supports'      => array_filter( $gateway->supports, [ $gateway, 'supports' ] ), // Pass supported features
                        'showDescription' => true, // Indicate that the description should be displayed
                    ];
                }
            }
        }

        // This action registers your new Blocks integration class with WooCommerce.
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new CPW_Zero_Total_Gateway_Blocks_Integration() );
            }
        );
    } // End if class_exists AbstractPaymentMethodType


function cpw_handle_apply_proceeds_balance_ajax() {
    // Basic debug logging to confirm function entry and request details
    
    // Ensure WooCommerce session is available
    if ( WC()->session === null || ! WC()->session->has_session() ) {
        error_log('DEBUG (CPW - AJAX Handler): WC Session NOT AVAILABLE or NO SESSION. Critical.');
        wp_send_json_error( array( 'message' => 'Session not available.' ) );
    } else {
        error_log('DEBUG (CPW - AJAX Handler): WC Session IS AVAILABLE. Session ID: ' . WC()->session->get_customer_id());
    }

    // Ensure user is logged in
    if ( ! is_user_logged_in() ) {
        error_log('DEBUG (CPW - AJAX Handler): User is not logged in.');
        wp_send_json_error( array( 'message' => 'You must be logged in to apply proceeds.' ) );
    }

    // Get and validate proceeds amount from POST data
    $proceeds_amount = isset( $_POST['proceeds_amount'] ) ? floatval( sanitize_text_field( wp_unslash( $_POST['proceeds_amount'] ) ) ) : 0;

    // Get user's available proceeds balance
    $user_id = get_current_user_id();
    $user_proceeds_balance = get_user_meta( $user_id, 'cpw_user_proceeds_balance', true );
    $user_proceeds_balance = floatval( $user_proceeds_balance );

    // Validate if the amount can be applied
    if ( $proceeds_amount <= 0 || $proceeds_amount > $user_proceeds_balance ) {
        error_log('DEBUG (CPW - AJAX Handler): Amount validation failed (amount too low or exceeds balance).');
        wp_send_json_error( array( 'message' => 'Invalid proceeds amount or exceeds your available balance.' ) );
    }

    // Save the applied proceeds discount to the WooCommerce session
    WC()->session->set( 'cpw_applied_proceeds_discount', $proceeds_amount );
    WC()->session->save_data(); // Crucial: explicitly save session data
    WC()->cart->calculate_totals();

    // Prepare success response
    $response = array(
        'message'   => 'Proceeds of ' . wc_price( $proceeds_amount ) . ' applied successfully!',
        // No fragments or cart_hash needed for a full page reload
    );

    wp_send_json_success( $response );
}

// Add the actions after the function definition (these MUST be present and only once)
add_action( 'wp_ajax_apply_proceeds_balance_via_ajax', 'cpw_handle_apply_proceeds_balance_ajax' );
//add_action( 'wp_ajax_nopriv_apply_proceeds_balance_via_ajax', 'cpw_handle_apply_proceeds_balance_ajax' ); // unneccessary as user is forced login check to use proceeds in the first place
    
// --- 1. PROCEEDS DISCOUNT APPLICATION LOGIC ---
/***********************************************************************************************************************************
     * Apply the proceeds discount to the cart.
     * Hooked into woocommerce_before_calculate_totals.
     *
     * @param WC_Cart $cart The WooCommerce cart object.
     */
if ( ! function_exists( 'cpw_apply_proceeds_discount_to_cart' ) ) { // Corrected wrapper
 function cpw_apply_proceeds_discount_to_cart( $cart ) {

    $applied_proceeds_discount = floatval( WC()->session->get( 'cpw_applied_proceeds_discount', 0 ) );
  
    // BEGIN IF THEN - only do if the proceeds is greater than 0
    if ( $applied_proceeds_discount > 0 ) {
        $calculated_cart_subtotal = 0; // Initialize vars
        
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset($cart_item['line_subtotal']) ) {
                $calculated_cart_subtotal += $cart_item['line_subtotal'];
            }
        }

    // This is our subtotal for the order.  Shipping and taxes are adding onto this, for calculation.
    // For the time being, taxes are manually set to 0 below.
    $current_cart_total = $calculated_cart_subtotal;

    // 3. Get the user's available proceeds balance
    $user_id = get_current_user_id();
    $available_proceeds_balance = is_numeric( get_proceeds_info( $user_id ) ) ? floatval( get_proceeds_info( $user_id ) ) : 0;

		// ***** BEGIN CRITICAL CHANGE *****
        // Ensure shipping and tax are calculated and available before reading them
        // This forces the cart to finalize its calculation state for these components
        WC()->cart->calculate_shipping();
      
        // ***** END CRITICAL CHANGE *****
        // 
		$shipping_total_pulled = WC()->cart->get_shipping_total();
        
		// --- START: NEW WAY TO GET TAX TOTAL (disabled at the moment) ---
		// ------ For the time being, lets store what we can in the meta, and just leave it --- 
        
		 $tax_total = 0;
         $calculated_taxes = WC()->cart->get_taxes(); // Returns an array like [tax_rate_id => tax_amount]
        
        if ( ! empty( $calculated_taxes ) ) {
            foreach ( $calculated_taxes as $tax_rate_id => $tax_amount ) {
                $tax_total += $tax_amount;
            }
         }
		
        // Ensure it's formatted correctly
        $tax_total_pulled = wc_format_decimal($tax_total, wc_get_price_decimals());
		
			// --- NEW: Store the calculated tax total before discount potentially zeroes it out ---
        		WC()->session->set( 'cpw_original_calculated_tax_total', $tax_total_pulled );
        	// --- END NEW ---
	    //
	    // ********************* set taxes to 0 for the time being - delete to re-enable
		// $final_shipping_total = $tax_total_pulled;
		$final_shipping_total = 0;
    	// $final_tax_total = $tax_total;
    	$final_tax_total = 0;
		
		//
		$final_cart_true_total = $final_shipping_total + $final_tax_total + $current_cart_total;
		//error_log("Final Cart Total: " . $final_cart_true_total); // debugging log
    
		
    	// 4. Get the current cart's true total (after recalculation, now includes shipping and taxes)
    	$current_cart_true_total = $final_cart_true_total; // at the moment just the subtotal; can always remove the manual shipping/tax = 0 on here
		$tax_total_pulled = 0;
        // This includes product subtotal + shipping + taxes
        $current_cart_total = $calculated_cart_subtotal + $shipping_total_pulled + $tax_total_pulled;
		//error_log('Total: ' . $current_cart_total);
 
        // Ensure current cart total is positive before applying discount
        if ( $current_cart_total <= 0 ) {
            error_log('DEBUG (CPW): cpw_apply_proceeds_discount_to_cart: Calculated cart total is zero or negative. Cannot apply discount this cycle.');
            return;
        }

        $discount_to_add = min( $applied_proceeds_discount, $current_cart_total );

        //  Session Variables we need for other functions
        WC()->session->set( 'cpw_actual_proceeds_discount_applied', $discount_to_add );
        WC()->session->set( 'cpw_cart_subtotal_before_discount', $calculated_cart_subtotal );
		
				/*
 				* error_log('--- CPW Debug (Detailed Cart State - Before Add Fee) ---');
					error_log('Cart Subtotal (get_subtotal()): ' . wc_format_decimal($cart->get_subtotal(), wc_get_price_decimals()));
					error_log('Cart Subtotal Tax (get_subtotal_tax()): ' . wc_format_decimal($cart->get_subtotal_tax(), wc_get_price_decimals()));
					error_log('Cart Contents Total (get_cart_contents_total()): ' . wc_format_decimal($cart->get_cart_contents_total(), wc_get_price_decimals()));
					error_log('Cart Total (get_total()): ' . wc_format_decimal($cart->get_total(), wc_get_price_decimals()));
					error_log('Cart Total Tax (get_total_tax()): ' . wc_format_decimal($cart->get_total_tax(), wc_get_price_decimals())); // <-- This is key
					error_log('Cart Total (get_total(\'edit\')): ' . wc_format_decimal($cart->get_total('edit'), wc_get_price_decimals()));
					error_log('Cart Taxes Array (get_taxes()): ' . print_r($cart->get_taxes(), true)); // <-- This is also key
					error_log('Stored Original Calculated Tax Total: ' . WC()->session->get( 'cpw_original_calculated_tax_total', 'NOT SET' )); // <-- And this one
				*/
		
        $cart->add_fee( __( 'Proceeds Applied', 'your-text-domain' ), -$discount_to_add, false, 'proceeds_discount_id' );

    } else {
        // --- IMPORTANT: Clear these if no discount is applied, to prevent old values persisting ---
        WC()->session->set( 'cpw_actual_proceeds_discount_applied', 0 );
        WC()->session->set( 'cpw_cart_subtotal_before_discount', 0 );
       // fixed - zero value is fine now, it will get validated during the ajax update in functions.php
    }
 
 
}
}
add_action( 'woocommerce_before_calculate_totals', 'cpw_apply_proceeds_discount_to_cart', 20, 1 );


/**
 * Filters the displayed total tax to show the pre-discount tax
 * if proceeds discount zeroed out the order total.
 *
 * This ensures the tax line still appears on the cart/checkout summary.
 *
 * @param float  $total_tax The calculated total tax.
 * @param WC_Cart $cart     The WooCommerce cart object.
 * @return float The modified total tax for display.
 */
// @todoalex taken out because it wasnt filtering correctly with the taxes
// 
//add_filter( 'woocommerce_get_total_tax', 'cpw_display_original_tax_if_zero_total', 10, 2 );
//add_filter( 'woocommerce_cart_totals_taxes_total', 'cpw_display_original_tax_if_zero_total', 10, 2 ); // Also filter the specific totals display

function cpw_display_original_tax_if_discount_applied( $total_tax, $cart ) {
	
    // Check if proceeds discount was applied
    $applied_proceeds_discount = WC()->session->get( 'cpw_applied_proceeds_discount', 0 );

    // Get the original tax total stored before the discount was applied
    $original_tax_total_before_discount = WC()->session->get( 'cpw_original_calculated_tax_total', 0 );

    // If proceeds were applied AND there was an original tax amount (i.e., tax was calculated initially)
    // AND the current calculated total tax is effectively zero (or very close due to floating point issues)
    // Then, force display of the original tax total.
    if ( $applied_proceeds_discount > 0 && floatval($original_tax_total_before_discount) > 0 && floatval( $total_tax ) <= 0.001 ) {
        return floatval( $original_tax_total_before_discount );
    }
    
    // Otherwise, return the tax calculated by WooCommerce.
    // This will handle scenarios where tax is still correctly calculated (e.g., partial discount,
    // but the taxable base is still significant), or if no discount is applied.
    return $total_tax;
}
	
	
// --- 2. ORDER META SAVE LOGIC ---
/***********************************************************************************************************************************
     * Save custom proceeds discount data as order meta.
     *
     * @param int $order_id The ID of the newly created order.
     * @param array $data Data submitted to checkout (not used here, but part of hook signature).
*/
if ( ! function_exists( 'cpw_save_proceeds_discount_order_meta' ) ) { // Corrected wrapper
    function cpw_save_proceeds_discount_order_meta( $order_id, $data ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            error_log('DEBUG (CPW): cpw_save_proceeds_discount_order_meta: Order not found for ID: ' . $order_id);
            return;
        }

        // Retrieve the values from session
        $original_proceeds_balance = floatval( WC()->session->get( 'cpw_applied_proceeds_discount', 0 ) );
        $actual_discount_applied    = floatval( WC()->session->get( 'cpw_actual_proceeds_discount_applied', 0 ) );
        $cart_subtotal_before_discount = floatval( WC()->session->get( 'cpw_cart_subtotal_before_discount', 0 ) );

        // Save as order meta if values are greater than 0
        // Using custom meta keys with a clear prefix (_cpw_) for easy identification.
        if ( $original_proceeds_balance > 0 ) {
            $order->update_meta_data( '_cpw_original_proceeds_balance', wc_format_decimal( $original_proceeds_balance, wc_get_price_decimals() ) );
        }
        if ( $actual_discount_applied > 0 ) {
            $order->update_meta_data( '_cpw_actual_proceeds_discount_applied', wc_format_decimal( $actual_discount_applied, wc_get_price_decimals() ) );
        }
        if ( $cart_subtotal_before_discount > 0 ) {
            $order->update_meta_data( '_cpw_cart_subtotal_before_proceeds_discount', wc_format_decimal( $cart_subtotal_before_discount, wc_get_price_decimals() ) );
        }

        // Crucially, save the order changes
        $order->save();

        // After saving to order meta, clear the session variables.
        WC()->session->set( 'cpw_applied_proceeds_discount', 0 );
        WC()->session->set( 'cpw_actual_proceeds_discount_applied', 0 );
        WC()->session->set( 'cpw_cart_subtotal_before_discount', 0 );
       
    }
	
  add_action( 'woocommerce_checkout_create_order', 'cpw_save_proceeds_discount_order_meta', 10, 2 );
}

/***********************************************************************************************************************************
* Clear proceeds discount session variables after order is completed (fallback).
* This ensures sessions are cleaned up even if something prevents the checkout_create_order hook's clear.
*
* @param int $order_id The ID of the order.
*/
    if ( ! function_exists( 'cpw_clear_proceeds_discount_session_after_order' ) ) { // Corrected wrapper
        function cpw_clear_proceeds_discount_session_after_order( $order_id ) {
            if ( ! WC()->session ) {
                error_log('DEBUG (CPW): cpw_clear_proceeds_discount_session_after_order: WC()->session not initialized. Exiting.');
                return;
            }

            // Only clear if the values are still present, to avoid unnecessary session writes.
            if ( WC()->session->get( 'cpw_applied_proceeds_discount', 0 ) > 0 ||
                WC()->session->get( 'cpw_actual_proceeds_discount_applied', 0 ) > 0 ||
                WC()->session->get( 'cpw_cart_subtotal_before_discount', 0 ) > 0 ) {

                WC()->session->set( 'cpw_applied_proceeds_discount', 0 );
                WC()->session->set( 'cpw_actual_proceeds_discount_applied', 0 );
                WC()->session->set( 'cpw_cart_subtotal_before_discount', 0 );
            }
        }
        add_action( 'woocommerce_thankyou', 'cpw_clear_proceeds_discount_session_after_order', 10, 1 ); // Fires on the order received page
        add_action( 'woocommerce_order_details_after_order_table', 'cpw_clear_proceeds_discount_session_after_order', 10, 1 ); // Fires when viewing an order in My Account
}


/**
* Register the custom payment gateway with WooCommerce.
*/
if ( ! function_exists( 'cpw_add_zero_total_gateway' ) ) { 
        function cpw_add_zero_total_gateway( $methods ) {
            $methods['cpw_zero_total_gateway'] = 'WC_Gateway_CPW_Zero_Total';
            return $methods;
        }
} // End if function_exists
add_filter( 'woocommerce_payment_gateways', 'cpw_add_zero_total_gateway' );


/**
* AJAX callback to clear proceeds discount session variables for testing.
* DEVELOPMENT/TESTING ONLY.
*/
    if ( ! function_exists( 'cpw_clear_proceeds_session_callback' ) ) { // Corrected wrapper
        add_action( 'wp_ajax_cpw_clear_proceeds_session', 'cpw_clear_proceeds_session_callback' );
        add_action( 'wp_ajax_nopriv_cpw_clear_proceeds_session', 'cpw_clear_proceeds_session_callback' ); // Allow for logged-out users

        function cpw_clear_proceeds_session_callback() {
            if ( ! WC()->session ) {
                // Attempt to initialize WC session if not already available
                WC()->session = WC_Session_Handler::init();
                if ( ! WC()->session ) {
                    wp_send_json_error( 'WooCommerce session not initialized.' );
                    return;
                }
            }
            WC()->session->set( 'cpw_applied_proceeds_discount', 0 );
            WC()->session->set( 'cpw_actual_proceeds_discount_applied', 0 );
            WC()->session->set( 'cpw_cart_subtotal_before_discount', 0 );

            // Force cart recalculation on the server side after clearing session
            WC()->cart->calculate_totals();
            wp_send_json_success( 'Proceeds discount session variables cleared and cart recalculated.' );
        }
    } // End if function_exists

} // End of mce_init_checkout_enhancements()

/** other functions i moved to plugin **/
function cpw_hide_payment_gateways_if_zero_total( $gateways ) {
    if ( ! WC()->cart ) {
        return $gateways; // Cart not initialized, do nothing
    }

    // Only filter if the cart total is effectively zero
    if ( WC()->cart->total <= 0 ) {

        $zero_total_gateway_id = 'cpw_zero_total_gateway'; // *** IMPORTANT: This MUST match your WC_Gateway_CPW_Zero_Total::$id ***
        $filtered_gateways = array();

        // If our zero-total gateway is enabled and exists, include ONLY it.
        if ( isset( $gateways[ $zero_total_gateway_id ] ) && $gateways[ $zero_total_gateway_id ]->is_available() ) {
            $filtered_gateways[ $zero_total_gateway_id ] = $gateways[ $zero_total_gateway_id ];
        } else {
            error_log('DEBUG (CPW - Gateway Filter): Zero Total Gateway is not available when cart is 0. Check settings or is_available() logic.');
        }

        return $filtered_gateways; // Return ONLY the zero-total gateway (or an empty array if it's not available)
    }

    return $gateways; // For non-zero totals, return all original gateways
}
// Ensure this hook is active. It's likely already there if the function is.
add_filter( 'woocommerce_available_payment_gateways', 'cpw_hide_payment_gateways_if_zero_total' );


/**
 * Localize custom AJAX data, including a nonce, for the checkout page.
 * This ensures our JavaScript has a reliable nonce for AJAX requests.
 */
add_action( 'wp_enqueue_scripts', 'cpw_localize_proceeds_ajax_data', 20 );

function cpw_localize_proceeds_ajax_data() {
    // Get the current user's available proceeds balance.
    $user_max_proceeds_balance = 0.00; // Default
    if ( is_user_logged_in() ) {
        $current_user_id = get_current_user_id();
        $user_proceeds = get_user_meta( $current_user_id, 'cpw_user_proceeds_balance', true );
        $user_max_proceeds_balance = floatval( $user_proceeds );
        // Ensure it's not negative
        if ( $user_max_proceeds_balance < 0 ) {
            $user_max_proceeds_balance = 0.00;
        }
    }

    // Pass the max balance to JavaScript
    wp_localize_script(
        'cpw-checkout', // Handle of your enqueued script
        'cpw_proceeds_ajax',
        array(
            'ajax_url'            => admin_url( 'admin-ajax.php' ),
            'nonce'               => wp_create_nonce( 'cpw-proceeds-balance-nonce' ),
            'message_error_general' => __( 'An unexpected error occurred. Please try again.', 'your-text-domain' ),
            'max_proceeds_balance' => $user_max_proceeds_balance, // <-- NEW: Pass the max balance
        )
    );
}


//
// Begin Custom Functionality for the Proceeds Management in the WooCommerce checkout process
// 
/**
 * Add custom data / label to the cart item.
 *
 * @param array $cart_item_data Array of item data.
 * @param int   $product_id    ID of the product being added.
 * @param int   $variation_id  ID of the variation being added.
 * @return array Modified item data.
 */
function cpw_add_cart_discount_label( $cart_item_data, $product_id, $variation_id ) {
	// Add your custom field value here. For testing, we'll just add some static text.
	$custom_value = 'Proceeds Balance';

	// Make sure the custom data is unique to this cart item.
	$cart_item_data['proceeds_balance'] = $custom_value;

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'cpw_add_cart_discount_label', 10, 3 );

/**
 * Save custom data when the order is created in the WooCommerce meta->line item
 *
 * @param WC_Order_Item $item Order item object.
 * @param string        $cart_item_key Cart item key.
 * @param array         $values Cart item data.
 * @param WC_Order      $order The order object.
 */
function cpw_add_proceeds_balance_meta( $item, $cart_item_key, $values, $order ) {
	$proceeds_balance = get_proceeds_info();
	if ( isset( $proceeds_balance) ) {
		$item->add_meta_data( '_proceeds_balance', $proceeds_balance );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'cpw_add_proceeds_balance_meta', 10, 4 );


// Function: Updates our FileMaker database via an API call once order is processed.  Will only fire ONCE due to meta value set/checked
// Called on a successful order only
//######################################################
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'cpw_update_filemaker_database', 10, 2 );
	
function cpw_update_filemaker_database( $order, $request_data_from_hook) {
	
    // --- START: Run Once Check ---
    // Check if the FileMaker update has already been sent for this specific order.
    // '_fm_update_sent' is a custom meta key we'll set.
    if ( $order->get_meta( '_fm_update_sent' ) === 'yes' ) {
        error_log( 'CPW Debug: FileMaker update already sent for Order ID: ' . $order->get_id() . '. Skipping duplicate call.' );
        return; // Exit the function immediately if it's already been processed for this order
    }
    // --- END: Run Once Check ---

	$fm_amount = WC()->session->get( 'cpw_applied_proceeds_discount' );
	
	$fm_orderid = $order->get_id();

	$fm_projectid = get_transient("ProjectID" . $_COOKIE['fm_user_id']);
	
	
    // Basic validation: Ensure we have the critical data before trying to connect
    if (!$fm_amount ||  ! $fm_projectid || ! $fm_orderid ) {
		error_log("CPW ERROR: FileMaker has been updated for Order ID: " . $fm_orderid . " with amount: " . $fm_amount . " | Project ID: " . $fm_projectid);
        return;
    }

    try {
        // Connect To CAM (your existing working code)
        $fm = new fmCWP(FM_HOST, FM_DATABASE, FM_LAYOUT, FM_USER, FM_PASSWORD);
        
        // FM -> Go To Layout
        $fm->setFilemakerLayout("@Projects");
        
        // Set the json script Parameter
        $scriptParam =array(
            'script.param' => wp_json_encode( 
                array(
                    '$action' => 'purchase',
                    '$amount' => $fm_amount,
                    '$projectID' => $fm_projectid,
                    '$orderID' => $fm_orderid,
                )
            )
        );

        // Run script
        $result = $fm->runScript('Options Web', $scriptParam);

        // Capture error
        $error = $fm->isError($result); // This typically returns true/false
        
        // Capture Response Array
        $response = $fm->getResponse($result); // Assuming this captures any FileMaker response

		// @todo filemaker
		$scriptResults = json_decode($response['response']['scriptResult']);
		$success = $scriptResults->success ;            

		$scriptResult = $scriptResults->result ;
		//"Project Inactive!"
		if(!$success){
			//$error = false;
			$errorMessage = "We encountered an error during the checkout proceeds for the Project ID: " . $fm_projectid . "\n" .
			"with an Order ID of: " . $fm_orderid . " for the proceeds amount of " . $fm_amount . " used.\n\n" . 
			"The returned error is: " . $scriptResult . " \n\n" . 
			"Please check the corresponding order both in the WooCommerce Dashboard and the FileMaker database to correctly synch values.\n\n" . 
			"Important: The proceeds MAY NOT have been deducted from this purchase and will have to be adjusted manually!";
			
			sendAdminErrorEmail( get_option('admin_email'), $errorMessage );
		}
	
        // Log based on success/failure
        if ( $error ) {
            error_log("CPW ERROR: FileMaker update failed for Order ID: " . $fm_orderid . " | Error: " . (is_array($error) ? implode(', ', $error) : $error) );
			
        } else {
            error_log("CPW SUCCESS: FileMaker has been updated for Order ID: " . $fm_orderid . " with amount: " . $fm_amount . " | Project ID: " . $fm_projectid);
        }

        // --- START: Mark as Sent ---
        // Set the order meta flag to 'yes' so this function doesn't run again for this order.
        $order->update_meta_data( '_fm_update_sent', 'yes' );
        $order->save(); // IMPORTANT: Save the order to persist the new meta data to the database
        // --- END: Mark as Sent ---
        
    } catch ( Exception $e ) {
        // Catch any PHP exceptions during the process (e.g., connection errors, malformed data)
        error_log( 'CPW EXCEPTION: FileMaker connection or update failed for Order ID: ' . $fm_orderid . ' | Exception Message: ' . $e->getMessage() );
    }
}


// ////////////////////////////////////////////////////////////////
 add_filter( 'woocommerce_get_order_item_totals', 'proceeds_discount_total_row', 10, 2 );

function proceeds_discount_total_row( $total_rows, $order ) {
    // Retrieve the custom discount data from order meta
    $custom_discount_name   = $order->get_meta( '_proceeds_discount_name' );
    $custom_discount_amount = $order->get_meta( '_proceeds_discount_amount' );

    $new_total_rows = array();
    $discount_row_added = false; // Flag to track if the discount was added

    foreach ( $total_rows as $key => $row ) {
        $new_total_rows[ $key ] = $row; // Copy existing row

        if ( 'cart_subtotal' === $key ) { // Insert after subtotal
            // Only add 'Proceeds Adjustment' if the custom discount was actually applied and is greater than 0
            if ( ! empty( $custom_discount_name ) && $custom_discount_amount > 0 ) {
                $new_total_rows['my_custom_discount'] = array(
                    'label' => $custom_discount_name . ':', // e.g., "Proceeds Adjustment:"
                    'value' => '-' . wc_price( $custom_discount_amount, array( 'currency' => $order->get_currency() ) ), // Display as negative amount
                );
                $discount_row_added = true;
            }

			$proceeds_remaining = get_proceeds_info();
			$proceeds_remaining = (float)$proceeds_remaining - (float)$custom_discount_amount;
			if($proceeds_remaining < 0) { $proceeds_remaining = 0; }
            // Add 'Remaining Proceeds' if the discount was applied (or if you always want it)
            // It makes sense to show 'Remaining Proceeds' only when the 'Proceeds Adjustment' is present.
            if ( $discount_row_added ) {
                $new_total_rows['my_remaining_proceeds'] = array(
                    'label' => 'Remaining Proceeds:',
                    'value' => wc_price( $proceeds_remaining, array( 'currency' => $order->get_currency() ) ), // Uses the final total set by your other function
                );
            }
        }
    }
    return $new_total_rows;
}

/**
 * Add proceeds discount amount to WooCommerce order emails.
 */
add_action( 'woocommerce_email_after_order_table', 'cpw_add_proceeds_to_order_emails', 10, 4 );

function cpw_add_proceeds_to_order_emails( $order, $sent_to_admin, $plain_text, $email ) {
    
	// used session over user_meta, as it felt unreliable and was set multiple times by Woo
	$proceeds_amount = WC()->session->get( 'cpw_applied_proceeds_discount' );

    //error_log( 'CPW Email Debug: Hook woocommerce_email_after_order_table called for Order ID: ' . $order->get_id() . '. Proceeds Amount found: ' . ( $proceeds_amount ? $proceeds_amount : 'none' ) );


    if ( $proceeds_amount && $proceeds_amount > 0 ) { // Only display if greater than 0
	    
		$proceeds_balance_total = get_proceeds_info();
		$proceeds_balance = (float)$proceeds_balance_total - $proceeds_amount;		
			if($proceeds_balance <= 0) { $proceeds_balance = 0; }	
		
		$formatted_proceeds = wc_price( $proceeds_amount ); // Format as currency
		$formatted_proceeds_amount = '$' . number_format($proceeds_balance, 2);
		
    if ( $plain_text ) {
            // For plain text emails
        echo "\n" . 'Proceeds Applied: ' . $formatted_proceeds . "\n";
    } else {
            // For HTML emails
  		echo '<div style="margin-top: 20px;  margin-bottom:20px; padding: 15px; border: 1px solid #eee; background-color: #f9f9f9;">';
        echo '<h3>Additional Order Information:</h3>';
        echo '<p>This customer applied their proceeds balance, which adjusted the total price.</p>';
        echo '<ul>';
        echo '<li>Their Proceeds Applied were: ' . $formatted_proceeds . '</li>';
        echo '<li>Their Proceeds remaining are: ' . $formatted_proceeds_amount . '</li>';
        echo '</ul>';
        echo '</div>';
        }
    }
	WC()->session->set( 'cpw_applied_proceeds_discount', 0 );
}



// Ensure the Cart Object is initialized for AJAX requests (@todo keep as a safeguard, possible snippet)
add_action( 'woocommerce_init', 'cpw_init_wc_session_for_ajax' );
function cpw_init_wc_session_for_ajax() {
    if ( ! is_admin() || ( defined('DOING_AJAX') && DOING_AJAX ) ) {
        if ( null === WC()->session ) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }
    }
}

// Ensure WC_Session is initialized if not already (important for CLI or early hooks)
// Keep this part, it's generally good practice for WooCommerce sessions
add_action( 'woocommerce_loaded', function() {
    if ( ! WC()->session ) {
        WC()->session = new WC_Session_Handler();
        WC()->session->init();
    }
}, 1 );


/** ******************************************** Custom Functions *******************
 */
function get_proceeds_info() {
	$fm_user_id = get_transient("ClientID" . $_COOKIE['fm_user_id']);
	if($fm_user_id) {
		// Connect to the FileMaker database
		$fm = new fmCWP(FM_HOST, FM_DATABASE, FM_LAYOUT, FM_USER, FM_PASSWORD);
	
	    // Go To Layout
	    $fm->setFilemakerLayout("API | Projects");
		$newProjectID = get_transient("ProjectID" . $_COOKIE['fm_user_id']);
	
	    // Setting up parameters for find record
	    $params = array(
    	    "query" => array(
        	    array(
            	    "ID" => $newProjectID
           		 ),
        		)
    		);
	
		// Find the Client by id
    	$result = $fm->findRecords($params);

    	// Capture Response Array
    	$response = $fm->getResponse($result);
    
    	// Capture error
    	$error = $fm->isError($result);
		
		// Parse the response from database into an array @projectData['label']
		// if(isset($response)){ fmcwpShowResponse($response); }
		$projectData = $response['response']['data'][0]['fieldData'];
	
		// Grab their available proceeds
		if($projectData['cProceedsDue']) { 
			$proceeds = $projectData['cProceedsDue'];
			
		}	
		if($error) {
			return $error;
		} else { 
			    $current_user_id = get_current_user_id();
				$proceeds_balance = floatval($proceeds);
				// Make it accessible easily for our javascript and php
				update_user_meta( $current_user_id, 'cpw_user_proceeds_balance', $proceeds_balance );
	
			return $proceeds;
		}
	
}
}


// Author: @alex_seidler
// Purpose: To handle the session data in WordPress from any page
// ideally accessable from a shortcode.
if ( ! function_exists( 'cwp_session' )) {
	function cwp_wc_session( $action, $key, $value = null ) {
    	// WooCommerce or its session is not initialized.
	    // This is crucial. Ensure this function runs after WC is ready.
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
	        error_log( 'WooCommerce session is not available.  Please enable cookies' );
    	    return false;
    	}
	
		// If no key send then lets just return before errors pop up
		if(!$action || !$key) {
			return false;
		}

		// Lets check what we want to do, can easily add on later
    	switch ( $action ) {
        	case 'save':
           		WC()->session->set( $key, $value );
            	// This will return if the value was successfully added to the wordpress session
          	   return WC()->session->get( $key ) === $value;
        	case 'get':
           	 return WC()->session->get( $key );
	        case 'delete': // This will remove a session variable
            	WC()->session->__unset( $key );
            	return ! WC()->session->get( $key ); // Returns true if successfully unset
    	    default:
        	    return false;

	//end switch
	}
	// end function
	}

// end if
}

// CWP Format Error Response
function cwpError($response){
	$message = is_array($response) ? 'Error!! ' . $response['messages'][0]['message'] : 'Error!! ' . $response; ;
	echo '<div 
	style="
	width: 100%; color: red; 
	border: 1px solid #E0E0E0;
	border-radius: 5px;
	box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
	margin-bottom: 25px; 
	padding: 10px 15px;
	font-size: 14px;
	background: lightyellow;
	">' . $message . '</div>' ;	
}

// Our own mail function to send administrator emails -- for now, used for inactive accounts or FileMaker errors that still go through successfully
function sendAdminErrorEmail($to, $message) {

	
    $subject = 'Urgent: Law Enforcement Services Inactive Account proceeds spent!';
    $headers = 'From: noreply@lawenforcementservices.com' . "\r\n" .
               'Reply-To: noreply@lawenforcementservices.com' . "\r\n" .
               'X-Mailer: PHP/' . phpversion();

    $email_body = "Hello Admin,\n\n" .
                  "An error occurred during a customer purchase.:\n\n" .
                  "Error Details: " . $message . "\n\n" .
                  "Please investigate this issue as soon as possible.\n\n" .
                  "Timestamp: " . date('Y-m-d H:i:s T') . "\n";

    // Use wp_mail() if you are in a WordPress environment, otherwise use mail()
    if (function_exists('wp_mail')) {
        $sent = wp_mail($to, $subject, $email_body, $headers);
        if ($sent) {
            error_log('Admin error email sent successfully via wp_mail to ' . $to);
        } else {
            error_log('Failed to send admin error email via wp_mail to ' . $to);
        }
    } else {
        // Fallback to PHP's built-in mail() function
        $sent = mail($to, $subject, $email_body, $headers);
        if ($sent) {
            error_log('Admin error email sent successfully via mail() to ' . $to);
        } else {
            error_log('Failed to send admin error email via mail() to ' . $to . '. Check your PHP mail configuration.');
        }
    }
	
}