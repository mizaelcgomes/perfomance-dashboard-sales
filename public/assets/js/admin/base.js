const ICO = {
  sun: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
  shield: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
  key: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="5.5"/><path d="M21 2l-9.6 9.6"/><path d="M15.5 7.5l3 3L22 7l-3-3"/></svg>',
  mail: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
  lock: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
  link: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
  trash: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>',
  refresh: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>',
  check: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
  xcircle: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
  clock: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
  target: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg>',
  flame: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
  phone: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
  alert: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
  trendDown: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>',
  zap: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
  tv: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="7" width="20" height="15" rx="2"/><polyline points="17 2 12 7 7 2"/></svg>',
  user: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
  tag: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
  award: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>',
  scale: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="3" x2="12" y2="21"/><line x1="6" y1="21" x2="18" y2="21"/><line x1="4" y1="7" x2="20" y2="7"/><path d="M4 7l-2.5 6a2.5 2.5 0 0 0 5 0z"/><path d="M20 7l-2.5 6a2.5 2.5 0 0 0 5 0z"/></svg>',
  download: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
  edit: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
  music: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
  play: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
  x: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>',
  dotOk: '<i class="st-dot ok" aria-hidden="true"></i>', dotWait: '<i class="st-dot wait" aria-hidden="true"></i>', dotBad: '<i class="st-dot bad" aria-hidden="true"></i>', dotNone: '<i class="st-dot none" aria-hidden="true"></i>',
};
// Escapa texto vindo do banco antes de inserir via innerHTML (nomes de closers/equipes)
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
// ── Constants ──────────────────────────────────────────────────────────────
let TIPOS = ADMIN_BOOT.tipos;
let TIPO_LABELS = ADMIN_BOOT.tipoLabels;
let TIPOS_MAP = {}; // chave→label (populated after custom load)
TIPOS.forEach((k,i)=>TIPOS_MAP[k]=TIPO_LABELS[i]);
let TIPO_CORES = ADMIN_BOOT.tipoCores;   // chave→cor do benefício (a mesma em todo o sistema)
let TIPO_ICONS = ADMIN_BOOT.tipoIcons;  // chave→chave do ícone do benefício
const TIPO_ICONES = ADMIN_BOOT.tipoIcones; // catálogo de ícones: chave→desenho (svg)
const tpBase = k => String(k || '').indexOf('outros:') === 0 ? 'outros' : String(k || '');
const tpSvg = key => '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (TIPO_ICONES[key] || TIPO_ICONES.file) + '</svg>';
const tpCor = k => TIPO_CORES[tpBase(k)] || '#94a3b8';
const tpIcon = k => tpSvg(TIPO_ICONS[tpBase(k)] || (tpBase(k) === 'outros' ? 'more' : 'file'));
function tpShade(hex, n){   // clareia a cor em n passos: fatias "Outros: …" diferentes do mesmo tipo continuam distinguíveis
  const v = parseInt(hex.slice(1), 16), f = Math.min(.55, n * .16);
  return '#' + [v >> 16 & 255, v >> 8 & 255, v & 255].map(x => Math.round(x + (255 - x) * f).toString(16).padStart(2, '0')).join('');
}
const defaultWeekDates  = ADMIN_BOOT.weekDates;
const defaultWeekLabels = ADMIN_BOOT.weekLabels;
const todayIdx = ADMIN_BOOT.todayIdx;

let weekDates  = [...defaultWeekDates];
let weekLabels = [...defaultWeekLabels];
let weekDayCount = ADMIN_BOOT.weekDayCount;
let monthWeeks = [];
let allClosers    = [];
let allClosersMensal = [];
let allElenco = [];   // todos os closers ativos (inclusive os de equipe oculta da TV): equipes e seletor de contratos
let allEquipes    = [];
let allTeams      = [];
let allTeamsMensal= [];
let totalMes      = 0;
let weekBoundsData = null;
let viewMonth   = ADMIN_BOOT.curMonth;
let viewYear    = ADMIN_BOOT.curYear;
let vsWYear     = ADMIN_BOOT.curYear;
let vsWMonth    = ADMIN_BOOT.curMonth;
let vsWWeeks    = [];
let curSemNum   = null;
let curSemAno   = ADMIN_BOOT.curYear;
let curSemMes   = ADMIN_BOOT.curMonth;
let monthDataLoaded = false;
let chartPeriod = 'week';
let gfYear = ADMIN_BOOT.curYear;
let gfMonth = ADMIN_BOOT.curMonth;
let gfWeeks = []; 
const ptMonths = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

const GOLD_PALETTE = ['#d4af37','#b8960c','#e8c84a','#927200','#f0d878','#a07a00','#f5e088','#8b6800','#fef9c3','#6b4a00'];

// ── Tab routing ────────────────────────────────────────────────────────────
function toggleForm(id){
  const el=document.getElementById(id);
  el.style.display=el.style.display==='none'?'block':'none';
}
const TAB_INFO = {
  painel:{eyebrow:'PAINEL',title:'Painel',subtitle:'',crumbs:['Painel','Visão Geral']},
  rankings:{eyebrow:'RANKINGS',title:'Rankings',subtitle:'Desempenho de closers e equipes',crumbs:['Operação','Rankings']},
  contratos:{eyebrow:'CONTRATOS',title:'Contratos',subtitle:'Registre vendas em segundos e audite a semana por benefício',crumbs:['Operação','Contratos']},
  equipes:{eyebrow:'GESTÃO DO TIME',title:'Equipes',subtitle:'Organize equipes e closers, edite cadastros e gerencie afastamentos',crumbs:['Gestão','Equipes']},
  produtividade:{eyebrow:'PRODUTIVIDADE',title:'Produtividade',subtitle:'Funil de ações dos closers e conversão em contratos',crumbs:['Gestão','Produtividade']},
  relatorios:{eyebrow:'RELATÓRIOS',title:'Relatórios',subtitle:'Performance dos closers e custo por contrato',crumbs:['Gestão','Relatórios']},
  metas:{eyebrow:'METAS & REGRAS',title:'Metas & Regras',subtitle:'Metas por período, desempate e tipos de benefício',crumbs:['Gestão','Metas']},
  calendario:{eyebrow:'CALENDÁRIO',title:'Calendário',subtitle:'Semanas de trabalho configuradas',crumbs:['Gamificação','Calendário']},
  propaganda:{eyebrow:'PROPAGANDA',title:'Propaganda',subtitle:'Gerenciamento de anúncios e rotação',crumbs:['Gamificação','Propaganda']},
  vivo:{eyebrow:'AO VIVO',title:'Ao Vivo',subtitle:'Monitoramento em tempo real',crumbs:['Gamificação','TV Ao Vivo']}
};
function showTab(tab, btn) {
  btn = btn || document.querySelector('.tab-btn[data-tab="'+tab+'"]');
  document.querySelectorAll('.tab-btn').forEach(b=>{b.classList.remove('active');b.removeAttribute('aria-current');});
  document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
  if(btn){btn.classList.add('active');btn.setAttribute('aria-current','page');}
  document.getElementById('tab'+tab.charAt(0).toUpperCase()+tab.slice(1)).classList.add('active');
  document.body.dataset.tab = tab;
  const info=TAB_INFO[tab]||{eyebrow:'',title:'',subtitle:'',crumbs:['','']};
  document.getElementById('pageHeading').textContent=info.title;
  document.getElementById('pageSubtitle').textContent=info.subtitle;
  document.getElementById('tbTitle').textContent=info.title;
  document.title=info.title+' – Admin TOP CLOSERS';
  sbCloseDrawer(false);
  window.scrollTo({top:0});
  if (tab==='painel')     pnReload();
  if (tab==='calendario') { calLoad(); calLoadHist(); }
  if (tab==='contratos')  cvShow();
  if (tab==='rankings')   { initRankingSelectors(); renderRankings(); }
  if (tab==='relatorios') rpOpen();
  if (tab==='equipes')    { loadDeletedClosers(); loadSuspendedClosers(); tmLoadOverview(); tmLoadAcessos(); }
  if (tab==='produtividade') prLoad();
  if (tab==='metas')      msShow();
  if (tab==='propaganda') { loadPropagandas(); }
  if (tab==='vivo')       { loadPropagandas().then(()=>{loadRotation();loadBgMusic();loadCustomPopup();loadCeremonyPreview();}); }
}

// ── Toast ──────────────────────────────────────────────────────────────────
function toast(msg, err=false) {
  const t=document.getElementById('toast');
  t.textContent=msg; t.className='toast'+(err?' err':'')+' show';
  setTimeout(()=>t.classList.remove('show'),3000);
}

// ── Image upload helpers ───────────────────────────────────────────────────
function previewImg(e, imgId, areaId, phId, pvId) {
  const f=e.target.files[0]; if(!f) return;
  if(f.size>500*1024){toast('Imagem muito grande (máx 500KB)',true);e.target.value='';return;}
  const rd=new FileReader();
  rd.onload=ev=>{
    document.getElementById(imgId).src=ev.target.result;
    document.getElementById(phId).style.display='none';
    document.getElementById(pvId).style.display='block';
    document.getElementById(areaId).classList.add('has-img');
  };
  rd.readAsDataURL(f);
}
function removeImg(inputId, areaId, phId, pvId) {
  document.getElementById(inputId).value='';
  document.getElementById(phId).style.display='block';
  document.getElementById(pvId).style.display='none';
  document.getElementById(areaId).classList.remove('has-img');
}
function setPreview(src, imgId, areaId, phId, pvId) {
  if(src){
    document.getElementById(imgId).src=src;
    document.getElementById(phId).style.display='none';
    document.getElementById(pvId).style.display='block';
    document.getElementById(areaId).classList.add('has-img');
  } else { removeImg('',areaId,phId,pvId); }
}

// ── Image src helper ───────────────────────────────────────────────────────
function imgSrc(v) {
  if(v.imageUrl) return v.imageUrl;
  if(v.img)      return `https://i.pravatar.cc/150?img=${v.img}`;
  return null;
}

// ── Main data fetch ────────────────────────────────────────────────────────
async function fetchData() {
  try {
    const [r1, r2, r3] = await Promise.all([
      fetch(API_URL+'?action=get_data').then(r=>r.json()),
      fetch(API_URL+'?action=get_equipes').then(r=>r.json()),
      fetch(API_URL+'?action=get_elenco').then(r=>r.json()),
    ]);
    if(r1.success) {
      allClosers = r1.data;
      allElenco = r3.success ? r3.data : [];
      weekDayCount = r1.weekDays || weekDayCount;
      if(Array.isArray(r1.weekLabels)&&r1.weekLabels.length) weekLabels = r1.weekLabels;
      if(Array.isArray(r1.weekDates)&&r1.weekDates.length)   weekDates  = r1.weekDates;
      if(Array.isArray(r1.monthWeeks)&&r1.monthWeeks.length) monthWeeks = r1.monthWeeks;
      if(Array.isArray(r1.data_mensal)) allClosersMensal = r1.data_mensal;
      if(r1.total_mes != null) totalMes = r1.total_mes;
      if(Array.isArray(r1.teams))        allTeams       = r1.teams;
      if(Array.isArray(r1.teams_mensal)) allTeamsMensal = r1.teams_mensal;
      if(r1.curSemNum != null) curSemNum = r1.curSemNum;
      if(r1.curSemAno)         curSemAno = r1.curSemAno;
      if(r1.curSemMes)         curSemMes = r1.curSemMes;
      if(r1.weekBounds) {
        weekBoundsData = r1.weekBounds;
        const wr = document.getElementById('ovWeekRange');
        if(wr && r1.weekBounds.start) {
          const fmt = d => d.substring(5).split('-').reverse().join('/');
          wr.textContent = fmt(r1.weekBounds.start)+' – '+fmt(r1.weekBounds.end);
        }
      }
    }
    if(r2.success) allEquipes = r2.data;
    renderOverview();
    cvPopulate();
    populateClEqSel();
    populatePnCloser();
    tmRender();
    // Re-render rankings if tab is active
    if(document.getElementById('tabRankings')?.classList.contains('active')) renderRankings();
  } catch(e){console.error(e);}
}

function renderOverview() { shellRefresh(); }

