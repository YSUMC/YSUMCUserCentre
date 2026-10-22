#!/usr/bin/env python3
"""Run the existing production flow plus bounded launcher checks while gated.

Requires the private production-fixture.php create step. Never prints tokens.
Only its synthetic instance and uploaded texture are changed and removed.
"""
from pathlib import Path
import hashlib
import io
import json
import secrets
import struct
import zlib
import zipfile
from html.parser import HTMLParser

BACK=Path('/www/server/php-compat-assessments/20261022-review/backup')

def skin_checks(ctx):
    request,check,fixture=ctx['request'],ctx['check'],ctx['fixture']
    headers={'Authorization':'Bearer '+ctx['tokens']['access_token'],'Accept':'application/json'}
    def chunk(kind,data): return struct.pack('!I',len(data))+kind+data+struct.pack('!I',zlib.crc32(kind+data)&0xffffffff)
    pixel=secrets.token_bytes(3)+b'\xff'
    png=b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!IIBBBBB',64,64,8,6,0,0,0))+chunk(b'IDAT',zlib.compress((b'\0'+pixel*64)*64))+chunk(b'IEND',b'')
    player=fixture['player_name']; tid=None
    try:
        check(request('GET','/api/launcher/skins',params={'player':player}).status_code==401,'Launcher skins require a bearer token')
        check(request('GET','/api/launcher/skins',params={'player':player},headers=headers).status_code==200,'Janus token reads owned launcher profile')
        upload=request('POST','/api/launcher/skins',params={'player':player,'type':'steve','name':'Release verification','public':'false'},data=png,headers=headers|{'Content-Type':'image/png'})
        check(upload.status_code==200 and upload.json().get('created') is True,'Launcher raw PNG upload uses core storage and policy')
        tid=upload.json()['texture']['tid']
        equipped=request('PUT','/api/launcher/skins/equip',json={'player':player,'skin':tid},headers=headers)
        check(equipped.status_code==200 and equipped.json()['player']['skin']['tid']==tid,'Launcher equips the owned skin')
        denied=request('PUT','/api/launcher/skins/equip',json={'player':'not-owned-release-test','skin':tid},headers=headers)
        check(denied.status_code==400,'Launcher rejects an unowned player')
        removed=request('DELETE','/api/launcher/skins',json={'player':player,'tid':tid},headers=headers)
        check(removed.status_code==200 and removed.json()['player']['skin'] is None,'Launcher removes and unequips its closet texture')
    finally:
        if tid is not None:
            csrf=ctx['Forms'](request('GET','/user').text).csrf
            deleted=request('DELETE',f'/texture/{tid}',headers={'X-CSRF-TOKEN':csrf,'Accept':'application/json'})
            check(deleted.status_code==200 and deleted.json().get('code')==0,'Launcher test texture removed from shared storage')

source=(BACK/'production-http-smoke.py').read_text()
marker="refreshed = request('POST', metadata['token_endpoint']"
assert source.count(marker)==1
source=source.replace(marker,"skin_checks(globals())\n"+marker)
context={'__name__':'__main__','skin_checks':skin_checks}
exec(compile(source,str(BACK/'production-http-smoke.py'),'exec'),context)
request,check,session,base=context['request'],context['check'],context['session'],context['base']
anon=context['anon']; Forms=context['Forms']
csrf=Forms(request('GET','/admin/launcher/interop').text).csrf
headers={'X-CSRF-TOKEN':csrf,'Accept':'application/json'}

class Selection(HTMLParser):
    def __init__(self,text):
        super().__init__();self.ids=[];self.feed(text)
    def handle_starttag(self,tag,attrs):
        attrs=dict(attrs)
        if tag=='input' and attrs.get('name')=='published[]' and 'checked' in attrs: self.ids.append(attrs['value'])

prior=Selection(request('GET','/admin/launcher/interop').text).ids
instance='review-'+secrets.token_hex(8)
(BACK/'synthetic-launcher.json').write_text(json.dumps({'instance':instance,'prior_publications':prior}))
instance_path='/admin/launcher/instances/'+instance
def public():
    response=anon.get(base+'/.well-known/mc-interop-v1.json',timeout=20)
    check(response.status_code==200,'Well-known endpoint traverses real Nginx to PHP')
    return response
def selection(ids):
    result=request('POST','/admin/launcher/interop/publications',json={'published':ids},headers=headers)
    check(result.status_code==200 and result.json().get('ok') is True,'Publication selection saved')

created=False
try:
    initial=public()
    mirror=anon.get(base+'/api/launcher/interop/v1/site',timeout=20)
    check(initial.content==mirror.content,'Well-known and API mirror return identical bytes')
    check('Set-Cookie' not in initial.headers and 'public' in initial.headers.get('Cache-Control',''),'Catalog is shareable without session cookies')
    conditional=anon.get(base+'/.well-known/mc-interop-v1.json',headers={'If-None-Match':initial.headers['ETag']},timeout=20)
    check(conditional.status_code==304 and not conditional.content,'ETag conditional GET returns empty 304')
    for method,url in [('post','/api/launcher/interop/v1/refresh'),('post','/api/launcher/interop/v1/refresh?token=invalid'),('get','/api/launcher/interop/v1/refresh')]:
        result=getattr(anon,method)(base+url,timeout=20)
        check(result.status_code in (403,405),'Refresh rejects missing credentials or unsupported method')
    created_response=request('POST','/admin/launcher/instances',json={'instance_id':instance,'name':'Release verification','game_version':'1.21.1','loader':'fabric','loader_version':'0.16.10','server_address':'play.example.com','enabled':True},headers=headers,allow_redirects=False)
    check(created_response.status_code==302,'Admin creates a bounded launcher fixture')
    created=True
    packed=io.BytesIO()
    with zipfile.ZipFile(packed,'w') as archive:
        archive.writestr('modrinth.index.json',json.dumps({'formatVersion':1,'game':'minecraft','versionId':'test','name':'Verification','files':[],'dependencies':{'minecraft':'1.21.1','fabric-loader':'0.16.10'}}))
    data=packed.getvalue()
    upload=request('POST',instance_path+'/packs',files={'pack':('verification.mrpack',data,'application/zip')},data={'format':'mrpack'},headers=headers,allow_redirects=False)
    check(upload.status_code==302,'Admin pack upload parses metadata and stores revision')
    download='/api/launcher/packs/'+instance+'/2'
    check(anon.get(base+download,timeout=20).status_code==404,'Unselected pack cannot be downloaded')
    selection(prior+[instance])
    document=public().json()
    entry=next(p for p in document['packs'] if p['id']==instance)
    check(entry['revision']==2 and entry['artifact']['sha1']==hashlib.sha1(data).hexdigest() and entry['artifact']['sha512']==hashlib.sha512(data).hexdigest(),'Published artifact carries matching hashes and revision')
    artifact=anon.get(entry['artifact']['url'],timeout=20)
    check(artifact.status_code==200 and artifact.content==data,'Pack download streams the exact uploaded bytes')
    legacy=anon.get(base+'/api/launcher/manifest',timeout=20).json()
    check(any(p['id']==instance for p in legacy['instances']),'Legacy launcher receives the compatible published mrpack')
    selection(prior)
    check(anon.get(base+download,timeout=20).status_code==404,'Unpublishing also removes download access')
finally:
    if created:
        selection(prior)
        removed=request('DELETE',instance_path,headers=headers,allow_redirects=False)
        check(removed.status_code==302,'Synthetic launcher instance and artifact removed')

(BACK/'interop-http-passed.json').write_text(json.dumps({'passed':len(context['checks']),'checks':context['checks']},indent=2))
print('Production launcher and authentication verification passed; only fixture cleanup remains.')
