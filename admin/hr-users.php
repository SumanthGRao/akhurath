<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Location: ' . base_path('admin/dashboard-access.php#hr'), true, 302);
exit;
