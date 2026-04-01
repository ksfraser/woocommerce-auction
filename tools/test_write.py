#!/usr/bin/env python3
"""Simple test writer used to validate docs write permissions.

Usage:
  python tools/test_write.py --out docs/test_write.txt
"""
import argparse
from pathlib import Path


def build_args():
    p = argparse.ArgumentParser(description='Write a small test file to docs to verify write access')
    p.add_argument('--repo-root', default=Path(__file__).resolve().parent.parent, type=Path,
                   help='Repository root (default: parent of tools/)')
    p.add_argument('--out', default='docs/test_write.txt', help='Output file path (relative to repo-root)')
    return p.parse_args()


def main():
    args = build_args()
    repo = Path(args.repo_root).resolve()
    out = (repo / args.out).resolve()
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text('ok', encoding='utf-8')
    print('WROTE', out)


if __name__ == '__main__':
    main()
