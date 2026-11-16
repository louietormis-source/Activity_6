<?php
declare(strict_types=1);

$databaseHost = 'sql111.infinityfree.com';
$databasePort = 3306;
$databaseName = 'if0_43413321_Inventoryproducts';
$databaseUser = 'if0_43413321';
$databasePassword = 'Z2dBle6n24AYtf';

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $databaseHost,
    $databasePort,
    $databaseName
);

return new PDO($dsn, $databaseUser, $databasePassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
