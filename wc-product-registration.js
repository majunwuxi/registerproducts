jQuery(document).ready(function($) {
    $('#product-registration-form').on('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        formData.append('action', 'validate_serial');
        formData.append('nonce', wc_product_registration.nonce);

        $('#registration-result').html('<p>Validating serial number...</p>');

        $.ajax({
            url: wc_product_registration.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                console.log('Validation response:', response);
                if (response.success) {
                    var product = response.data.product;
                    var confirmHtml = '<h3>' + product.name + '</h3>' +
                                      '<img src="' + product.image + '" alt="' + product.name + '" style="max-width: 200px;">' +
                                      '<p><a href="' + product.url + '" target="_blank">View Product</a></p>' +
                                      '<p>Is this the correct product?</p>' +
                                      '<button id="confirm-registration">Yes, Register this Product</button>';
                    $('#registration-result').html(confirmHtml);

                    $('#confirm-registration').on('click', function() {
                        formData.append('action', 'register_product');
                        formData.append('product_id', product.id);

                        $('#registration-result').html('<p>Registering product...</p>');

                        $.ajax({
                            url: wc_product_registration.ajax_url,
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function(reg_response) {
                                console.log('Registration response:', reg_response);
                                if (reg_response.success) {
                                    $('#registration-result').html('<p>' + reg_response.data.message + '</p>');
                                    // Refresh the registered products list if it exists on the page
                                    if ($('.user-registered-products').length) {
                                        location.reload();
                                    }
                                } else {
                                    $('#registration-result').html('<p>Error: ' + reg_response.data.message + '</p>');
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Registration error:', error);
                                $('#registration-result').html('<p>Error: Unable to register product. Please try again.</p>');
                            }
                        });
                    });
                } else {
                    $('#registration-result').html('<p>Error: ' + response.data.message + '</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Validation error:', error);
                $('#registration-result').html('<p>Error: Unable to validate serial number. Please try again.</p>');
            }
        });
    });

    // Function to refresh the registered products list
    function refreshRegisteredProducts() {
        if ($('.user-registered-products').length) {
            $.ajax({
                url: wc_product_registration.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_user_registered_products',
                    nonce: wc_product_registration.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('.user-registered-products').html(response.data.html);
                    } else {
                        console.error('Failed to refresh registered products:', response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error refreshing registered products:', error);
                }
            });
        }
    }

    // Refresh the registered products list when the page loads
    refreshRegisteredProducts();
});