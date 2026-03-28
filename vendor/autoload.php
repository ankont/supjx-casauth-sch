<?php
declare(strict_types=1);

$phpCasBootstrap = __DIR__ . '/apereo/phpcas/source/CAS.php';

if (!is_file($phpCasBootstrap)) {
    throw new RuntimeException('Bundled phpCAS bootstrap not found.');
}

require_once $phpCasBootstrap;
