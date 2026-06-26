<?php 
 use PHPMailer\PHPMailer\PHPMailer;
 use PHPMailer\PHPMailer\Exception;

 require_once __DIR__ . '/mailer_config.php';

 if(isset($_POST["send"])){
    $err = null;
    $mail = new_configured_mailer($err);
    if ($mail === null) {
        echo "<script>alert('Mailer error: " . addslashes($err) . "');</script>";
        exit;
    }
    $mail->addAddress($_POST["email"]);
    $mail->Subject = $_POST["subject"];
    $mail->Body = $_POST["message"];
    try {
        $mail->send();
        echo "<script>alert('Sent Successfully!');document.location.href='index.php';</script>";
    } catch (Exception $e) {
        echo "<script>alert('Send failed: " . addslashes($e->getMessage()) . "');</script>";
    }
 }
  
?>