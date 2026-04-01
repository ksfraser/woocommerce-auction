#!/usr/bin/env python3
"""Run the requirements -> tests pipeline.

This wrapper runs in sequence:
  1. tools/extract_requirements.py
  2. tools/summarize_requirements.py
  3. tools/map_missing_impl.py
  4. tools/generate_missing_tests.py

It accepts a `--repo-root` and passes sensible defaults to each step.
Use `--no-step` flags to skip specific stages.
"""
import argparse
import subprocess
from pathlib import Path
import sys


def build_args():
    p = argparse.ArgumentParser(description='Run requirements->tests pipeline')
    p.add_argument('--repo-root', default=Path(__file__).resolve().parent.parent, type=Path,
                   help='Repository root (default: parent of tools/)')
    p.add_argument('--skip-extract', action='store_true', help='Skip extraction step')
    p.add_argument('--skip-summarize', action='store_true', help='Skip summarize step')
    p.add_argument('--skip-map', action='store_true', help='Skip missing-impl mapping step')
    p.add_argument('--skip-tests', action='store_true', help='Skip missing-tests generation step')
    p.add_argument('--threshold', type=int, default=5, help='High-priority mentions threshold for test stubs')
    return p.parse_args()


def run(cmd, cwd=None):
    print('>',' '.join(cmd))
    res = subprocess.run(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    print(res.stdout)
    if res.returncode != 0:
        print('Command failed:', cmd, 'exit', res.returncode, file=sys.stderr)
        sys.exit(res.returncode)


def main():
    args = build_args()
    repo = Path(args.repo_root).resolve()
    tools = repo / 'tools'

    # step 1: extract
    if not args.skip_extract:
        run([sys.executable, str(tools / 'extract_requirements.py')], cwd=str(repo))

    # step 2: summarize
    if not args.skip_summarize:
        run([sys.executable, str(tools / 'summarize_requirements.py'),
             '--repo-root', str(repo)], cwd=str(repo))

    # step 3: map missing impl
    if not args.skip_map:
        run([sys.executable, str(tools / 'map_missing_impl.py'),
             '--repo-root', str(repo),
             '--mapping', 'docs/requirements_mapping.json',
             '--report', 'docs/requirements_report.json',
             '--out', 'docs/missing_impl_mapping.json'], cwd=str(repo))

    # step 4: generate missing tests and stubs
    if not args.skip_tests:
        run([sys.executable, str(tools / 'generate_missing_tests.py'),
             '--repo-root', str(repo),
             '--missing-mapping', 'docs/missing_impl_mapping.json',
             '--report', 'docs/requirements_report.json',
             '--out', 'docs/missing_tests_mapping.json',
             '--tests-dir', 'tests/auto_generated',
             '--threshold', str(args.threshold)], cwd=str(repo))

    print('Pipeline completed successfully')


if __name__ == '__main__':
    main()
