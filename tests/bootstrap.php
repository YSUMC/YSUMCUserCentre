<?php

// Register plugin classes before PHPUnit loads test doubles extending them.
$loader = require dirname(__DIR__).'/vendor/autoload.php';
$loader->addPsr4('SITMinecraft\\Launcher\\', dirname(__DIR__).'/plugins/sitmc-launcher/src');
$loader->addPsr4('LittleSkin\\YggdrasilConnect\\', dirname(__DIR__).'/plugins/yggdrasil-connect/src');
