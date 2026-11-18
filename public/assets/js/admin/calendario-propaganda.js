// ── Calendário – Semanas do Mês ────────────────────────────────────────────
let calYear  = ADMIN_BOOT.curYear;
let calMonth = ADMIN_BOOT.curMonth;
let calSemanas = [];   // [{semana_num, data_inicio, data_fim}]
let calIsConfigured = false;
let calHistData = [];

function calLabel(m,y){ return ptMonths[m-1]+' '+y; }

function calChangeMonth(d){
  calMonth+=d;
  if(calMonth>12){calMonth=1;calYear++;}
  if(calMonth<1){calMonth=12;calYear--;}
  calLoad();
}

async function calLoad(){
  document.getElementById('calMonthLabel').textContent=calLabel(calMonth,calYear);
  const d=await fetch(`${API_URL}?action=get_semanas_mes&ano=${calYear}&mes=${calMonth}`).then(r=>r.json());
  calSemanas=[];
  calIsConfigured=false;
  if(d.success && d.data && d.data.length){
    calIsConfigured=true;
    calSemanas=d.data.map(r=>({semana_num:+r.semana_num,data_inicio:r.data_inicio,data_fim:r.data_fim}));
  }
  calRenderRows();
  calUpdateStatus();
}

function calRenderRows(){
  const wrap=document.getElementById('semanaRows');
  if(!calSemanas.length){ wrap.innerHTML='<div style="color:rgba(212,175,55,.4);font-size:11px;padding:8px 0">Nenhuma semana definida. Clique em "+ Adicionar Semana".</div>'; return; }
  wrap.innerHTML=calSemanas.map((s,i)=>`
    <div class="semana-row" id="calRow-${i}">
      <div class="semana-row-num">S${i+1}</div>
      <label>Início<input type="date" id="calIni-${i}" value="${s.data_inicio||''}"></label>
      <label>Fim<input type="date" id="calFim-${i}" value="${s.data_fim||''}"></label>
      <div class="semana-row-del">
        <button class="btn btn-d btn-sm" onclick="calRemoveSemana(${i})">${ICO.x}</button>
      </div>
    </div>`).join('');
}

function calUpdateStatus(){
  const st=document.getElementById('calStatus');
  const del=document.getElementById('calDeleteBtn');
  if(calIsConfigured){
    st.textContent='Configurado manualmente';st.className='cal-status cfg';
    del.style.display='inline-flex';
  } else {
    st.textContent='Auto-calculado';st.className='cal-status auto';
    del.style.display='none';
  }
}

function calAddSemana(){
  // Suggest next start date = day after last end, or 1st of month
  let ini='', fim='';
  if(calSemanas.length){
    const last=calSemanas[calSemanas.length-1];
    if(last.data_fim){
      const d=new Date(last.data_fim+'T12:00:00');d.setDate(d.getDate()+1);
      ini=d.toISOString().slice(0,10);
      const d2=new Date(ini+'T12:00:00');d2.setDate(d2.getDate()+6);
      fim=d2.toISOString().slice(0,10);
    }
  } else {
    ini=`${calYear}-${String(calMonth).padStart(2,'0')}-01`;
    const d2=new Date(ini+'T12:00:00');d2.setDate(d2.getDate()+6);
    fim=d2.toISOString().slice(0,10);
  }
  calSemanas.push({semana_num:calSemanas.length+1,data_inicio:ini,data_fim:fim});
  calRenderRows();
}

function calRemoveSemana(idx){
  calSemanas.splice(idx,1);
  calSemanas.forEach((s,i)=>s.semana_num=i+1);
  calRenderRows();
}

function calCollect(){
  const rows=[];
  calSemanas.forEach((_,i)=>{
    const ini=document.getElementById(`calIni-${i}`)?.value||'';
    const fim=document.getElementById(`calFim-${i}`)?.value||'';
    if(ini&&fim) rows.push({semana_num:i+1,data_inicio:ini,data_fim:fim});
  });
  return rows;
}

async function calSave(){
  const rows=calCollect();
  if(!rows.length){toast('Adicione pelo menos uma semana',true);return;}
  // Validate: start <= end
  for(const r of rows){
    if(r.data_inicio>r.data_fim){toast(`Semana ${r.semana_num}: início deve ser antes do fim`,true);return;}
  }
  const bd=new URLSearchParams();
  bd.append('action','save_semanas_mes');bd.append('ano',calYear);bd.append('mes',calMonth);
  bd.append('semanas',JSON.stringify(rows));
  const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
  if(d.success){toast(d.message);calIsConfigured=true;calSemanas=rows;calUpdateStatus();await calLoadHist();}
  else toast(d.message,true);
}

async function calDelete(){
  if(!confirm(`Remover configuração de ${calLabel(calMonth,calYear)}? O cálculo automático será usado.`))return;
  const bd=new URLSearchParams();bd.append('action','delete_semanas_mes');bd.append('ano',calYear);bd.append('mes',calMonth);
  const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
  if(d.success){toast(d.message);calIsConfigured=false;calSemanas=[];calRenderRows();calUpdateStatus();await calLoadHist();}
  else toast(d.message,true);
}

async function calLoadHist(){
  const d=await fetch(API_URL+'?action=get_semanas_historico').then(r=>r.json());
  const el=document.getElementById('calHistList');
  if(!d.success||!d.data.length){el.innerHTML='<div class="empty-state">Nenhum mês configurado ainda</div>';return;}
  calHistData=d.data;
  el.innerHTML=d.data.map(r=>`
    <div class="hist-item">
      <div class="hist-mes">${ptMonths[r.mes-1]} ${r.ano}</div>
      <div class="hist-detail">${r.total_semanas} semana(s) · ${r.primeiro_dia||''} → ${r.ultimo_dia||''}</div>
      <div class="hist-actions">
        <button class="btn btn-s btn-sm" onclick="calGoTo(${r.ano},${r.mes})">Editar</button>
        <button class="btn btn-d btn-sm" onclick="calDeleteMonth(${r.ano},${r.mes})">Remover</button>
      </div>
    </div>`).join('');
}

async function calDeleteMonth(ano,mes){
  if(!confirm(`Remover configuração de ${ptMonths[mes-1]} ${ano}?`))return;
  const bd=new URLSearchParams();bd.append('action','delete_semanas_mes');bd.append('ano',ano);bd.append('mes',mes);
  const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
  if(d.success){toast(d.message);if(calYear===ano&&calMonth===mes){calIsConfigured=false;calSemanas=[];calRenderRows();calUpdateStatus();}await calLoadHist();}
  else toast(d.message,true);
}

function calGoTo(ano,mes){
  calYear=ano;calMonth=mes;
  showTab('calendario',document.querySelector('.tab-btn[onclick*="calendario"]'));
  calLoad();
}

// ── Propaganda CRUD ────────────────────────────────────────────────────────
let allPropagandas = [];
let rotationItems  = [];

function resetPropForm(){
  document.getElementById('propId').value='';
  document.getElementById('propTitulo').value='';
  document.getElementById('propHtml').value='';
  document.getElementById('propAtivo').checked=true;
  document.getElementById('propFormTitle').textContent='Nova Propaganda';
  document.getElementById('propSubmitBtn').textContent='Adicionar Propaganda';
  document.getElementById('propCancelBtn').style.display='none';
}

async function loadPropagandas(){
  try{
    const d=await fetch(API_URL+'?action=get_propagandas').then(r=>r.json());
    if(d.success){ allPropagandas=d.data||[]; renderPropList(); }
  }catch(e){console.error(e);}
}

function renderPropList(){
  const list=document.getElementById('propList');
  const cnt=document.getElementById('propCount');
  if(cnt) cnt.textContent=allPropagandas.length;
  updateAddRotPropOptions();
  if(!allPropagandas.length){
    list.innerHTML='<div class="empty-state"><div>Nenhuma propaganda cadastrada</div></div>';return;
  }
  list.innerHTML=allPropagandas.map(p=>{
    const on=p.ativo==1||p.ativo===true;
    const preview=(p.conteudo_html||'').replace(/<[^>]*>/g,'').trim().slice(0,80);
    return`<div class="prop-item${on?'':' inactive'}">
      <div class="prop-info">
        <div class="prop-title">${p.titulo||'Sem título'}</div>
        <div class="prop-preview">${preview||'(sem conteúdo)'}</div>
        <span class="prop-badge ${on?'on':'off'}">${on?'Ativa':'Inativa'}</span>
      </div>
      <div class="prop-actions">
        <button class="btn btn-s btn-sm" onclick="editPropaganda(${p.id})">Editar</button>
        <button class="btn btn-s btn-sm" onclick="togglePropaganda(${p.id},${on?0:1})">${on?'Desativar':'Ativar'}</button>
        <button class="btn btn-d btn-sm" onclick="deletePropaganda(${p.id})">Excluir</button>
      </div>
    </div>`;
  }).join('');
}

function editPropaganda(id){
  const p=allPropagandas.find(x=>x.id==id); if(!p) return;
  document.getElementById('propId').value=p.id;
  document.getElementById('propTitulo').value=p.titulo||'';
  document.getElementById('propHtml').value=p.conteudo_html||'';
  document.getElementById('propAtivo').checked=p.ativo==1||p.ativo===true;
  document.getElementById('propFormTitle').textContent='Editar Propaganda';
  document.getElementById('propSubmitBtn').textContent='Salvar';
  document.getElementById('propCancelBtn').style.display='inline-flex';
  showTab('propaganda',document.querySelector('.tab-btn[onclick*="propaganda"]'));
  window.scrollTo({top:0,behavior:'smooth'});
}

async function togglePropaganda(id, val){
  const bd=new URLSearchParams();
  bd.append('action','toggle_propaganda'); bd.append('id',id); bd.append('ativo',val);
  const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
  if(d.success){toast(d.message);await loadPropagandas();}else toast(d.message,true);
}

async function deletePropaganda(id){
  const p=allPropagandas.find(x=>x.id==id);
  if(!p||!confirm(`Excluir propaganda "${p.titulo||id}"?`)) return;
  const bd=new URLSearchParams();
  bd.append('action','delete_propaganda'); bd.append('id',id);
  const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
  if(d.success){toast(d.message);await loadPropagandas();}else toast(d.message,true);
}

async function submitPropaganda(e){
  e.preventDefault();
  const id=document.getElementById('propId').value;
  const bd=new URLSearchParams();
  bd.append('action',id?'update_propaganda':'add_propaganda');
  if(id) bd.append('id',id);
  bd.append('titulo',document.getElementById('propTitulo').value);
  bd.append('conteudo_html',document.getElementById('propHtml').value);
  bd.append('ativo',document.getElementById('propAtivo').checked?1:0);
  try{
    const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
    if(d.success){toast(d.message);resetPropForm();await loadPropagandas();}else toast(d.message,true);
  }catch{toast('Erro de conexão',true);}
  return false;
}

// ── Rotation CRUD ──────────────────────────────────────────────────────────
const ROT_PODIUM_LABELS = { semanal:'Pódio Semanal — Closers', geral:'Pódio Mensal — Closers', equipe_semanal:'Pódio Semanal — Equipes', equipe_mensal:'Pódio Mensal — Equipes',
  retro_semana:'Retrospectiva da Semana Anterior — Hall da Fama', retro_mes:'Retrospectiva do Mês Anterior — Hall da Fama' };

async function loadRotation(){
  try{
    const d=await fetch(API_URL+'?action=get_rotation').then(r=>r.json());
    if(d.success){rotationItems=d.data||[]; renderRotation();}
    updateAddRotPropOptions();
  }catch(e){}
}

function updateAddRotPropOptions(){
  const grp=document.getElementById('addRotPropGroup');
  if(!grp)return;
  grp.innerHTML=allPropagandas.map(p=>`<option value="ad:${p.id}">${p.titulo||'(sem título) #'+p.id}</option>`).join('');
}

function renderRotation(){
  const list=document.getElementById('rotationList');
  if(!list)return;
  if(!rotationItems.length){
    list.innerHTML='<div style="padding:16px;text-align:center;color:rgba(255,255,255,0.35);font-size:13px;">Nenhum item — usando rotação padrão (todos os pódios, 60 s cada).</div>';
    return;
  }
  list.innerHTML=rotationItems.map((item,i)=>{
    let tag,name;
    if(item.type==='podium'){
      tag=/^retro_/.test(item.mode)?'Retrospectiva':'Pódio'; name=ROT_PODIUM_LABELS[item.mode]||item.mode;
    } else {
      tag='Anúncio';
      const p=allPropagandas.find(x=>x.id==item.propaganda_id);
      name=p?(p.titulo||'#'+p.id):`Propaganda #${item.propaganda_id}`;
    }
    const isFirst=i===0, isLast=i===rotationItems.length-1;
    return`<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:rgba(255,255,255,0.04);border-radius:10px;margin-bottom:6px;">
      <span style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:3px 8px;border-radius:999px;border:1px solid rgba(212,175,55,.25);color:rgba(212,175,55,.75);flex-shrink:0;">${tag}</span>
      <span style="flex:1;font-size:14px;color:#fff;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${name}">${name}</span>
      <input type="number" value="${item.duration||60}" min="5" max="3600" class="inp" style="width:72px;text-align:center;padding:4px 6px;"
             oninput="rotationItems[${i}].duration=Math.max(5,parseInt(this.value)||60)" title="Duração em segundos">
      <span style="font-size:12px;color:rgba(255,255,255,0.4);flex-shrink:0;">s</span>
      <button class="btn btn-s btn-sm" onclick="moveRotationItem(${i},-1)" ${isFirst?'disabled':''} title="Mover para cima">↑</button>
      <button class="btn btn-s btn-sm" onclick="moveRotationItem(${i},1)" ${isLast?'disabled':''} title="Mover para baixo">↓</button>
      <button class="btn btn-d btn-sm" onclick="removeRotationItem(${i})" title="Remover">${ICO.x}</button>
    </div>`;
  }).join('');
}

function addRotationItem(){
  const sel=document.getElementById('addRotSelect');
  const val=sel.value; if(!val)return;
  const [type,id]=val.split(':');
  if(type==='podium') rotationItems.push({type:'podium',mode:id,duration:/^retro_/.test(id)?30:60});
  else if(type==='ad') rotationItems.push({type:'ad',propaganda_id:parseInt(id),duration:15});
  sel.value='';
  renderRotation();
}

function removeRotationItem(idx){
  rotationItems.splice(idx,1);
  renderRotation();
}

function moveRotationItem(idx,dir){
  const n=idx+dir;
  if(n<0||n>=rotationItems.length)return;
  [rotationItems[idx],rotationItems[n]]=[rotationItems[n],rotationItems[idx]];
  renderRotation();
}

async function saveRotation(){
  const bd=new URLSearchParams();
  bd.append('action','save_rotation');
  bd.append('rotation',JSON.stringify(rotationItems));
  try{
    const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
    if(d.success)toast(d.message); else toast(d.message,true);
  }catch{toast('Erro de conexão',true);}
}

// ── Background Music ───────────────────────────────────────────────────────
let bgMusicPending=''; // track currently queued for the panel

async function loadBgMusic(){
  try{
    const d=await fetch(API_URL+'?action=get_bg_music').then(r=>r.json());
    if(d.success) renderBgMusicList(d.data||[]);
  }catch(e){}
}

function renderBgMusicList(files){
  const list=document.getElementById('bgMusicList');
  if(!list)return;
  if(!files.length){
    list.innerHTML='<div style="padding:12px 0;text-align:center;color:rgba(255,255,255,0.3);font-size:13px;">Nenhuma música adicionada ainda.</div>';
    return;
  }
  const fmtSize=b=>b<1048576?(b/1024).toFixed(0)+' KB':(b/1048576).toFixed(1)+' MB';
  // Store filenames in a JS-accessible array so onclick can reference by index safely
  window._bgFiles = files.map(f=>f.name);
  list.innerHTML=files.map((f,i)=>{
    const isPending=f.name===bgMusicPending;
    const dispName=f.name.replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return`<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;background:rgba(255,255,255,0.04);border-radius:10px;margin-bottom:6px;${isPending?'border:1px solid rgba(212,175,55,0.35);':''}">
      <span style="font-size:18px;">${isPending?ICO.play:ICO.music}</span>
      <div style="flex:1;min-width:0;">
        <div style="font-size:13px;color:${isPending?'#d4af37':'#fff'};overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${dispName}">${dispName}</div>
        <div style="font-size:11px;color:rgba(255,255,255,0.35);">${fmtSize(f.size)}${isPending?' · <span style="color:#d4af37;">enviada ao painel</span>':''}</div>
      </div>
      <audio controls src="${f.url}" style="height:28px;width:150px;flex-shrink:0;opacity:0.7;"></audio>
      <button class="btn btn-s btn-sm" onclick="playBgNow(window._bgFiles[${i}])" title="Tocar no painel agora">${ICO.play} Tocar</button>
      <button class="btn btn-d btn-sm" onclick="deleteBgMusic(window._bgFiles[${i}])">${ICO.x}</button>
    </div>`;
  }).join('');
}

async function playBgNow(name){
  const bd=new URLSearchParams();bd.append('action','set_bg_next');bd.append('name',name);
  const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
  if(d.success){
    toast(d.message);
    bgMusicPending=name;
    loadBgMusic();
  }else toast(d.message,true);
}

async function uploadBgMusic(e){
  e.preventDefault();
  const fi=document.getElementById('bgMusicFile');
  if(!fi.files.length){toast('Selecione um arquivo MP3',true);return false;}
  const fd=new FormData();
  fd.append('action','upload_bg_music');fd.append('music',fi.files[0]);
  try{
    const d=await fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json());
    if(d.success){
      toast(d.message);
      fi.value='';document.getElementById('bgMusicFileName').textContent='';
      await loadBgMusic();
    } else toast(d.message,true);
  }catch{toast('Erro de conexão',true);}
  return false;
}

async function deleteBgMusic(name){
  if(!confirm(`Remover "${name}"?`))return;
  const bd=new URLSearchParams();bd.append('action','delete_bg_music');bd.append('name',name);
  const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
  if(d.success){toast(d.message);await loadBgMusic();}else toast(d.message,true);
}

// ── Pop-up Personalizado ────────────────────────────────────────────────────
async function loadCustomPopup(){
  try{
    const d=await fetch(API_URL+'?action=get_custom_popup').then(r=>r.json());
    if(!d.success) return;
    document.getElementById('cpTitulo').value=d.titulo||'';
    if(d.foto1) setPreview(d.foto1,'cpPreviewImg1','cpUploadArea1','cpPlaceholder1','cpPreviewWrap1');
    if(d.foto2) setPreview(d.foto2,'cpPreviewImg2','cpUploadArea2','cpPlaceholder2','cpPreviewWrap2');
  }catch{}
}
async function submitCustomPopup(e){
  e.preventDefault();
  const fd=new FormData();
  fd.append('action','save_custom_popup');
  fd.append('titulo',document.getElementById('cpTitulo').value);
  const f1=document.getElementById('cpFoto1').files[0]; if(f1) fd.append('foto1',f1);
  const f2=document.getElementById('cpFoto2').files[0]; if(f2) fd.append('foto2',f2);
  try{
    const d=await fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json());
    if(d.success){toast(d.message);await loadCustomPopup();}else toast(d.message,true);
  }catch{toast('Erro de conexão',true);}
  return false;
}
async function triggerCustomPopup(){
  if(!confirm('Disparar o pop-up agora no painel?'))return;
  const bd=new URLSearchParams();bd.append('action','trigger_custom_popup');
  try{
    const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
    if(d.success)toast(d.message);else toast(d.message,true);
  }catch{toast('Erro de conexão',true);}
}

// ── Cerimônia de Metas ──────────────────────────────────────────────────────
function ceremFmtDate(d){ return d ? d.substring(5).split('-').reverse().join('/') : ''; }
async function loadCeremonyPreview(semana=''){
  const list=document.getElementById('ceremList'),btn=document.getElementById('ceremTriggerBtn'),sel=document.getElementById('ceremWeek');
  list.innerHTML='<div style="font-size:12px;color:rgba(255,255,255,0.4)">Carregando...</div>';
  try{
    const d=await fetch(API_URL+'?action=get_ceremony_preview'+(semana?'&semana='+encodeURIComponent(semana):'')).then(r=>r.json());
    if(!d.success){list.innerHTML='<div style="font-size:12px;color:rgba(255,255,255,0.4)">'+(d.message||'Erro ao carregar')+'</div>';return;}
    document.getElementById('ceremWeekLbl').textContent=d.weekStart?`(${ceremFmtDate(d.weekStart)} – ${ceremFmtDate(d.weekEnd)})`:'';
    sel.innerHTML=d.semanas.map(s=>`<option value="${s.start}"${s.start===d.weekStart?' selected':''}>${ceremFmtDate(s.start)} – ${ceremFmtDate(s.end)}</option>`).join('');
    document.getElementById('ceremFinalDuration').value=d.final_duration||15;
    document.getElementById('ceremMusicFileName').textContent=d.musica?('Atual: '+d.musica.split('/').pop()):'Nenhuma música definida';
    if(!d.winners.length){
      list.innerHTML='<div style="font-size:12px;color:rgba(255,255,255,0.4)">Ninguém bateu a meta nessa semana</div>';
      btn.disabled=true;
      return;
    }
    btn.disabled=false;
    list.innerHTML=d.winners.map(w=>`
      <div style="display:flex;align-items:center;gap:10px;padding:6px 10px;background:rgba(212,175,55,.06);border:1px solid rgba(212,175,55,.15);border-radius:8px;">
        <span style="font-size:11px;font-weight:700;color:rgba(212,175,55,.6);min-width:22px;">#${w.pos}</span>
        <span style="flex:1;font-size:13px;font-weight:700;color:#fff;">${w.name}</span>
        <span style="font-size:11px;color:rgba(212,175,55,.7);">${w.vendas}/${w.meta} (${w.score}%)</span>
      </div>`).join('');
  }catch{list.innerHTML='<div style="font-size:12px;color:rgba(255,255,255,0.4)">Erro de conexão</div>';}
}
async function saveCeremonySettings(){
  const fd=new FormData();
  fd.append('action','save_ceremony_settings');
  fd.append('final_duration',document.getElementById('ceremFinalDuration').value||15);
  const f=document.getElementById('ceremMusicFile').files[0]; if(f) fd.append('musica',f);
  try{
    const d=await fetch(API_URL,{method:'POST',body:fd}).then(r=>r.json());
    if(d.success){
      toast(d.message);
      document.getElementById('ceremMusicFile').value='';
      await loadCeremonyPreview(document.getElementById('ceremWeek').value);
    } else toast(d.message,true);
  }catch{toast('Erro de conexão',true);}
}
async function triggerCeremony(){
  const sel=document.getElementById('ceremWeek'),opt=sel&&sel.selectedOptions[0];
  if(!confirm('Disparar a cerimônia de metas da semana '+(opt?opt.textContent:'escolhida')+' agora no painel?'))return;
  const bd=new URLSearchParams();bd.append('action','trigger_ceremony');bd.append('semana',sel?sel.value:'');
  try{
    const d=await fetch(API_URL,{method:'POST',body:bd}).then(r=>r.json());
    if(d.success)toast(d.message);else toast(d.message,true);
  }catch{toast('Erro de conexão',true);}
}

