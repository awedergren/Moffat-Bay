<?php
/*
Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren
Date: February 15, 2026
Project: Moffat Bay Marina Project
File: logout.php
Purpose: Log out user and clear session.
Non-executing header only.
*/
// logout.php - destroy session and redirect to login page
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}
session_destroy();
header('Location: BlueTeam_LoginPage.php');
exit;

?>
