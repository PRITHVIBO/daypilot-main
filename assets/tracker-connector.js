/* DayPilot progress connector: sends aggregate counts only, never code or notes. */
(function(root){
  'use strict';
  const keys={dsa:'dsa-intelligencia-progress-v1',sql:'queryquest.sql-tracker.v1',web:'stackforge.webdev.projects.v1',cloud:'cloudlab-project-tracker-v1'};
  const integer=v=>Math.max(0,Math.min(10000000,Math.floor(Number(v)||0)));
  function summarize(source,data){
    let rows=[];const out={source,total:0,completed:0,in_progress:0,attempts:0,minutes:0,reviews_due:0};
    if(source==='dsa'){
      rows=Array.isArray(data.questions)?data.questions:[];
      const attempts=Object.values(data.attemptsByQuestion||{}).flat().filter(a=>a&&typeof a==='object');
      out.attempts=attempts.length;out.minutes=attempts.reduce((n,a)=>n+integer(a.minutes),0);
      out.reviews_due=integer(data.reviews_due);
    }else if(source==='sql'){rows=Array.isArray(data.questions)?data.questions:[];out.attempts=rows.reduce((n,q)=>n+integer(q.attempts),0);}
    else if(source==='web'){rows=(Array.isArray(data)?data:[]).filter(p=>p&&!p.deleted&&p.status!=='Archived');}
    else if(source==='cloud'){rows=(Array.isArray(data)?data:[]).filter(p=>p&&!p.deleted&&(typeof p.inPlan==='boolean'?p.inPlan:!!(p.custom||p.pinned)||p.status!=='Not started'));}
    else throw new Error('Unknown tracker.');
    rows=rows.filter(q=>q&&typeof q==='object');out.total=rows.length;
    out.completed=rows.filter(q=>['solved','completed'].includes(String(q.status||'').toLowerCase())).length;
    out.in_progress=rows.filter(q=>['in progress','needs revision','review','blocked'].includes(String(q.status||'').toLowerCase())).length;
    for(const k of Object.keys(out))if(k!=='source')out[k]=integer(out[k]);return out;
  }
  if(typeof module!=='undefined'&&module.exports)module.exports={summarize};
  if(typeof document==='undefined')return;
  const script=document.currentScript;const source=script?.dataset.source;if(!keys[source])return;
  const configKey='daypilot.connection.'+source;let config={},busy=false,lastSignature='',retryAt=0;
  try{config=JSON.parse(localStorage.getItem(configKey)||'{}');}catch{}
  function current(){let data=JSON.parse(localStorage.getItem(keys[source])||'null');if(!data)throw new Error('Save progress in this tracker first.');
    if(source==='dsa'){
      if(typeof root.allPracticeQuestions!=='function')throw new Error('The DSA question catalog is unavailable.');
      const questions=root.allPracticeQuestions();data={questions,attemptsByQuestion:data.attemptsByQuestion,reviews_due:typeof root.isReviewDue==='function'?questions.filter(root.isReviewDue).length:0};
    }
    return summarize(source,data);
  }
  function endpoint(value){const u=new URL(value);if(u.username||u.password||u.hash||u.search||!u.pathname.endsWith('/tracker_ingest.php'))throw new Error('Use the tracker_ingest.php URL from your DayPilot connection.');if(u.protocol!=='https:'&&!(u.protocol==='http:'&&['localhost','127.0.0.1','[::1]'].includes(u.hostname)))throw new Error('Use HTTPS or localhost.');return u;}
  const button=document.createElement('button');button.type='button';button.textContent='↗ Connect DayPilot';button.style.cssText='position:fixed;right:18px;bottom:20px;z-index:9900;padding:11px 16px;background:#17252d;color:#fff;border:1px solid #52636a;border-radius:10px;font:12px system-ui;box-shadow:0 8px 25px #0002';
  const dialog=document.createElement('dialog');dialog.style.cssText='width:min(480px,calc(100% - 30px));padding:25px;border:1px solid #cbd5cc;border-radius:15px;background:#f8f8f2;color:#17252d;font:14px system-ui';
  dialog.innerHTML='<form style="display:grid;gap:13px"><div style="display:flex;justify-content:space-between;align-items:center"><strong>Connect this tracker to DayPilot</strong><button type="button" data-close aria-label="Close connector">×</button></div><p style="font-size:12px;line-height:1.7;margin:0">Send counts for completed items, attempts, minutes, and due reviews to your DayPilot account. Your saved code and notes stay here.</p><label style="display:grid;gap:5px">DayPilot ingestion URL<input name="endpoint" type="url" required placeholder="https://your-site/tracker_ingest.php"></label><label style="display:grid;gap:5px">Connection ID<input name="connection" required autocomplete="off"></label><label style="display:grid;gap:5px">Connection token<input name="token" type="password" required autocomplete="off"></label><label><input name="auto" type="checkbox"> Sync changes automatically while this tracker is open</label><p data-preview style="font-size:11px;line-height:1.6;margin:0"></p><p data-status role="status" style="font-size:12px;line-height:1.6;margin:0"></p><button type="submit" style="padding:12px;background:#17252d;color:white;border:0;border-radius:7px">Save and send summary</button><button type="button" data-disconnect>Disconnect this browser</button></form>';
  dialog.querySelectorAll('input:not([type=checkbox])').forEach(el=>el.style.cssText='width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccd2c5;border-radius:6px;background:white;color:#17252d;font:12px system-ui');
  document.body.append(button,dialog);const form=dialog.querySelector('form');const status=dialog.querySelector('[data-status]');
  button.addEventListener('click',()=>{for(const key of ['endpoint','connection','token'])form.elements[key].value=config[key]||'';form.elements.auto.checked=!!config.auto;
    try{const m=current();dialog.querySelector('[data-preview]').textContent='Preview: '+m.completed+'/'+m.total+' completed · '+m.attempts+' attempts · '+m.minutes+' recorded minutes. Current tracker origin: '+location.origin;status.textContent=location.origin==='null'?'Serve this tracker over localhost or HTTPS before connecting.':'';}catch(e){status.textContent=e.message;}dialog.showModal();});
  dialog.querySelector('[data-close]').onclick=()=>dialog.close();
  dialog.querySelector('[data-disconnect]').onclick=()=>{localStorage.removeItem(configKey);config={};lastSignature='';status.textContent='Disconnected locally. Revoke the token in DayPilot to disable it everywhere.';button.textContent='↗ Connect DayPilot';};
  async function send(manual){if(busy||!config.token||(!manual&&Date.now()<retryAt))return;
    busy=true;try{
      if(location.origin==='null')throw new Error('Open this tracker using localhost or HTTPS.');
      const metrics=current();const signature=JSON.stringify(metrics)+new Date().toLocaleDateString();if(!manual&&signature===lastSignature)return;
      const u=endpoint(config.endpoint);u.searchParams.set('connection',config.connection);
      const res=await fetch(u.href,{method:'POST',credentials:'omit',referrerPolicy:'no-referrer',headers:{'Content-Type':'application/json','Authorization':'Bearer '+config.token},body:JSON.stringify({...metrics,captured_at:new Date().toISOString()})});
      let result;try{result=await res.json();}catch{throw new Error('DayPilot did not return JSON. Check the server URL.');}
      if(!res.ok){retryAt=Date.now()+Math.max(60,Number(res.headers.get('Retry-After'))||600)*1000;throw new Error(result.error||'Sync failed ('+res.status+').');}
      lastSignature=signature;button.textContent='✓ DayPilot synced';status.textContent='Summary sent to '+u.origin+' at '+new Date().toLocaleTimeString()+'.';
    }catch(e){button.textContent='! DayPilot sync needs attention';status.textContent=e.message;}
    finally{busy=false;}
  }
  form.onsubmit=e=>{e.preventDefault();try{endpoint(form.elements.endpoint.value.trim());if(!/^[a-f0-9]{64}$/.test(form.elements.token.value.trim()))throw new Error('Paste the 64-character connection token from DayPilot.');config={endpoint:form.elements.endpoint.value.trim(),connection:form.elements.connection.value.trim(),token:form.elements.token.value.trim(),auto:form.elements.auto.checked};localStorage.setItem(configKey,JSON.stringify(config));send(true);}catch(err){status.textContent=err.message;}};
  setInterval(()=>{if(config.auto&&navigator.onLine)send(false);},60000);
})(typeof window!=='undefined'?window:globalThis);
