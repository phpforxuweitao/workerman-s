<?php
/**
 * Created by PhpStorm.
 * User: windrunner414
 * Date: 11/10/18
 * Time: 12:42 PM
 */

require '../Autoloader.php';

use Workerman\Worker;

$worker = new Worker('http://0.0.0.0:5555');

$worker->onWorkerStart = function ($worker) {
    echo $worker->id, ' start' . PHP_EOL;
};

$worker->onMessage = function ($connection, $data) {
    var_dump($_SERVER['REQUEST_METHOD']);
    echo $_SERVER;
};

Worker::runAll();