<?php

function loadEnv($filename = '../.env') {
    if (!file_exists($filename)) {
        return false;
    }

    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];

    foreach ($lines as $line) {
        // Skip comments and empty lines
        if (str_starts_with(trim($line), '#') || empty(trim($line))) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $env[trim($name)] = trim($value);
    }

    return $env;
}

 $env = loadEnv(); // Assuming you're using the loadEnv function

 $server = $env['server'];
 $username = $env["username"];
 $password = $env["password"];
 $dbname = $env["dbname"];

 $conn = new mysqli($server, $username, $password, $dbname);

 if ($conn->connect_error) {
    $response['status'] = 'error';
    $response['message'] = 'Database connection failed.';
    echo json_encode($response);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['website_url'])) {
    $website_url = $_POST['website_url'];

     // Generate a short hash
     $unique_id = uniqid();
     $hash = md5($unique_id);
     $short_hash = substr($hash, 0, 8); // This will create an 8-characters long hash.

    $stmt = $conn->prepare("INSERT INTO templates (hash,template) VALUES (?, ?)");
    $stmt->bind_param("ss",$short_hash, $website_url);
    if ($stmt->execute()) {
        $response['status'] = 'success';
        $response['message'] = 'Data inserted successfully.';
        $response['url'] = "https://infobhan.net/templates.php?id=".$short_hash;
    } else {
        $response['status'] = 'error';
        $response['message'] = 'Data insertion failed: ' . $stmt->error;
    }
    echo json_encode($response);
    exit();
}

$conn->close();

?>