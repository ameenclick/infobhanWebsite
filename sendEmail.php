<?php

// Disable error display (log them instead in production)
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('error_log', 'error.log');

// Start output buffering to catch any stray output
ob_start();
header('Content-Type: application/json');

$credentialsFile = 'secret_config.php';

if (!file_exists($credentialsFile)) {
    error_log("AWS credentials file not found: $credentialsFile");
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error. Config missing']);
    exit;
}

require_once $credentialsFile;

// Check if secret key is defined
if (!defined('CAPTCHA_SECRET_KEY')) {
    error_log("CAPTCHA_SECRET_KEY not defined");
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error. Captach not defined']);
    exit;
}

$secretKey = defined('CAPTCHA_SECRET_KEY');

if (isset($_POST['g-recaptcha-response'])) {
    $captcha = $_POST['g-recaptcha-response'];
    // Verify the reCAPTCHA response
    $response = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$secretKey&response=$captcha");
    $responseKeys = json_decode($response, true);
} else {
    $responseKeys = ["success" => 0];
}

// Load AWS credentials from a file located OUTSIDE the web root directory

// Check if credentials are available
if (!defined('AWS_REGION') || !defined('AWS_ACCESS_KEY') || !defined('AWS_SECRET_KEY') || 
    !defined('SES_SENDER_EMAIL') || !defined('SES_RECIPIENT_EMAIL')) {
    error_log("AWS credentials not properly defined in config file");
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error. Config missing']);
    exit;
}

try {
    if(isset($_POST['name']) && isset($_POST['email']) && intval($responseKeys["success"]) === 1) {
        $name = htmlspecialchars($_POST['name']);
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $message = htmlspecialchars($_POST['message'] ?? '');
        $phone = htmlspecialchars($_POST['phone'] ?? '');
        
        // Define email subject
        $fullSubject = "Contact Form Submission from $name";
        
        // Create text version of the email
        $textEmailContent = "Name: $name\nEmail: $email\nPhone: $phone\nMessage: $message";
        
        // Generate HTML email content directly instead of reading from file
        $htmlEmailContent = getEmailTemplate($name, $email, $phone, $message);

        // Construct the request
        $host = 'email.' . AWS_REGION . '.amazonaws.com';
        $endpoint = 'https://' . $host;
        
        // Prepare the request payload
        $actionName = 'SendEmail';
        $payload = [
            'Action' => $actionName,
            'Version' => '2010-12-01',
            'Source' => SES_SENDER_EMAIL,
            'Destination.ToAddresses.member.1' => SES_RECIPIENT_EMAIL,
            'Message.Subject.Data' => $fullSubject,
            'Message.Subject.Charset' => 'UTF-8',
            'Message.Body.Text.Data' => $textEmailContent,
            'Message.Body.Text.Charset' => 'UTF-8',
            'Message.Body.Html.Data' => $htmlEmailContent,
            'Message.Body.Html.Charset' => 'UTF-8',
            'ReplyToAddresses.member.1' => $email
        ];
        
        // Create the query string
        $queryString = http_build_query($payload);
        
        // Create the signature (AWS Signature Version 4)
        $date = gmdate('Ymd\THis\Z');
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = gmdate('Ymd') . '/' . AWS_REGION . '/ses/aws4_request';
        $signed_headers = 'content-type;host;x-amz-date';
        
        // Step 1: Create canonical request
        $canonical_headers = "content-type:application/x-www-form-urlencoded\nhost:" . $host . "\nx-amz-date:" . $date . "\n";
        $canonical_request = "POST\n/\n\n" . $canonical_headers . "\n" . $signed_headers . "\n" . hash('sha256', $queryString);
        
        // Step 2: Create string to sign
        $string_to_sign = $algorithm . "\n" . $date . "\n" . $credential_scope . "\n" . hash('sha256', $canonical_request);
        
        // Step 3: Calculate signature
        $kDate = hash_hmac('sha256', gmdate('Ymd'), 'AWS4' . AWS_SECRET_KEY, true);
        $kRegion = hash_hmac('sha256', AWS_REGION, $kDate, true);
        $kService = hash_hmac('sha256', 'ses', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);
        
        // Step 4: Create authorization header
        $authorization = $algorithm . ' ' . 
                        'Credential=' . AWS_ACCESS_KEY . '/' . $credential_scope . ', ' .
                        'SignedHeaders=' . $signed_headers . ', ' .
                        'Signature=' . $signature;
        
        // Prepare the cURL request
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'Host: ' . $host,
            'X-Amz-Date: ' . $date,
            'Authorization: ' . $authorization
        ]);
    
        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Check for errors
        if ($curlError) {
            throw new Exception("cURL Error: $curlError");
        }
        
        if ($httpCode !== 200) {
            error_log("AWS SES Error Response: $response");
            if (strpos($response, '<Code>') !== false) {
                preg_match('/<Code>(.*?)<\/Code>/', $response, $errorCode);
                preg_match('/<Message>(.*?)<\/Message>/', $response, $errorMessage);
                $awsErrorCode = isset($errorCode[1]) ? $errorCode[1] : 'Unknown';
                $awsErrorMessage = isset($errorMessage[1]) ? $errorMessage[1] : 'Unknown error';
                error_log("AWS Error Code: $awsErrorCode, Message: $awsErrorMessage");
            }
            throw new Exception('Failed to send email. HTTP Code: ' . $httpCode);
        }
        
        // Parse message ID if needed
        preg_match('/<MessageId>(.*?)<\/MessageId>/', $response, $messageId);
        $emailMessageId = isset($messageId[1]) ? $messageId[1] : '';
        
        // Clean output buffer and send JSON response (not an object)
        ob_clean();
        echo json_encode([
            'status' => 'success', 
            'message' => 'Thank you! Your message has been sent.',
            'messageId' => $emailMessageId
        ]);
        exit;
    } else {
        // Required POST parameters are missing or reCAPTCHA failed
        ob_clean();
        echo json_encode([
            'status' => 'error',
            'message' => isset($_POST['name']) && isset($_POST['email']) 
                ? 'reCAPTCHA verification failed. Please try again.' 
                : 'Missing or invalid form data.'
        ]);
        exit;
    }
} catch (Exception $e) {
    // Log the error message
    error_log("Error: " . $e->getMessage());
    
    // Return error response
    ob_clean();
    echo json_encode([
        'status' => 'error', 
        'message' => 'An error occurred while sending your message. Please try again later.'
    ]);
    exit;
} finally {
    // Clean output buffer and send the response if somehow we got here
    if (ob_get_length()) {
        ob_end_flush();
    }
}

/**
 * Generate the HTML email template with the form data
 * 
 * @param string $name Sender's name
 * @param string $email Sender's email
 * @param string $phone Sender's phone
 * @param string $message Sender's message
 * @return string Complete HTML email content
 */
function getEmailTemplate($name, $email, $phone, $message) {
    // Convert newlines to <br> tags for HTML display
    $messageHtml = nl2br($message);
    
    return '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Infobhan Email</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0px 3px 10px rgba(0, 0, 0, 0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header img {
            max-width: 250px;
            height: auto;
        }
        .content {
            padding: 20px;
            border-top: 2px solid rgba(157, 114, 19, 0.4);
            border-bottom: 2px solid rgba(157, 114, 19, 0.4);
            margin-bottom: 20px;
        }
        .content h2 {
            color: #333333;
            font-size: 24px;
            margin-bottom: 10px;
        }
        .content p {
            color: #777777;
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #9d7213;
            color: #ffffff;
            text-decoration: none;
            border-radius: 5px;
        }
        .footer {
            text-align: center;
            margin-top: 20px;
            color: #888888;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Customer Enquiry</h1>
        </div>
        <div class="content">
            <p>Hi, <br/>'.htmlspecialchars($name).' visited your website infobhan.net</p>
            <p>Email:'.htmlspecialchars($email).'</p>
            <p>Phone: '.htmlspecialchars($phone).'</p>
            <p>Message: <br/>'.htmlspecialchars($messageHtml).'</p>
        </div>
        <div class="footer">
            <p>You received this email from the website.</p>
            <p>&copy; 2023 Infobhan Systems. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
}
?>