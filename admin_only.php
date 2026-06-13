<?php
session_start();

// Only administrators can manage employee accounts.
if(!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin')) {
    header("Location: login.php");
    exit();
}
?>
