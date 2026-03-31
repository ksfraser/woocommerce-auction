#!/usr/bin/env pwsh
# Helper script to commit documentation files

$repoPath = (Get-Location).Path
$lockFile = Join-Path $repoPath ".git" "index.lock"

# Try to recover from lock
if (Test-Path $lockFile) {
    Write-Host "Waiting for git lock to clear..."
    Start-Sleep -Seconds 5
    if (Test-Path $lockFile) {
        Write-Host "Lock file still present. Git may be locked by another process."
        exit 1
    }
}

# Stage and commit
$files = @(
    "docs/FRD.md",
    "docs/UAT_TEST_SUITE.md",
    "docs/PRODUCTION_READINESS_CHECKLIST.md",
    "docs/BRD.md",
    "docs/NFR_REQUIREMENTS.md",
    "docs/SECURITY_REQUIREMENTS.md",
    "docs/DEPLOYMENT_GUIDE.md",
    "docs/OPERATIONS_GUIDE.md",
    "docs/QA_TEST_PLAN.md",
    "docs/ADMIN_CONFIGURATION_GUIDE.md",
    "docs/USER_MANUAL.md",
    "docs/TROUBLESHOOTING_GUIDE.md",
    "docs/DOCUMENTATION_AUDIT_REPORT.md"
)

Write-Host "Adding documentation files..."
foreach ($file in $files) {
    if (Test-Path $file) {
        & git add $file
        if ($LASTEXITCODE -eq 0) {
            Write-Host "Added: $file"
        } else {
            Write-Host "Failed to add: $file"
        }
    }
}

# Check what's staged
Write-Host "`nGit status:"
& git status --short

Write-Host "`nAttempting commit..."
$commitMsg = @"
docs: Phase 4-F - Complete production documentation (FRD, UAT, Readiness)

Documentation Suite:
- FRD.md: Functional Requirements
- UAT_TEST_SUITE.md: User Acceptance Testing  
- PRODUCTION_READINESS_CHECKLIST.md: Pre-launch Verification
- Complete 13-document product documentation
- Security, Operations, and Deployment guides
- QA Test Plan and Troubleshooting Guide

Production Status: READY FOR DEPLOYMENT
"@

& git commit -m $commitMsg

if ($LASTEXITCODE -eq 0) {
    Write-Host "`nCommit successful!"
    & git log --oneline -3
} else {
    Write-Host "`nCommit failed with exit code: $LASTEXITCODE"
}
