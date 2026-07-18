<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/hr-auth.php';

akh_hr_logout();
header('Location: ' . base_path('hr/login.php'));
exit;
