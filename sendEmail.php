<?php

// Disable error display (log them instead in production)
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('error_log', 'error.log'); // Will log to the same directory as the script

// Start output buffering to catch any stray output
ob_start();
header('Content-Type: application/json');

$secretKey = defined('CAPTCHA_SECRET_KEY'); // Default key for testing

$captcha = $_POST['g-recaptcha-response'];
// Verify the reCAPTCHA response
$response = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$secretKey&response=$captcha");
$responseKeys = json_decode($response, true);

// Debug the response
error_log('reCAPTCHA response: ' . print_r($responseData, true));

if(!$responseData->success) {
    // Captcha verification failed
    echo json_encode(['status' => 'error', 'message' => 'Captcha verification failed. Please try again.']);
    exit;
}

// Load AWS credentials from a file located OUTSIDE the web root directory
// Assuming your web root is something like /home/username/public_html/
// And this file is placed at /home/username/aws_config.php
$credentialsFile = 'secret_config.php';

if (!file_exists($credentialsFile)) {
    error_log("AWS credentials file not found: $credentialsFile");
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error.']);
    exit;
}

require_once $credentialsFile;

// Check if credentials are available
if (!defined('AWS_REGION') || !defined('AWS_ACCESS_KEY') || !defined('AWS_SECRET_KEY') || 
    !defined('SES_SENDER_EMAIL') || !defined('SES_RECIPIENT_EMAIL')) {
    error_log("AWS credentials not properly defined in config file");
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Server configuration error.']);
    exit;
}

try{

    if(isset($_POST['name']) && isset($_POST['email']) && intval($responseKeys["success"]) === 1){
        $name = $_POST['name'];
        $email = $_POST['email'];
        $message = $_POST['message'];
        $phone = $_POST['phone'];

        // Check if credentials are available
        if (!defined('AWS_REGION') || !defined('AWS_ACCESS_KEY') || !defined('AWS_SECRET_KEY') || 
            !defined('SES_SENDER_EMAIL') || !defined('SES_RECIPIENT_EMAIL')) {
            error_log("AWS credentials not properly defined in config file");
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Server configuration error.']);
            exit;
        }
        
        $email_template = file_get_contents('email_template.html');
        $email_template = str_replace('$name', $name, $email_template);
        $email_template = str_replace('$email', $email, $email_template);
        $email_template = str_replace('$phone', $phone, $email_template);
        $email_template = str_replace('$message', $message, $email_template);

        // Construct the request
        $date = gmdate('D, d M Y H:i:s e');
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
        
        // Create the signature
        $date = gmdate('Ymd\THis\Z');
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = gmdate('Ymd') . '/' . AWS_REGION . '/ses/aws4_request';
        $signed_headers = 'host;x-amz-date';
        
        // Step 1: Create canonical request
        $canonical_request = "POST\n/\n\nhost:" . $host . "\nx-amz-date:" . $date . "\n\n" . $signed_headers . "\n" . hash('sha256', $queryString);
        
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
        
        // Return success response
        ob_clean();
        echo json_encode([
            'status' => 'success', 
            'message' => 'Thank you! Your message has been sent.',
            'messageId' => $emailMessageId
        ]);

    }
    else
    {
        // Required POST parameters are missing
        $status = "failed";
        $response = "Missing or invalid form data.";
    }
}
catch (Exception $e) {
    // Log the error message
    error_log("Error: " . $e->getMessage());
    
    // Return error response
    ob_clean();
    echo json_encode([
        'status' => 'error', 
        'message' => 'An error occurred while sending your message. Please try again later.'
    ]);
}

finally {
    // Clean output buffer and send the response
    ob_end_flush();
}
?>