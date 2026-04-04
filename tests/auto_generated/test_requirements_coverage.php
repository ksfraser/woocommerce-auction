<?php
use PHPUnit\Framework\TestCase;

class RequirementsCoverageTest extends TestCase
{
    public function testImplementedRequirementsHaveTests()
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $reportFile = $root . 'docs' . DIRECTORY_SEPARATOR . 'requirements_report.json';

        if (!file_exists($reportFile)) {
            self::assertTrue(true);
            return;
        }

        $report = json_decode(file_get_contents($reportFile), true);
        if (empty($report) || empty($report['implemented'])) {
            self::assertTrue(true);
            return;
        }

        $implemented = $report['implemented'];
        $testsDir = $root . 'tests' . DIRECTORY_SEPARATOR;
        $missing = [];

        foreach ($implemented as $req) {
            $matches = $this->findTestsReferencingRequirement($testsDir, $req);
            if (empty($matches)) {
                $missing[] = $req;
            }
        }

        if (!empty($missing)) {
            $this->fail('Missing tests for requirements: ' . implode(', ', array_slice($missing, 0, 50)) . (count($missing) > 50 ? ' (and more...)' : ''));
        }

        $this->assertTrue(true, 'All implemented requirements have at least one referencing test file');
    }

    private function findTestsReferencingRequirement(string $dir, string $req): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $matches = [];

        foreach ($rii as $file) {
            if ($file->isDir()) {
                continue;
            }
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($ext, ['php', 'phpt'])) {
                continue;
            }

            $path = $file->getPathname();
            // read up to first 100KB to avoid huge files
            $contents = @file_get_contents($path, false, null, 0, 100000);
            if ($contents === false) {
                continue;
            }

            if (stripos($contents, $req) !== false
                || stripos($contents, '@requirement ' . $req) !== false
                || stripos($contents, '@covers-requirement ' . $req) !== false
            ) {
                $matches[] = $path;
            }
        }

        return $matches;
    }
}
