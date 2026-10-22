<?php

$root = dirname(__DIR__, 2);
$loader = require $root.'/vendor/autoload.php';
$loader->addPsr4('SITMinecraft\\Launcher\\', $root.'/plugins/sitmc-launcher/src');
