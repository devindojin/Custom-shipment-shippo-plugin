/**
 * 5. Create the JavaScript file (js/admin-shippo-script.js)
 * This file would contain the JavaScript logic from the previous immersive,
 * adapted to make AJAX calls to the PHP handlers defined above.
 *
 * Ensure you create a 'js' folder in the same directory as this PHP file
 * and place the JavaScript code there.
 */
/*
// Example content for js/admin-shippo-script.js*/

jQuery(document).ready(function($) {
    // Simulated Product Packaging Rules (fallback if no custom rules are saved)
    // In a real plugin, these might be global defaults or fetched from another source.
    // The key here is that these are *fallbacks* if no specific rule is found.
    const defaultProductPackagingRules = {
        'default': { length: 10, width: 8, height: 6, weight: 2 }, // General fallback
        // You could add more specific default rules here if needed, e.g.:
        // 'product_type_book': { '1': {l:8,w:6,h:1,w:1}, '5': {l:10,w:8,h:5,w:5}, 'default': {l:12,w:10,h:8,w:8} }
    };

    // DOM Elements
    const productSelect = $('#wc_shippo_product_select');
    const quantityInput = $('#wc_shippo_quantity');
    const packageTypeSelect = $('#wc_shippo_package_type'); // New
    const flatRateBoxOptions = $('#wc_shippo_flat_rate_box_options'); // New
    const flatRateBoxSizeSelect = $('#wc_shippo_flat_rate_box_size'); // New
    const customDimensionsFields = $('#wc_shippo_custom_dimensions_fields'); // New
    const packageLengthInput = $('#wc_shippo_package_length');
    const packageWidthInput = $('#wc_shippo_package_width');
    const packageHeightInput = $('#wc_shippo_package_height');
    const packageWeightInput = $('#wc_shippo_package_weight');
    const getRatesBtn = $('#wc_shippo_get_rates_btn');
    const resetPackageBtn = $('#wc_shippo_reset_package_btn');
    const savePackagingBtn = $('#wc_shippo_save_packaging_btn');
    const ratesDisplay = $('#wc_shippo_rates_display');
    const ratesList = $('#wc_shippo_rates_list');
    const createLabelBtn = $('#wc_shippo_create_label_btn');
    const messageBox = $('#wc_shippo_message_box');

    // Carrier selection elements
    const carrierSelectionType = $('#wc_shippo_carrier_selection_type');
    const customCarriersOptions = $('#wc_shippo_custom_carriers_options');
    const customCarrierCheckboxes = $('input[name="wc_shippo_selected_carriers[]"]');


    let selectedRate = null; // To store the currently selected shipping rate

    // Helper for loading spinner
    function toggleLoadingSpinner(button, show) {
        if (show) {
            button.prop('disabled', true).append('<span class="loading-spinner ml-2"></span>');
        } else {
            button.prop('disabled', false).find('.loading-spinner').remove();
        }
    }

    function showMessage(message, type) {
        messageBox.html(message);
        messageBox.removeClass('hidden message-success message-error');
        if (type === 'success') {
            messageBox.addClass('message-success');
        } else {
            messageBox.addClass('message-error');
        }
        messageBox.show();
    }

    function hideMessage() {
        messageBox.hide();
    }

    /**
     * Toggles visibility and read-only state of dimension fields based on package type.
     * @param {string} type 'custom' or 'flat_rate'
     */
    function toggleDimensionFields(type) {
        if (type === 'flat_rate') {
            customDimensionsFields.hide();
            flatRateBoxOptions.show();
            // Make dimension inputs read-only when flat rate is selected
            packageLengthInput.prop('readonly', true);
            packageWidthInput.prop('readonly', true);
            packageHeightInput.prop('readonly', true);
            // Weight can still be adjusted within flat rate limits, so not read-only
            packageWeightInput.prop('readonly', false);
            savePackagingBtn.prop('disabled', true); // Cannot save flat rate dimensions
            resetPackageBtn.prop('disabled', true); // Reset is not applicable for fixed dimensions
        } else { // 'custom'
            customDimensionsFields.show();
            flatRateBoxOptions.hide();
            packageLengthInput.prop('readonly', false);
            packageWidthInput.prop('readonly', false);
            packageHeightInput.prop('readonly', false);
            packageWeightInput.prop('readonly', false);
            savePackagingBtn.prop('disabled', false);
            resetPackageBtn.prop('disabled', false);
        }
        // Clear rates and disable label button when package type changes
        ratesList.empty();
        ratesDisplay.hide();
        createLabelBtn.prop('disabled', true);
        selectedRate = null;
    }

    /**
     * Updates the package dimensions and weight inputs.
     * Prioritizes:
     * 1. Selected Flat Rate Box dimensions (if package type is flat_rate).
     * 2. WooCommerce product's native dimensions (if available and not 0, for custom type).
     * 3. Custom saved packaging rules for the specific product ID and quantity (for custom type).
     * 4. Fallback to default packaging rules (if defined, for custom type).
     * 5. Leaves fields empty if no rules apply.
     */
    function updatePackageDetails() {
        hideMessage();
        const selectedProductId = productSelect.val();
        let quantity = parseInt(quantityInput.val(), 10);
        const packageType = packageTypeSelect.val();

        // Set quantity input to the quantity of the selected order item
        const selectedOption = productSelect.find('option:selected');
        const itemQuantity = parseInt(selectedOption.data('qty'), 10);
        if (!isNaN(itemQuantity) && itemQuantity > 0) {
            quantityInput.val(itemQuantity);
            quantity = itemQuantity; // Update quantity variable as well
        } else {
            // If no item quantity, ensure quantity is at least 1
            if (isNaN(quantity) || quantity < 1) {
                quantityInput.val(1);
                quantity = 1;
            }
        }

        let dimensions = { length: '', width: '', height: '', weight: '' }; // Start empty

        if (packageType === 'flat_rate') {
            const selectedTemplate = flatRateBoxSizeSelect.val();
            if (selectedTemplate && wcShippoParams.usps_flat_rate_templates[selectedTemplate]) {
                const templateData = wcShippoParams.usps_flat_rate_templates[selectedTemplate];
                dimensions = {
                    length: templateData.length,
                    width: templateData.width,
                    height: templateData.height,
                    weight: templateData.weight // Max weight for the box
                };
            }
        } else { // 'custom' package type
            // 1. Try to get WooCommerce product's native dimensions
            if (wcShippoParams.product_data && wcShippoParams.product_data[selectedProductId]) {
                const productInfo = wcShippoParams.product_data[selectedProductId];
                if (parseFloat(productInfo.length) > 0 && parseFloat(productInfo.width) > 0 &&
                    parseFloat(productInfo.height) > 0 && parseFloat(productInfo.weight) > 0) {
                    dimensions = {
                        length: parseFloat(productInfo.length),
                        width: parseFloat(productInfo.width),
                        height: parseFloat(productInfo.height),
                        weight: parseFloat(productInfo.weight)
                    };
                }

                // 2. Check for custom saved packaging rules for this product and quantity
                const customRules = productInfo.custom_packaging_rules;
                if (customRules && customRules[quantity]) { // Check for exact quantity match
                    const savedDims = customRules[quantity];
                    if (parseFloat(savedDims.length) > 0 && parseFloat(savedDims.width) > 0 &&
                        parseFloat(savedDims.height) > 0 && parseFloat(savedDims.weight) > 0) {
                        dimensions = savedDims; // Override with custom saved rule
                    }
                }
                // Add logic here for quantity *ranges* if your saving mechanism supports it
                // For example, iterate through customRules keys like '1-5', '6-10'
            }

            // 3. Fallback to default packaging rules (if defined)
            if (dimensions.length === '' && defaultProductPackagingRules.default) {
                dimensions = defaultProductPackagingRules.default;
            }
        }

        packageLengthInput.val(dimensions.length);
        packageWidthInput.val(dimensions.width);
        packageHeightInput.val(dimensions.height);
        packageWeightInput.val(dimensions.weight);

        ratesList.empty();
        ratesDisplay.hide();
        createLabelBtn.prop('disabled', true);
        selectedRate = null;
    }

    function resetPackageDimensions() {
        // This will trigger updatePackageDetails which will reload the initial values
        updatePackageDetails();
        showMessage('Package dimensions reset to default for selected product/quantity.', 'success');
    }

    async function savePackagingRule() {
        hideMessage();
        toggleLoadingSpinner(savePackagingBtn, true);

        const productId = productSelect.val();
        const quantity = parseInt(quantityInput.val(), 10);
        const length = parseFloat(packageLengthInput.val());
        const width = parseFloat(packageWidthInput.val());
        const height = parseFloat(packageHeightInput.val());
        const weight = parseFloat(packageWeightInput.val());

        // Ensure package type is 'custom' for saving
        if (packageTypeSelect.val() !== 'custom') {
            showMessage('Packaging rules can only be saved for "Custom Package" type.', 'error');
            toggleLoadingSpinner(savePackagingBtn, false);
            return;
        }

        if (isNaN(productId) || productId <= 0 || isNaN(quantity) || quantity <= 0 ||
            isNaN(length) || isNaN(width) || isNaN(height) || isNaN(weight) ||
            length <= 0 || width <= 0 || height <= 0 || weight <= 0) {
            showMessage('Please ensure a product is selected, quantity is valid, and all package dimensions/weight are positive numbers before saving.', 'error');
            toggleLoadingSpinner(savePackagingBtn, false);
            return;
        }

        try {
            const response = await $.ajax({
                url: wcShippoParams.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'wc_custom_shipping_save_packaging_rule',
                    nonce: wcShippoParams.nonce,
                    product_id: productId,
                    quantity: quantity,
                    length: length,
                    width: width,
                    height: height,
                    weight: weight
                }
            });

            if (response.success) {
                showMessage(response.data, 'success');
                // Update the localized product_data to reflect the newly saved rule
                if (!wcShippoParams.product_data[productId]) {
                    wcShippoParams.product_data[productId] = { custom_packaging_rules: {} };
                }
                if (!wcShippoParams.product_data[productId].custom_packaging_rules) {
                    wcShippoParams.product_data[productId].custom_packaging_rules = {};
                }
                wcShippoParams.product_data[productId].custom_packaging_rules[quantity] = { length, width, height, weight };

            } else {
                showMessage(`Failed to save packaging rule: ${response.data}`, 'error');
            }
        } catch (error) {
            console.error('Error saving packaging rule:', error);
            showMessage('An error occurred while saving the packaging rule. Please try again.', 'error');
        } finally {
            toggleLoadingSpinner(savePackagingBtn, false);
        }
    }


    async function getShippoRates() {
        hideMessage();
        ratesList.empty();
        ratesDisplay.hide();
        createLabelBtn.prop('disabled', true);
        selectedRate = null;
        toggleLoadingSpinner(getRatesBtn, true);

        const length = parseFloat(packageLengthInput.val());
        const width = parseFloat(packageWidthInput.val());
        const height = parseFloat(packageHeightInput.val());
        const weight = parseFloat(packageWeightInput.val());
        const packageType = packageTypeSelect.val(); // Get selected package type
        const flatRateBoxSize = flatRateBoxSizeSelect.val(); // Get selected flat rate box size

        const carrierSelection = carrierSelectionType.val();
        let selectedCarrierIds = [];

        if (carrierSelection === 'custom') {
            customCarrierCheckboxes.each(function() {
                if ($(this).is(':checked')) {
                    selectedCarrierIds.push($(this).val());
                }
            });
            if (selectedCarrierIds.length === 0) {
                showMessage('Please select at least one custom carrier, or choose "All Available Carriers".', 'error');
                toggleLoadingSpinner(getRatesBtn, false);
                return;
            }
        }

        // Validate dimensions/weight based on package type
        if (packageType === 'custom') {
            if (isNaN(length) || isNaN(width) || isNaN(height) || isNaN(weight) || length <= 0 || width <= 0 || height <= 0 || weight <= 0) {
                showMessage('Please enter valid positive custom package dimensions and weight.', 'error');
                toggleLoadingSpinner(getRatesBtn, false);
                return;
            }
        } else if (packageType === 'flat_rate') {
            if (!flatRateBoxSize) {
                showMessage('Please select a USPS Flat Rate Box size.', 'error');
                toggleLoadingSpinner(getRatesBtn, false);
                return;
            }
            if (isNaN(weight) || weight <= 0) { // Weight is still required for flat rate
                showMessage('Please enter a valid positive weight for the Flat Rate Box.', 'error');
                toggleLoadingSpinner(getRatesBtn, false);
                return;
            }
        }


        try {
            const response = await $.ajax({
                url: wcShippoParams.ajax_url,
                type: 'POST',
                dataType: 'json', // Explicitly set dataType to json
                data: {
                    action: 'wc_custom_shipping_get_shippo_rates', // PHP AJAX action
                    nonce: wcShippoParams.nonce,
                    order_id: wcShippoParams.order_id,
                    length: length,
                    width: width,
                    height: height,
                    weight: weight,
                    package_type: packageType, // Pass package type
                    flat_rate_box_size: flatRateBoxSize, // Pass flat rate box size
                    carrier_selection_type: carrierSelection, // Pass carrier selection type
                    selected_carrier_ids: selectedCarrierIds // Pass selected custom carrier IDs
                }
            });

            if (response.success) {
                const rates = response.data;
                if (rates.length > 0) {
                    rates.forEach(rate => {
                        const rateOptionDiv = $('<div>')
                            .addClass('rate-option p-3 mb-2 border rounded-lg flex justify-between items-center cursor-pointer')
                            .data('object-id', rate.object_id)
                            .data('carrier-name', rate.carrier_name)
                            .data('service-level-name', rate.service_level_name)
                            .html(`
                                <div>
                                    <p class="font-medium text-gray-900">${rate.carrier_name} - ${rate.service_level_name}</p>
                                    <p class="text-sm text-gray-600">Est. delivery: ${rate.estimated_days} days</p>
                                </div>
                                <span class="font-semibold text-lg text-indigo-700">$${parseFloat(rate.amount).toFixed(2)} ${rate.currency}</span>
                            `);
                        rateOptionDiv.on('click', function() {
                            $('.rate-option').removeClass('selected');
                            $(this).addClass('selected');
                            selectedRate = rate;
                            createLabelBtn.prop('disabled', false);
                        });
                        ratesList.append(rateOptionDiv);
                    });
                    ratesDisplay.show();
                } else {
                    showMessage('No shipping rates found for the selected options.', 'error');
                }
            } else {
                showMessage(`Failed to get rates: ${response.data}`, 'error');
            }
        } catch (error) {
            console.error('Error fetching rates:', error);
            showMessage('An error occurred while fetching shipping rates. Please try again.', 'error');
        } finally {
            toggleLoadingSpinner(getRatesBtn, false);
        }
    }

    async function createShippingLabel() {
        hideMessage();
        if (!selectedRate) {
            showMessage('Please select a shipping option first.', 'error');
            return;
        }

        toggleLoadingSpinner(createLabelBtn, true);

        try {
            const response = await $.ajax({
                url: wcShippoParams.ajax_url,
                type: 'POST',
                dataType: 'json', // Explicitly set dataType to json
                data: {
                    action: 'wc_custom_shipping_create_shippo_label', // PHP AJAX action
                    nonce: wcShippoParams.nonce,
                    order_id: wcShippoParams.order_id,
                    selected_rate_id: selectedRate.object_id,
                    carrier_name: selectedRate.carrier_name,
                    service_level_name: selectedRate.service_level_name,
                    amount: selectedRate.amount
                }
            });

            if (response.success) {
                const transaction = response.data;
                if (transaction.status === 'SUCCESS' || transaction.status === 'QUEUED') {
                    showMessage(`Label created successfully! Tracking: ${transaction.tracking_number}. <a href="${transaction.label_url}" target="_blank" class="text-blue-600 underline">Download Label</a>`, 'success');
                } else {
                    showMessage(`Failed to create label: ${transaction.messages.join(', ')}`, 'error');
                }
            } else {
                showMessage(`Failed to create label: ${response.data}`, 'error');
            }
        } catch (error) {
            console.error('Error creating label:', error);
            showMessage('An error occurred while creating the shipping label. Please try again.', 'error');
        } finally {
            toggleLoadingSpinner(createLabelBtn, false);
        }
    }

    // Event Listeners
    productSelect.on('change', updatePackageDetails);
    quantityInput.on('input', updatePackageDetails);
    packageTypeSelect.on('change', function() {
        toggleDimensionFields($(this).val());
        updatePackageDetails(); // Update dimensions based on new package type
    });
    flatRateBoxSizeSelect.on('change', updatePackageDetails); // Update dimensions when flat rate box size changes
    getRatesBtn.on('click', getShippoRates);
    resetPackageBtn.on('click', resetPackageDimensions);
    savePackagingBtn.on('click', savePackagingRule);
    createLabelBtn.on('click', createShippingLabel);

    // Carrier selection logic
    carrierSelectionType.on('change', function() {
        if ($(this).val() === 'custom') {
            customCarriersOptions.slideDown();
        } else {
            customCarriersOptions.slideUp();
        }
        // Clear rates and disable label button when carrier selection changes
        ratesList.empty();
        ratesDisplay.hide();
        createLabelBtn.prop('disabled', true);
        selectedRate = null;
    });

    // Initial load
    updatePackageDetails();
    toggleDimensionFields(packageTypeSelect.val()); // Set initial state for dimension fields
    carrierSelectionType.trigger('change'); // Trigger initial state for carrier selection
});