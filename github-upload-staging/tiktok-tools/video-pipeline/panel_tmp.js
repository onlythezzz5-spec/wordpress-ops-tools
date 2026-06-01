
// ====== GLOBALS ======
const API='/api', IMG_API='/api/image';
let vCur=null, iCur=null, imgStyles=[], imgStyleData={};

// ====== TAB SWITCH ======
async function switchTab(t){
  document.getElementById('viewVideo').style.display=t==='video'?'block':'none';
  document.getElementById('viewImage').style.display=t==='image'?'block':'none';
  document.getElementById('tabV').classList.toggle('active',t==='video');
  document.getElementById('tabI').classList.toggle('active',t==='image');
  if(t==='video') loadVProjects();
  if(t==='image'){await loadIStyles();loadIProjects();}
}

// ====== VIDEO TAB ======
async function loadVProjects(){
  const res=await fetch(`${API}/projects`);const ps=await res.json();
  const el=document.getElementById('vList');
  if(!ps.length){el.innerHTML='<div class="card" style="text-align:center;color:var(--dim);padding:40px">还没有视频项目</div>';return}
  el.innerHTML=ps.map(p=>`<div class="card project-card" onclick="openVProject('${p.id}')"><div style="display:flex;justify-content:space-between;align-items:center"><strong>${esc(p.name)}</strong><span class="badge badge-${p.status}">${sl(p.status)}</span></div><div style="font-size:11px;color:var(--dim);margin-top:4px">${p.image_count} 张图 · ${p.created.slice(0,10)}</div></div>`).join('');
}
function showNewVProject(){document.getElementById('modalV').classList.add('show');document.getElementById('vName').focus()}
function hideM(id){document.getElementById(id).classList.remove('show')}
async function createVProject(){
  const n=document.getElementById('vName').value.trim();if(!n)return;
  const fd=new FormData();fd.append('name',n);
  const r=await fetch(`${API}/projects`,{method:'POST',body:fd});const p=await r.json();
  hideM('modalV');document.getElementById('vName').value='';openVProject(p.id);
}
async function openVProject(pid){
  vCur=pid;
  const[pr,imgs,bgms]=await Promise.all([fetch(`${API}/projects`).then(r=>r.json()),fetch(`${API}/projects/${pid}/images`).then(r=>r.json()),fetch(`${API}/bgm`).then(r=>r.json())]);
  const p=pr.find(x=>x.id===pid);if(!p)return;
  document.getElementById('vList').style.display='none';
  const d=document.getElementById('vDetail');d.style.display='block';
  d.innerHTML=`<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px"><button style="background:none;color:var(--dim);font-size:18px;cursor:pointer" onclick="document.getElementById('vDetail').style.display='none';document.getElementById('vList').style.display='block';loadVProjects()">←</button><strong>${esc(p.name)}</strong><span class="badge badge-${p.status}">${sl(p.status)}</span><div style="flex:1"></div><button class="btn btn-d" onclick="delVProject('${pid}')">删除</button></div>
  <div class="upload-zone" id="vUpload" onclick="document.getElementById('vFile').click()" ondragover="this.classList.add('drag');event.preventDefault()" ondragleave="this.classList.remove('drag')" ondrop="dropVFiles(event,'${pid}')"><div class="icon">📁</div><p>拖拽分镜图 / 点击上传</p><input type="file" id="vFile" multiple accept="image/*" onchange="uploadVFiles('${pid}')"></div>
  ${imgs.length?`<div class="gallery">${imgs.map((x,i)=>`<div class="thumb"><img src="${API}/projects/${pid}/image/${x.name}?t=${Date.now()}"><div class="idx">${i+1}</div></div>`).join('')}</div>`:''}
  ${p.has_video?`<div class="card"><video controls style="max-width:280px;border-radius:8px" src="${API}/projects/${pid}/download?t=${Date.now()}"></video><br><button class="btn btn-p btn-sm" style="margin-top:8px" onclick="window.open('${API}/projects/${pid}/download','_blank')">⬇ 下载视频</button></div>`:''}
  
  ${imgs.length?`<div class="card"><h3>📝 分镜提示词编辑</h3>${imgs.map((x,i)=>`<div style="display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--brd)"><div class="thumb" style="width:48px;height:64px;flex-shrink:0"><img src="${API}/projects/${pid}/image/${x.name}?t=${Date.now()}"><div class="idx">${i+1}</div></div><div class="fld" style="flex:1"><textarea id="shotPrompt_${i}" style="width:100%;background:var(--bg);border:1px solid var(--brd);border-radius:6px;color:var(--tx);font-size:11px;padding:6px 8px;resize:vertical;min-height:40px;font-family:inherit" placeholder="分镜${i+1}专属Prompt (留空使用全局)"></textarea></div></div>`).join('')}</div>`:''}
<div class="card"><h3>⚙ 生成设置</h3><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><div class="fld"><label>全局 Prompt</label><input type="text" id="vPrompt" value="smooth cinematic product shot, professional lighting, 4K"></div><div class="fld"><label>字幕</label><input type="text" id="vCaption" placeholder="品牌标语"></div><div class="fld"><label>BGM</label><select id="vBgm"><option value="">无</option>${bgms.map(f=>`<option>${f}</option>`).join('')}</select></div><div class="fld"><label>模型</label><select id="vModel"><optgroup label="Veo"><option value="veo-3.1-fast">Veo 3.1 Fast</option></optgroup><optgroup label="即梦"><option value="jimeng-i2v-1080">即梦 3.0 Pro</option></optgroup><optgroup label="Kling"><option value="kling-v1-6">Kling 1.6</option></optgroup></select></div><div class="fld" style="justify-content:flex-end"><button class="btn btn-p" id="vGenBtn" onclick="startVGen('${pid}')" ${!imgs.length||p.status==='generating'?'disabled':''}>⚡ 生成</button></div></div>${p.status==='generating'?`<div class="status-bar"><div class="spin"></div>${p.message||'生成中...'}</div><script>setTimeout(()=>openVProject('${pid}'),5000)<\/script>`:''}</div>`;
}
async function uploadVFiles(pid){const f=document.getElementById('vFile');if(!f.files.length)return;const fd=new FormData();for(const x of f.files)fd.append('files',x);await fetch(`${API}/projects/${pid}/upload`,{method:'POST',body:fd});f.value='';openVProject(pid)}
async function dropVFiles(e,pid){e.preventDefault();e.currentTarget.classList.remove('drag');const fd=new FormData();for(const x of e.dataTransfer.files)fd.append('files',x);await fetch(`${API}/projects/${pid}/upload`,{method:'POST',body:fd});openVProject(pid)}
async function startVGen(pid){const b=document.getElementById('vGenBtn');b.disabled=true;b.textContent='排队中...';const fd=new FormData();fd.append('prompt',document.getElementById('vPrompt')?.value||'');fd.append('caption',document.getElementById('vCaption')?.value||'');fd.append('bgm_name',document.getElementById('vBgm')?.value||'');fd.append('model_name',document.getElementById('vModel')?.value||'kling-v1-6');fd.append('resolution','1080x1920');fd.append('fps','30');fd.append('crf','20');await fetch(`${API}/projects/${pid}/generate`,{method:'POST',body:fd});openVProject(pid)}
async function delVProject(pid){if(!confirm('确认删除？'))return;await fetch(`${API}/projects/${pid}`,{method:'DELETE'});document.getElementById('vDetail').style.display='none';document.getElementById('vList').style.display='block';loadVProjects()}

// ====== IMAGE TAB ======
let imgPlat='TikTok',imgTrig='Impulse',iPollTimer=null;

async function loadIStyles(){
  try{const r=await fetch(`${IMG_API}/styles`);imgStyleData=await r.json();imgStyles=imgStyleData.styles||[];
  const sel=document.getElementById('iStyle');if(sel&&!sel.options.length)sel.innerHTML=imgStyles.map(s=>`<option value="${s.id}">${s.name}</option>`).join('');}
  catch(e){}
}
async function loadIProjects(){
  const r=await fetch(`${IMG_API}/projects`);const ps=await r.json();
  const el=document.getElementById('iList');
  if(!ps.length){el.innerHTML='<div class="card" style="text-align:center;color:var(--dim);padding:40px">还没有生图项目</div>';return}
  el.innerHTML=ps.map(p=>`<div class="card project-card" onclick="openIProject('${p.id}')"><div style="display:flex;justify-content:space-between;align-items:center"><strong>${esc(p.name)}</strong><span class="badge badge-${p.status}">${sl(p.status)}</span></div><div style="font-size:11px;color:var(--dim);margin-top:4px">${p.image_count} 张参考图 · ${p.style} · ${p.ratio} · ${p.created.slice(0,10)}</div></div>`).join('');
}
function showNewIProject(){var m=document.getElementById('modalI');if(m){m.classList.add('show');loadIStyles();setTimeout(function(){var f=document.getElementById('iName');if(f)f.focus()},200)}}
async function createIProject(){
  const n=document.getElementById('iName').value.trim();if(!n)return;
  const fd=new FormData();fd.append('name',n);fd.append('style',document.getElementById('iStyle').value);fd.append('ratio',document.getElementById('iStyle').value);
  const r=await fetch(`${IMG_API}/projects`,{method:'POST',body:fd});const p=await r.json();
  hideM('modalI');document.getElementById('iName').value='';openIProject(p.id);
}

function stopIPoll(){if(iPollTimer){clearInterval(iPollTimer);iPollTimer=null;}}

function _stepClass(s,n){const steps=['upload','configure','analyzed','strategy_ready','done'];const i=steps.indexOf(s);if(i<0)return s===n?'active':'';const ni=steps.indexOf(n);return i>ni?'done':i===ni?'active':'';}

async function openIProject(pid){
  stopIPoll();iCur=pid;
  const ts=Date.now();
  const[pr,results,strategy]=await Promise.all([fetch(`${IMG_API}/projects?t=${ts}`).then(r=>r.json()),fetch(`${IMG_API}/projects/${pid}/results?t=${ts}`).then(r=>r.json()).catch(()=>[]),fetch(`${IMG_API}/projects/${pid}/strategy?t=${ts}`).then(r=>r.json()).catch(()=>null)]);
  const p=pr.find(x=>x.id===pid);if(!p)return;
  await loadIStyles();
  document.getElementById('iList').style.display='none';
  const d=document.getElementById('iDetail');d.style.display='block';
  window._iResults=results;window._iCur=0;window._iStrategyCache=strategy;

  const imgs=p.images||[],analysis=p.product_analysis;
  const wstep=(p.status==='done')?'done':(p.status==='strategy_ready')?'strategy_ready':(p.status==='analyzed')?'analyzed':(p.workflow_step&&p.workflow_step!=='upload')?p.workflow_step:(imgs.length?'configure':'upload');
  const plats=imgStyleData.platforms||[],trigs=imgStyleData.triggers||[];
  const curPlat=p.platform||'TikTok',curTrig=p.trigger||'Impulse';

  const stepBar=`<div class="steps">
    <div class="step-item ${_stepClass(wstep,'upload')}">1. 上传</div>
    <div class="step-item ${_stepClass(wstep,'configure')}">2. 配置</div>
    <div class="step-item ${_stepClass(wstep,'analyzed')}">3. 分析</div>
    <div class="step-item ${_stepClass(wstep,'strategy_ready')}">4. 策略</div>
    <div class="step-item ${_stepClass(wstep,'done')}">5. 渲染</div>
  </div>`;

  let html=`<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap"><button style="background:none;color:var(--dim);font-size:18px;cursor:pointer" onclick="stopIPoll();document.getElementById('iDetail').style.display='none';document.getElementById('iList').style.display='block';loadIProjects()">←</button><strong>${esc(p.name)}</strong><span class="badge badge-${p.status}">${sl(p.status)}</span><span style="font-size:11px;color:var(--dim)">${p.style} · ${p.ratio}</span><div style="flex:1"></div><button class="btn btn-d" onclick="delIProject('${pid}')">删除</button></div>`;
  html+=stepBar;

  // ---- STEP 1: Upload ----
  html+=`<div class="card"><h3>📁 参考产品图</h3><div class="upload-zone" onclick="document.getElementById('iFile_${pid}').click()" ondragover="event.preventDefault();event.currentTarget.classList.add('drag')" ondragleave="event.currentTarget.classList.remove('drag')" ondrop="event.preventDefault();dropIFiles(event,'${pid}')"><div class="icon">📁</div><p>拖拽或点击上传产品图 (锁定外观)</p><p style="font-size:10px;color:var(--dim)">JPG/PNG · 建议 3-5 张不同角度</p><input type="file" id="iFile_${pid}" multiple accept="image/*" onchange="uploadIFiles('${pid}')"></div>`;
  if(imgs.length){
    html+=`<div class="gallery" style="margin-bottom:10px">${imgs.map((n,i)=>`<div class="thumb"><img src="${IMG_API}/projects/${pid}/image/${n}?t=${Date.now()}"><div class="idx">${i+1}</div><div class="del" onclick="event.stopPropagation();removeIImage('${pid}','${n}')">×</div></div>`).join('')}</div>`;
  }
  html+=`</div>`;

  // ---- STEP 2: Configure ----
  html+=`<div class="card"><h3>⚙ 市场环境 & 风格</h3>`;
  html+=`<div class="g3" style="margin-bottom:10px">${plats.map(x=>`<div class="sel-card ${curPlat===x.id?'on':''}" onclick="selPlatform(this,'${x.id}')"><span class="ic">${x.icon}</span><span class="lb">${x.name}</span><span class="ht">${x.hint}</span></div>`).join('')}</div>`;
  html+=`<div class="g3" style="margin-bottom:10px">${trigs.map(x=>`<div class="sel-card ${curTrig===x.id?'on':''}" onclick="selTrigger(this,'${x.id}')"><span class="ic">${x.icon}</span><span class="lb">${x.name}</span><span class="ht">${x.hint}</span></div>`).join('')}</div>`;
  html+=`<div class="g2"><div class="fld"><label>风格</label><select id="i??Edit">${imgStyles.map(s=>`<option value="${s.id}" ${s.id===p.style?'selected':''}>${s.name}</option>`).join('')}</select></div><div class="fld"><label>比例</label><select id="i??Edit"><option value="1:1" ${p.ratio==='1:1'?'selected':''}>1:1 正方 (Amazon)</option><option value="9:16" ${p.ratio==='9:16'?'selected':''}>9:16 竖屏 (TikTok)</option><option value="4:5" ${p.ratio==='4:5'?'selected':''}>4:5 竖向 (Ins)</option><option value="3:4" ${p.ratio==='3:4'?'selected':''}>3:4 详情 (Ozon)</option><option value="16:9" ${p.ratio==='16:9'?'selected':''}>16:9 横版</option></select></div></div>`;
  html+=`<div class="fld" style="margin-top:8px"><label>产品描述 (越详细越好)</label><textarea id="iDesc" style="width:100%;background:var(--bg);border:1px solid var(--brd);border-radius:6px;color:var(--tx);font-size:12px;padding:10px;resize:vertical;min-height:60px" placeholder="产品名称、材质、功能、尺寸、目标人群、使用场景...">${esc(p.description||p.name||'')}</textarea></div>`;
  html+=`</div>`;

  // ---- STEP 3: Analyze ----
  html+=`<div class="card"><h3>🔍 产品特征分析</h3>`;
  if(analysis){
    html+=`<div class="ana-grid">`;
    for(const[k,v]of Object.entries(analysis)){
      const val=Array.isArray(v)?v.join(', '):String(v||'');
      html+=`<div><span class="ak">${k}:</span> <span class="av">${esc(val)}</span></div>`;
    }
    html+=`</div>`;
  }else{
    html+=`<p style="font-size:11px;color:var(--dim);margin-bottom:10px">GPT-4o 将分析你的产品图，提取材质、颜色、纹理、特征等视觉信息，确保生成图与实物一致。</p>`;
  }
  html+=`<div class="step-actions"><button class="btn btn-ac btn-sm" id="iAnaBtn" onclick="runAnalyze('${pid}')" ${!imgs.length?'disabled':''}>${analysis?'🔄 重新分析产品':'🔍 分析产品特征'}</button></div>`;
  if(p.status==='analyzing') html+=`<div class="status-bar"><div class="spin"></div>${p.message||'分析中...'}</div>`;
  html+=`</div>`;

  // ---- STEP 4: Strategy ----
  html+=`<div class="card"><h3>📋 9-Shot 转化策略</h3>`;
  if(strategy&&strategy.shots){
    html+=`<table class="shot-table"><thead><tr><th>#</th><th>角度</th><th>俄语标题</th><th>中文翻译</th><th>提示词 (img_prompt)</th><th>状态</th><th></th></tr></thead><tbody>`;
    for(const s of strategy.shots){
      const rid=s.id,hasRes=results.find(r=>r.id===rid&&!r.error);
      html+=`<tr>
        <td class="shot-idx">${rid}</td>
        <td class="shot-name">${esc(s.angle_name||'Shot '+rid)}<br><span style="font-size:9px;color:var(--dim)">${esc(s.angle_name_cn||'')}</span></td>
        <td><input value="${esc(s.headline||'')}" id="iShHl_${rid}" style="width:100%;background:var(--bg);border:1px solid var(--brd);border-radius:4px;color:var(--tx);font-size:11px;padding:3px 5px"></td>
        <td><input value="${esc(s.headline_cn||'')}" id="iShHlCn_${rid}" style="width:100%;background:var(--bg);border:1px solid var(--brd);border-radius:4px;color:var(--tx);font-size:11px;padding:3px 5px"></td>
        <td class="shot-prompt-col"><textarea id="iShPr_${rid}">${esc(s.img_prompt||'')}</textarea></td>
        <td>${hasRes?`<span class="shot-status-ok">✓</span>`:`<span class="shot-status-pending">-</span>`}</td>
        <td><button class="btn btn-o btn-xxs" onclick="renderOneShot('${pid}',${rid})" ${p.status==='rendering'?'disabled':''}>生成</button></td>
      </tr>`;
    }
    html+=`</tbody></table>`;
  }else{
    html+=`<p style="font-size:11px;color:var(--dim);margin-bottom:10px">GPT-4o 根据产品分析 + 市场环境生成 9 张图的转化策略。你可以编辑每张图的提示词。</p>`;
  }
  html+=`<div class="step-actions">
    <button class="btn btn-ac btn-sm" id="iStrBtn" onclick="runStrategyGen('${pid}')" ${!imgs.length?'disabled':''}>${strategy?'🔄 重新生成策略':'🧠 生成 9-Shot 策略'}</button>
    ${strategy?`<button class="btn btn-gr btn-sm" id="iSaveBtn" onclick="saveStrategy('${pid}')">💾 保存编辑</button>`:''}
    ${strategy?`<button class="btn btn-p btn-sm" id="iRenAllBtn" onclick="renderAllShots_('${pid}')" ${p.status==='rendering'?'disabled':''}>🚀 批量生成全部 9 张</button>`:''}
  </div>`;
  if(p.status==='strategy_generating') html+=`<div class="status-bar"><div class="spin"></div>${p.message||'策略生成中...'}</div>`;
  html+=`</div>`;

  // ---- STEP 5: Render Results ----
  if(results.length){
    html+=`<div class="card"><h3>🖼 渲染结果</h3>`;
    html+=`<div style="display:grid;grid-template-columns:1fr 300px;gap:12px">`;
    const firstOK=results.find(r=>r.saved_path&&!r.error);
html+=`<div class="canvas-main" style="background:var(--card);border-radius:12px;min-height:400px;display:flex;align-items:center;justify-content:center">${firstOK?`<img id="iMainImg" src="${IMG_API}/projects/${pid}/shot/${firstOK.saved_path}" alt="" style="max-width:100%;max-height:65vh;border-radius:8px">`:`<span style="color:var(--dim)">渲染中...</span>`}</div>`;
    html+=`<div>`;
    const s0=results[0];
    if(s0&&!s0.error){
      html+=`<div class="result-meta" style="margin-bottom:10px"><div class="core">${esc(strategy?.desire_core_pt||'')}</div><div style="font-size:10px;color:var(--dim)">${esc(strategy?.desire_core_cn||'')}</div></div>`;
      html+=`<div class="result-headline" style="margin-bottom:4px">${esc(s0.headline||'')}</div><div class="result-bullets">${(s0.bullets||[]).map(b=>`<div>${esc(b)}</div>`).join('')}</div>`;
    }
    html+=`<button class="btn btn-p btn-sm" style="width:100%;margin-top:8px" onclick="downloadAllI('${pid}')">⬇ 打包下载</button>`;
    html+=`</div></div>`;
    html+=`<div class="thumb-nav" style="margin-top:8px">${results.map((r,i)=>r.error||!r.saved_path?`<div class="tn-item" style="border-color:var(--rd);opacity:1;display:flex;align-items:center;justify-content:center;font-size:9px;color:var(--rd);cursor:default">${r.error?'失败':'等待'}</div>`:`<div class="tn-item ${r.saved_path===firstOK?.saved_path?'active':''}" onclick="selIShot(${i})"><img src="${IMG_API}/projects/${pid}/shot/${r.saved_path}"><div class="tn-label">${r.angle_name||'Shot '+(i+1)}</div></div>`).join('')}</div>`;
    html+=`</div>`;
  }
  const hasRes = results && results.length > 0;
  const allOK = hasRes && results.every(r => r.error || r.saved_path);
  const busy = ['analyzing','strategy_generating','rendering','generating'];
  const progress = p.progress || 0;
  if(busy.includes(p.status) || (hasRes && !allOK)){
    html+=`<div class="card" style="margin-top:12px"><div style="display:flex;align-items:center;gap:10px;margin-bottom:6px"><div class="spin"></div><span style="font-size:12px">${p.message||'处理中...'}</span><span style="font-size:11px;color:var(--ac);margin-left:auto">${progress}%</span></div><div style="background:var(--brd);border-radius:4px;height:6px;overflow:hidden"><div style="background:var(--ac);height:100%;width:${progress}%;transition:width .5s;border-radius:4px"></div></div></div>`;
    if(!iPollTimer) iPollTimer=setInterval(()=>openIProject(pid),2000);
  }else if(!hasRes && (p.status==='done'||p.status==='failed')){
    stopIPoll();
  }

  d.innerHTML=html;
  // Restore platform/trigger globals
  imgPlat=curPlat;imgTrig=curTrig;
}

// ---- Image Step Actions ----
function selPlatform(el,id){imgPlat=id;el.parentElement.querySelectorAll('.sel-card').forEach(c=>c.classList.remove('on'));el.classList.add('on')}
function selTrigger(el,id){imgTrig=id;el.parentElement.querySelectorAll('.sel-card').forEach(c=>c.classList.remove('on'));el.classList.add('on')}

async function uploadIFiles(pid){const f=document.getElementById('iFile_'+pid);if(!f||!f.files.length)return;const zone=f.closest('.upload-zone');if(zone)zone.style.opacity='0.5';const fd=new FormData();for(const x of f.files)fd.append('files',x);try{const r=await fetch(`${IMG_API}/projects/${pid}/upload`,{method:'POST',body:fd});if(!r.ok){const msg=(await r.json()).detail||r.statusText;throw new Error(msg)}f.value='';openIProject(pid)}catch(e){alert('上传失败: '+e.message);if(zone)zone.style.opacity='1'}}
async function dropIFiles(e,pid){e.preventDefault();e.currentTarget.classList.remove('drag');try{const fd=new FormData();for(const x of e.dataTransfer.files)fd.append('files',x);const r=await fetch(`${IMG_API}/projects/${pid}/upload`,{method:'POST',body:fd});if(!r.ok)throw new Error((await r.json()).detail||r.statusText);openIProject(pid)}catch(err){alert('上传失败: '+err.message)}}
async function removeIImage(pid,name){await fetch(`${IMG_API}/projects/${pid}/image/${encodeURIComponent(name)}`,{method:'DELETE'});openIProject(pid)}

async function runAnalyze(pid){
  const b=document.getElementById('iAnaBtn');if(b){b.disabled=true;b.textContent='GPT-4o 分析中...';}
  await fetch(`${IMG_API}/projects/${pid}/analyze`,{method:'POST'});
  stopIPoll();iPollTimer=setInterval(()=>openIProject(pid),2000);
}
async function runStrategyGen(pid){
  const b=document.getElementById('iStrBtn');if(b){b.disabled=true;b.textContent='策略生成中...';}
  const fd=new FormData();fd.append('platform',imgPlat);fd.append('trigger',imgTrig);
  fd.append('style',document.getElementById('i??Edit')?.value||'');fd.append('ratio',document.getElementById('i??Edit')?.value||'');
  fd.append('description',document.getElementById('iDesc')?.value||'');
  // Save config + generate strategy only (no render)
  await fetch(`${IMG_API}/projects/${pid}/strategy/generate`,{method:'POST',body:fd});
  stopIPoll();iPollTimer=setInterval(()=>openIProject(pid),2000);
}
async function saveStrategy(pid,silent){
  const strategy=window._iStrategyCache||(await fetch(`${IMG_API}/projects/${pid}/strategy?t=${Date.now()}`).then(r=>r.json()));
  if(!strategy||!strategy.shots){if(!silent)alert('策略未加载');return false;}
  for(const s of strategy.shots){
    const hl=document.getElementById('iShHl_'+s.id);if(hl)s.headline=hl.value;
    const hc=document.getElementById('iShHlCn_'+s.id);if(hc)s.headline_cn=hc.value;
    const pr=document.getElementById('iShPr_'+s.id);if(pr)s.img_prompt=pr.value;
  }
  await fetch(`${IMG_API}/projects/${pid}/strategy/update`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({strategy})});
  window._iStrategyCache=strategy;
  if(!silent)alert('策略已保存');
  return true;
}
async function renderAllShots_(pid){
  const b=document.getElementById('iRenAllBtn');if(b){b.disabled=true;b.textContent='渲染中...';}
  await saveStrategy(pid,true);
  const fd=new FormData();fd.append('ratio',document.getElementById('i??Edit')?.value||'');
  await fetch(`${IMG_API}/projects/${pid}/render`,{method:'POST',body:fd});
  stopIPoll();iPollTimer=setInterval(()=>openIProject(pid),2000);
}
async function renderOneShot(pid,sid){
  await saveStrategy(pid,true);
  await fetch(`${IMG_API}/projects/${pid}/render/${sid}`,{method:'POST'});
  stopIPoll();iPollTimer=setInterval(()=>openIProject(pid),2000);
}

async function delIProject(pid){stopIPoll();if(!confirm('确认删除？'))return;await fetch(`${IMG_API}/projects/${pid}`,{method:'DELETE'});document.getElementById('iDetail').style.display='none';document.getElementById('iList').style.display='block';loadIProjects()}
function downloadAllI(pid){const rs=window._iResults||[];rs.forEach((r,i)=>{if(!r.error&&r.saved_path)setTimeout(()=>window.open(`${IMG_API}/projects/${pid}/shot/${r.saved_path}`,'_blank'),i*600)})}
function selIShot(i){document.querySelectorAll('.tn-item').forEach((el,j)=>el.classList.toggle('active',j===i));const r=(window._iResults||[])[i];if(r&&!r.error&&r.saved_path){const el=document.getElementById('iMainImg');if(el)el.src=`${IMG_API}/projects/${iCur}/shot/${r.saved_path}`}}

// ====== HELPERS ======
function esc(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function sl(s){return{draft:'??',generating:'???',rendering:'???',strategy_generating:'?????',strategy_ready:'????',analyzing:'???',analyzed:'???',done:'??',partial:'????',failed:'??'}[s]||s}

// ====== INIT ======
loadVProjects();loadIStyles();
