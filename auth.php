<?php
require_once __DIR__ . '/auth_session.php';

function check_user_role(string $required_role): void
{
    if (auth_activate_role($required_role)) {
        return;
    }

    $_SESSION['flash'] = 'Please log in to continue.';
    header('Location: login.php');
    exit();
}
