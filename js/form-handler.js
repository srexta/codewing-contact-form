jQuery(document).ready(function($) {
    $('.codewing-contact-form').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $message = $form.siblings('.codewing-form-message');
        const $submit = $form.find('button[type="submit"]');
        
        // Disable submit button
        $submit.prop('disabled', true);
        
        // Clear previous messages
        $message.hide().removeClass('success error');
        
        // Collect form data
        const formData = new FormData(this);
        formData.append('action', 'codewing_contact_form');
        
        // Send AJAX request
        $.ajax({
            url: ajax_object.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $message.addClass('success').html(response.data).fadeIn();
                    $form[0].reset();
                } else {
                    $message.addClass('error').html(response.data).fadeIn();
                }
            },
            error: function() {
                $message.addClass('error')
                    .html('An error occurred. Please try again later.')
                    .fadeIn();
            },
            complete: function() {
                $submit.prop('disabled', false);
            }
        });
    });
});