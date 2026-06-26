<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize & Validate Input
    $name = htmlspecialchars(strip_tags(trim($_POST["name"])));
    $email = filter_var(trim($_POST["email"]), FILTER_SANITIZE_EMAIL);
    $message = htmlspecialchars(strip_tags(trim($_POST["message"])));

    // Check for empty fields
    if (empty($name) || empty($email) || empty($message)) {
        echo "<script>alert('All fields are required.'); window.history.back();</script>";
        exit;
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<script>alert('Invalid email format.'); window.history.back();</script>";
        exit;
    }

    // Email Setup
    $to = "visitacionredjanphils@gmail.com"; // Your email
    $subject = "New Contact Message from $name";

    $body = "Name: $name\n";
    $body .= "Email: $email\n\n";
    $body .= "Message:\n$message";

    $headers = "From: no-reply@yourdomain.com\r\n"; // Replace with your domain email
    $headers .= "Reply-To: $email\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    // Send Email
    if (@mail($to, $subject, $body, $headers)) {
        echo "<script>
                alert('Your message has been sent successfully! Redirecting...');
                setTimeout(function() { window.location.href = 'index.html'; }, 2000); 
              </script>";
    } else {
        error_log("Mail sending failed for $email to $to.");
        echo "<script>alert('Message sending failed. Please try again later.'); window.history.back();</script>";
    }
} else {
    echo "<script>alert('Invalid request.'); window.history.back();</script>";
}
?>
