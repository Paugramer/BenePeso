<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
check_user_role('peso_staff');
$moduleRole = 'peso_staff';
require __DIR__ . '/historical_report.php';
