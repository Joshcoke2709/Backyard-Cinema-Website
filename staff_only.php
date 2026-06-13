<?php
session_start();

// Protect maintenance pages from patrons and logged-out visitors.
if(!isset($_SESSION['role']) || ($_SESSION['role'] !== 'supervisor' && $_SESSION['role'] !== 'admin')) {
    header("Location: login.php");
    exit();
}
?>
