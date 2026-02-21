<?php
include_once 'config.php'; // NOSONAR
session_unset();
session_destroy();
header("Location: index.php");
exit();
