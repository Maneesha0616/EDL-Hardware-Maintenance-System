<?php

$serverName = "localhost\\SQLEXPRESS";
$connectionOptions = array(
    "Database" => "edl_HardwareMaintenanceSystem",
    "Uid" => "",
    "PWD" => ""
);
$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    die(print_r(sqlsrv_errors(), true));
}
?>