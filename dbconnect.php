<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "skripsi";

// Global domain variable
$GLOBALS['domain'] = "http://localhost/skripsi";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

?>