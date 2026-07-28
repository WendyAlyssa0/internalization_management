<?php
session_start();
session_destroy();
header("Location: ../public/website.php");
exit();
?>
