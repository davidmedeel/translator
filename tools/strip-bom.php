<?php

declare(strict_types=1);

/**
 * Removes a UTF-8 BOM from every PHP file under a directory.
 *
 * PowerShell 5.1 Set-Content writes a BOM by default, which PHP rejects when
 * "declare(strict_types=1)" has to be the first statement.
 *
 *   php tools/strip-bom.php tools/glossary
 */

$dir = $argv[1] ?? __DIR__;
$removed = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $bytes = (string) file_get_contents($path);

    if (strncmp($bytes, "\xEF\xBB\xBF", 3) !== 0) {
        continue;
    }

    file_put_contents($path, substr($bytes, 3));
    $removed[] = $path;
}

printf("%d ficheros sin BOM\n", count($removed));

foreach ($removed as $path) {
    echo '  ', $path, PHP_EOL;
}