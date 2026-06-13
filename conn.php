<?php
	// Use Jamaica time so schedule dates and "today" checks match the cinema.
	date_default_timezone_set('America/Jamaica');

	// Local XAMPP database settings.
	$servername = "localhost";
	$username = "root";
	$password = "";
	$dbname = "example";
	// Create one shared MySQL connection for the page that includes this file.
	$conn = new mysqli($servername, $username, $password, $dbname);

	// Stop immediately if MySQL cannot be reached.
	if ($conn->connect_error) {
	die("Connection failed: " . $conn->connect_error);
}
