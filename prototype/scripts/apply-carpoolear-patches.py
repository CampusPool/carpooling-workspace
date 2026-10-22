#!/usr/bin/env python3
"""Verify a known patch prefix before applying remaining patches; preserve unknown edits."""
import hashlib
from pathlib import Path
import re
import subprocess
import sys
import tempfile


def run(*argv, cwd=None):
    return subprocess.check_output(argv, cwd=cwd)


def main():
    target = Path(sys.argv[1]).resolve()
    patches = [Path(p).resolve() for p in sys.argv[2:]]
    paths = sorted({p for patch in patches for p in re.findall(r'^\+\+\+ b/(.+)$', patch.read_text(), re.M)})
    if not paths or any(Path(p).is_absolute() or '..' in Path(p).parts for p in paths):
        raise RuntimeError('Invalid patch paths')
    changes = set(run('git', 'diff', 'HEAD', '--name-only', '-z', cwd=target).decode().strip('\0').split('\0')) - {''}
    if changes - set(paths):
        raise RuntimeError('Integration checkout has tracked edits outside the patch stack; preserve and inspect them')
    def hashes(folder):
        return {p: hashlib.sha256((folder/p).read_bytes()).hexdigest() if (folder/p).is_file() else None for p in paths}
    actual = hashes(target)
    with tempfile.TemporaryDirectory(prefix='mobility-patch-proof-') as directory:
        proof = Path(directory)
        run('git', 'init', '--quiet', str(proof))
        entries = dict(line.split('\t', 1)[::-1] for line in run('git', 'ls-tree', '-r', 'HEAD', cwd=target).decode().splitlines())
        baseline_paths = set(entries)
        for p in paths:
            if p not in baseline_paths: continue
            blob = run('git', 'show', 'HEAD:'+p, cwd=target)
            dest = proof/p; dest.parent.mkdir(parents=True, exist_ok=True); dest.write_bytes(blob)
            dest.chmod(0o755 if entries[p].startswith('100755 ') else 0o644)
        prefixes = [hashes(proof)]
        for patch in patches:
            run('git', 'apply', '--check', '--whitespace=error', str(patch), cwd=proof)
            run('git', 'apply', str(patch), cwd=proof)
            prefixes.append(hashes(proof))
        matching = [i for i, prefix in enumerate(prefixes) if prefix == actual]
        if not matching:
            raise RuntimeError('Integration checkout does not match a known patch prefix; no files changed. Preserve and inspect edits')
        applied = max(matching)
        for patch in patches[:applied]: print('Already applied: '+patch.name)
        for patch in patches[applied:]:
            run('git', 'apply', '--check', '--whitespace=error', str(patch), cwd=target)
            run('git', 'apply', str(patch), cwd=target)
            print('Applied: '+patch.name)
        if hashes(target) != prefixes[-1]: raise RuntimeError('Final patch content differs from the verified stack')


if __name__ == '__main__':
    main()
