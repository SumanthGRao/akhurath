<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/whatsapp-dashboard-auth.php';

akh_wa_dashboard_logout();
header('Location: ' . base_path('whatsapp/login.php'));
exit;
