<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Infobhan Website Templates</title>
    <style>
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;  /* to remove any extra scrollbars */
        }

        iframe {
            display: block;
            width: 100%;
            height: 100vh;  /* full viewport height */
            border: none;   /* to remove the default border */
        }
    </style>
</head>
<body>

<?php
    function loadEnv($filename = '.env') {
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
    
    $env = loadEnv();
    if(isset($_GET['id']))
    {
        $server = $env['server'];
        $username = $env["username"];
        $password = $env["password"];
        $dbname = $env["dbname"];
        
        // Create connection
        $conn = new mysqli($server, $username, $password, $dbname);
        
        // Check connection
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        } 
        
        $sql = "SELECT template FROM templates WHERE hash=?";
        $prepared = $conn->prepare($sql) or die("Something went wrong.");
        $prepared->bind_param("i", $_GET['id']);

        $prepared->execute();
        $result = $prepared->get_result();
        $row = $result->fetch_assoc();

        $prepared->close();
        $conn->close();

        if($row)
        {
            echo '<iframe id="previewFrame" src="'.$row["template"].'" frameborder="0" scrolling="yes"></iframe>';
        }
        else {
            echo '<iframe src="notfound.html" frameborder="0" scrolling="yes"></iframe>';
        }
        $conn->close();
    }
    else
    {
        echo '<iframe src="idmissing.html" frameborder="0" scrolling="yes"></iframe>';
    }
    
?>
 <div id="container" class="container mt-2">
    <center>
        <!-- Loader -->
        <div id="loader" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
            <div class="spinner-border text-primary" role="status">
            </div>
        </div>
    </center>
</div>
<script>
    $(document).ready(function() {
        $("#previewFrame").on("load", function() {
            $("#loader").hide();
        });
    })
</script>
</body>
</html>