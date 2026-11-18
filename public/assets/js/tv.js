// Escapa texto vindo do banco (nomes de closers/equipes) antes de inserir via innerHTML,
// evitando XSS armazenado caso um nome contenha HTML/script.
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
let weekLabels=['Qua','Qui','Sex','Sáb','Dom','Seg','Ter'];
let weekDates=[];
let weekDatesFull=[]; // datas completas 'Y-m-d' da semana atual, mesmo índice de weekDates/daily
let weekDayCount=7;
let podiumMode='semanal';
let teamData=[],teamDataMensal=[],teamsData=[],teamsDataMensal=[],monthWeeksData=[],propagandasData=[],previousOrder=[],podiumTransitioning=false,previousVendas={},firstLoad=true,celebrationTimeout=null;
let rotationData=[],rotationIdx=0,rotationTimer=null,dataLoaded=false,rotationLoaded=false,rotationStarted=false;
const celebrationTracks=(window.TV_CFG&&window.TV_CFG.celebrationTracks)||[];
// ── Milestone state ─────────────────────────────────────────────────────────
const _msMonthKey='ms_'+new Date().toISOString().slice(0,7);
let _msLastSeen=parseInt(localStorage.getItem(_msMonthKey)||'0');
let _msFirstLoad=true;
let _msTimeout=null;
let _msConfettiStop=null;
let _msPending=null;
let lastCelebTrackIdx=-1;

// ── Background music ──────────────────────────────────────────────────────
const bgTracks=(window.TV_CFG&&window.TV_CFG.bgTracks)||[];
const BG_VOLUME=0.35;
let bgIdx=-1,bgFadeInterval=null,bgPlaying=true,bgEnabled=true,lastBgNextTrack='';
let bgQueue=[],bgQueuePos=0; // shuffle queue state
let bgPendingUrl=null; // track URL requested by admin while autoplay was blocked

function _bgAudio(){return document.getElementById('bgAudio');}

// Fisher-Yates shuffle; avoids starting the new cycle with the last-played track
function _bgShuffle(){
  const arr=bgTracks.map((_,i)=>i);
  for(let i=arr.length-1;i>0;i--){
    const j=Math.floor(Math.random()*(i+1));
    [arr[i],arr[j]]=[arr[j],arr[i]];
  }
  // If only one track, nothing to avoid
  if(arr.length>1&&arr[0]===bgIdx){
    const swap=Math.floor(Math.random()*(arr.length-1))+1;
    [arr[0],arr[swap]]=[arr[swap],arr[0]];
  }
  return arr;
}

// Returns the next index from the shuffle queue, refilling when exhausted
function _bgNext(){
  if(!bgQueue.length||bgQueuePos>=bgQueue.length){
    bgQueue=_bgShuffle();
    bgQueuePos=0;
  }
  return bgQueue[bgQueuePos++];
}

function _bgLoadAndPlay(idx){
  bgIdx=idx;
  const a=_bgAudio();if(!a)return;
  a.src=bgTracks[bgIdx];
  a.volume=BG_VOLUME;
  // Navegadores bloqueiam autoplay COM som sem interação prévia do usuário — o que
  // nunca acontece numa TV. Autoplay MUDO é sempre permitido, então começamos assim
  // e desmutamos logo em seguida (permitido pelo navegador sem precisar de gesto novo).
  a.muted=false;
  a.load();
  a.play().then(()=>{
    bgPlaying=true;hideBgEnableBtn();
    setTimeout(()=>{a.muted=false;},300);
  }).catch(()=>{bgPlaying=true;showBgEnableBtn();});
}

function _bgSetOnEnded(){
  const a=_bgAudio();if(!a)return;
  a.onended=function(){if(bgEnabled&&!bgFadeInterval)_bgLoadAndPlay(_bgNext());};
}

function startBgMusic(){
  if(!bgTracks.length)return;
  bgEnabled=true;
  _bgSetOnEnded();
  _bgLoadAndPlay(_bgNext());
}

// Jump to a specific track by filename (called by fetchData when admin picks one)
function jumpToBgTrack(name){
  // Build the URL directly — avoids stale static array for newly uploaded tracks
  const url='music/bg/'+name;
  // If the track exists in the static array, update queue state
  const idx=bgTracks.findIndex(t=>t===name||t.split('/').pop()===name);
  if(idx>=0){bgQueue=bgQueue.slice(bgQueuePos).filter(i=>i!==idx);bgQueuePos=0;bgIdx=idx;}
  // Admin-requested play always enables music and attempts playback
  bgEnabled=true;
  if(bgFadeInterval){clearInterval(bgFadeInterval);bgFadeInterval=null;}
  _bgSetOnEnded();
  const a=_bgAudio();if(!a)return;
  a.src=url;a.volume=BG_VOLUME;a.muted=true;a.load();
  a.play()
    .then(()=>{bgPlaying=true;bgPendingUrl=null;hideBgEnableBtn();setTimeout(()=>{a.muted=false;},300);})
    .catch(()=>{
      // Browser blocked autoplay: store pending URL so enableBgMusic resumes it
      bgPlaying=false;bgPendingUrl=url;showBgEnableBtn();
    });
}

function enableBgMusic(){
  hideBgEnableBtn();
  bgEnabled=true;
  const a=_bgAudio();
  if(bgPendingUrl){
    // Admin requested a specific track while autoplay was blocked — resume it now
    const url=bgPendingUrl;bgPendingUrl=null;
    if(a){a.src=url;a.volume=BG_VOLUME;a.load();a.play().then(()=>{bgPlaying=true;}).catch(()=>{bgPlaying=false;showBgEnableBtn();});}
    return;
  }
  if(!bgTracks.length)return;
  _bgLoadAndPlay(bgIdx<0?_bgNext():bgIdx);
}

function showBgEnableBtn(){
  if(!bgTracks.length)return;
  const b=document.getElementById('audioEnableBtn');if(b)b.style.display='flex';
}
function hideBgEnableBtn(){
  const b=document.getElementById('audioEnableBtn');if(b)b.style.display='none';
}

function fadeBgOut(ms,cb){
  const a=_bgAudio();if(!a||!bgPlaying){if(cb)cb();return;}
  if(bgFadeInterval){clearInterval(bgFadeInterval);bgFadeInterval=null;}
  const start=a.volume,steps=20,step_ms=ms/steps;let s=0;
  bgFadeInterval=setInterval(()=>{
    s++;a.volume=Math.max(0,start*(1-s/steps));
    if(s>=steps){clearInterval(bgFadeInterval);bgFadeInterval=null;a.pause();bgPlaying=false;if(cb)cb();}
  },step_ms);
}

function fadeBgIn(ms){
  if(!bgEnabled||!bgTracks.length)return;
  const a=_bgAudio();if(!a)return;
  if(bgFadeInterval){clearInterval(bgFadeInterval);bgFadeInterval=null;}
  if(!a.src||a.src===location.href||a.src===window.location.href)a.src=bgTracks[bgIdx<0?0:bgIdx];
  a.volume=0;
  a.play().then(()=>{
    bgPlaying=true;
    const steps=20,step_ms=ms/steps;let s=0;
    bgFadeInterval=setInterval(()=>{
      s++;a.volume=Math.min(BG_VOLUME,BG_VOLUME*(s/steps));
      if(s>=steps){clearInterval(bgFadeInterval);bgFadeInterval=null;}
    },step_ms);
  }).catch(()=>{bgPlaying=false;});
}

// ── Podium mode ────────────────────────────────────────────────────────────
const ALL_MODES   = ['semanal','geral','equipe_semanal','equipe_mensal'];
const MODE_LABELS = { semanal:'RANKING SEMANAL', geral:'RANKING MENSAL', equipe_semanal:'EQUIPES — SEMANAL', equipe_mensal:'EQUIPES — MENSAL' };
// ── Retrospectiva: HALL DA FAMA em tela cheia — versão LEVE (mesmas animações do dashboard normal; sem confete, sem contador) ──
// Item da rotação (mode 'retro_semana' = semana anterior, 'retro_mes' = mês anterior). Dados: get_retrospectiva.
const RETRO_MODES=['retro_semana','retro_mes'];
let retroData=null,retroPromise=null;
const isRetroMode=m=>RETRO_MODES.includes(m);
const isRetroMes=m=>m==='retro_mes';
const retroPeriodo=m=>retroData?(isRetroMes(m)?retroData.mes:retroData.semana):null;
// Sem contrato no período a tela seria vazia e gastaria o tempo da rotação: o item é pulado.
const retroDisponivel=m=>{const p=retroPeriodo(m);return !!p&&p.total>0&&(p.closers||[]).some(c=>c.vendas>0);};
const rotationUsaRetro=()=>rotationData.some(i=>i.type==='podium'&&isRetroMode(i.mode));
// Uma carga por vez: quem chama enquanto outra está em andamento recebe a MESMA promessa e espera por ela.
function loadRetro(){
  if(!retroPromise)retroPromise=(async()=>{
    try{
      const j=await fetch(API_URL+'?action=get_retrospectiva&_t='+Date.now()).then(r=>r.json());
      if(j&&j.success)retroData={semana:j.semana,mes:j.mes};
    }catch(e){}
    retroPromise=null;
  })();
  return retroPromise;
}

const hallEl=()=>document.getElementById('hall');
const hallStageEl=()=>document.getElementById('hallStage');
const isHallVisible=()=>{const h=hallEl();return !!h&&h.classList.contains('on');};
const hexOk=(c,d)=>/^#[0-9a-f]{6}$/i.test(c||'')?c:(d||'#d4af37');
// cor de destaque: se a cor do time for escura (some no fundo preto), clareia em direção ao branco
function hallTone(hex,d){
  const c=hexOk(hex,d),v=parseInt(c.slice(1),16),r=v>>16,g=(v>>8)&255,b=v&255;
  if((0.2126*r+0.7152*g+0.0722*b)/255>=0.5)return c;
  const f=x=>Math.round(x+(255-x)*0.62).toString(16).padStart(2,'0');
  return '#'+f(r)+f(g)+f(b);
}
let hallHiding=false,hallAfter=[],hallRingTimer=null;

const HALL_CROWN='<svg class="hall-crown" viewBox="0 0 64 52" aria-hidden="true"><path d="M4 42 L8 12 L21 26 L32 5 L43 26 L56 12 L60 42 Z" fill="url(#hallGold)" stroke="#ffd700" stroke-width="1.2" stroke-linejoin="round"/><rect x="4" y="42" width="56" height="6" rx="2.5" fill="url(#hallGold)"/><circle cx="8" cy="11" r="3.2" fill="#fff"/><circle cx="32" cy="4" r="3.6" fill="#fff"/><circle cx="56" cy="11" r="3.2" fill="#fff"/></svg>';
const HALL_TROPHY='<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M14 5h20v13a10 10 0 0 1-20 0z" fill="url(#hallGold)"/><path d="M14 9H6c0 8 3 12 9 13M34 9h8c0 8-3 12-9 13" fill="none" stroke="#ffd700" stroke-width="2.4" stroke-linecap="round"/><rect x="21" y="26" width="6" height="8" fill="url(#hallGold)"/><rect x="14" y="34" width="20" height="5" rx="2" fill="url(#hallGold)"/><rect x="11" y="39" width="26" height="4" rx="2" fill="#9a7500"/></svg>';
const HALL_DEFS='<svg class="hall-defs" aria-hidden="true"><defs><linearGradient id="hallGold" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffd700"/><stop offset=".5" stop-color="#d4af37"/><stop offset="1" stop-color="#9a7500"/></linearGradient></defs></svg>';

// tamanho inicial da fonte conforme o tamanho do nome (depois hallFitNames encolhe o que não couber)
function hallFont(txt,steps){const l=String(txt||'').length;return (steps.find(s=>l<=s[0])||steps[steps.length-1])[1];}
// iniciais + foto por cima (se a foto falhar, a imagem é removida e as iniciais ficam)
function hallAv(src,ini){
  return `<span class="ini">${esc(ini)}</span>${src?`<img src="${esc(src)}" alt="" onerror="this.remove()">`:''}`;
}
const hallIni=n=>String(n||'?').trim().charAt(0).toUpperCase();

function hallHTML(mode){
  const p=retroPeriodo(mode),mes=isRetroMes(mode);
  const top=(p.closers||[]).filter(c=>c.vendas>0).slice(0,3);
  const t=p.melhor_equipe;
  const periodo=((p.label||'').split(' · ')[1]||'').toUpperCase().replace(' A ',' → ');
  const col=(v,r)=>{
    if(!v)return '';
    const metal=['','gold','silver','bronze'][r],src=getImageSrc(v);
    return `<div class="hall-col r${r} ${metal}"><div class="hall-float">
      ${r===1?HALL_CROWN:''}
      <div class="hall-card">
        <div class="hall-avw"><div class="hall-av">${hallAv(src,hallIni(v.name))}</div><b class="hall-medal">${r}º</b></div>
        <div class="hall-name" style="--nf:${hallFont(v.name,r===1?[[12,54],[16,46],[20,38],[99,30]]:[[12,44],[16,38],[20,32],[99,26]])}px">${esc(v.name)}</div>
        <div class="hall-tm"><i style="--tc:${hallTone(v.equipe_cor)}"></i>${esc(v.equipe_nome||'Sem equipe')}</div>
        <div class="hall-big"><b>${v.vendas}</b><span>CONTRATOS</span></div>
        <div class="hall-meter"><i style="--p:${Math.min(100,v.score)}%"></i></div>
        <div class="hall-meta"><span>${v.score}% DA META</span><span>META ${v.metas}</span></div>
        <div class="hall-badge${v.bateu?' ok':''}">${v.bateu?'✔ META BATIDA':'FALTARAM '+v.faltam}</div>
      </div></div>
      <div class="hall-ped"><b>${r}</b></div>
    </div>`;
  };
  let team='';
  if(t){
    const tc=hallTone(t.cor),C=298,O=(C*(1-Math.min(100,t.score||0)/100)).toFixed(1);
    const squad=(p.closers||[]).filter(x=>x.equipe_id===t.id&&x.vendas>0).sort((a,b)=>b.vendas-a.vendas).slice(0,5).map(m=>{
      const s=getImageSrc(m);
      return `<div class="m" title="${esc(m.name)}">${esc(hallIni(m.name))}${s?`<img src="${esc(s)}" alt="" onerror="this.remove()">`:''}</div>`;
    }).join('');
    team=`<aside class="hall-team" style="--tc:${tc}"><div class="hall-tcard">
      <div class="hall-tk">${HALL_TROPHY}<span>MELHOR EQUIPE</span></div>
      <div class="hall-tlogo" style="--c:${C};--o:${O}">
        <svg class="ring" viewBox="0 0 100 100" aria-hidden="true"><circle class="bg" cx="50" cy="50" r="47.4"/><circle class="fill" cx="50" cy="50" r="47.4"/></svg>
        <div class="hall-tin">${hallAv(t.imageUrl,hallIni(t.nome))}</div>
        <div class="hall-tpct">${t.score||0}%</div>
      </div>
      <div class="hall-tname" style="--nf:${hallFont(t.nome,[[10,60],[14,50],[18,40],[99,32]])}px">${esc(t.nome)}</div>
      <div class="hall-tstats"><div><b>${t.vendas||0}</b><span>CONTRATOS</span></div><div><b>${t.metas||0}</b><span>META</span></div><div><b>${t.members||0}</b><span>CLOSERS</span></div></div>
      ${squad?`<div class="hall-squad">${squad}</div>`:''}
      ${p.vice?`<div class="hall-vice">2º lugar · <b>${esc(p.vice.nome)}</b> · ${p.vice.score}%</div>`:''}
    </div></aside>`;
  }
  return HALL_DEFS+`
    <div class="hall-head"><div class="hall-kick">★ HALL DA FAMA ★</div><div class="hall-title">RETROSPECTIVA</div>
      <div class="hall-sub"><span>${mes?'DO MÊS':'DA SEMANA'}</span><small>${esc(periodo)}</small></div></div>
    <div class="hall-podium">${col(top[1],2)}${col(top[0],1)}${col(top[2],3)}</div>
    ${team}
    <div class="hall-foot"><span>TOTAL DE CONTRATOS<b>${p.total}</b></span><i></i><span>METAS BATIDAS<b>${p.bateram}/${(p.closers||[]).length}</b></span><i></i><span>EQUIPES<b>${(p.equipes||[]).length}</b></span></div>`;
}

// cada nome começa grande e encolhe (passos de 2px) até caber numa linha. Se mesmo assim não couber: closer com 3+ palavras
// vira "primeiro + último nome" (o completo fica no title); depois encolhe mais; por fim reticências. Equipe quebra em até 2 linhas.
function hallFitNames(){
  document.querySelectorAll('#hall .hall-name,#hall .hall-tname').forEach(el=>{
    const team=el.classList.contains('hall-tname'),base=parseFloat(getComputedStyle(el).fontSize);
    const fits=()=>el.scrollWidth<=el.clientWidth+1;
    const shrink=min=>{let fs=parseFloat(getComputedStyle(el).fontSize),g=60;while(!fits()&&fs>min&&g--){fs-=2;el.style.setProperty('--nf',fs+'px');}return fits();};
    if(shrink(team?30:26))return;
    if(team){
      el.style.setProperty('--nf',Math.min(base,40)+'px');
      el.style.whiteSpace='normal';el.style.display='-webkit-box';el.style.webkitBoxOrient='vertical';el.style.webkitLineClamp='2';el.style.overflow='hidden';
      return;
    }
    const w=el.textContent.trim().split(/\s+/);
    if(w.length>2){el.title=el.textContent.trim();el.textContent=w[0]+' '+w[w.length-1];el.style.setProperty('--nf',base+'px');if(shrink(26))return;}
    if(shrink(22))return;
    el.style.overflow='hidden';el.style.textOverflow='ellipsis';
  });
}
function hallFit(){
  const st=hallStageEl();if(!st)return;
  st.style.setProperty('--hs',Math.min(innerWidth/1920,innerHeight/1080));
}

function showHall(mode){
  const h=hallEl(),st=hallStageEl();
  if(!h||!st||!retroDisponivel(mode))return;
  if(isHallVisible()){hideHall(()=>showHall(mode));return;}      // já tem um Hall na tela: sai e entra o próximo
  st.innerHTML=hallHTML(mode);
  h.classList.toggle('solo',!retroPeriodo(mode).melhor_equipe);
  document.documentElement.classList.add('hall-open','hall-busy');
  hallFit();
  h.classList.add('on');
  hallFitNames();
  if(document.fonts&&document.fonts.ready)document.fonts.ready.then(()=>{if(isHallVisible())hallFitNames();});
  requestAnimationFrame(()=>h.classList.add('show'));
  // o anel de % da equipe enche logo depois de abrir (transição de 1,5 s no CSS), como os anéis do dashboard normal
  clearTimeout(hallRingTimer);
  const enche=()=>{const r=document.querySelector('#hall .hall-tlogo');if(r&&isHallVisible())r.classList.add('go');};
  if(window.matchMedia&&matchMedia('(prefers-reduced-motion:reduce)').matches)enche();else hallRingTimer=setTimeout(enche,300);
}
function hideHall(cb){
  const h=hallEl();
  if(!h||!h.classList.contains('on')){if(cb)cb();return;}
  if(cb)hallAfter.push(cb);
  if(hallHiding)return;
  hallHiding=true;clearTimeout(hallRingTimer);h.classList.remove('show');
  document.documentElement.classList.remove('hall-busy');          // a dashboard volta a ser desenhada por baixo do fade
  setTimeout(()=>{
    h.classList.remove('on');hallStageEl().innerHTML='';hallHiding=false;
    document.documentElement.classList.remove('hall-open');
    const fs=hallAfter;hallAfter=[];fs.forEach(f=>f());
  },450);
}
window.addEventListener('resize',()=>{if(isHallVisible())hallFit();});

// ── Rotation ────────────────────────────────────────────────────────────────
function getEffectiveRotation(){
  // Pódio de equipe precisa de >= 3 equipes; sem isso o item só mostraria o aviso
  // "Cadastre pelo menos 3 equipes" e gastaria o tempo dele na rotação à toa.
  const hasTeamsSem = teamsData && teamsData.length >= 3;
  const hasTeamsMes = teamsDataMensal && teamsDataMensal.length >= 3;
  const DEFAULT = [{type:'podium',mode:'semanal',duration:60}];
  const base = rotationData.length ? rotationData : DEFAULT;
  return base.filter(item=>{
    if(item.type==='podium'){
      if(item.mode==='equipe_semanal') return hasTeamsSem;
      if(item.mode==='equipe_mensal')  return hasTeamsMes;
      if(isRetroMode(item.mode)) return retroDisponivel(item.mode);
      return ALL_MODES.includes(item.mode);
    }
    if(item.type==='ad') return propagandasData.some(p=>p.id==item.propaganda_id&&(p.ativo==1||p.ativo===true));
    return false;
  });
}

function isAdPanelVisible(){
  const pp=document.getElementById('propaPanel');
  return pp&&pp.classList.contains('visible');
}

function updateModeBadge(){
  const lbl=document.getElementById('podiumModeLabel');
  if(lbl){lbl.style.opacity='0';setTimeout(()=>{lbl.textContent=MODE_LABELS[podiumMode]||'';lbl.style.opacity='1';},350);}
}

function scheduleNext(){
  if(rotationTimer)clearTimeout(rotationTimer);
  if(tvManual)return;                                  // modo manual (?rot=N): quem troca de item são as setas
  const eff=getEffectiveRotation();
  if(!eff.length)return;
  const item=eff[rotationIdx%eff.length];
  const dur=Math.max(5,(item.duration||60))*1000;
  rotationTimer=setTimeout(advanceRotation,dur);
}

function advanceRotation(){
  const eff=getEffectiveRotation();
  if(!eff.length)return;
  rotationIdx=(rotationIdx+1)%eff.length;
  applyRotationItem();
}

function applyRotationItem(){
  const eff=getEffectiveRotation();
  if(!eff.length)return;
  const item=eff[rotationIdx%eff.length];
  if(item.type==='podium'&&isRetroMode(item.mode)){
    hideAdPanel(()=>showHall(item.mode));
    scheduleNext();
    return;
  }
  if(isHallVisible())hideHall();          // sai do Hall (fade) enquanto o próximo item monta por trás
  if(item.type==='podium'){
    const prevMode=podiumMode;
    podiumMode=item.mode;
    hideAdPanel(()=>{
      if(prevMode!==podiumMode)updateModeBadge();
      if(!podiumTransitioning){
        podiumTransitioning=true;
        fadeBoard(()=>{renderPodium(true);renderRankingList();podiumTransitioning=false;});
      }
    });
  } else if(item.type==='ad'){
    const ad=propagandasData.find(p=>p.id==item.propaganda_id);
    if(!ad){advanceRotation();return;}
    showAdPanel(ad);
    renderRankingList();
  }
  scheduleNext();
}

function showAdPanel(ad){
  if(podiumTransitioning)return;
  podiumTransitioning=true;
  const pp=document.getElementById('propaPanel');
  if(pp){
    // O anúncio roda num iframe (srcdoc) com documento próprio: CSS, scripts e nomes do anúncio não colidem com os da TV
    // (antes o HTML ia direto no innerHTML e um 'const esc' do anúncio derrubava o script da TV e do próprio anúncio).
    pp.innerHTML='';
    const fr=document.createElement('iframe');
    fr.setAttribute('title','Anúncio');
    fr.style.cssText='position:absolute;inset:0;width:100%;height:100%;border:0;display:block;background:#000;';
    fr.srcdoc=ad.conteudo_html||'';
    pp.appendChild(fr);
    pp.style.display='flex';
    requestAnimationFrame(()=>requestAnimationFrame(()=>pp.classList.add('visible')));
  }
  setTimeout(()=>{podiumTransitioning=false;},500);
}

function hideAdPanel(cb){
  const pp=document.getElementById('propaPanel');
  if(!pp||!pp.classList.contains('visible')){if(cb)cb();return;}
  pp.classList.remove('visible');
  setTimeout(()=>{
    pp.style.display='none';
    pp.innerHTML='';
    if(cb)cb();
  },500);
}

function tryStartRotation(){
  if(rotationStarted||!dataLoaded||!rotationLoaded)return;
  const eff=getEffectiveRotation();
  if(!eff.length)return;       // nada exibível ainda (ex.: retrospectiva sem dados carregados): tenta de novo na próxima carga
  rotationStarted=true;
  if(tvManual)rotationIdx=Math.min(TV_MANUAL_ROT,eff.length)-1;
  const first=eff[rotationIdx%eff.length];
  if(first.type==='podium'){
    if(isRetroMode(first.mode)){showHall(first.mode);}
    else if(first.mode!==podiumMode){
      podiumMode=first.mode;
      updateModeBadge();
      renderPodium(false);
      renderRankingList();
    }
  } else if(first.type==='ad'){
    const ad=propagandasData.find(p=>p.id==first.propaganda_id&&(p.ativo==1||p.ativo===true));
    if(ad){showAdPanel(ad);renderRankingList();}
    else{rotationIdx=1;return applyRotationItem();}
  }
  scheduleNext();
}

// ── Navegação manual da rotação (PC, notebook, celular) ─────────────────────────────────────────
// Na TV (sem mouse nem toque) as setas nunca aparecem e a rotação segue sozinha. Em aparelhos com ponteiro, as setas aparecem
// quando alguém mexe o mouse ou toca na tela e somem após 3,5 s parado. Ao usar uma seta, a rotação automática para e a pessoa
// passa pelos itens; recarregar a página volta à rotação automática. Com ?rot=N a página já abre no item N (1 = primeiro).
const TV_MANUAL_ROT=(()=>{const n=parseInt(new URLSearchParams(location.search).get('rot'),10);return n>=1?n:null;})();
let tvManual=TV_MANUAL_ROT!==null;
const TV_PTR=matchMedia('(any-pointer:fine),(any-pointer:coarse)').matches;
let tvNavTimer=null;
function tvNavReveal(){
  if(!TV_PTR)return;
  document.documentElement.classList.add('nav-on');
  clearTimeout(tvNavTimer);
  tvNavTimer=setTimeout(()=>document.documentElement.classList.remove('nav-on'),3500);
}
function tvStep(dir){
  const eff=getEffectiveRotation();
  if(!eff.length)return;
  if(!tvManual){tvManual=true;clearTimeout(rotationTimer);}   // a pessoa escolheu: a rotação automática para
  rotationIdx=((rotationIdx+dir)%eff.length+eff.length)%eff.length;
  applyRotationItem();
}

// ── Audio ──────────────────────────────────────────────────────────────────
function playCelebrationSound(vol){
  const a=document.getElementById('celebrationAudio');
  if(!a||!celebrationTracks.length)return;
  let idx=Math.floor(Math.random()*celebrationTracks.length);
  if(celebrationTracks.length>1) while(idx===lastCelebTrackIdx) idx=Math.floor(Math.random()*celebrationTracks.length);
  lastCelebTrackIdx=idx;
  a.pause();a.src=celebrationTracks[idx];a.preload='auto';a.load();a.volume=vol;a.currentTime=0;
  a.play().catch(()=>{});
}

// ── Stats strip ───────────────────────────────────────────────────────────
function updateStatsStrip(j){
  // Contratos da semana
  const semana = j.total_semana ?? (j.data||[]).reduce((s,v)=>s+(v.vendas||0),0);
  const metaSem = j.meta_semana_total ?? (j.data||[]).reduce((s,v)=>s+(v.metas||0),0);
  const pct = metaSem > 0 ? Math.round(semana/metaSem*100) : 0;
  const el = document.getElementById('statSemana');
  if(el) el.textContent = semana;
  const obs = document.getElementById('statSemanaObs');
  if(obs) obs.textContent = metaSem > 0 ? `${pct}% da meta` : 'sem meta';

  // Meta semanal total
  const ms = document.getElementById('statMetaSem');
  if(ms) ms.textContent = metaSem || '–';
  const mo = document.getElementById('statMetaObs');
  if(mo) mo.textContent = metaSem > 0 ? `faltam ${Math.max(0,metaSem-semana)}` : '–';

  // Meta de hoje — soma das metas diárias específicas de hoje, só entre quem
  // tem uma definida naquele dia (o calendário do admin é opcional por closer).
  const hojeIdx = weekDatesFull.indexOf(_todayYMD());
  let metaHoje = 0, contratosHoje = 0, hasMetaHoje = false;
  if(hojeIdx >= 0 && Array.isArray(j.data)) j.data.forEach(v=>{
    const m=(v.daily_metas||[])[hojeIdx];
    if(m!=null && m>0){ metaHoje+=m; hasMetaHoje=true; }
    contratosHoje += (v.daily||[])[hojeIdx]||0;
  });
  const hTile=document.getElementById('statHojeTile'), hSep=document.getElementById('statHojeSep');
  if(hTile) hTile.style.display = hasMetaHoje ? '' : 'none';
  if(hSep)  hSep.style.display  = hasMetaHoje ? '' : 'none';
  if(hasMetaHoje){
    const hv=document.getElementById('statHoje'), ho=document.getElementById('statHojeObs');
    const bateuHoje = contratosHoje >= metaHoje;
    if(hv){ hv.textContent = `${contratosHoje}/${metaHoje}`; hv.classList.toggle('good', bateuHoje); }
    if(ho) ho.textContent = bateuHoje ? 'meta batida!' : `faltam ${metaHoje-contratosHoje}`;
  }

  // CAC
  const cac = j.cac;
  const tile = document.getElementById('statCacTile');
  const cv = document.getElementById('statCac');
  const co = document.getElementById('statCacObs');
  const show = cac !== null && cac !== undefined;
  if(tile) tile.style.display = show ? '' : 'none';
  const sep = document.getElementById('statCacSep');
  if(sep) sep.style.display = show ? '' : 'none';
  if(cv && cac != null) cv.textContent = 'R$ '+cac.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  if(co && cac != null) co.textContent = `${j.total_mes||0} contratos no mês`;
}

// ── Global popup queue ──────────────────────────────────────────────────────
// Único ponto de entrada pra exibir qualquer overlay (celebration, milestone,
// goal, custom popup, cerimônia). Garante que só um fica visível por vez —
// os outros esperam na fila em vez de aparecer um em cima do outro. Cada
// função de "show" chama _enqueuePopup ao abrir e cada função de "close"
// chama _popupDone ao terminar (depois do fade, quando o overlay já sumiu).
let _popupBusy=false;
const _popupQueue=[];
function _enqueuePopup(runFn){
  _popupQueue.push(runFn);
  _tryRunNextPopup();
}
function _tryRunNextPopup(){
  if(_popupBusy||!_popupQueue.length) return;
  _popupBusy=true;
  _popupQueue.shift()();
}
function _popupDone(){
  _popupBusy=false;
  _tryRunNextPopup();
}

// ── Data fetch ────────────────────────────────────────────────────────────
// Sempre celebração de "novo contrato" — bater a meta semanal já tem seu
// próprio popup dedicado via checkGoalHits (que usa a meta EFETIVA, com
// override de período incluído). checkGoalHits roda antes desta função a
// cada fetchData e preenche _goalHitNames — se o mesmo contrato que gerou
// esta venda também bateu alguma meta, mostra só o popup de meta.
function checkNewSales(nd){
  if(firstLoad){nd.forEach(v=>{previousVendas[v.name]=v.vendas});firstLoad=false;return;}
  nd.forEach(v=>{
    const p=previousVendas[v.name]||0,c=v.vendas;
    if(c>p&&!_goalHitNames.has(v.name)) _enqueuePopup(()=>showCelebration(v.name,c-p));
    previousVendas[v.name]=c;
  });
}

// ── Milestone (every 100 contracts this month) ────────────────────────────
function checkMilestone(total){
  const t=parseInt(total)||0;
  const milestone=Math.floor(t/100)*100;
  if(_msFirstLoad){
    _msFirstLoad=false;
    if(milestone>_msLastSeen){_msLastSeen=milestone;localStorage.setItem(_msMonthKey,milestone);}
    return;
  }
  // Total dropped below the last shown milestone — reset so it can re-fire when it comes back up
  if(milestone<_msLastSeen){
    _msLastSeen=milestone;
    localStorage.setItem(_msMonthKey,milestone);
    return;
  }
  if(milestone>0&&milestone>_msLastSeen){
    _msLastSeen=milestone;
    localStorage.setItem(_msMonthKey,milestone);
    _queueMilestone(milestone);
  }
}

function _queueMilestone(n){
  _msPending=n; // se vários chegarem enquanto espera na fila, só o mais recente é mostrado
  _enqueuePopup(()=>{
    if(_msPending==null){_popupDone();return;} // já mostrado/superado por um milestone mais novo
    const show=_msPending; _msPending=null;
    showMilestone(show);
  });
}

function showMilestone(n){
  const o=document.getElementById('milestoneOverlay'); if(!o) return;
  document.getElementById('msNumber').textContent=n;
  const headlines={100: "MARCO HISTÓRICO!",
  200: "DUPLO CENTENÁRIO!",
  300: "TRIPLAMENTE LENDÁRIO!",
  400: "POTÊNCIA TOTAL!",
  500: "MEIO MILHAR DE LENDAS!",
  600: "SEXTUPLO IMPACTO!",
  700: "MAGNITUDE MÁXIMA!",
  800: "FORÇA TITÂNICA!",
  900: "IMPÉRIO CONSTRUÍDO!",
  1000: "A META DOS SONHOS: 1K!"};
  document.getElementById('msHeadline').textContent=headlines[n]||(n+' — IMPARÁVEIS!');
  // reset bar animation
  const bar=document.getElementById('msBarFill');
  bar.style.animation='none'; bar.offsetHeight; bar.style.animation='';
  o.classList.add('show');
  if(_msConfettiStop) _msConfettiStop();
  _msConfettiStop=_startConfetti();
  playCelebrationSound(0.85);
  if(_msTimeout) clearTimeout(_msTimeout);
  _msTimeout=setTimeout(closeMilestone,30000);
}

function closeMilestone(){
  const o=document.getElementById('milestoneOverlay'); if(!o) return;
  o.style.opacity='0'; o.style.transition='opacity .6s';
  setTimeout(()=>{o.classList.remove('show');o.style.opacity='';o.style.transition='';_popupDone();},620);
  if(_msConfettiStop){_msConfettiStop();_msConfettiStop=null;}
  if(_msTimeout){clearTimeout(_msTimeout);_msTimeout=null;}
}

// Data de hoje no fuso do navegador da TV, no mesmo formato 'Y-m-d' que o
// servidor usa em weekDatesFull — evita o desvio de fuso do toISOString()
// (que é UTC) perto da virada da meia-noite local.
function _todayYMD(){
  const d=new Date();
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
}

// ── Goal popups (closer daily/weekly/monthly, team weekly) ────────────────
const _goalMonthKey='goa_'+new Date().toISOString().slice(0,7);
let _goalQueue=[],_goalShowing=false,_goalTimeout=null,_goalFirstLoad=true,_goalConfettiStop=null;
// Nomes de closers que bateram alguma meta nesta rodada de checkGoalHits —
// checkNewSales consulta isso pra não mostrar "novo contrato" em cima de quem
// já vai ganhar o popup de meta (evita os dois popups pro mesmo contrato).
let _goalHitNames=new Set();

function _goalKey(type,id){return `${_goalMonthKey}_${type}_${id}`;}

// Meta mensal efetiva do closer. Prioriza a meta cadastrada (metas_periodo
// semana_num=0 ou vendedores.meta_mensal) e, quando não há nenhuma, cai na
// projeção meta_semanal x semanas do mês (meta_m_val, vindo da api.php) — que é
// exatamente a base do score_mensal mostrado no painel.
// Sem esse fallback, quem só tem meta SEMANAL cadastrada ficava com metaMes=0 e
// o popup de meta do mês nunca disparava, mesmo com o card marcando 100%.
function _metaMensalEfetiva(v){
  const cadastrada = v.meta_m_eff ?? v.meta_mensal ?? 0;
  return cadastrada > 0 ? cadastrada : (v.meta_m_val ?? 0);
}

// Meta e vendas do dia corrente pro closer, a partir de daily_metas/daily
// (mesmo índice de weekDatesFull). Retorna null quando hoje não tem meta
// diária definida ou não está dentro da semana atual carregada.
function _metaHojeDoCloser(v){
  const idx=weekDatesFull.indexOf(_todayYMD());
  if(idx<0) return null;
  const meta=(v.daily_metas||[])[idx];
  if(meta==null||meta<=0) return null;
  return {meta, vendas:(v.daily||[])[idx]||0};
}

function checkGoalHits(data,totalSemana,metaSemanaTot,totalHoje,metaHojeTot){
  if(_goalFirstLoad){
    _goalFirstLoad=false;
    if(Array.isArray(data)) data.forEach(v=>{
      const hoje=_metaHojeDoCloser(v);
      if(hoje&&hoje.vendas>=hoje.meta) localStorage.setItem(_goalKey('dia',`${v.id}_${_todayYMD()}`),String(hoje.vendas));
      if((v.meta_s_eff||0)>0&&v.vendas>=(v.meta_s_eff||0)) localStorage.setItem(_goalKey('sem',v.id),String(v.vendas));
      const metaMes=_metaMensalEfetiva(v);
      if(metaMes>0&&(v.vendas_mes||0)>=metaMes) localStorage.setItem(_goalKey('mes',v.id),String(v.vendas_mes));
    });
    if((metaSemanaTot||0)>0&&(totalSemana||0)>=(metaSemanaTot||0)) localStorage.setItem(_goalKey('team','sem'),'1');
    if((metaHojeTot||0)>0&&(totalHoje||0)>=(metaHojeTot||0)) localStorage.setItem(_goalKey('diageral',_todayYMD()),'1');
    _goalHitNames.clear();
    return;
  }
  const toQ=[];
  if(Array.isArray(data)) data.forEach(v=>{
    const hoje=_metaHojeDoCloser(v);
    if(hoje){
      const k=_goalKey('dia',`${v.id}_${_todayYMD()}`);
      if(hoje.vendas>=hoje.meta){
        if(!parseInt(localStorage.getItem(k)||'0')||parseInt(localStorage.getItem(k))<hoje.meta){
          localStorage.setItem(k,String(hoje.vendas)); toQ.push({type:'dia',v,metaDia:hoje.meta,vendasDia:hoje.vendas});
        }
      } else { localStorage.removeItem(k); }
    }

    const metaSem=v.meta_s_eff||0;
    if(metaSem>0&&v.vendas>=metaSem){
      const k=_goalKey('sem',v.id);
      if(!parseInt(localStorage.getItem(k)||'0')||parseInt(localStorage.getItem(k))<metaSem){
        localStorage.setItem(k,String(v.vendas)); toQ.push({type:'sem',v});
      }
    } else { localStorage.removeItem(_goalKey('sem',v.id)); }

    const metaMes=_metaMensalEfetiva(v);
    if(metaMes>0&&(v.vendas_mes||0)>=metaMes){
      const k=_goalKey('mes',v.id);
      if(!parseInt(localStorage.getItem(k)||'0')||parseInt(localStorage.getItem(k))<metaMes){
        localStorage.setItem(k,String(v.vendas_mes)); toQ.push({type:'mes',v,metaMes});
      }
    } else { localStorage.removeItem(_goalKey('mes',v.id)); }
  });
  if((metaSemanaTot||0)>0&&(totalSemana||0)>=(metaSemanaTot||0)){
    const k=_goalKey('team','sem');
    if(!localStorage.getItem(k)){ localStorage.setItem(k,'1'); toQ.push({type:'team',totalSemana,metaSemanaTot}); }
  } else { localStorage.removeItem(_goalKey('team','sem')); }
  if((metaHojeTot||0)>0&&(totalHoje||0)>=(metaHojeTot||0)){
    const k=_goalKey('diageral',_todayYMD());
    if(!localStorage.getItem(k)){ localStorage.setItem(k,'1'); toQ.push({type:'diageral',totalHoje,metaHojeTot}); }
  } else { localStorage.removeItem(_goalKey('diageral',_todayYMD())); }
  // Quando o mesmo contrato fecha mais de uma meta de uma vez, a ordem na fila
  // é sempre: diária → geral do dia → semanal → mensal → time. O sort é
  // estável, então closers diferentes mantêm a ordem do ranking dentro de cada tipo.
  const _GOAL_PRIO={dia:0,diageral:1,sem:2,mes:3,team:4};
  toQ.sort((a,b)=>(_GOAL_PRIO[a.type]??9)-(_GOAL_PRIO[b.type]??9));
  _goalHitNames.clear();
  toQ.forEach(i=>{ if(i.v) _goalHitNames.add(i.v.name); });
  toQ.forEach(i=>_goalQueue.push(i));
  if(!_goalShowing){_goalShowing=true;_enqueuePopup(_processGoalQueue);}
}

function _processGoalQueue(){
  if(!_goalQueue.length){_goalShowing=false;_popupDone();return;}
  _doShowGoalPopup(_goalQueue.shift());
}

function _doShowGoalPopup(item){
  const o=document.getElementById('goalOverlay'); if(!o) return;
  o.dataset.popupType=item.type;
  if(item.type==='dia'){
    document.getElementById('goalBurst').textContent='⚡';
    document.getElementById('goalTypeLabel').textContent='META DO DIA';
    document.getElementById('goalName').textContent=item.v.name;
    document.getElementById('goalHeadline').textContent='META DO DIA BATIDA!';
    document.getElementById('goalSub').textContent=`${item.vendasDia} contratos hoje — meta de ${item.metaDia}`;
    document.getElementById('goalPhotoWrap').style.display='';
    _setGoalPhoto(item.v);
  } else if(item.type==='sem'){
    document.getElementById('goalBurst').textContent='🎯';
    document.getElementById('goalTypeLabel').textContent='META SEMANAL';
    document.getElementById('goalName').textContent=item.v.name;
    document.getElementById('goalHeadline').textContent='META SEMANAL BATIDA!';
    document.getElementById('goalSub').textContent=`${item.v.vendas} contratos — meta de ${item.v.meta_s_eff}`;
    document.getElementById('goalPhotoWrap').style.display='';
    _setGoalPhoto(item.v);
  } else if(item.type==='mes'){
    document.getElementById('goalBurst').textContent='⭐';
    document.getElementById('goalTypeLabel').textContent='META MENSAL';
    document.getElementById('goalName').textContent=item.v.name;
    document.getElementById('goalHeadline').textContent='META MENSAL BATIDA!';
    document.getElementById('goalSub').textContent=`${item.v.vendas_mes} contratos no mês — meta de ${item.metaMes}`;
    document.getElementById('goalPhotoWrap').style.display='';
    _setGoalPhoto(item.v);
  } else if(item.type==='team'){
    document.getElementById('goalBurst').textContent='🔥';
    document.getElementById('goalTypeLabel').textContent='META DO TIME';
    document.getElementById('goalName').textContent='TIME COMERCIAL';
    document.getElementById('goalHeadline').textContent='META DA SEMANA BATIDA!';
    document.getElementById('goalSub').textContent=`${item.totalSemana} contratos — meta de ${item.metaSemanaTot}`;
    document.getElementById('goalPhotoWrap').style.display='none';
  } else {
    document.getElementById('goalBurst').textContent='☀️';
    document.getElementById('goalTypeLabel').textContent='META GERAL DO DIA';
    document.getElementById('goalName').textContent='TIME COMERCIAL';
    document.getElementById('goalHeadline').textContent='META DO DIA BATIDA!';
    document.getElementById('goalSub').textContent=`${item.totalHoje} contratos hoje — meta de ${item.metaHojeTot}`;
    document.getElementById('goalPhotoWrap').style.display='none';
  }
  const bar=document.getElementById('goalBarFill');
  if(bar){bar.style.animation='none';bar.offsetHeight;bar.style.animation='';}
  o.classList.add('show');
  if(_goalConfettiStop) _goalConfettiStop();
  _goalConfettiStop=_startGoalConfetti(item.type);
  playCelebrationSound(0.8);
  if(_goalTimeout) clearTimeout(_goalTimeout);
  _goalTimeout=setTimeout(closeGoalPopup,30000);
}

function _setGoalPhoto(v){
  const el=document.getElementById('goalPhoto'); if(!el) return;
  el.innerHTML='';
  const src=v.imageUrl||v.imageBase64||(v.img?`https://i.pravatar.cc/150?img=${v.img}`:null);
  if(src){const img=document.createElement('img');img.src=src;img.style.cssText='width:100%;height:100%;object-fit:cover;';el.appendChild(img);}
  else{const d=document.createElement('div');d.className='initials';d.textContent=v.name.charAt(0);el.appendChild(d);}
}

function closeGoalPopup(){
  const o=document.getElementById('goalOverlay'); if(!o) return;
  o.style.opacity='0'; o.style.transition='opacity .6s';
  setTimeout(()=>{o.classList.remove('show');o.style.opacity='';o.style.transition='';},620);
  if(_goalConfettiStop){_goalConfettiStop();_goalConfettiStop=null;}
  if(_goalTimeout){clearTimeout(_goalTimeout);_goalTimeout=null;}
  setTimeout(_processGoalQueue,700);
}

// ── Custom popup (admin-triggered, two photos) ────────────────────────────
let _cpLastSeen=localStorage.getItem('_cpLastSeen')||'',_cpFirstLoad=true,_cpTimeout=null;

async function checkCustomPopup(){
  try{
    const j=await fetch(API_URL+'?action=get_custom_popup&_t='+Date.now()).then(r=>r.json());
    if(!j.success||!j.triggered_at) return;
    if(_cpFirstLoad){
      _cpFirstLoad=false;
      if(!_cpLastSeen){ _cpLastSeen=j.triggered_at; localStorage.setItem('_cpLastSeen',_cpLastSeen); }
      return;
    }
    if(j.triggered_at!==_cpLastSeen){
      _cpLastSeen=j.triggered_at;
      localStorage.setItem('_cpLastSeen',_cpLastSeen);
      _enqueuePopup(()=>showCustomPopup(j.titulo,j.foto1,j.foto2));
    }
  }catch(e){}
}

function _setCustomPopupPhoto(elId,url){
  const el=document.getElementById(elId); if(!el) return;
  el.innerHTML='';
  if(url){el.style.display='';const img=document.createElement('img');img.src=url;el.appendChild(img);}
  else el.style.display='none';
}

function showCustomPopup(titulo,foto1,foto2){
  const o=document.getElementById('customPopupOverlay'); if(!o) return;
  document.getElementById('customPopupTitle').textContent=titulo||'';
  _setCustomPopupPhoto('customPopupPhoto1',foto1);
  _setCustomPopupPhoto('customPopupPhoto2',foto2);
  const bar=document.getElementById('customPopupBarFill');
  if(bar){bar.style.animation='none';bar.offsetHeight;bar.style.animation='';}
  o.classList.add('show');
  playCelebrationSound(0.8);
  if(_cpTimeout) clearTimeout(_cpTimeout);
  _cpTimeout=setTimeout(closeCustomPopup,30000);
}

function closeCustomPopup(){
  const o=document.getElementById('customPopupOverlay'); if(!o) return;
  o.style.opacity='0'; o.style.transition='opacity .6s';
  setTimeout(()=>{o.classList.remove('show');o.style.opacity='';o.style.transition='';_popupDone();},620);
  if(_cpTimeout){clearTimeout(_cpTimeout);_cpTimeout=null;}
}

// ── Goal ceremony (admin-triggered, previous week's podium honorees, one at a time) ──
let _ceremQueue=[],_ceremShowing=false,_ceremTimeout=null,_ceremConfettiStop=null;
let _ceremLastSeen=localStorage.getItem('_ceremLastSeen')||'',_ceremFirstLoad=true;
let _ceremMusicUrl=null,_ceremFinalDuration=15;
const CEREMONY_SUSPENSE_MS=2200;
const CEREMONY_SUSPENSE_CHAMPION_MS=3800;
const CEREMONY_REVEAL_MS=5200;
const CEREMONY_REVEAL_CHAMPION_MS=7500;

async function checkCeremony(){
  try{
    const j=await fetch(API_URL+'?action=get_ceremony&_t='+Date.now()).then(r=>r.json());
    if(!j.success||!j.triggered_at) return;
    if(_ceremFirstLoad){
      _ceremFirstLoad=false;
      if(!_ceremLastSeen){ _ceremLastSeen=j.triggered_at; localStorage.setItem('_ceremLastSeen',_ceremLastSeen); }
      return;
    }
    if(j.triggered_at!==_ceremLastSeen){
      _ceremLastSeen=j.triggered_at;
      localStorage.setItem('_ceremLastSeen',_ceremLastSeen);
      if(Array.isArray(j.winners)&&j.winners.length)
        _enqueuePopup(()=>startCeremony(j.winners,j.musica||null,j.final_duration||15));
    }
  }catch(e){}
}

function startCeremony(winners,musicaUrl,finalDuration){
  // Reveal in reverse order (lowest-ranked first) so the champion closes the ceremony — builds suspense
  _ceremQueue=winners.slice().reverse();
  _ceremMusicUrl=musicaUrl;
  _ceremFinalDuration=Math.max(3,parseInt(finalDuration)||15);
  const o=document.getElementById('ceremonyOverlay');
  const prog=document.getElementById('ceremonyProgress');
  prog.innerHTML=_ceremQueue.map((_,i)=>`<span class="ceremony-dot" data-i="${i}"></span>`).join('');
  const pop=document.getElementById('ceremonyPopup');
  pop.style.display='';
  pop.classList.remove('champion-reveal');
  document.getElementById('ceremonyKicker').textContent='SOLENIDADE DE ENTREGA DE METAS';
  document.getElementById('ceremonyFinal').classList.remove('show');
  o.classList.add('show');
  _ceremShowing=true;
  fadeBgOut(800);
  if(_ceremMusicUrl){
    const a=document.getElementById('ceremonyAudio');
    if(a){a.pause();a.src=_ceremMusicUrl;a.volume=0.75;a.currentTime=0;a.play().catch(()=>{});}
  }
  _showCeremonyItem(0);
}

function _showCeremonyItem(idx){
  if(idx>=_ceremQueue.length){ _showCeremonyFinal(); return; }
  const item=_ceremQueue[idx];
  const isChampion=item.pos===1;
  document.querySelectorAll('#ceremonyProgress .ceremony-dot').forEach((d,i)=>{
    d.classList.toggle('active',i===idx);
    d.classList.toggle('done',i<idx);
  });
  const pop=document.getElementById('ceremonyPopup');
  const rank=item.pos<=3?String(item.pos):'other';
  pop.dataset.rank=rank;
  pop.classList.remove('champion-reveal');
  pop.classList.add('suspense');
  document.getElementById('ceremonyKicker').textContent=isChampion?'E O CAMPEÃO DA SEMANA É...':'SOLENIDADE DE ENTREGA DE METAS';
  if(_ceremConfettiStop){_ceremConfettiStop();_ceremConfettiStop=null;}
  if(_ceremTimeout) clearTimeout(_ceremTimeout);
  const suspenseMs=isChampion?CEREMONY_SUSPENSE_CHAMPION_MS:CEREMONY_SUSPENSE_MS;
  const revealMs=isChampion?CEREMONY_REVEAL_CHAMPION_MS:CEREMONY_REVEAL_MS;
  _ceremTimeout=setTimeout(()=>{
    _revealCeremonyItem(item,pop,rank,isChampion);
    _ceremTimeout=setTimeout(()=>_showCeremonyItem(idx+1),revealMs);
  },suspenseMs);
}

function _revealCeremonyItem(item,pop,rank,isChampion){
  pop.classList.remove('suspense');
  if(isChampion) pop.classList.add('champion-reveal');
  document.getElementById('ceremonyKicker').textContent='SOLENIDADE DE ENTREGA DE METAS';
  const medals={1:'🥇',2:'🥈',3:'🥉'};
  document.getElementById('ceremonyMedal').textContent=medals[item.pos]||'🏅';
  document.getElementById('ceremonyRankLabel').textContent=isChampion?'CAMPEÃO DA SEMANA':(item.pos<=3?`${item.pos}º LUGAR`:`${item.pos}º LUGAR — META BATIDA`);
  document.getElementById('ceremonyName').textContent=item.name;
  document.getElementById('ceremonyTeam').textContent=item.equipeNome||'';
  document.getElementById('ceremonyStats').textContent=`${item.vendas} contratos — meta ${item.meta}`;
  document.getElementById('ceremonySub').textContent=isChampion?'A meta semanal foi conquistada com louvor!':'Meta semanal batida!';
  _setCeremonyPhoto(item);
  _ceremConfettiStop=_startCeremonyConfetti(rank,isChampion);
  if(!_ceremMusicUrl) playCelebrationSound(isChampion?0.95:0.8);
}

function _setCeremonyPhoto(v){
  const el=document.getElementById('ceremonyPhoto'); if(!el) return;
  el.innerHTML='';
  if(v.imageUrl){const img=document.createElement('img');img.src=v.imageUrl;el.appendChild(img);}
  else{const d=document.createElement('div');d.className='initials';d.textContent=v.name.charAt(0);el.appendChild(d);}
}

function _showCeremonyFinal(){
  document.querySelectorAll('#ceremonyProgress .ceremony-dot').forEach(d=>{d.classList.remove('active');d.classList.add('done');});
  const pop=document.getElementById('ceremonyPopup');
  pop.style.display='none';
  pop.classList.remove('suspense','champion-reveal');
  document.getElementById('ceremonyKicker').textContent='TODOS OS DESTAQUES DA SEMANA';
  const medals={1:'🥇',2:'🥈',3:'🥉'};
  const grid=document.getElementById('ceremonyFinalGrid');
  // Grid shows best-first, independent of the reversed reveal order
  const ordered=_ceremQueue.slice().sort((a,b)=>a.pos-b.pos);
  grid.innerHTML=ordered.map(w=>{
    const rankCls=w.pos<=3?`rank-${w.pos}`:'';
    const photo=w.imageUrl?`<img src="${w.imageUrl}" alt="${esc(w.name)}">`:`<div class="initials">${esc(w.name).charAt(0)}</div>`;
    const medal=w.pos<=3?`<span class="ceremony-final-medal">${medals[w.pos]}</span>`:'';
    return `<div class="ceremony-final-card ${rankCls}"><div class="ceremony-final-photo">${photo}</div>${medal}<span class="ceremony-final-name">${esc(w.name)}</span><span class="ceremony-final-stat">${w.vendas}/${w.meta}</span></div>`;
  }).join('');
  document.getElementById('ceremonyFinal').classList.add('show');
  if(_ceremConfettiStop) _ceremConfettiStop();
  _ceremConfettiStop=_startCeremonyConfetti('other',true);
  if(_ceremTimeout) clearTimeout(_ceremTimeout);
  _ceremTimeout=setTimeout(_finishCeremony,_ceremFinalDuration*1000);
}

function _finishCeremony(){
  const o=document.getElementById('ceremonyOverlay'); if(!o) return;
  o.style.opacity='0'; o.style.transition='opacity .6s';
  setTimeout(()=>{
    o.classList.remove('show');o.style.opacity='';o.style.transition='';
    const pop=document.getElementById('ceremonyPopup');
    pop.style.display='';
    pop.classList.remove('suspense','champion-reveal');
    document.getElementById('ceremonyKicker').textContent='SOLENIDADE DE ENTREGA DE METAS';
    document.getElementById('ceremonyFinal').classList.remove('show');
    _popupDone();
  },620);
  if(_ceremConfettiStop){_ceremConfettiStop();_ceremConfettiStop=null;}
  if(_ceremTimeout){clearTimeout(_ceremTimeout);_ceremTimeout=null;}
  const a=document.getElementById('ceremonyAudio');
  if(a){a.pause();a.currentTime=0;}
  fadeBgIn(2500);
  _ceremShowing=false;_ceremQueue=[];
}

function closeCeremony(){ _finishCeremony(); }

function _startCeremonyConfetti(rank,boost){
  const cv=document.getElementById('ceremonyCanvas'); if(!cv) return()=>{};
  const cx=cv.getContext('2d');
  cv.width=window.innerWidth; cv.height=window.innerHeight;
  const pal={1:['#ffd700','#fff8dc','#d4af37','#ffe066','#ffffff'],
             2:['#c0c4cc','#e8eaed','#9aa0a6','#ffffff'],
             3:['#cd7f32','#e8a95c','#8b5a2b','#ffcb8e'],
             other:['#d4af37','#ffd700','#e8c84a','#f0d878','#ffffff']};
  const colors=pal[rank]||pal.other;
  const pieces=Array.from({length:boost?340:200},()=>({x:Math.random()*cv.width,y:-30-Math.random()*cv.height*.6,
    w:Math.random()*12+4,h:Math.random()*6+2,color:colors[Math.floor(Math.random()*colors.length)],
    rot:Math.random()*360,spin:(Math.random()-.5)*14,vx:(Math.random()-.5)*5,vy:Math.random()*4+1.2,op:1}));
  let raf;
  (function draw(){
    cx.clearRect(0,0,cv.width,cv.height);let alive=false;
    pieces.forEach(p=>{if(p.y>cv.height+20){p.op=0;return;}alive=true;
      cx.save();cx.globalAlpha=Math.min(1,p.op);cx.translate(p.x+p.w/2,p.y+p.h/2);cx.rotate(p.rot*Math.PI/180);
      cx.fillStyle=p.color;cx.fillRect(-p.w/2,-p.h/2,p.w,p.h);cx.restore();
      p.x+=p.vx;p.y+=p.vy;p.rot+=p.spin;p.vy+=0.07;if(p.y>cv.height*.65)p.op=Math.max(0,p.op-.014);});
    if(alive)raf=requestAnimationFrame(draw);
  })();
  return()=>{cancelAnimationFrame(raf);cx.clearRect(0,0,cv.width,cv.height);};
}

function _startGoalConfetti(type){
  const cv=document.getElementById('goalCanvas'); if(!cv) return()=>{};
  const cx=cv.getContext('2d');
  cv.width=window.innerWidth; cv.height=window.innerHeight;
  const pal={dia:['#9b59b6','#8e44ad','#af7ac5','#d2b4de','#f4ecf7','#ffffff'],
             sem:['#2ecc71','#27ae60','#1abc9c','#abebc6','#d5f5e3','#ffffff'],
             mes:['#3498db','#2980b9','#5dade2','#a9cce3','#d6eaf8','#ffffff'],
             team:['#e67e22','#c0392b','#f39c12','#e74c3c','#f8c471','#ffffff'],
             diageral:['#e74c3c','#c0392b','#ff6b5b','#f5b7b1','#fdedec','#ffffff']};
  const colors=pal[type]||pal.sem;
  const pieces=Array.from({length:200},()=>({x:Math.random()*cv.width,y:-30-Math.random()*cv.height*.6,
    w:Math.random()*12+4,h:Math.random()*6+2,color:colors[Math.floor(Math.random()*colors.length)],
    rot:Math.random()*360,spin:(Math.random()-.5)*14,vx:(Math.random()-.5)*5,vy:Math.random()*4+1.2,op:1}));
  let raf;
  (function draw(){
    cx.clearRect(0,0,cv.width,cv.height);let alive=false;
    pieces.forEach(p=>{if(p.y>cv.height+20){p.op=0;return;}alive=true;
      cx.save();cx.globalAlpha=Math.min(1,p.op);cx.translate(p.x+p.w/2,p.y+p.h/2);cx.rotate(p.rot*Math.PI/180);
      cx.fillStyle=p.color;cx.fillRect(-p.w/2,-p.h/2,p.w,p.h);cx.restore();
      p.x+=p.vx;p.y+=p.vy;p.rot+=p.spin;p.vy+=0.07;if(p.y>cv.height*.65)p.op=Math.max(0,p.op-.014);});
    if(alive)raf=requestAnimationFrame(draw);
  })();
  return()=>{cancelAnimationFrame(raf);cx.clearRect(0,0,cv.width,cv.height);};
}

function _startConfetti(){
  const cv=document.getElementById('milestoneCanvas');
  const cx=cv.getContext('2d');
  cv.width=window.innerWidth; cv.height=window.innerHeight;
  const golds=['#d4af37','#ffd700','#e8c84a','#f0d878','#b8960c','#fff8dc','#ffffff','#ffe066','#ffcc00'];
  const pieces=Array.from({length:220},()=>({
    x:Math.random()*cv.width, y:-30-Math.random()*cv.height*0.6,
    w:Math.random()*13+4, h:Math.random()*6+2,
    color:golds[Math.floor(Math.random()*golds.length)],
    rot:Math.random()*360, spin:(Math.random()-.5)*14,
    vx:(Math.random()-.5)*5, vy:Math.random()*4+1.2, op:1
  }));
  let raf;
  (function draw(){
    cx.clearRect(0,0,cv.width,cv.height);
    let alive=false;
    pieces.forEach(p=>{
      if(p.y>cv.height+20){p.op=0;return;}
      alive=true;
      cx.save();cx.globalAlpha=Math.min(1,p.op);
      cx.translate(p.x+p.w/2,p.y+p.h/2);cx.rotate(p.rot*Math.PI/180);
      cx.fillStyle=p.color;cx.fillRect(-p.w/2,-p.h/2,p.w,p.h);
      cx.restore();
      p.x+=p.vx;p.y+=p.vy;p.rot+=p.spin;p.vy+=0.07;
      if(p.y>cv.height*.65) p.op=Math.max(0,p.op-.014);
    });
    if(alive) raf=requestAnimationFrame(draw);
  })();
  return ()=>{cancelAnimationFrame(raf);cx.clearRect(0,0,cv.width,cv.height);};
}

async function fetchData(){
  try{
    const r=await fetch(API_URL+'?action=get_data&_t='+Date.now()),j=await r.json();
    if(!j.success)return;
    weekDayCount=j.weekDays||weekDayCount;
    if(Array.isArray(j.weekLabels)&&j.weekLabels.length) weekLabels=j.weekLabels;
    if(Array.isArray(j.weekDates)&&j.weekDates.length)   weekDates=j.weekDates;
    if(Array.isArray(j.weekDatesFull)&&j.weekDatesFull.length) weekDatesFull=j.weekDatesFull;
    teamsData       = j.teams        || [];
    teamsDataMensal = j.teams_mensal  || [];
    monthWeeksData  = j.monthWeeks    || [];
    if(j.total_mes!=null){const mc=document.getElementById('mesCounter');if(mc){mc.textContent=`${j.total_mes} CONTRATOS EM ${j.mes_label||''}`;mc.style.display='block';}checkMilestone(j.total_mes);}
    const _semTotal=j.total_semana??((j.data||[]).reduce((s,v)=>s+(v.vendas||0),0));
    checkGoalHits(j.data,_semTotal,j.meta_semana_total??0,j.total_hoje??0,j.meta_dia_total??0);
    updateStatsStrip(j);
    if(Array.isArray(j.rotation))   rotationData   = j.rotation;
    if(!retroData&&rotationUsaRetro())loadRetro();
    if(Array.isArray(j.propagandas))propagandasData= j.propagandas;
    if(j.bg_next_track&&j.bg_next_track!==lastBgNextTrack){lastBgNextTrack=j.bg_next_track;jumpToBgTrack(j.bg_next_track);}
    checkNewSales(j.data);
    const nj=JSON.stringify(j.data),oj=JSON.stringify(teamData);
    if(nj!==oj&&teamData.length>0){
      document.getElementById('liveIndicator').style.display='flex';
      setTimeout(()=>document.getElementById('liveIndicator').style.display='none',3000);
      teamData       = j.data;
      teamDataMensal = j.data_mensal || [];
      const co=teamData.map(v=>v.name),hc=co.some((n,i)=>n!==previousOrder[i]);
      previousOrder=co;
      if(hc&&!isAdPanelVisible()) transitionPodium();
      else if(!isAdPanelVisible()){renderPodium(false);renderRankingList();}
    } else if(!teamData.length){
      teamData       = j.data;
      teamDataMensal = j.data_mensal || [];
      previousOrder  = teamData.map(v=>v.name);
      dataLoaded=true;
      tryStartRotation();
      renderPodium(true);renderRankingList();
    } else {
      dataLoaded=true;
      tryStartRotation();
    }
  }catch(e){console.error(e);}
}

// ── Image ──────────────────────────────────────────────────────────────────
function getImageSrc(v){
  if(v.imageUrl)return v.imageUrl;
  if(v.imageBase64)return v.imageBase64;
  if(v.img)return`https://i.pravatar.cc/150?img=${v.img}`;
  return null;
}

// ── Pódio e classificação (palco único em tela cheia) ──────────────────────────────────────────
// ── Palco 1920×1080 escalado para caber em qualquer tela ──────────────────────────────────────────
function tvFit(){const st=document.getElementById('tvStage');if(st)st.style.setProperty('--ds',Math.min(innerWidth/1920,innerHeight/1080));}
tvFit();
window.addEventListener('resize',tvFit);

// A troca de modo (rotação ou mudança de ordem) é um fade do quadro inteiro: some, remonta por trás e volta.
function fadeBoard(cb){
  const b=document.getElementById('tvBoard');
  if(!b){cb();return;}
  b.classList.add('out');
  setTimeout(()=>{cb();requestAnimationFrame(()=>b.classList.remove('out'));},350);
}

// ── Dados do modo atual, já normalizados (closers ou equipes; semana ou mês) ──
function tvEntries(){
  const isTeam=podiumMode==='equipe_semanal'||podiumMode==='equipe_mensal';
  const isMensal=podiumMode==='geral'||podiumMode==='equipe_mensal';
  const src=isTeam?(isMensal?teamsDataMensal:teamsData):(isMensal?teamDataMensal:teamData);
  const list=(src||[]).map(x=>{
    const vendas=isMensal?(x.vendas_mes||0):(x.vendas||0);
    const meta=isTeam?(isMensal?(x.meta_m_eff||x.metas||0):(x.meta_s_eff||x.metas||0)):(isMensal?(_metaMensalEfetiva(x)||x.metas||0):(x.metas||0));
    const score=isMensal?(x.score_mensal||0):(x.score||0);
    return {
      raw:x, nome:isTeam?x.nome:x.name, img:isTeam?x.imageUrl:getImageSrc(x),
      equipe:isTeam?null:x.equipe_nome, equipeCor:isTeam?null:x.equipe_cor, sub:isTeam?(x.members||0)+' CLOSERS':null,
      vendas, meta, score, bateu:meta>0&&vendas>=meta, faltam:Math.max(0,meta-vendas)
    };
  });
  return {isTeam,isMensal,list};
}

// Quadradinhos: contratos por dia (semana) ou por semana (mês). Dia com meta diária batida ganha o destaque dourado.
function tvDayBoxes(v,mensal,labels,isTeam){
  const lab=t=>labels?`<div class="day-label">${esc(t)}</div>`:'';
  if(mensal){
    const ws=v.week_scores||[];
    return (monthWeeksData||[]).map((w,wi)=>{const val=ws[wi]||0;return `<div class="day-block"><div class="day-box${val>0?' has-contracts':''}">${val||''}</div>${lab('S'+(wi+1))}</div>`;}).join('');
  }
  const dm=v.daily_metas||[];
  return (v.daily||[]).map((val,idx)=>{
    const md=dm[idx];
    const c=(!isTeam&&md!=null&&md>0&&val>=md)?' has-contracts goal-hit':(val>0?' has-contracts':'');
    return `<div class="day-block"><div class="day-box${c}">${val||''}</div>${lab(weekDates[idx]||weekLabels[idx]||('D'+(idx+1)))}</div>`;
  }).join('');
}

// Ornamento dos cantos dos cartões (o mesmo do dashboard antigo)
const cornerSVG=`<svg viewBox="0 0 24 24"><path d="M12 2L22 12L12 22L2 12L12 2Z M12 6L18 12L12 18L6 12L12 6Z"/></svg>`;

// ── Cartão do pódio: estrutura e movimentos do dashboard antigo (moldura metálica, medalha com argola, foto em octógono,
//    anel de % que enche e o "float" suave), em tamanho maior ──
function tvCard(E,v,c){
  const first=c.n===1,ini=esc(String(v.nome||'?').trim().charAt(0).toUpperCase());
  const photo=`<div class="initials">${ini}</div>${v.img?`<img class="tcg-photo" src="${esc(v.img)}" alt="" loading="lazy" onerror="this.remove()">`:''}`;
  const nf=hallFont(v.nome,first?[[14,34],[22,30],[99,26]]:[[14,32],[22,28],[99,24]]);
  const per=E.isMensal?'Mês':'Semana';
  const stats=E.isTeam
    ?[[v.vendas,per],[v.meta,'Meta'],[v.raw.members||0,'Closers']]
    :[[v.vendas,per],[v.meta,E.isMensal?'Meta Mês':'Meta'],[v.faltam,'Faltam']];
  const corners=['tl','tr','bl','br'].map(k=>`<div class="corner-ornament corner-${k}">${cornerSVG}</div>`).join('');
  return `<div class="tcg-card ${c.pos} active" data-pct="${Math.min(100,v.score)}"><div class="tcg-float"><div class="tcg-card-frame"><div class="tcg-card-border"><div class="tcg-card-inner">
      ${corners}
      <div class="tcg-medal"><div class="medal-body"><div class="medal-ring"></div><div class="medal-circle">${c.n}º</div></div></div>
      <div class="tcg-photo-area"><div class="tcg-photo-frame"></div><div class="tcg-photo-inner">${photo}</div></div>
      <div class="tcg-name" style="--nf:${nf}px" title="${esc(v.nome)}"><span>${esc(v.nome)}</span></div><div class="tcg-divider"></div>
      <div class="tcg-pct-badge"><svg viewBox="0 0 100 100"><circle class="pct-bg" cx="50" cy="50" r="45"/><circle class="pct-fill" cx="50" cy="50" r="45"/></svg><div class="tcg-pct-value">${v.score}%</div></div>
      <div class="tcg-stats">${stats.map(s=>`<div class="tcg-stat"><div class="tcg-stat-value">${s[0]}</div><div class="tcg-stat-label">${s[1]}</div></div>`).join('')}</div>
    </div></div></div></div></div>`;
}

// Os anéis de % dos cartões enchem logo depois de montados (a transição de 1,5 s é do CSS), um cartão após o outro
function tvFillRings(animate){
  const rc=2*Math.PI*45;
  document.querySelectorAll('#podiumStage .tcg-card').forEach((card,i)=>{
    const f=card.querySelector('.pct-fill'),pct=+card.getAttribute('data-pct')||0;
    if(f)setTimeout(()=>{f.style.strokeDashoffset=rc-(pct/100)*rc;},animate?300+i*150:50);
  });
}

// ── Pódio: 1º ao 3º ──────────────────────────────────────────────────────────────────────────────
function renderPodium(animate){
  const lbl=document.getElementById('podiumModeLabel');
  if(lbl)lbl.textContent=MODE_LABELS[podiumMode]||'RANKING SEMANAL';
  const stage=document.getElementById('podiumStage');
  const E=tvEntries();
  if(E.isTeam)E.list=E.list.filter(x=>!x.raw.oculta_tv);   // equipe oculta da TV aparece no ranking, por último, mas não disputa o pódio
  if(E.list.length<3){
    stage.innerHTML=`<div class="podium-empty"><span class="podium-empty-icon">🏆</span><span class="podium-empty-text">${E.isTeam?'Cadastre pelo menos 3 equipes':'Adicione pelo menos 3 closers'}<br>para exibir o pódio</span></div>`;
    return;
  }
  const cfg=[{pos:'second',n:2,i:1},{pos:'first',n:1,i:0},{pos:'third',n:3,i:2}];
  stage.innerHTML='<div class="podium-row">'+cfg.map(c=>tvCard(E,E.list[c.i],c)).join('')+'</div>';
  tvFillRings(animate!==false);
}

function transitionPodium(){
  if(podiumTransitioning||isAdPanelVisible())return;
  podiumTransitioning=true;
  fadeBoard(()=>{renderPodium(true);renderRankingList();podiumTransitioning=false;});
}

// ── Classificação completa (1º ao último) na metade direita da tela ───────────────────────────────
// Closers: duas colunas (acima de 44, três compactas). Equipes são poucas: uma coluna só (duas só se passarem de 14).
// A altura da linha acompanha a quantidade e o avatar, o nome e os quadradinhos crescem junto com ela.
const TV_GRID_H=850,TV_GAP_Y=5;
function renderRankingList(){
  const list=document.getElementById('rankingList');
  const title=document.getElementById('rankingTitle'),count=document.getElementById('rankingCount');
  const E=tvEntries(),N=E.list.length;
  if(title)title.textContent=E.isTeam?(E.isMensal?'Equipes — Mensal':'Equipes — Semanal'):(E.isMensal?'Closers — Mensal':'Closers — Semanal');
  if(count)count.textContent=N+(E.isTeam?' equipes':' closers');
  if(!N){list.innerHTML='';return;}
  const cols=E.isTeam?(N>14?2:1):(N>44?3:2),rows=Math.ceil(N/cols),solo=cols===1;
  const cap=solo?128:(rows<=5?96:72);
  const rh=Math.max(34,Math.min(cap,Math.floor((TV_GRID_H-(rows-1)*TV_GAP_Y)/rows)));
  const tight=rh<46;
  const bx=Math.max(14,Math.min(solo?30:22,Math.round(rh*0.36)));
  const st=list.style;
  const nfBase=Math.max(tight?14:15,Math.min(solo?28:19,Math.round(rh*0.25)));
  list.dataset.nf=nfBase;
  st.setProperty('--cols',cols);st.setProperty('--rows',rows);st.setProperty('--rh',rh+'px');
  st.setProperty('--av',Math.max(24,Math.min(80,Math.round(rh*0.62)))+'px');
  st.setProperty('--bx',bx+'px');
  st.setProperty('--fz',Math.max(9,Math.min(16,Math.round(bx*0.5)))+'px');
  st.setProperty('--lb',Math.max(8,Math.min(12,Math.round(bx*0.42)))+'px');
  st.setProperty('--nf',nfBase+'px');
  list.classList.toggle('compact',cols>=3);list.classList.toggle('solo',solo);list.classList.toggle('tight',tight);
  const offs=[];
  list.innerHTML=E.list.map((v,i)=>{
    let cls='rank-item';
    if(i===0)cls+=' top1';else if(i===1)cls+=' top2';else if(i===2)cls+=' top3';
    else if(E.isMensal&&!E.isTeam){if(i===N-1)cls+=' last1';else if(i===N-2)cls+=' last2';else if(i===N-3)cls+=' last3';}
    offs.push(2*Math.PI*21*(1-Math.min(100,v.score)/100));
    const ini=esc(String(v.nome||'?').charAt(0).toUpperCase());
    const av=`<div class="initials">${ini}</div>${v.img?`<img src="${esc(v.img)}" alt="" loading="lazy" onerror="this.remove()">`:''}`;
    const foraDisputa=E.isTeam&&!!(v.raw&&v.raw.oculta_tv);   // equipe oculta da TV: os números são reais, mas ela não tem posição no ranking
    return `<div class="${cls}${foraDisputa?' fora-disputa':''}"><div class="rank-num">${foraDisputa?'—':String(i+1).padStart(2,'0')}</div>
      <div class="rank-avatar-wrap"><svg class="rank-pct-ring" viewBox="0 0 48 48"><circle class="pct-bg" cx="24" cy="24" r="21"/><circle class="pct-fill" cx="24" cy="24" r="21"/></svg><div class="rank-avatar">${av}</div></div>
      <div class="rank-info"><div class="rank-name"><span class="rank-nm">${esc(v.nome)}</span>${E.isTeam?`<span class="rank-v">${v.vendas}v${foraDisputa?' · fora da disputa':''}</span>`:''}</div><div class="daily-contracts">${tvDayBoxes(v.raw,E.isMensal,true,E.isTeam)}</div></div></div>`;
  }).join('');
  // os anéis de % de cada linha enchem em sequência, como no dashboard antigo
  list.querySelectorAll('.pct-fill').forEach((ci,i)=>{setTimeout(()=>{ci.style.strokeDashoffset=offs[i];},80+i*25);});
  tvFitNames();
}

// Os nomes da lista usam a fonte da Retrospectiva (Cinzel preto), mais larga que a Inter de antes. Um único tamanho para todos: o maior
// em que o nome mais comprido ainda cabe (mínimo 13 px; abaixo disso entram as reticências). Refeito quando a fonte web termina de carregar.
function tvFitNames(){
  const list=document.getElementById('rankingList');
  if(!list)return;
  const els=[...list.querySelectorAll('.rank-nm')];
  if(!els.length)return;
  let size=+list.dataset.nf||18;
  list.style.setProperty('--nf',size+'px');
  for(let g=0;g<4&&size>13;g++){
    const r=Math.min(...els.map(e=>e.clientWidth/Math.max(1,e.scrollWidth)));
    if(r>=0.999)break;
    size=Math.max(13,Math.min(size-1,Math.floor(size*r)));
    list.style.setProperty('--nf',size+'px');
  }
}
if(document.fonts&&document.fonts.ready)document.fonts.ready.then(tvFitNames);

// ── Celebrations ───────────────────────────────────────────────────────────
const CELEB_DURATION = 28000; // ms total visible
const CELEB_FADEOUT  = 5000;  // ms music fade-out before close
let celebFadeInterval = null;
let celebConfettiStop = null;

function showCelebration(sn,q=1){_showCeleb(sn,'NOVO CONTRATO!','fechou um novo contrato!','rgba(212,175,55,0.85)',0.6);}
function _showCeleb(sn,title,msg,color,vol){
  const o=document.getElementById('celebrationOverlay');
  if(!o)return;
  document.getElementById('celebrationTitle').textContent=title;
  document.getElementById('celebrationSeller').textContent=sn;
  const m=document.getElementById('celebrationMessage');m.textContent=msg;m.style.color=color;
  const ic=document.getElementById('celebrationIcon');if(ic)ic.textContent='🏆';
  const p=document.getElementById('celebrationPhoto');
  const v=teamData.find(x=>x.name===sn);
  if(v){const i=getImageSrc(v);p.innerHTML=i?`<img src="${i}" alt="${esc(sn)}">`:`<div class="initials">${esc(sn).charAt(0)}</div>`;}
  else p.innerHTML=`<div class="initials">${esc(sn).charAt(0)}</div>`;
  o.classList.add('show');
  fadeBgOut(1200);          // fade background music out
  playCelebrationSound(vol);
  if(celebConfettiStop){celebConfettiStop();celebConfettiStop=null;}
  celebConfettiStop=_startCelebConfetti();
  if(celebrationTimeout)clearTimeout(celebrationTimeout);
  if(celebFadeInterval){clearInterval(celebFadeInterval);celebFadeInterval=null;}
  celebrationTimeout=setTimeout(()=>{
    startAudioFade(vol);
    setTimeout(closeCelebration, CELEB_FADEOUT);
  }, CELEB_DURATION - CELEB_FADEOUT);
}
function _startCelebConfetti(){
  const cv=document.getElementById('celebrationCanvas'); if(!cv) return()=>{};
  const cx=cv.getContext('2d');
  cv.width=window.innerWidth; cv.height=window.innerHeight;
  const colors=['#ffd700','#fff8dc','#d4af37','#b8960c','#ffe066'];
  const pieces=Array.from({length:170},()=>({x:Math.random()*cv.width,y:-30-Math.random()*cv.height*.6,
    r:Math.random()*7+5,color:colors[Math.floor(Math.random()*colors.length)],
    rot:Math.random()*360,spin:(Math.random()-.5)*10,vx:(Math.random()-.5)*4,vy:Math.random()*3.6+1,op:1}));
  let raf;
  // Coin-shaped pieces that flip as they fall — visually distinct from the rectangular confetti elsewhere
  (function draw(){
    cx.clearRect(0,0,cv.width,cv.height);let alive=false;
    pieces.forEach(p=>{if(p.y>cv.height+20){p.op=0;return;}alive=true;
      cx.save();cx.globalAlpha=Math.min(1,p.op);cx.translate(p.x,p.y);
      const scaleX=Math.max(0.15,Math.abs(Math.cos(p.rot*Math.PI/180)));
      cx.scale(scaleX,1);
      cx.beginPath();cx.arc(0,0,p.r,0,Math.PI*2);cx.fillStyle=p.color;cx.fill();
      cx.restore();
      p.x+=p.vx;p.y+=p.vy;p.rot+=p.spin*6;p.vy+=0.06;if(p.y>cv.height*.65)p.op=Math.max(0,p.op-.012);});
    if(alive)raf=requestAnimationFrame(draw);
  })();
  return()=>{cancelAnimationFrame(raf);cx.clearRect(0,0,cv.width,cv.height);};
}
function startAudioFade(startVol){
  const a=document.getElementById('celebrationAudio');
  if(!a)return;
  const steps=40, interval=CELEB_FADEOUT/steps;
  let step=0;
  celebFadeInterval=setInterval(()=>{
    step++;
    a.volume=Math.max(0, startVol*(1-step/steps));
    if(step>=steps){clearInterval(celebFadeInterval);celebFadeInterval=null;}
  },interval);
}
function closeCelebration(){
  const o=document.getElementById('celebrationOverlay'),a=document.getElementById('celebrationAudio');
  if(o)o.classList.remove('show');
  if(a){a.pause();a.currentTime=0;a.volume=1;}
  if(celebrationTimeout){clearTimeout(celebrationTimeout);celebrationTimeout=null;}
  if(celebFadeInterval){clearInterval(celebFadeInterval);celebFadeInterval=null;}
  if(celebConfettiStop){celebConfettiStop();celebConfettiStop=null;}
  fadeBgIn(2500);            // bring background music back
  _popupDone();
}

// ── Rotation init ───────────────────────────────────────────────────────────
async function loadRotation(){
  try{
    const [rj,pj]=await Promise.all([
      fetch(API_URL+'?action=get_rotation&_t='+Date.now()).then(r=>r.json()),
      fetch(API_URL+'?action=get_propagandas&_t='+Date.now()).then(r=>r.json()),
    ]);
    if(rj.success)rotationData=rj.data||[];
    if(pj.success)propagandasData=pj.data||[];
    if(rotationUsaRetro())await loadRetro();   // a rotação só começa com os dados da retrospectiva já na mão
    rotationLoaded=true;
    tryStartRotation();
  }catch(e){}
}

setInterval(fetchData,10000);
setInterval(loadRotation,120000);
setInterval(()=>{if(rotationUsaRetro())loadRetro();},300000);   // retrospectiva muda pouco: a cada 5 min
setInterval(checkCustomPopup,10000);
setInterval(checkCeremony,10000);

// ── Auto-reload preventivo ──────────────────────────────────────────────────
// A TV fica com a aba aberta por dias seguidos; isso evita acúmulo de memória/
// travamentos ao longo do tempo e garante que atualizações do sistema entrem
// em vigor sozinhas, sem precisar de F5 manual. Só recarrega quando nenhum
// pop-up estiver visível, pra nunca interromper uma comemoração no meio.
const PAGE_LOAD_TIME  = Date.now();
const AUTO_RELOAD_MS  = 6*60*60*1000; // 6 horas
setInterval(()=>{
  if(Date.now()-PAGE_LOAD_TIME < AUTO_RELOAD_MS) return;
  const busy = document.querySelector('.celebration-overlay.show,.milestone-overlay.show,.goal-overlay.show,.ceremony-overlay.show,.custom-popup-overlay.show');
  if(busy) return;
  location.reload();
}, 5*60*1000);

if(TV_PTR){
  document.getElementById('tvPrev').addEventListener('click',()=>tvStep(-1));
  document.getElementById('tvNext').addEventListener('click',()=>tvStep(1));
  ['pointermove','pointerdown','touchstart'].forEach(ev=>document.addEventListener(ev,tvNavReveal,{passive:true}));
  document.addEventListener('keydown',e=>{
    if(e.key==='ArrowLeft'){tvNavReveal();tvStep(-1);}
    else if(e.key==='ArrowRight'){tvNavReveal();tvStep(1);}
  });
}

fetchData();
loadRotation();
checkCustomPopup();
checkCeremony();
startBgMusic();
