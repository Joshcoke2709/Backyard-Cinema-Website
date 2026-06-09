<?php
session_start();

// Destroy the session to log out the user
session_unset();
session_destroy();

// Redirect to the main page after logout
header("Location: moviesphp.php");
exit();