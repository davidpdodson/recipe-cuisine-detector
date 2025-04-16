jQuery(document).ready(function($) {
    $('#detect-cuisine-btn').on('click', function() {
        const button = $(this);
        button.prop('disabled', true).text('Detecting...');
        $('#cuisine-response').text('');

        $.post(recipeCuisine.ajax_url, {
            action: 'detect_recipe_cuisine',
            nonce: recipeCuisine.nonce,
            post_id: recipeCuisine.post_id
        }, function(response) {
            if(response.success) {
                $('#cuisine-response').text('Cuisine: ' + response.data);
            } else {
                $('#cuisine-response').text('Error: ' + response.data);
            }
            button.prop('disabled', false).text('Detect Cuisine');
        });
    });
});
