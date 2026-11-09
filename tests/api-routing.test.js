const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
test('the production request helper preserves action names with cache busting and filters',async()=>{
 const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/app.js'),'utf8');
 const begin=source.indexOf('const api=async('),end=source.indexOf('\nfunction esc(',begin);assert.ok(begin>=0&&end>begin);
 const requests=[];const api=vm.runInNewContext(source.slice(begin,end)+'\napi;',{state:{csrf:'test-only'},Date,AbortController,setTimeout,clearTimeout,fetch:async(url)=>{requests.push(url);return {ok:true,status:200,text:async()=>'{"ok":true}'};}});
 for(const input of ['connections_overview','projects','tasks&status=all&limit=300','analytics?days=30'])await api(input);
 const urls=requests.map(u=>new URL(u,'https://daypilot.example/'));
 assert.deepEqual(urls.map(u=>u.searchParams.get('action')),['connections_overview','projects','tasks','analytics']);
 assert.ok(urls.every(u=>u.searchParams.has('_dp')));assert.equal(urls[2].searchParams.get('status'),'all');assert.equal(urls[3].searchParams.get('days'),'30');
});
