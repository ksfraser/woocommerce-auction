#!/usr/bin/env python3
"""Map missing requirements to doc mentions and suggest implementation files.

This tool scans a set of directories for token matches to propose files
that likely relate to a missing requirement. CLI options allow tuning
the search scope and thresholds so the script can be reused across projects.
"""
import argparse
import json
import re
from pathlib import Path
from collections import Counter


def build_args():
    p = argparse.ArgumentParser(description='Map missing requirements to docs and suggest files')
    p.add_argument('--repo-root', default=Path(__file__).resolve().parent.parent, type=Path,
                   help='Repository root (default: parent of tools/)')
    p.add_argument('--mapping', default='docs/requirements_mapping.json', help='Requirements mapping JSON (relative to repo-root)')
    p.add_argument('--report', default='docs/requirements_report.json', help='Requirements report JSON (relative to repo-root)')
    p.add_argument('--out', default='docs/missing_impl_mapping.json', help='Output JSON path (relative to repo-root)')
    p.add_argument('--search-dirs', default='includes,src,tests,docs,plugin-fw,assets,templates',
                   help='Comma-separated dirs to scan (relative to repo-root)')
    p.add_argument('--exts', default='.php,.md,.txt,.js,.json,.html,.css', help='Comma-separated file extensions to include')
    p.add_argument('--max-chars', type=int, default=200000, help='Max chars to read from each file')
    p.add_argument('--max-results', type=int, default=10, help='Max search results per requirement')
    return p.parse_args()


def tokens_from_req(req):
    parts = re.split(r'[^A-Za-z0-9]+', req)
    parts = [p for p in parts if p]
    tokens = []
    for p in parts:
        toks = re.findall(r'[A-Z]{2,}(?=[A-Z][a-z]|\b)|[A-Z]?[a-z]+|[0-9]+', p)
        tokens.extend(toks)
    tokens = [t.lower() for t in tokens if len(t) > 1]
    return list(dict.fromkeys(tokens))


def build_file_index(repo, search_dirs, exts):
    root = repo
    exts = {e.lower() for e in exts}
    file_index = []
    for sd in search_dirs:
        dirroot = (root / sd)
        if not dirroot.is_dir():
            continue
        for dirpath, dirnames, filenames in __import__('os').walk(dirroot):
            if 'vendor' in dirpath.split(__import__('os').sep):
                continue
            for fn in filenames:
                ext = __import__('os').path.splitext(fn)[1].lower()
                if ext in exts:
                    fp = Path(dirpath) / fn
                    file_index.append(fp)
    return file_index


def search_tokens(file_index, tokens, max_results=10, max_chars=200000, repo=None):
    counts = Counter()
    for fp in file_index:
        try:
            txt = fp.read_text(encoding='utf-8', errors='ignore')[:max_chars].lower()
        except Exception:
            continue
        score = 0
        for t in tokens:
            if t in txt:
                score += txt.count(t)
        if score > 0:
            counts[str(fp)] = score
    most = counts.most_common(max_results)
    results = []
    for k, v in most:
        rel = Path(k).relative_to(repo) if repo else Path(k)
        results.append({'file': str(rel).replace('\\','/'), 'count': v})
    return results


def main():
    args = build_args()
    repo = Path(args.repo_root).resolve()
    mapping_path = (repo / args.mapping).resolve()
    report_path = (repo / args.report).resolve()
    out_path = (repo / args.out).resolve()

    if not mapping_path.exists():
        print('Missing mapping:', mapping_path)
        raise SystemExit(2)
    if not report_path.exists():
        print('Missing report:', report_path)
        raise SystemExit(2)

    req_map = json.loads(mapping_path.read_text(encoding='utf-8'))
    report = json.loads(report_path.read_text(encoding='utf-8'))
    missing = set(report.get('missing_impl', []))

    search_dirs = [sd.strip() for sd in args.search_dirs.split(',') if sd.strip()]
    exts = [e.strip() for e in args.exts.split(',') if e.strip()]
    file_index = build_file_index(repo, search_dirs, exts)

    mapping = {}
    for req in sorted(missing):
        entry = {'requirement': req}
        mentions = req_map.get(req, [])
        entry['doc_mentions'] = mentions
        toks = tokens_from_req(req)
        entry['tokens'] = toks
        if toks:
            entry['suggested_files'] = search_tokens(file_index, toks, max_results=args.max_results, max_chars=args.max_chars, repo=repo)
        else:
            entry['suggested_files'] = []
        mapping[req] = entry

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(mapping, indent=2), encoding='utf-8')
    print('WROTE', out_path)


if __name__ == '__main__':
    main()
