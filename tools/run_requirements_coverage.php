<?php
// Simple runner to check which implemented requirements have referencing test files.
$root = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
$reportFile = $root . 'docs' . DIRECTORY_SEPARATOR . 'requirements_report.json';

if (!file_exists($reportFile)) {
    echo "requirements_report.json not found\n";
    exit(1);
}

$report = json_decode(file_get_contents($reportFile), true);
if (empty($report) || empty($report['implemented'])) {
    echo "No implemented requirements found in report\n";
    exit(1);
}

$implemented = $report['implemented'];
$testsDir = $root . 'tests' . DIRECTORY_SEPARATOR;
$missing = [];

function findTestsReferencingRequirement(string $dir, string $req): array {
    if (!is_dir($dir)) {
        return [];
    }
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $matches = [];
    foreach ($rii as $file) {
        if ($file->isDir()) continue;
        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, ['php','phpt'])) continue;
        $path = $file->getPathname();
        $contents = @file_get_contents($path, false, null, 0, 100000);
        if ($contents === false) continue;
        if (stripos($contents, $req) !== false
            || stripos($contents, '@requirement ' . $req) !== false
            || stripos($contents, '@covers-requirement ' . $req) !== false) {
            $matches[] = $path;
        }
    }
    return $matches;
}

foreach ($implemented as $req) {
    $matches = findTestsReferencingRequirement($testsDir, $req);
    if (empty($matches)) {
        $missing[] = $req;
    }
}

if (!empty($missing)) {
    echo "Missing tests for requirements (count=" . count($missing) . "):\n";
    echo implode("\n", $missing) . "\n";
    exit(2);
}

echo "All implemented requirements have at least one referencing test file.\n";
exit(0);
