jQuery(document).ready(function($) {
    $('#wescraper-form').on('submit', function(e) {
        e.preventDefault();
        
        var $button = $(this).find('button[type="submit"]');
        var originalText = $button.text();
        
        $button.prop('disabled', true)
               .text('Processing...');
        
        $.ajax({
            url: $(this).attr('action'),
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data.message);
                }
            },
            error: function() {
                alert('An error occurred while processing the request.');
            },
            complete: function() {
                $button.prop('disabled', false)
                       .text(originalText);
            }
        });
    });
}); 