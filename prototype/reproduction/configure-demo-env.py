#!/usr/bin/env python3
"""Create private local synthetic routing configuration; never overwrite keys."""
from pathlib import Path
root=Path(__file__).resolve().parent.parent
target=root/'reproduction/package.env'
if target.exists():
    current=target.read_text()
    if 'http://demo-router:5000' not in current:
        raise SystemExit('Existing package.env is not configured for the portable demo. Back up your settings, then remove package.env to recreate the synthetic configuration.')
    print('Using existing portable demo configuration.')
    raise SystemExit(0)
values={}
for mode in ('car','bike','foot'):
    values['MOBILITY_ROUTING_'+mode.upper()+'_BASE_URL']='http://demo-router:5000'
    values['MOBILITY_ROUTING_'+mode.upper()+'_PROFILE']=mode
    values['MOBILITY_ROUTING_'+mode.upper()+'_EXPECTED_DATA_VERSION']=mode+'|crop_sha256='+('a'*64)+'|profile_sha256='+('b'*64)
text=(root/'config/.env.example').read_text().splitlines()
lines=[]
for line in text:
    key=line.partition('=')[0]
    if key in values:
        line=key+'='+values[key]
    lines.append(line)
target.write_text('\n'.join(lines)+'\n')
target.chmod(0o600)
print('Created ignored synthetic demo environment with no provider credentials.')
