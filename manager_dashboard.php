<?php
include 'auth.php';
if ($_SESSION['role'] !== 'manager') {
    echo "Access Denied!";
    exit();
}
?>
<h1>Manager Dashboard</h1>
<a href="logout.php">Logout</a>
