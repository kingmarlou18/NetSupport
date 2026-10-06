<?php
require_once 'config.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    header("Location: event.php?id={$id}");
} else {
    header('Location: events.php');
}
exit;
