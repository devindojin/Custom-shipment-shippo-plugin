<?php
/**
 * Plugin Name: WooCommerce Custom Shipping Management
 * Description: Adds a custom section to the WooCommerce order edit page for managing package dimensions, fetching Shippo rates, and creating labels.
 * Version: 1.0
 * Author: Ashwini
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

//echo __DIR__ . '../vendor/autoload.php';
//require_once(__DIR__ . '/vendor/autoload.php');

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
//include 'lib/Shippo.php';
// Include the Shippo PHP library
use Shippo\API;
use Shippo\API\Models\Operations;




/**
 * 1. Add Custom Meta Box to WooCommerce Order Edit Screen
 * This function registers the new section (meta box) that will appear on the order page.
 */
function wc_custom_shipping_add_meta_box() {
    add_meta_box(
        'wc_shippo_shipping_box', // Unique ID for the meta box
        __( 'Shippo Shipping & Label Management', 'wc-custom-shipping' ), // Title of the meta box
        'wc_custom_shipping_meta_box_callback', // Callback function to render the content
        'shop_order', // Post type (WooCommerce orders are 'shop_order')
        'normal', // Context (where on the screen the box should appear: 'normal', 'side', 'advanced')
        'high' // Priority (where within the context: 'high', 'core', 'default', 'low')
    );
}
add_action( 'add_meta_boxes', 'wc_custom_shipping_add_meta_box' );


/**
 * 2. Callback function to render the content of the meta box
 * This function contains the HTML structure for your custom section.
 * The dynamic parts (like fetching rates, creating labels) would be handled by JavaScript
 * making AJAX calls to WordPress (which then communicates with Shippo).
 *
 * @param WP_Post $post The current post object (WooCommerce order).
 */
function wc_custom_shipping_meta_box_callback( $post ) {
     // Add a nonce field for security
    wp_nonce_field( 'wc_shippo_shipping_save_data', 'wc_shippo_shipping_nonce' );

    // Get current order object
    $order = wc_get_order( $post->ID );

    // Get order items
    $order_items = $order->get_items();

    // Initialize USPS flat rate templates array
    $usps_flat_rate_templates_for_html = array();

    // --- Dynamically fetch USPS Parcel Templates from Shippo API ---
    // IMPORTANT: Replace with your actual Live/Test Shippo API Key
    $shippo_api_key = 'shippo_test_ad5cfe31b5547e58b7dfe3bb0695b1ff81fe370a'; // Use your test key for development

    // Check if Shippo SDK class exists before attempting to use it
    if (class_exists('\\Shippo')) {
        try {
            // Initialize Shippo SDK (assuming v2.x or newer)
            $sdk = \Shippo::builder()
                ->setSecurity(
                    'ShippoToken ' . $shippo_api_key
                )
                ->setShippoApiVersion('2018-02-08')
                ->build();

            // List carrier parcel templates for USPS
            $response = $sdk->carrierParcelTemplates->list(
                array(
                    'carrier' => 'usps',
                    'shippoApiVersion' => '2018-02-08'
                )
            );

            if ($response->carrierParcelTemplateList !== null && !empty($response->carrierParcelTemplateList->results)) {
                foreach ($response->carrierParcelTemplateList->results as $template) {
                    // Ensure the template has the necessary properties
                    if (isset($template->template) && isset($template->name)) {
                        $usps_flat_rate_templates_for_html[$template->template] = array(
                            'display_name'  => $template->name,
                            'length'        => isset($template->length) ? (float)$template->length : '',
                            'width'         => isset($template->width) ? (float)$template->width : '',
                            'height'        => isset($template->height) ? (float)$template->height : '',
                            'weight'        => isset($template->weight) ? (float)$template->weight : 70, // Default to 70 lbs if not specified
                            'mass_unit'     => isset($template->massUnit) ? $template->massUnit : 'lb',
                            'distance_unit' => isset($template->distanceUnit) ? $template->distanceUnit : 'in',
                        );
                    }
                }
            } else {
                error_log('Shippo API did not return any USPS parcel templates or response was null. Falling back to static templates.');
                // Fallback to static templates if API call fails or returns empty
                $usps_flat_rate_templates_for_html = wc_custom_shipping_get_static_usps_templates();
            }
        } catch (Throwable $e) { // Catch both Shippo_Error and general Exceptions
            error_log('Shippo API Error fetching USPS templates in wc_custom_shipping_meta_box_callback: ' . $e->getMessage());
            // Fallback to static templates on error
            $usps_flat_rate_templates_for_html = wc_custom_shipping_get_static_usps_templates();
        }
    } else {
        error_log('Shippo SDK class (\\Shippo) not found in wc_custom_shipping_meta_box_callback. Falling back to static USPS templates. Please ensure Shippo SDK is installed via Composer.');
        $usps_flat_rate_templates_for_html = wc_custom_shipping_get_static_usps_templates();
    }

    ?>
    <div id="wc-shippo-shipping-container" class="woocommerce_order_items_wrapper">
        <h3 class="wc-order-data-usage-title">Order #<?php echo esc_html( $order->get_order_number() ); ?> Shipping Details</h3>

        <div class="wc-shippo-section">
            <h4>Order Items & Quantity</h4>
            <p class="description" style="display: none;">Select an order item to automatically load its associated package dimensions based on quantity.</p>
            <div class="form-field form-field-wide">
                <div>
                    <?php
                    if ( $order_items ) {
                        foreach ( $order_items as $item_id => $item ) {
                            $product = $item->get_product();
                            if ( $product ) {
                                // Use product ID as value for mapping to packaging rules in JS
                                // Store item quantity as a data attribute for easier access in JS
                                echo '<span value="' . esc_attr( $product->get_id() ) . '" data-qty="' . esc_attr( $item->get_quantity() ) . '">' . esc_html( $item->get_name() ) . ' (x' . esc_html( $item->get_quantity() ) . ')</span>';
                            }
                        }
                    } else {
                        echo '<span value="">No products in this order</span>';
                    }
                    ?>
                </div>

                <label for="wc_shippo_product_select" style="display: none;">Select Product:</label>
                <select id="wc_shippo_product_select" name="wc_shippo_product_select" class="select short" style="display: none;">
                    <?php
                    if ( $order_items ) {
                        foreach ( $order_items as $item_id => $item ) {
                            $product = $item->get_product();
                            if ( $product ) {
                                // Use product ID as value for mapping to packaging rules in JS
                                // Store item quantity as a data attribute for easier access in JS
                                echo '<option value="' . esc_attr( $product->get_id() ) . '" data-qty="' . esc_attr( $item->get_quantity() ) . '">' . esc_html( $item->get_name() ) . ' (x' . esc_html( $item->get_quantity() ) . ')</option>';
                            }
                        }
                    } else {
                        echo '<option value="">No products in this order</option>';
                    }
                    ?>
                </select>
            </div>
            <div class="form-field form-field-wide" style="display: none;">
                <label for="wc_shippo_quantity">Order Quantity:</label>
                <input type="number" id="wc_shippo_quantity" name="wc_shippo_quantity" class="short" value="1" min="1">
            </div>
        </div>

        <div class="wc-shippo-section">
            <h4>Package Type & Dimensions</h4>
            <div class="form-field form-field-wide">
                <label for="wc_shippo_package_type">Package Type:</label>
                <select id="wc_shippo_package_type" name="wc_shippo_package_type" class="select short">
                    <option value="custom">Custom Package</option>
                    <option value="flat_rate">USPS Flat Rate Box</option>
                </select>
            </div>

            <div id="wc_shippo_flat_rate_box_options" style="display: none;">
                <div class="form-field form-field-wide">
                    <label for="wc_shippo_flat_rate_box_size">Flat Rate Box Size:</label>
                    <select id="wc_shippo_flat_rate_box_size" name="wc_shippo_flat_rate_box_size" class="select short">
                        <option value="">Select a Flat Rate Box</option>
                        <?php
                        foreach ( $usps_flat_rate_templates_for_html as $template_id => $template_data ) {
                            echo '<option value="' . esc_attr( $template_id ) . '">' . esc_html( $template_data['display_name'] ) . '</option>';
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div id="wc_shippo_custom_dimensions_fields">
                <p class="description">These values are automatically calculated based on order items, but can be manually adjusted for this specific order.</p>
                <div class="form-field form-field-wide">
                    <label for="wc_shippo_package_length">Length (in):</label>
                    <input type="number" id="wc_shippo_package_length" name="wc_shippo_package_length" class="short" step="0.1" value="0">
                </div>
                <div class="form-field form-field-wide">
                    <label for="wc_shippo_package_width">Width (in):</label>
                    <input type="number" id="wc_shippo_package_width" name="wc_shippo_package_width" class="short" step="0.1" value="0">
                </div>
                <div class="form-field form-field-wide">
                    <label for="wc_shippo_package_height">Height (in):</label>
                    <input type="number" id="wc_shippo_package_height" name="wc_shippo_package_height" class="short" step="0.1" value="0">
                </div>
                <div class="form-field form-field-wide">
                    <label for="wc_shippo_package_weight">Weight (oz): <span id="wc_shippo_package_weight_lb" class="description"></span></label>
                    <input type="number" id="wc_shippo_package_weight" name="wc_shippo_package_weight" class="short" step="0.1" value="0">
                </div>
                <button type="button" id="wc_shippo_reset_package_btn" class="button button-secondary">Reset Package Dimensions</button>
                <button type="button" id="wc_shippo_save_packaging_btn" class="button button-primary ml-2">Save These Packaging Rules</button>
            </div>
        </div>

        <div class="wc-shippo-section" id="carrier-selection-box">
            <h4>Carrier Selection</h4>
            <div class="form-field form-field-wide">
                <label for="wc_shippo_carrier_selection_type">Choose Carriers:</label>
                <select id="wc_shippo_carrier_selection_type" name="wc_shippo_carrier_selection_type" class="select short">
                    <option value="all">All Available Carriers</option>
                    <option value="custom">Select Custom Carriers</option>
                </select>
            </div>
            <div id="wc_shippo_custom_carriers_options" style="display: none;">
                <p class="description">Select specific carriers to get rates from:</p>
                <?php
      
                // Simulated carrier accounts for demonstration.
                // In a real plugin, these would be fetched from Shippo API or plugin settings.
                // In a real plugin, these would be fetched from Shippo API or plugin settings.
                //  $carrier_accounts_for_shippo = array(
                //     'eba8e1874e9a40a9a25b748958453793', //  ID 1 (e.g., USPS)
                //     '16608f80e5bf4b32b6788d9193573fc7', //  ID 2 (e.g., UPS)
                //     'a0c4f37cec214d4491043bd4a888a9e2', //  ID 3 (e.g., FedEx)
                //     '7e206c85e6354d9d9ed42ebf3c438839'  //  ID 4 (e.g., DHL)
                // );

                $available_carriers = array(
                    'eba8e1874e9a40a9a25b748958453793'  => 'USPS',
                    '16608f80e5bf4b32b6788d9193573fc7'   => 'UPS',
                    'a0c4f37cec214d4491043bd4a888a9e2' => 'FedEx',
                    '7e206c85e6354d9d9ed42ebf3c438839'   => 'DHL Express',
                );

                foreach ( $available_carriers as $id => $name ) {
                    echo '<label style="display: block; margin-bottom: 5px;">';
                    echo '<input type="checkbox" name="wc_shippo_selected_carriers[]" value="' . esc_attr( $id ) . '" checked="checked" /> ';
                    echo esc_html( $name );
                    echo '</label>';
                }
                ?>
            </div>
        </div>

        <div class="wc-shippo-section">
            <button type="button" id="wc_shippo_get_rates_btn" class="button button-primary">Get Shipping Rates</button>
            <div id="wc_shippo_rates_display" style="margin-top: 15px; display: none;">
                <h4>Available Shipping Options & Rates</h4>
                <div id="wc_shippo_rates_list">
                    <!-- Rates will be loaded here by JavaScript -->
                </div>
            </div>
        </div>

        <div class="wc-shippo-section">
            <button type="button" id="wc_shippo_create_label_btn" class="button button-primary" disabled>Create Shipping Label</button>
            <div id="wc_shippo_message_box" style="margin-top: 15px;">
                <!-- Messages will be displayed here by JavaScript -->
            </div>
        </div>
    </div>
    <?php
}

/**
 * Helper function to provide static USPS templates as a fallback or for initial setup.
 */
function wc_custom_shipping_get_static_usps_templates() {

    return array(
        'USPS_FlatRateEnvelope' => array(
            'display_name' => 'Priority Mail Flat Rate® Envelope - EP14F',
            'length'       => 12.5, 'width' => 9.5, 'height' => 0.75, 'weight' => 70,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_FlatRateWindowEnvelope' => array(
            'display_name' => 'Priority Mail Flat Rate® Window Envelope - EP14H',
            'length'       => 15, 'width' => 9.5, 'height' => 0.75, 'weight' => 70,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_FlatRatePaddedEnvelope' => array(
            'display_name' => 'Priority Mail Flat Rate® Padded Envelope',
            'length'       => 12.5, 'width' => 9.5, 'height' => 1, 'weight' => 70,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_LargeFlatRateBox' => array(
            'display_name' => 'Priority Mail Flat Rate® Large Box - LARGEFRB',
            'length'       => 8.625, 'width' => 5.375, 'height' => 1.625, 'weight' => 70,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_SmallFlatRateEnvelope' => array(
            'display_name' => 'Priority Mail Flat Rate® Small Envelope - EP14B',
            'length'       => 11, 'width' => 8.5, 'height' => 5.5, 'weight' => 70,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_MediumFlatRateBox1' => array(
            'display_name' => 'Priority Mail Flat Rate® Medium Box - 1',
            'length'       => 12, 'width' => 12, 'height' => 5.5, 'weight' => 70,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_MediumFlatRateBox2' => array(
            'display_name' => 'Priority Mail Flat Rate® Medium Box - 2',
            'length'       => 10.125, 'width' => 7.125, 'height' => 5, 'weight' => 15,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_LargeFlatRateBoardGameBox' => array(
            'display_name' => 'Priority Mail Board Game Large Flat Rate Box',
            'length'       => 12.25, 'width' => 10.5, 'height' => 5.5, 'weight' => 20,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_APOFlatRateBox' => array(
            'display_name' => 'Priority Mail Flat Rate® APO/FPO Box - MILIFRB',
            'length'       => 12.25, 'width' => 10.5, 'height' => 5.5, 'weight' => 20,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_FlatRateCardboardEnvelope' => array(
            'display_name' => 'Priority Mail Flat Rate® Envelope - EP14F',
            'length'       => 12.25, 'width' => 10.5, 'height' => 5.5, 'weight' => 20,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_FlatRateGiftCardEnvelope' => array(
            'display_name' => 'Priority Mail Gift Card Flat Rate Envelope - EP14GT',
            'length'       => 12.25, 'width' => 10.5, 'height' => 5.5, 'weight' => 20,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_FlatRateLegalEnvelope' => array(
            'display_name' => 'Priority Mail Flat Rate® Legal Envelope - EP14L',
            'length'       => 12.25, 'width' => 10.5, 'height' => 5.5, 'weight' => 20,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_RegionalRateBoxA1' => array(
            'display_name' => 'Priority Mail Regional Rate Box® - A1',
            'length'       => 12.25, 'width' => 10.5, 'height' => 5.5, 'weight' => 20,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_RegionalRateBoxA2' => array(
            'display_name' => 'Priority Mail Regional Rate Box® - A2',
            'length'       => 12.25, 'width' => 10.5, 'height' => 5.5, 'weight' => 20,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_RegionalRateBoxB1' => array(
            'display_name' => '	Priority Mail Regional Rate Box® - B1',
            'length'       => 12.25, 'width' => 10.5, 'height' => 5.5, 'weight' => 20,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_RegionalRateBoxB2' => array(
            'display_name' => 'Priority Mail Regional Rate Box® - B2',
            'length'       => 12.25, 'width' => 10.5, 'height' => 5.5, 'weight' => 20,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
        'USPS_SoftPack' => array(
            'display_name' => 'Self Packaging - Packaging not provided by USPS',
            'length'       => 12.25, 'width' => 10.5, 'height' => 5.5, 'weight' => 20,
            'mass_unit'    => 'lb', 'distance_unit'=> 'in',
        ),
    );
}

/**
 * 3. Enqueue Admin Scripts for Dynamic Functionality
 * This function loads the JavaScript that will power the dynamic parts of the meta box.
 * This JavaScript would be similar to the one in the previous immersive, adapted for WordPress AJAX.
 */
function wc_custom_shipping_admin_scripts() {
    global $pagenow, $post;

    // Only load on the order edit page
    if ( 'post.php' === $pagenow && 'shop_order' === $post->post_type ) {
        // Enqueue your custom JavaScript file
        wp_enqueue_script(
            'wc-shippo-shipping-script',
            plugin_dir_url( __FILE__ ) . 'js/admin-shippo-script.js', // Path to your JS file
            array( 'jquery' ), // Dependencies
            '1.0',
            true // Load in footer
        );

        // Prepare data for JavaScript
        $product_data_for_js = array();
        $order = wc_get_order( $post->ID );
        if ( $order ) {
            foreach ( $order->get_items() as $item_id => $item ) {
                $product = $item->get_product();
                if ( $product ) {
                    $product_id = $product->get_id();
                    $product_data_for_js[ $product_id ] = array(
                        'name'     => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'length'   => $product->get_length(),
                        'width'    => $product->get_width(),
                        'height'   => $product->get_height(),
                        'weight'   => $product->get_weight(),
                        // Retrieve custom packaging rules saved for this product
                        'custom_packaging_rules' => get_post_meta( $product_id, '_wc_custom_packaging_rules', true ) ?: array(),
                    );
                }
            }
        }

        //  Simulated carrier accounts for JavaScript.
        //  In a real plugin, these would be fetched from Shippo API or plugin settings.
        //  $carrier_accounts_for_shippo = array(
        //     'eba8e1874e9a40a9a25b748958453793', //  ID 1 (e.g., USPS)
        //     '16608f80e5bf4b32b6788d9193573fc7', //  ID 2 (e.g., UPS)
        //     'a0c4f37cec214d4491043bd4a888a9e2', //  ID 3 (e.g., FedEx)
        //     '7e206c85e6354d9d9ed42ebf3c438839'  //  ID 4 (e.g., DHL)
        // );

        $available_carriers_for_js = array(
            array( 'id' => 'eba8e1874e9a40a9a25b748958453793', 'name' => 'USPS' ),
            array( 'id' => '16608f80e5bf4b32b6788d9193573fc7', 'name' => 'UPS' ),
            array( 'id' => 'a0c4f37cec214d4491043bd4a888a9e2', 'name' => 'FedEx' ),
            array( 'id' => '7e206c85e6354d9d9ed42ebf3c438839', 'name' => 'DHL Express' ),
        );
        // Pass data from PHP to JavaScript (e.g., AJAX URL, nonce, order ID, product data)
        wp_localize_script(
            'wc-shippo-shipping-script',
            'wcShippoParams',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'wc_shippo_shipping_ajax_nonce' ),
                'order_id' => $post->ID,
                'product_data' => $product_data_for_js, // Pass product data including custom rules
                'available_carriers' => $available_carriers_for_js, // Pass available carrier IDs
                'usps_flat_rate_templates' => wc_custom_shipping_get_static_usps_templates(), // Pass USPS flat rate templates // USPS Flat Rate Box templates with their dimensions and max weight (Shippo's recommended max weight)
            )
        );

        // Add some basic inline styles for the meta box
            wp_add_inline_style(
                'wp-admin', // Changed handle to 'wp-admin'
                '
                #wc-shippo-shipping-container .wc-shippo-section {
                    padding: 15px;
                    border: 1px solid #25d695;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    background-color: #f9fafb;
                }
                #wc-shippo-shipping-container h3, #wc-shippo-shipping-container h4 {
                    margin-top: 0;
                    margin-bottom: 15px;
                    color: #333;
                }
                #wc-shippo-shipping-container .form-field {
                    margin-bottom: 15px;
                }
                #wc-shippo-shipping-container label {
                    font-weight: 600;
                    display: block;
                    margin-bottom: 5px;
                }
                #wc-shippo-shipping-container input[type="number"],
                #wc-shippo-shipping-container select {
                    width: 100%;
                    padding: 8px 10px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    box-sizing: border-box;
                }
                #wc-shippo-shipping-container .button-primary {
                    background-color: #007cba;
                    border-color: #007cba;
                    color: #fff;
                    box-shadow: none;
                    text-shadow: none;
                }
                #wc-shippo-shipping-container .button-primary:hover {
                    background-color: #006799;
                    border-color: #006799;
                }
                #wc-shippo-shipping-container .button-secondary {
                    background-color: #f3f4f6;
                    border-color: #d1d5db;
                    color: #374151;
                }
                #wc-shippo-shipping-container .button-secondary:hover {
                    background-color: #e5e7eb;
                    border-color: #9ca3af;
                }
                /* Styles for rate options */
                .rate-option {
                    background-color: #f9fafb;
                    border: 1px solid #e5e7eb;
                    border-radius: 8px;
                    padding: 12px;
                    margin-bottom: 10px;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    cursor: pointer;
                    transition: background-color 0.2s, border-color 0.2s;
                }
                .rate-option:hover {
                    background-color: #eff6ff;
                    border-color: #bfdbfe;
                }
                .rate-option.selected {
                    background-color: #e0e7ff; /* Light blue background */
                    border-color: #6366f1; /* Indigo border */
                    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.3); /* Subtle glow */
                }
                /* Loading spinner style */
                .loading-spinner {
                    border: 4px solid rgba(255, 255, 255, 0.3);
                    border-top: 4px solid #ffffff;
                    border-radius: 50%;
                    width: 20px;
                    height: 20px;
                    animation: spin 1s linear infinite;
                    display: inline-block;
                    vertical-align: middle; /* Align with text */
                    margin-left: 8px; /* Space from text */
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                /* Message Box Styles */
                .message-box {
                    padding: 12px;
                    border-radius: 8px;
                    margin-top: 20px;
                    font-size: 0.95rem;
                }
                .message-success {
                    background-color: #dcfce7;
                    color: #16a34a;
                    border: 1px solid #bbf7d0;
                }
                .message-error {
                    background-color: #fee2e2;
                    color: #dc2626;
                    border: 1px solid #fecaca;
                }
                '
            );
    }
}
add_action( 'admin_enqueue_scripts', 'wc_custom_shipping_admin_scripts' );


/**
 * 4. AJAX Handlers (for fetching rates and creating labels)
 * These functions would handle the actual communication with the Shippo API.
 * The JavaScript in admin-shippo-script.js would make AJAX requests to these actions.
 */

// Handle AJAX request to get Shippo rates
function wc_custom_shipping_ajax_get_shippo_rates() {
    // Check nonce for security
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wc_shippo_shipping_ajax_nonce' ) ) {
        wp_send_json_error( 'Nonce verification failed.' );
    }

    // Check user capabilities
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( 'You do not have permission to perform this action.' );
    }

    // Sanitize and validate input data from $_POST
    $order_id = absint( $_POST['order_id'] );
    $package_type = sanitize_text_field( $_POST['package_type'] );
    $flat_rate_box_size = sanitize_text_field( $_POST['flat_rate_box_size'] ); // Will be empty if package_type is 'custom'

    $length   = floatval( $_POST['length'] );
    $width    = floatval( $_POST['width'] );
    $height   = floatval( $_POST['height'] );
    $weight   = floatval( $_POST['weight'] );

    $carrier_selection_type = sanitize_text_field( $_POST['carrier_selection_type'] );
    $selected_carrier_ids = isset( $_POST['selected_carrier_ids'] ) ? array_map( 'sanitize_text_field', (array) $_POST['selected_carrier_ids'] ) : array();


    // Basic validation for dimensions/weight based on package type
    if ( 'custom' === $package_type && ( $length <= 0 || $width <= 0 || $height <= 0 || $weight <= 0 ) ) {
        wp_send_json_error( 'Invalid custom package dimensions or weight.' );
    } elseif ( 'flat_rate' === $package_type && empty( $flat_rate_box_size ) ) {
        wp_send_json_error( 'Please select a USPS Flat Rate Box size.' );
    }

    // Get the WooCommerce order object
    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        wp_send_json_error( 'Order not found.' );
    }

    // --- Retrieve "To Address" (Shipping Address from the Order) ---
    $to_address = array(
        'name'      => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
        'company'   => $order->get_shipping_company(),
        'street1'   => $order->get_shipping_address_1(),
        'street2'   => $order->get_shipping_address_2(),
        'city'      => $order->get_shipping_city(),
        'state'     => $order->get_shipping_state(),
        'zip'       => $order->get_shipping_postcode(),
        'country'   => $order->get_shipping_country(),
        'phone'     => $order->get_billing_phone(),
        'email'     => $order->get_billing_email(),
    );

    // --- Retrieve "From Address" (Store Origin Address) ---
    $from_address = array(
        'name'      => get_bloginfo( 'name' ),
        'company'   => get_bloginfo( 'name' ),
        'street1'   => WC()->countries->get_base_address(),
        'street2'   => WC()->countries->get_base_address_2(),
        'city'      => WC()->countries->get_base_city(),
        'state'     => WC()->countries->get_base_state(),
        'zip'       => WC()->countries->get_base_postcode(),
        'country'   => WC()->countries->get_base_country(),
        'phone'     => '',
        'email'     => get_option( 'admin_email' ),
    );

    // --- Prepare Parcel Data based on Package Type ---
    $parcel_data = array();
    if ( 'custom' === $package_type ) {
        $parcel_data = array(
            'length'        => $length,
            'width'         => $width,
            'height'        => $height,
            'distance_unit' => 'in',
            'weight'        => $weight,
            'mass_unit'     => 'oz',
        );
    } elseif ( 'flat_rate' === $package_type ) {
        // For flat rate boxes, Shippo uses the 'template' parameter.
        // The weight still needs to be provided, as it's used for eligibility checks.
        $parcel_data = array(
            'template'  => $flat_rate_box_size, // e.g., 'USPS_FLAT_RATE_BOX'
            'weight'    => $weight, // Max weight for flat rate is typically 70 lbs
            'mass_unit' => 'oz', // Shippo uses ounces for weight
            'distance_unit' => 'in', // Shippo uses inches for dimensions
        );
    }

    // --- Prepare Carrier Accounts for Shippo API ---
    $carrier_accounts_for_shippo = array();
    if ( 'all' === $carrier_selection_type ) {
        // In a real scenario, you'd fetch all your Shippo carrier account IDs and pass them here.
        // For simulation, we'll keep it empty, implying "all" to Shippo.
        $carrier_accounts_for_shippo = array(
            'eba8e1874e9a40a9a25b748958453793', //  ID 1 (e.g., USPS)
            '16608f80e5bf4b32b6788d9193573fc7', //  ID 2 (e.g., UPS)
            'a0c4f37cec214d4491043bd4a888a9e2', //  ID 3 (e.g., FedEx)
            '7e206c85e6354d9d9ed42ebf3c438839'  //  ID 4 (e.g., DHL)
        );

    } elseif ( 'custom' === $carrier_selection_type && ! empty( $selected_carrier_ids ) ) {
        $carrier_accounts_for_shippo = $selected_carrier_ids;

    } else {
        wp_send_json_error( 'Invalid carrier selection or no custom carriers selected.' );
    }


    // --- THIS IS WHERE YOU WOULD CALL THE ACTUAL SHIPPO API ---
    $shippo_api_key = 'shippo_test_ad5cfe31b5547e58b7dfe3bb0695b1ff81fe370a';
    \Shippo::setApiKey( $shippo_api_key );

    try {
        $shippo_shipment_payload = array(
            'address_from' => $from_address,
            'address_to'   => $to_address,
            'parcels'      => array( $parcel_data ),
            'async'        => false,
        );

        if ( ! empty( $carrier_accounts_for_shippo ) ) {
            $shippo_shipment_payload['carrier_accounts'] = $carrier_accounts_for_shippo;
        }

            // echo '<pre>';
            // echo 'Selected Carrier IDs: ';
            // print_r( $selected_carrier_ids );    
            // print_r( $shippo_shipment_payload );

            // echo '</pre>';

        $shipment = \Shippo_Shipment::create( $shippo_shipment_payload );



        if ( ! empty( $shipment->rates ) ) {
            $simulated_rates = array();

            foreach ( $shipment->rates as $rate ) {
                $all_simulated_carriers[] = array(
                    "carrier_name"      => $rate->provider,
                    "service_level_name"=> $rate['servicelevel']['name'],
                    "amount"            => floatval($rate->amount),
                    "currency"          => $rate->currency,
                    "estimated_days"    => isset($rate->estimated_days) ? intval($rate->estimated_days) : 0,
                    "object_id"         => $rate->object_id,
                );
            }

                // echo '<pre>';
                // print_r( $all_simulated_carriers );
                // echo '</pre>';
                // echo'>> $package_type ::: '. $package_type;
                // die();

             // Filter simulated rates based on package type and carrier selection
            $temp_rates = array();

            if ( 'flat_rate' === $package_type ) {
                // If flat rate, only provide USPS rates in simulation
               wp_send_json_success(  $all_simulated_carriers );

            } else { 
                // 'custom' package type
                if ( 'all' === $carrier_selection_type ) {
                    foreach ( $all_simulated_carriers as $id => $rate_data ) {
                        $temp_rates[] = $rate_data;
                    }
                } elseif ( 'custom' === $carrier_selection_type && ! empty( $selected_carrier_ids ) ) {
                    wp_send_json_success(  $all_simulated_carriers );
                }
            }

            if ( empty( $temp_rates ) ) {
                wp_send_json_error( 'No rates found for the selected package and carrier options.' );
            }

            wp_send_json_success( $temp_rates );

        } else {
            $error_messages = array();
            if ( ! empty( $shipment->messages ) ) {
                foreach ( $shipment->messages as $message ) {
                    $error_messages[] = $message->text;
                }
            }
            wp_send_json_error( 'Shippo API returned no rates. Messages: ' . ( ! empty( $error_messages ) ? implode(', ', $error_messages) : 'None' ) );
        }

        if ( empty( $all_simulated_carriers ) ) {
            wp_send_json_error( 'No rates found for the selected package and carrier options.' );
        }

    } catch ( \Shippo_Error $e ) {
        error_log( 'Shippo API Error (get_rates): ' . $e->getMessage() . ' - Code: ' . $e->getHttpStatus() . ' - JSON: ' . $e->getJsonBody() );
        wp_send_json_error( 'Shippo API Error: ' . $e->getMessage() );
    } catch ( \Exception $e ) {
        error_log( 'General Error (get_rates): ' . $e->getMessage() );
        wp_send_json_error( 'An unexpected error occurred while fetching rates.' );
    }
}
add_action( 'wp_ajax_wc_custom_shipping_get_shippo_rates', 'wc_custom_shipping_ajax_get_shippo_rates' );


// Handle AJAX request to create Shippo label
function wc_custom_shipping_ajax_create_shippo_label() {
    // Check nonce for security
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wc_shippo_shipping_ajax_nonce' ) ) {
        wp_send_json_error( 'Nonce verification failed.' );
    }

    // Check user capabilities
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( 'You do not have permission to perform this action.' );
    }

    // Sanitize and validate input data
    $order_id = absint( $_POST['order_id'] );
    $selected_rate_id = sanitize_text_field( $_POST['selected_rate_id'] );
    $carrier_name = sanitize_text_field( $_POST['carrier_name'] );
    $service_level_name = sanitize_text_field( $_POST['service_level_name'] );
    $amount = floatval( $_POST['amount'] );

    if ( $order_id <= 0 || empty( $selected_rate_id ) ) {
        wp_send_json_error( 'Invalid request for label creation.' );
    }

    // --- IMPORTANT: Set your Shippo API Key here before making the API call ---
    // Get this from your Shippo account. Consider storing this securely (e.g., in WP options, not hardcoded).
    $shippo_api_key = 'shippo_test_ad5cfe31b5547e58b7dfe3bb0695b1ff81fe370a'; // Replace with your actual Live Shippo API Key
    \Shippo::setApiKey( $shippo_api_key );

    try {
        // Purchase the desired rate.
        $transaction = \Shippo_Transaction::create(array(
            'rate' => $selected_rate_id,
            'async' => false, // Set to true if you want to handle webhooks for label completion
        ));

        // Check the status of the transaction
        if ( $transaction->status === 'SUCCESS' || $transaction->status === 'QUEUED' ) {
            // Save the tracking number and label URL to the order meta data.
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_shippo_tracking_number', $transaction->tracking_number );
                $order->update_meta_data( '_shippo_label_url', $transaction->label_url );
                $order->save();
            }

            wp_send_json_success( array(
                'status'         => $transaction->status,
                'tracking_number'=> $transaction->tracking_number,
                'label_url'      => $transaction->label_url,
                'messages'       => array( 'Label successfully created or queued.' ),
            ));


        } else {
            $error_messages = array();
            if ( ! empty( $transaction->messages ) ) {
                foreach ( $transaction->messages as $message ) {
                    $error_messages[] = $message->text;
                }
            }
            // Log the full Shippo error response for debugging
            error_log( 'Shippo Label Creation Failed for Order ' . $order_id . ': ' . print_r( $transaction, true ) );
            wp_send_json_error( 'Shippo API returned an error status: ' . ( ! empty( $error_messages ) ? implode(', ', $error_messages) : 'Unknown error' ) );
        }

    } catch ( \Shippo_Error $e ) {
        // Catch specific Shippo API errors
        error_log( 'Shippo API Error (create_label) for Order ' . $order_id . ': ' . $e->getMessage() . ' - Code: ' . $e->getHttpStatus() . ' - JSON: ' . $e->getJsonBody() );
        wp_send_json_error( 'Shippo API Error: ' . $e->getMessage() );
    } catch ( \Exception $e ) {
        // Catch any other unexpected PHP errors
        error_log( 'General Error (create_label) for Order ' . $order_id . ': ' . $e->getMessage() );
        wp_send_json_error( 'An unexpected error occurred while creating the label.' );
    }
}
add_action( 'wp_ajax_wc_custom_shipping_create_shippo_label', 'wc_custom_shipping_ajax_create_shippo_label' );


/**
 * Handle AJAX request to save custom packaging rules for a product.
 */
function wc_custom_shipping_ajax_save_packaging_rule() {
    // Check nonce for security
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'wc_shippo_shipping_ajax_nonce' ) ) {
        wp_send_json_error( 'Nonce verification failed.' );
    }

    // Check user capabilities (e.g., manage products or edit shop orders)
    if ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( 'You do not have permission to perform this action.' );
    }

    // Sanitize and validate input data
    $product_id = absint( $_POST['product_id'] );
    $quantity   = absint( $_POST['quantity'] );
    $length     = floatval( $_POST['length'] );
    $width      = floatval( $_POST['width'] );
    $height     = floatval( $_POST['height'] );
    $weight     = floatval( $_POST['weight'] );

    if ( $product_id <= 0 || $quantity <= 0 || $length <= 0 || $width <= 0 || $height <= 0 || $weight <= 0 ) {
        wp_send_json_error( 'Invalid product ID, quantity, or package dimensions.' );
    }

    // Get existing custom packaging rules for this product
    $existing_rules = get_post_meta( $product_id, '_wc_custom_packaging_rules', true );
    if ( ! is_array( $existing_rules ) ) {
        $existing_rules = array();
    }

    // Define a key for the rule based on quantity (e.g., '1', '1-5', '6-10')
    // For simplicity, let's just use the exact quantity for now, or a range if you implement that in JS.
    // A more robust solution would allow defining quantity ranges.
    $rule_key = (string) $quantity; // Using exact quantity as key for simplicity

    // Update or add the new rule
    $existing_rules[ $rule_key ] = array(
        'length' => $length,
        'width'  => $width,
        'height' => $height,
        'weight' => $weight,
    );

    // Save the updated rules back to the product meta
    update_post_meta( $product_id, '_wc_custom_packaging_rules', $existing_rules );

    wp_send_json_success( 'Packaging rule saved successfully for product ID ' . $product_id . ' and quantity ' . $quantity . '.' );
}
add_action( 'wp_ajax_wc_custom_shipping_save_packaging_rule', 'wc_custom_shipping_ajax_save_packaging_rule' );