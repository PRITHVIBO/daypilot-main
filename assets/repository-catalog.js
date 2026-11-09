/* Public repository discovery for DayPilot. Deliberately separate from private GitHub OAuth. */
(() => {
 'use strict';
 const $ = id => document.getElementById(id);
 let catalog = [],loadedOwner = '', loading = false, truncated = false;
 const safeOwner = owner => /^[a-z0-9](?:[a-z0-9-]{0,38})$/i.test(owner);
 const safeRepo = name => /^[a-z0-9_.-]{1,100}$/i.test(name);
 const repoUrl = (owner,name) => 'https://github.com/'+encodeURIComponent(owner)+'/'+encodeURIComponent(name);
 const el = (tag,cls,text) => {
   const node=document.createElement(tag);
   if(cls)node.className=cls;
   if(text!==undefined)node.textContent=String(text);
   return node;
 };
 const status = message => {if($('repoCatalogStatus'))$('repoCatalogStatus').textContent=message;};
 function watchlist(){
   return ($('connectionsRepos')?.value||'').split(',').map(s=>s.trim()).filter(Boolean);
 }
 function render(){
   const node=$('repoCatalogResults');
   if(!node)return;
   node.replaceChildren();
   const term=($('repoCatalogSearch')?.value||'').trim().toLocaleLowerCase();
   let visible=catalog.filter(r=>[r.name,r.language,r.description].some(v=>String(v||'').toLocaleLowerCase().includes(term)));
   if($('repoCatalogSort')?.value==='name')visible.sort((a,b)=>a.name.localeCompare(b.name));
   else visible.sort((a,b)=>String(b.pushed_at||'').localeCompare(String(a.pushed_at||'')));
   $('repoCatalogCount').textContent=catalog.length+' public repositories'+(truncated?' (first 100)':'');
   if(!visible.length){
     node.append(el('div','empty',catalog.length?'No matching repositories.':'No repositories available yet.'));
     return;
   }
   const selected=watchlist();
   for(const r of visible){
     if(!safeRepo(r.name))continue;
     const card=el('article','repo-catalog-card');
     card.append(el('strong','',r.name));
     card.append(el('p','',r.description||'No repository description yet.'));
     const meta=el('div','repo-catalog-meta');
     meta.append(el('span','',r.language||'Language unspecified'));
     if(r.archived)meta.append(el('span','','Archived'));
     if(r.fork)meta.append(el('span','','Fork'));
     if(r.pushed_at){
       const date=new Date(r.pushed_at);
       if(!Number.isNaN(date.getTime()))meta.append(el('span','','Updated '+date.toLocaleDateString()));
     }
     card.append(meta);
     const actions=el('div','repo-catalog-actions');
     const link=el('a','','Open repository ↗');
     link.href=repoUrl(loadedOwner,r.name);link.target='_blank';link.rel='noopener noreferrer';
     actions.append(link);
     const button=el('button','secondary small',selected.includes(r.name)?'In watchlist':'+ Watch');
     button.type='button';
     button.disabled=selected.includes(r.name)||!!r.archived;
     button.addEventListener('click',()=>{
       const names=watchlist();
       if(names.includes(r.name))return;
       if(names.length>=8){status('The live monitoring watchlist supports eight repositories. Remove one under Connections first.');return;}
       names.push(r.name);
       $('connectionsRepos').value=names.join(', ');
       status('Added '+r.name+' to the watchlist form. Open Connections and save the watchlist to activate monitoring.');
       render();
     });
     actions.append(button);card.append(actions);node.append(card);
   }
 }
 async function load(force=false){
   if(!state.user||loading||!$('repoCatalogResults'))return;
   const owner=($('connectionsOwner')?.value||'PRITHVIBO').trim();
   if(!safeOwner(owner)){status('Enter a valid public GitHub owner under Connections.');return;}
   if(!force&&catalog.length&&loadedOwner===owner){render();return;}
   loading=true;status('Loading public GitHub repositories…');
   const reload=$('repoCatalogReload');if(reload)reload.disabled=true;
   try{
     const data=await api('connections_discover',{timeoutMs:20000});
     if(data.owner!==owner)throw new Error('GitHub owner changed. Reload the dashboard.');
     catalog=Array.isArray(data.repositories)?data.repositories:[];
     loadedOwner=owner;truncated=!!data.truncated;
     status(catalog.length?'Showing public repository metadata only. Monitoring and private access are separate.':'No public repositories found.');
     render();
   }catch(err){
     status('Repository discovery unavailable: '+(err.message||'Network error')+'. Your existing tasks and saved watchlist were not changed.');
   }finally{loading=false;if(reload)reload.disabled=false;}
 }
 $('repoCatalogSearch')?.addEventListener('input',render);
 $('repoCatalogSort')?.addEventListener('change',render);
 $('repoCatalogReload')?.addEventListener('click',()=>load(true));
 document.addEventListener('daypilot:view',e=>{if(e.detail==='dashboard')load();});
 document.addEventListener('daypilot:repositories',()=>load(true));
})();