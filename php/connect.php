<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $user_name = $_POST["name"];
    $user_email = $_POST["email"];
    $user_message = $_POST["message"];

    // Auto-reply message
    $subject = "Thank You for Contacting Us!";
    $reply_message = "Hi $user_name,\n\nThank you for reaching out. We received your message:\n\n\"$user_message\"\n\nWe will get back to you soon.\n\nBest regards,\nYour Team";

    // Email headers
    $headers = "From: support@yourdomain.com"; // Change to your support email

    // Send email to the user
    mail($user_email, $subject, $reply_message, $headers);

    // (Optional) Redirect after submission
    header("Location: thank-you.html");
    exit();
}
?>
