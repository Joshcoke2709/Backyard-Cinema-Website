<?php
	date_default_timezone_set('America/Jamaica');

	$servername = "localhost";
	$username = "root";
	$password = "";
	$dbname = "example";
	// Create a connection
	$conn = new mysqli($servername, $username, $password, $dbname);
	// Check the connection
	if ($conn->connect_error) {
	die("Connection failed: " . $conn->connect_error);
}
