#!/usr/bin/env python3
import os, re, json
root = os.getcwd()
pattern = re.compile(r"\bREQ[-A-Z0-9]+\b")
ignore_dirs = {'.git','vendor','node_modules','coverage','.idea','.vs'}
mapping = {}
for dirpath, dirnames, filenames in os.walk(root):
    # skip ignored dirs
    parts = dirpath.split(os.sep)
    if any(p in ignore_dirs for p in parts):
        continue
    for fname in filenames:
        # skip binary files by extension
        if fname.endswith(('.png','.jpg','.jpeg','.gif','.ttf','.woff','.woff2','.otf','.zip','.exe')):
            continue
        fpath = os.path.join(dirpath, fname)
        try:
            with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
                for i, line in enumerate(f, start=1):
                    for m in pattern.findall(line):
                        mapping.setdefault(m, []).append({'file': os.path.relpath(fpath, root).replace('\\\\','/'), 'line': i, 'context': line.strip()})
        except Exception as e:
            # skip unreadable files
            continue
# sort mapping keys
out = {k: mapping[k] for k in sorted(mapping.keys())}
out_path = os.path.join(root, 'docs', 'requirements_mapping.json')
with open(out_path, 'w', encoding='utf-8') as out_f:
    json.dump(out, out_f, indent=2, ensure_ascii=False)
print('Wrote', out_path)
