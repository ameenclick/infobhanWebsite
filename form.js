// script.js
$(document).ready(function () {
    // jQuery for handling the contact form submission
    $("#contactForm").submit(function (e) {
        e.preventDefault(); // Prevent the default form submission

        const captchaResponse = grecaptcha.getResponse();

        if(!captchaResponse.length > 0){
            $("#responseAlert").html('<div class="alert alert-danger">Please complete the captcha.</div>');
            throw new Error("Captcha not complete");
        }
        else
        {
            $("#responseAlert").html('<div class="alert alert-info">Submiting...</div>');
        }

        // Disable submit button
        $("#submit").prop('disabled', true);

        // Store a reference to the form
        var $form = $(this);

        // Perform AJAX request to send form data to sendEmail.php
        $.ajax({
            type: "POST",
            url: "sendEmail.php",
            data: $form.serialize(), // Serialize the form data
            success: function (response) {
                // Handle the response here (e.g., show a success message)
                var status = JSON.parse(response).status
                if (status === "success") {
                    // Enable submit button
                    $("#submit").prop('disabled', false);
                    $("#responseAlert").html('<div class="alert alert-success">Thank you for visiting our website; we will reach out soon.</div>');
                } else {
                    console.log(response)
                    $("#responseAlert").html('<div class="alert alert-danger">Failed to communicate. Please try again later.</div>');
                }
            },
            error: function () {
                // Handle errors here
                $("#responseAlert").html('<div class="alert alert-danger">An error occurred. Please try again later.</div>');
            }
        });
    });
});
