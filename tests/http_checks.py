"""HTTP checks against an explicitly configured isolated local development server.
Run only after integration.php on safarilink_test; no live providers are used.
"""
import urllib.request, urllib.parse, urllib.error, http.cookiejar, re, os, time, hmac, hashlib, struct, base64
base=os.environ.get('SAFARILINK_TEST_URL','')
if not re.fullmatch(r'http://127\.0\.0\.1:\d+',base): raise SystemExit('Set SAFARILINK_TEST_URL to an isolated loopback test server.')
count=0
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(c,path='/',data=None):
 try:
  r=c.open(base+path, urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None)
  return r.status,r.read().decode(),r.headers
 except urllib.error.HTTPError as e:return e.code,e.read().decode(),e.headers
def csrf(html):return re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
def ok(condition,label):
 global count
 if not condition:raise AssertionError(label)
 count+=1;print('PASS '+label)
c=client()
status,html,headers=request(c)
ok(status==200 and 'Go a little' in html,'passenger search page renders')
ok('default-src' in headers['Content-Security-Policy'],'content security policy present')
ok('HttpOnly' in headers.get('Set-Cookie',''),'session cookie is HttpOnly')
status,csrf_result,_=request(c,'/?view=login',{'action':'login','csrf':'wrong','identity':'x','password':'invalid'})
ok(status==419,'invalid CSRF rejected (status '+str(status)+', body '+csrf_result[:300]+')')
for view in ['finance','dispatch','routes','fleet','boarding','reports']:
 status,_,_=request(c,'/?view='+view);ok(status==403,'anonymous denied '+view)
status,html,_=request(c,'/?view=register')
suffix=str(time.time_ns())
email='http'+suffix+'@example.invalid'
# Unique phone comes from the test suffix, never an actual contact.
phone='07'+suffix[-8:]
status,html,_=request(c,'/?view=register',{'action':'register','csrf':csrf(html),'name':'HTTP test passenger','email':email,'phone':phone,'password':'Isolated-Test-Password-123'})
ok(status==200 and 'Account created' in html,'passenger registration')
status,html,_=request(c,'/?view=login',{'action':'login','csrf':csrf(html),'identity':email,'password':'Isolated-Test-Password-123'})
ok(status==200 and 'My bookings' in html,'passenger sign-in')
status,html,_=request(c,'/?view=bookings');ok(status==200 and 'No records' in html,'new passenger sees no other bookings')
status,_,_=request(c,'/?view=booking&id=1');ok(status==404,'cross-customer booking access denied over HTTP')
status,_,_=request(c,'/?view=ticket&id=1');ok(status==404,'cross-customer ticket access denied over HTTP')
status,html,_=request(c,'/?view=retrieve')
status,html,_=request(c,'/?view=retrieve',{'action':'retrieve','csrf':csrf(html),'reference':'SL-NOT-A-BOOKING','token':'0'*64})
ok(status==200 and 'invalid' in html,'invalid guest key grants no booking access')
status,_,_=request(c,'/?view=finance');ok(status==403,'passenger cannot access finance')
staff=client()
status,html,_=request(staff,'/?view=login')
status,html,_=request(staff,'/?view=login',{'action':'login','csrf':csrf(html),'identity':'operator@example.invalid','password':'Fixture-Only-Password-123'})
ok(status==200 and 'Verify staff access' in html,'staff login requires MFA enrollment')
status,_,_=request(staff,'/?view=finance');ok(status==403,'password alone does not grant staff access')
status,html,_=request(staff,'/?view=mfa')
secret=re.search(r'<code class="token">([A-Z2-7]+)</code>',html).group(1)
counter=int(time.time())//30
value=hmac.new(base64.b32decode(secret),struct.pack('>Q',counter),hashlib.sha1).digest();offset=value[-1]&15
code=str((struct.unpack('>I',value[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
status,html,_=request(staff,'/?view=mfa',{'action':'mfa','csrf':csrf(html),'code':code})
ok(status==200 and 'Dispatch' in html,'valid authenticator code completes staff login')
status,html,_=request(staff,'/?view=finance');ok(status==200 and 'Finance &amp;' not in html and 'reconciliation' in html,'authorized finance view loads')
status,html,_=request(staff,'/?view=reports');ok(status==200 and 'Net collections' in html,'authorized financial report loads')
status,csv,headers=request(staff,'/?view=report_export');ok(status==200 and headers.get_content_type()=='text/csv' and 'net_cents' in csv,'authorized report CSV export')
print(f'{count} HTTP checks passed.')
