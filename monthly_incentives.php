<?php
// Legacy page: it used a non-existent `monthly_incentives` table. Incentives are stored in
// `company_incentives` and managed per month (add / edit / delete) on company_tracker.php,
// so this page now just forwards there, keeping the requested year.
require_once __DIR__ . '/autoload.php';
use App\Core\Bootstrap;

Bootstrap::init();

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]) ?: (int) date('Y');

header('Location: company_tracker.php?year=' . (int) $year);
exit();
