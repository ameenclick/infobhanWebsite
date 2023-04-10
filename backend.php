<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$root_path = dirname($_SERVER['DOCUMENT_ROOT'] . $_SERVER['PHP_SELF']);

//Load Composer's autoloader
require $root_path.'/PHPMailer/src/Exception.php';
require $root_path.'/PHPMailer/src/PHPMailer.php';
require $root_path.'/PHPMailer/src/SMTP.php';


//Create an instance; passing `true` enables exceptions
ob_start();
$mail = new PHPMailer(true);
$email= $_POST['email'];
$name= $_POST['name'];
$subject = $_POST['subject'];
$phone=$_POST['phone'];
$body= $_POST['body'];

try {
    //Server settings
    $env = parse_ini_file('.env');
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;                      //Enable verbose debug output
    $mail->isSMTP();  
    $mail->Mailer = "smtp";                                          //Send using SMTP
    $mail->Host       = $env["EMAIL_HOST"];                     //Set the SMTP server to send through
    $mail->SMTPAuth   = true;     
    $mail->SMTPSecure = "tls";                              //Enable SMTP authentication
    $mail->Username   = $env["EMAIL_USERNAME"];                     //SMTP username
    $mail->Password   = $env["EMAIL_PASSWORD"];                               //SMTP password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;            //Enable implicit TLS encryption
    $mail->Port       = 587;                                    //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

    //Recipients
    $mail->setFrom("website@infobhan.net");
    $mail->addAddress('info@infobhan.net', 'Infobhan Systems');     //Add a recipient
    $mail->addCC('abdulsalam@infobhan.net');
    $mail->addBCC('developer@infobhan.net');

    //Content
    $mail->isHTML(true);                                  //Set email format to HTML
    $mail->Subject = $subject;
    $mail->Body    ="<br/> Name: <b>".$name."</b><br/> Email: <b>".$email."</b><br/>".$body;
    $mail->AltBody = $body;

    $mail->send();
    ob_end_clean();
    // Get the root URL dynamically
    $filename = basename($_SERVER['PHP_SELF']);
    $request_uri = str_replace($filename, '', $_SERVER['REQUEST_URI']);
    $root_url = "http" . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "s" : "") . "://";
    $root_url .= $_SERVER['HTTP_HOST'] . $request_uri;

    // Construct the new page URL
    $new_page_url = $root_url."success.html";

    header("Location: $new_page_url");
} catch (Exception $e) {
    echo "Message could not be sent.";
    //echo "\n Mailer Error: {$mail->ErrorInfo}";
}

?>