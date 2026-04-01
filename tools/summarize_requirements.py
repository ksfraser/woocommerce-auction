#!/usr/bin/env python3
"""Summarize requirements mapping into a report JSON.

This script detects whether a requirement appears implemented (code files)
or tested (tests paths) using simple heuristics. Use the CLI flags to point
it at different repo layouts.
"""
import argparse
import json
from pathlib import Path


def build_args():
    p = argparse.ArgumentParser(description='Summarize requirements mapping into report')
    p.add_argument('--repo-root', default=Path(__file__).resolve().parent.parent, type=Path,
                   help='Repository root (default: parent of tools/)')
    p.add_argument('--mapping', default='docs/requirements_mapping.json', help='Input mapping JSON (relative to repo-root)')
    p.add_argument('--out', default='docs/requirements_report.json', help='Output report JSON (relative to repo-root)')
    return p.parse_args()


def main():
    args = build_args()
    repo = Path(args.repo_root).resolve()
    inpath = (repo / args.mapping).resolve()
    outpath = (repo / args.out).resolve()
    if not inpath.exists():
        print('Missing mapping:', inpath)
        raise SystemExit(2)

    m = json.loads(inpath.read_text(encoding='utf-8'))
    report = {
        'total_requirements': len(m),
        'implemented': [],
        'tested': [],
        'missing_impl': [],
        'missing_tests': [],
        'only_docs': []
    }

    for req, entries in m.items():
        files = [e.get('file', '') for e in entries]
        has_impl = any(
            f.startswith('includes') or f.startswith('src') or f.lower().endswith('.php') or '\\includes\\' in f or '/includes/' in f
            for f in files
        )
        has_test = any(
            f.startswith('tests') or f.startswith('test') or '/tests/' in f or '\\tests\\' in f
            for f in files
        )
        if has_impl:
            report['implemented'].append(req)
        else:
            report['missing_impl'].append(req)
        if has_test:
            report['tested'].append(req)
        else:
            report['missing_tests'].append(req)
        if not has_impl and not has_test:
            report['only_docs'].append(req)

    outpath.parent.mkdir(parents=True, exist_ok=True)
    outpath.write_text(json.dumps(report, indent=2), encoding='utf-8')
    print('WROTE', outpath)
    print('TOTAL', report['total_requirements'], 'IMPLEMENTED', len(report['implemented']), 'TESTED', len(report['tested']))


if __name__ == '__main__':
    main()
