#!/usr/bin/env python3
"""Generate missing_tests mapping and (PHPUnit) test stubs.

This script is configurable so it can be applied to other PHP projects.

Examples:
  python tools/generate_missing_tests.py \
    --missing-mapping docs/missing_impl_mapping.json \
    --report docs/requirements_report.json \
    --out docs/missing_tests_mapping.json \
    --tests-dir tests/auto_generated --threshold 5
"""

import argparse
import json
import re
from pathlib import Path


def build_args():
    p = argparse.ArgumentParser(description='Generate missing-tests mapping and PHPUnit stubs')
    p.add_argument('--repo-root', default=Path(__file__).resolve().parent.parent, type=Path,
                   help='Repository root (default: parent of tools/)')
    p.add_argument('--missing-mapping', default='docs/missing_impl_mapping.json', help='Missing-impl mapping JSON')
    p.add_argument('--report', default='docs/requirements_report.json', help='Requirements report JSON')
    p.add_argument('--out', default='docs/missing_tests_mapping.json', help='Output mapping JSON')
    p.add_argument('--tests-dir', default='tests/auto_generated', help='Directory to write test stubs')
    p.add_argument('--threshold', type=int, default=5, help='Mentions threshold to consider high-priority')
    p.add_argument('--missing-tests-key', default='missing_tests', help='JSON key in report listing missing tests')
    return p.parse_args()


def sanitize_for_filename(req):
    return re.sub(r'[^A-Za-z0-9_]', '_', req)


def sanitize_for_class(req):
    s = re.sub(r'[^A-Za-z0-9]', '_', req)
    if s and s[0].isdigit():
        s = '_' + s
    return s


def main():
    args = build_args()
    repo = Path(args.repo_root).resolve()
    missing_path = (repo / args.missing_mapping).resolve()
    report_path = (repo / args.report).resolve()
    out_path = (repo / args.out).resolve()
    tests_dir = (repo / args.tests_dir).resolve()

    if not missing_path.exists():
        print('Missing file:', missing_path)
        raise SystemExit(2)
    if not report_path.exists():
        print('Missing file:', report_path)
        raise SystemExit(2)

    mapping = json.loads(missing_path.read_text(encoding='utf-8'))
    report = json.loads(report_path.read_text(encoding='utf-8'))

    missing_tests = report.get(args.missing_tests_key, [])
    out = {}
    tests_dir.mkdir(parents=True, exist_ok=True)

    for req in missing_tests:
        entries = mapping.get(req, [])
        mentions = len(entries) if isinstance(entries, list) else 0
        files = [e.get('file') for e in entries] if mentions else []
        out[req] = {'mentions': mentions, 'files': files}

        # create PHPUnit stub for high-priority items
        if mentions >= args.threshold:
            safe = sanitize_for_filename(req)
            class_name = 'TestREQ_' + sanitize_for_class(req)
            test_path = tests_dir / f'test_{safe}.php'
            if not test_path.exists():
                content = (
                    f"<?php\n"
                    "use PHPUnit\\Framework\\TestCase;\n\n"
                    "/**\n"
                    f" * @coversRequirement {req}\n"
                    f" * Requirement: {req}\n"
                    " */\n"
                    f"class {class_name} extends TestCase {{\n"
                    "    public function test_requirement_placeholder() {\n"
                    f"        $this->markTestIncomplete('Implement tests for requirement: {req}');\n"
                    "    }\n"
                    "}\n"
                )
                test_path.write_text(content, encoding='utf-8')
                print('Wrote test stub:', test_path)

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(out, indent=2, ensure_ascii=False), encoding='utf-8')
    print('Wrote', out_path)


if __name__ == '__main__':
    main()
