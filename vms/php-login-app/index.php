<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// create a log channel
$log = new Logger('php-login-app');
$log->pushHandler(new StreamHandler('/logs/php-login-app.log', Logger::INFO));

$log->info("login page requested");

if($_SERVER['REQUEST_METHOD']!='POST') {
    include('bootstrap.phtml');
    exit;
}

$users =
    ['freek'=>'paans',
    'hello'=>'world'];

$login = $_POST['login'];
$pass = $_POST['password'];

if(!array_key_exists($login,$users) || $users[$login]!=$pass) {
    echo "Invalid login!";
    die;
}

echo "logged in";

