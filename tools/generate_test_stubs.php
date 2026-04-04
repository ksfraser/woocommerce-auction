<?php
// Generates PHPUnit test stubs for each missing requirement listed in docs/requirements_missing.json
$root = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
$missingFile = $root . 'docs' . DIRECTORY_SEPARATOR . 'requirements_missing.json';
$targetDir = $root . 'tests' . DIRECTORY_SEPARATOR . 'auto_generated' . DIRECTORY_SEPARATOR;
if (!file_exists($missingFile)) {
    fwrite(STDERR, "Missing file: $missingFile\n");
    exit(1);
}
$data = json_decode(file_get_contents($missingFile), true);
if (empty($data) || empty($data['missing'])) {
    fwrite(STDOUT, "No missing requirements to generate stubs for.\n");
    exit(0);
}
if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0777, true)) {
        fwrite(STDERR, "Failed to create target directory: $targetDir\n");
        exit(2);
    }
}
function sanitizeForFilename(string $req): string {
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $req);
}
function sanitizeForClass(string $req): string {
    $s = preg_replace('/[^A-Za-z0-9]/', '_', $req);
    return 'Test_Requirement_' . $s;
}
foreach ($data['missing'] as $req) {
    $fileName = 'test_' . strtolower(sanitizeForFilename($req)) . '.php';
    $path = $targetDir . $fileName;
    if (file_exists($path)) {
        continue; // don't overwrite existing stubs
    }
    $className = sanitizeForClass($req);
    $contents = <<<PHP
<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @requirement {$req}
 */
class {$className} extends TestCase
{
    public function test_todo_{$className}(): void
    {
        // Minimal passing placeholder for {$req}
        self::assertTrue(true, 'Placeholder test for {$req}');
    }
}
PHP;
    file_put_contents($path, $contents);
}
fwrite(STDOUT, "Generated stubs for " . count($data['missing']) . " requirements into $targetDir\n");
exit(0);
