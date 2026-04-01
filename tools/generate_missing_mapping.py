#!/usr/bin/env python3
"""Generate a missing-implementation mapping from report + mapping files.

This script is generic and configurable via CLI flags so it can be reused
across PHP projects that follow a similar `docs/` traceability pattern.

Examples:
  python tools/generate_missing_mapping.py \
    --report docs/requirements_report.json \
    --mapping docs/requirements_mapping.json \
    --out docs/missing_impl_mapping.json \
    --missing-key missing_impl

Defaults assume `docs/requirements_report.json` and
`docs/requirements_mapping.json` under the repo root.
"""

import argparse
import json
from pathlib import Path


def build_args():
    p = argparse.ArgumentParser(description='Generate missing-implementation mapping')
    p.add_argument('--repo-root', default=Path(__file__).resolve().parent.parent, type=Path,
                   help='Repository root (default: parent of tools/)')
    p.add_argument('--report', default='docs/requirements_report.json', help='Report JSON path (relative to repo-root)')
    p.add_argument('--mapping', default='docs/requirements_mapping.json', help='Mapping JSON path (relative to repo-root)')
    p.add_argument('--out', default='docs/missing_impl_mapping.json', help='Output JSON path (relative to repo-root)')
    p.add_argument('--missing-key', default='missing_impl', help='JSON key in report listing missing requirements')
    return p.parse_args()


def main():
    args = build_args()
    repo = Path(args.repo_root).resolve()
    report_path = (repo / args.report).resolve()
    mapping_path = (repo / args.mapping).resolve()
    out_path = (repo / args.out).resolve()

    if not report_path.exists():
        print('Missing report:', report_path)
        raise SystemExit(2)
    if not mapping_path.exists():
        print('Missing mapping:', mapping_path)
        raise SystemExit(2)

    report = json.loads(report_path.read_text(encoding='utf-8'))
    mapping = json.loads(mapping_path.read_text(encoding='utf-8'))

    missing = report.get(args.missing_key, [])

    out = {}
    for req in missing:
        # try exact key, then case-insensitive fallback
        entry = mapping.get(req)
        if entry is None:
            entry = mapping.get(getattr(req, 'upper', lambda: req)())
        out[req] = entry if entry is not None else []

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(out, indent=2, ensure_ascii=False), encoding='utf-8')
    print('Wrote', out_path)


if __name__ == '__main__':
    main()
