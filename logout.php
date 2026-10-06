<?php require_once 'config.php'; log_activity('Logged out'); $_SESSION=[]; session_destroy(); header('Location:login.php');
