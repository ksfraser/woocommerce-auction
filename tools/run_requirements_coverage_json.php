<?php
// JSON-mode runner to list implemented requirements missing test references.
$root = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
$reportFile = $root . 'docs' . DIRECTORY_SEPARATOR . 'requirements_report.json';
if (!file_exists($reportFile)) {
    echo json_encode(["error" => "requirements_report.json not found"], JSON_PRETTY_PRINT);
    exit(1);
}
$report = json_decode(file_get_contents($reportFile), true);
if (empty($report) || empty($report['implemented'])) {
    echo json_encode(["error" => "No implemented requirements found in report"], JSON_PRETTY_PRINT);
    exit(1);
}
$implemented = $report['implemented'];
$testsDir = $root . 'tests' . DIRECTORY_SEPARATOR;
$missing = [];
function hasTestRef(string $dir, string $req): bool {
    if (!is_dir($dir)) {
        return false;
    }
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
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
            return true;
        }
    }
    return false;
}
foreach ($implemented as $req) {
    if (!hasTestRef($testsDir, $req)) {
        $missing[] = $req;
    }
}
echo json_encode(["missing" => $missing, "count" => count($missing)], JSON_PRETTY_PRINT);
exit(empty($missing) ? 0 : 2);
