Tools for requirements->code mapping and test scaffolding

Scripts:

- `generate_missing_mapping.py` — Combine a requirements report and a requirements mapping JSON to produce a `missing_impl_mapping.json` for requirements that are missing implementations. Configurable via CLI flags:
  - `--repo-root` (default: parent of `tools/`)
  - `--report` (default: `docs/requirements_report.json`)
  - `--mapping` (default: `docs/requirements_mapping.json`)
  - `--out` (default: `docs/missing_impl_mapping.json`)
  - `--missing-key` (default: `missing_impl`)

- `generate_missing_tests.py` — Builds `docs/missing_tests_mapping.json` from the report and missing-impl mapping and creates PHPUnit test stubs for high-priority items. Configurable flags:
  - `--repo-root` (default: parent of `tools/`)
  - `--missing-mapping` (default: `docs/missing_impl_mapping.json`)
  - `--report` (default: `docs/requirements_report.json`)
  - `--out` (default: `docs/missing_tests_mapping.json`)
  - `--tests-dir` (default: `tests/auto_generated`)
  - `--threshold` (default: `5`)
  - `--missing-tests-key` (default: `missing_tests`)

Usage examples:

```bash
python tools/generate_missing_mapping.py --report docs/requirements_report.json --mapping docs/requirements_mapping.json --out docs/missing_impl_mapping.json

python tools/generate_missing_tests.py --missing-mapping docs/missing_impl_mapping.json --report docs/requirements_report.json --tests-dir tests/auto_generated --threshold 5
```

These scripts use pathlib and standard library only; they should work on Windows, macOS, and Linux with Python 3.8+.

Other helper scripts
- `tools/test_write.py` — small utility to verify write access. Now accepts `--repo-root` and `--out`.
- `tools/summarize_requirements.py` — previously project-specific; now accepts `--repo-root`, `--mapping`, and `--out`.
- `tools/map_missing_impl.py` — token-based search to suggest implementation files; now accepts `--repo-root`, `--mapping`, `--report`, `--out`, `--search-dirs`, `--exts`, `--max-chars`, `--max-results`.

All scripts are designed to be usable in other PHP projects by passing `--repo-root` (or adjusting the default paths).

Wrapper
-------
- `tools/run_pipeline.py` — runs the full pipeline (extract -> summarize -> map -> generate tests).
  Example:

```bash
python tools/run_pipeline.py --repo-root .
```

Use `--skip-extract`, `--skip-summarize`, `--skip-map`, or `--skip-tests` to skip stages, and `--threshold` to set the mentions threshold for test stubs.
