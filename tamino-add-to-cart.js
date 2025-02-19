jQuery(document).ready(function($) {
    $('#tamino-add-to-cart-btn').on('click', function(e) {
        e.preventDefault(); // Prevent the default hyperlink behavior

        // Get the URL from the href attribute of the link
        let addToCartUrl = $(this).attr('href');

        // Capture the number of adults and children
        let adults = $('#adults').val();
        let children = $('#children').val();

        // Send the AJAX request to add the product to the cart
        $.ajax({
            url: addToCartUrl,
            type: 'GET',
            data: {
                adults: adults,   // Send the number of adults
                children: children // Send the number of children
            },
            success: function(response) {
                // Display a success message with the selected values
                alert('Product added to cart successfully!\n' +
                      'Adults: ' + adults + '\n' +
                      'Children: ' + children);
                // Optionally, you could also update a cart count or refresh the cart display here
            },
            error: function() {
                alert('Failed to add product to cart.');
            }
        });
    });
});
