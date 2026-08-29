<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$version = new \Voxel\Version();

header('Content-Type: text/plain; charset=utf-8');
echo $version->describe();