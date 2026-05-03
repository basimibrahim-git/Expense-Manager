<?php
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;

Bootstrap::init();
session_unset();
session_destroy();
header("Location: index.php");
exit();
