<?php
/**
 * CLI utility to verify that every local CSS reference resolves to an existing file.
 */

$root = dirname(__DIR__);

$excludedDirectories = array(
    $root . DIRECTORY_SEPARATOR . '.git',
    $root . DIRECTORY_SEPARATOR . 'node_modules',
    $root . DIRECTORY_SEPARATOR . 'vendor',
    $root . DIRECTORY_SEPARATOR . 'agent_instructions',
);

$allowedExtensions = array('php', 'js', 'css', 'html', 'htm');

$directoryIterator = new RecursiveDirectoryIterator(
    $root,
    FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
);

$filterIterator = new RecursiveCallbackFilterIterator(
    $directoryIterator,
    function ($current, $key, $iterator) use ($excludedDirectories) {
        $pathName = $current->getPathname();

        foreach ($excludedDirectories as $excluded) {
            if (strpos($pathName, $excluded) === 0) {
                return false;
            }
        }

        return true;
    }
);

$iterator = new RecursiveIteratorIterator($filterIterator);

$references = array();

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }

    $extension = strtolower($fileInfo->getExtension());

    if (!in_array($extension, $allowedExtensions, true)) {
        continue;
    }

    $relativePath = substr($fileInfo->getPathname(), strlen($root) + 1);

    if ('tests' . DIRECTORY_SEPARATOR . 'check-css-assets.php' === $relativePath) {
        continue;
    }

    $contents = @file_get_contents($fileInfo->getPathname());

    if (false === $contents) {
        fprintf(STDERR, "[WARN] Unable to read %s\n", $relativePath);
        continue;
    }

    if (!preg_match_all('~(["\'(])\\s*(css/[A-Za-z0-9._/\-]+)~', $contents, $matches)) {
        continue;
    }

    foreach ($matches[2] as $match) {
        if (strpos($match, 'css/') !== 0) {
            continue;
        }

        $normalized = preg_replace('/[#?].*/', '', $match);

        if (!isset($references[$normalized])) {
            $references[$normalized] = array('files' => array());
        }

        $references[$normalized]['files'][$relativePath] = true;
    }
}

ksort($references);

$missing = array();

foreach ($references as $path => $info) {
    $fullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

    if (!file_exists($fullPath)) {
        $missing[$path] = array_keys($info['files']);
    }
}

if (!empty($missing)) {
    echo "Missing CSS asset references detected:\n";
    foreach ($missing as $asset => $sources) {
        echo sprintf(" - %s (referenced by: %s)\n", $asset, implode(', ', $sources));
    }
    exit(1);
}

$totalReferences = count($references);

$cssDirectory = $root . DIRECTORY_SEPARATOR . 'css';
$unreferencedFiles = array();

if (is_dir($cssDirectory)) {
    $cssIterator = new DirectoryIterator($cssDirectory);
    foreach ($cssIterator as $cssFile) {
        if ($cssFile->isDot() || $cssFile->getExtension() !== 'css') {
            continue;
        }

        $relative = 'css/' . $cssFile->getFilename();
        if (!isset($references[$relative])) {
            $unreferencedFiles[] = $relative;
        }
    }
}

echo sprintf("All CSS asset references resolved (%d unique reference%s).\n", $totalReferences, $totalReferences === 1 ? '' : 's');

if (!empty($unreferencedFiles)) {
    echo "Warning: The following CSS files are not referenced anywhere in the codebase:\n";
    foreach ($unreferencedFiles as $file) {
        echo sprintf(" - %s\n", $file);
    }
}

exit(0);
