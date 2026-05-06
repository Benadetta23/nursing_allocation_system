<?php
session_start();
session_destroy();
header("Location: /nursing_allocation_system/login.php");
exit();
?>