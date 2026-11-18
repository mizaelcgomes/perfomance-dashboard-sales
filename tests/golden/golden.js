#!/usr/bin/env node
'use strict';
/*
 * Golden tests do TOP CLOSERS (fase 0 do planejamento MVC)
 *
 * Congelam as respostas JSON da api.php e da closer-api.php para comparar depois de cada fase da migração.
 * Só roda no AMBIENTE LOCAL DE TESTE (banco de teste local, php -S em 127.0.0.1:8787). Nunca aponte para produção.
 *
 *   node golden.js capture      [--dir snapshots] [--base http://127.0.0.1:8787/] [--escritas] [--closer] [--so <regex>]
 *   node golden.js compare      (mesmas opções; compara com o que está em --dir)
 *   node golden.js estabilidade (captura duas vezes e mostra o que muda sozinho: campos voláteis)
 *   node golden.js cobertura    (só confere se toda ação do api.php tem chamada no catálogo)
 *
 * Use o golden.ps1, que recria o banco antes e depois (as chamadas de escrita alteram dados).
 * Sem --escritas e sem --closer só as leituras e as chamadas "sem login" rodam (não alteram nada).
 *
 * DATA: o banco de teste usa datas relativas a "hoje" (semana corrente, mês corrente). Por isso o snapshot guarda o dia
 * em que foi gerado e o compare recusa rodar em outro dia (ver README.md, "Comparar em outro dia").
 */
const fs = require('fs');
const path = require('path');
const HERE = __dirname;
const ROOT = path.resolve(HERE, '..', '..');
const args = process.argv.slice(2);
const cmd = args[0] || 'compare';
const opt = (n, d) => { const i = args.indexOf('--' + n); return i >= 0 && args[i + 1] && !args[i + 1].startsWith('--') ? args[i + 1] : d; };
let BASE = opt('base', 'http://127.0.0.1:8787/');
const BASE_ANTIGA = opt('base-antiga', '');
const COM_VARIANTES = args.includes('--variantes');
const DIR = path.resolve(HERE, opt('dir', 'snapshots'));
const COM_ESCRITAS = args.includes('--escritas');
const COM_CLOSER = args.includes('--closer');
const COM_VARIANTES_TV = args.includes('--variantes-tv');   // estados do banco (metas, semanas, férias...) e leituras da TV/Painel refeitas em cada um
const COM_SUCESSO = args.includes('--sucesso');   // caminho de SUCESSO das escritas migradas (cenários em sequência; grava no banco e em music/)
const SO = opt('so', '') ? new RegExp(opt('so', '')) : null;
const SESSAO = 'PHPSESSID=testadmin0001';

if (![BASE, BASE_ANTIGA || BASE].every(b => /^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?\//.test(b))) { console.error('Recusado: --base precisa ser local (127.0.0.1 ou localhost). Golden tests nunca rodam em produção.'); process.exit(2); }

// Campos que mudam sozinhos a cada chamada (hora, token, aleatório): saem da comparação. Confirmados com "estabilidade".
const IGNORAR_CHAVES = new Set(['server_time', 'gerado_em', 'generated_at', 'now', 'timestamp', '_t', 'img_pravatar', 'hora']);   // img_pravatar: sorteado ao cadastrar um closer; hora: hora do lançamento no feed (relógio)
// Textos com sufixo de relógio (ex.: "Closer golden mk3x9") viram um valor fixo
const NORMALIZAR_TEXTO = [[/Closer golden [0-9a-z]+/g, 'Closer golden X'], [/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/g, '<datahora>'], [/ceremony_\d+_/g, 'ceremony_T_']];

// ── HTTP ────────────────────────────────────────────────────────────────────────
const zlib = require('zlib');
// Hash do desenho de cada página (descomprimido), ignorando a hora de geração no rodapé: dois PDFs com o mesmo hash têm o mesmo conteúdo.
function hashPdf(buf) {
  const txt = buf.toString('latin1'), partes = [], re = /stream\n([\s\S]*?)\nendstream/g;
  let m;
  while ((m = re.exec(txt))) {
    let b = Buffer.from(m[1], 'latin1');
    try { b = zlib.inflateSync(b); } catch (e) { /* sem compressão */ }
    partes.push(b.toString('latin1').replace(/Gerado em \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/g, 'Gerado em <data>'));
  }
  return require('crypto').createHash('sha1').update(partes.join('\n--\n')).digest('hex');
}
let _tok = null;
async function tokenAdmin(renovar) {
  if (_tok && !renovar) return _tok;
  const r = await fetch(BASE + 'api.php?action=get_csrf', { headers: { cookie: SESSAO } });
  const j = await r.json(); _tok = j.token || ''; return _tok;
}
async function chamar(c) {
  const h = {};
  if (c.admin !== false && !c.anonimo) h.cookie = SESSAO;
  let url = BASE + c.url, body;
  if (c.metodo === 'POST') {
    if (c.arquivos) {   // multipart de verdade (upload de foto/música): o fetch define o Content-Type com o boundary
      const fd = new FormData();
      for (const [k, v] of Object.entries(c.form || {})) fd.append(k, v);
      for (const [k, a] of Object.entries(c.arquivos)) fd.append(k, new Blob([a.bytes], { type: a.tipo || 'application/octet-stream' }), a.nome);
      body = fd;
    } else {
      h['content-type'] = 'application/x-www-form-urlencoded';
      body = new URLSearchParams(c.form || {}).toString();
    }
    if (!c.anonimo && c.admin !== false) h['x-csrf-token'] = await tokenAdmin();
  }
  const r = await fetch(url, { method: c.metodo, headers: h, body, redirect: 'manual' });
  return resumir(r.status, r.headers.get('content-type') || '', Buffer.from(await r.arrayBuffer()));
}
function resumir(status, ct, buf) {
  if (/application\/pdf/.test(ct)) {
    const txt = buf.toString('latin1');
    return { status, tipo: 'pdf', pdf: { inicio: txt.slice(0, 8), paginas: (txt.match(/\/Type\s*\/Page[^s]/g) || []).length, vazio: buf.length === 0, conteudo: hashPdf(buf) } };
  }
  const txt = buf.toString('utf8');
  try { return { status, tipo: 'json', corpo: normalizar(JSON.parse(txt)) }; }
  catch (e) { return { status, tipo: 'texto', corpo: normalizarTexto(txt.slice(0, 400)) }; }
}
function normalizarTexto(t) { return NORMALIZAR_TEXTO.reduce((s, [re, v]) => s.replace(re, v), t); }
function normalizar(v) {
  if (Array.isArray(v)) return v.map(normalizar);
  if (v && typeof v === 'object') { const o = {}; Object.keys(v).sort().forEach(k => { if (!IGNORAR_CHAVES.has(k)) o[k] = normalizar(v[k]); }); return o; }
  return typeof v === 'string' ? normalizarTexto(v) : v;
}

// ── Catálogo ─────────────────────────────────────────────────────────────────────
function hoje() { return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo' }).format(new Date()); }
function somaDias(iso, n) { const d = new Date(iso + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }
async function get(qs) { return (await fetch(BASE + 'api.php?' + qs, { headers: { cookie: SESSAO } })).json(); }

// ações do admin (app/Http/Acoes.php, antes cada "case" do api.php) — usado para exigir cobertura total e montar as chamadas "sem login"
function acoesDoApi() {
  const src = fs.readFileSync(path.join(ROOT, 'app', 'Http', 'Acoes.php'), 'utf8');
  const admin = src.slice(src.indexOf('public const ADMIN'), src.indexOf('public const CLOSER_PUBLICAS'));
  return [...new Set(['login', 'get_csrf', ...[...admin.matchAll(/^\s{8}'([a-z_0-9]+)' =>/gm)].map(m => m[1])])];
}
const SEM_LOGIN_PULAR = new Set(['login']);   // o login tem chamada própria (conta a tentativa errada)
// Ações que NÃO ganham chamada com login: efeito fora do banco (arquivo em disco, API externa, token). Têm só a chamada "sem login".
const SEM_GOLDEN_COM_LOGIN = {
  upload_bg_music: 'grava arquivo de música em disco',
  delete_bg_music: 'apaga arquivo de música do disco',
  set_bg_next: 'depende dos arquivos de música em disco',
  save_ceremony_settings: 'grava música da cerimônia em disco',
  login: 'tem chamada própria (login_invalido)',
  get_csrf: 'devolve o token da sessão, que é aleatório',
};
const GETS_EXTRA = ['get_', 'relatorio_closers_pdf'];
const eLeitura = a => GETS_EXTRA.some(p => a.startsWith(p)) || a === 'relatorio_closers_pdf';

async function montarCatalogo(forcarEscritas) {
  const dia = hoje(), [ano, mes] = dia.split('-').map(Number);
  const dados = await fetch(BASE + 'api.php?action=get_data').then(r => r.json());
  const equipes = (await get('action=get_equipes')).data || [];
  const vid = (dados.data && dados.data[0] && dados.data[0].id) || 1, eid = (equipes[0] && equipes[0].id) || 1;
  const ini = somaDias(dia, -6), mm = String(mes).padStart(2, '0');
  const C = [];
  const add = c => C.push(c);
  const L = (nome, qs, extra) => add(Object.assign({ nome: 'leitura__' + nome, grupo: 'leitura', metodo: 'GET', url: 'api.php?action=' + qs, acao: qs.split('&')[0] }, extra));

  // 1) chamadas sem login, uma por ação do api.php (a maioria deve responder "Não autenticado" — contrato de segurança)
  acoesDoApi().filter(a => !SEM_LOGIN_PULAR.has(a)).forEach(a => {
    const leitura = eLeitura(a);
    add({ nome: 'semlogin__' + a, grupo: 'semlogin', acao: a, anonimo: true, metodo: leitura ? 'GET' : 'POST', url: 'api.php' + (leitura ? '?action=' + a : ''), form: leitura ? undefined : { action: a } });
  });
  // 2) leituras com login (ou públicas), com parâmetros reais do banco
  const ap = `ano=${ano}&mes=${mes}`;
  L('get_data', 'get_data', { admin: false }); L('get_public_contratos_total', 'get_public_contratos_total', { admin: false });
  ['get_equipes', 'get_elenco', 'get_suspended_vendedores', 'get_deleted_vendedores', 'get_time_overview', 'get_tiebreaker', 'get_investimento_historico',
    'get_tipos_custom', 'get_tipos_config', 'get_semanas_historico', 'get_propagandas', 'get_custom_popup', 'get_ceremony_preview', 'get_ceremony', 'get_bg_music',
    'get_rotation', 'get_retrospectiva', 'get_acessos', 'get_painel'].forEach(a => L(a, a));
  L('get_propagandas_ativas', 'get_propagandas&ativo=1');
  L('get_tipos', `get_tipos&data_registro=${dia}&vendedor_id=${vid}`);
  L('get_feed', `get_feed&data=${dia}`);
  L('get_matriz', `get_matriz&start=${ini}&end=${dia}&vendedor_id=${vid}`);
  L('get_atendimentos', `get_atendimentos&start=${ini}&end=${dia}&vendedor_id=${vid}`);
  L('get_semanas_mes', `get_semanas_mes&${ap}`); L('get_month_weeks', `get_month_weeks&${ap}`);
  L('get_chart_data_week', 'get_chart_data&period=week'); L('get_chart_data_month', `get_chart_data&period=month&${ap}`); L('get_chart_data_year', `get_chart_data&period=year&ano=${ano}`);
  L('get_chart_data_closer', `get_chart_data&period=week&vendedor_id=${vid}`);
  L('get_week_summary', `get_week_summary&${ap}&semana_num=1`); L('get_week_summary_excluidos', `get_week_summary&${ap}&semana_num=1&include_deleted=1`);
  L('get_painel_semana1', `get_painel&${ap}&semana_num=1`);
  L('get_custo_contrato', `get_custo_contrato&${ap}`);
  L('get_monthly_summary', `get_monthly_summary&month=${mes}&year=${ano}`); L('get_monthly_summary_excluidos', `get_monthly_summary&month=${mes}&year=${ano}&include_deleted=1`);
  L('get_relatorio_closers', `get_relatorio_closers&${ap}`); L('get_relatorio_closers_equipe', `get_relatorio_closers&${ap}&equipe=${eid}`);
  L('relatorio_closers_pdf', `relatorio_closers_pdf&${ap}`);
  // fase 2 (Relatórios): mês anterior, mês futuro, parâmetros inválidos ou ausentes, filtro de equipe no PDF
  const ant = mes === 1 ? [ano - 1, 12] : [ano, mes - 1], prox = mes === 12 ? [ano + 1, 1] : [ano, mes + 1], nomeEquipe = encodeURIComponent((equipes[0] && equipes[0].nome) || 'x');
  L('get_relatorio_closers_mes_anterior', `get_relatorio_closers&ano=${ant[0]}&mes=${ant[1]}`); L('get_relatorio_closers_mes_futuro', `get_relatorio_closers&ano=${prox[0]}&mes=${prox[1]}`);
  L('get_relatorio_closers_invalido', 'get_relatorio_closers&ano=1999&mes=13'); L('get_relatorio_closers_sem_parametros', 'get_relatorio_closers');
  L('get_custo_contrato_mes_anterior', `get_custo_contrato&ano=${ant[0]}&mes=${ant[1]}`); L('get_custo_contrato_mes_futuro', `get_custo_contrato&ano=${prox[0]}&mes=${prox[1]}`);
  L('get_custo_contrato_invalido', 'get_custo_contrato&ano=abc&mes=0'); L('get_custo_contrato_sem_parametros', 'get_custo_contrato');
  L('relatorio_closers_pdf_equipe', `relatorio_closers_pdf&${ap}&equipe=${nomeEquipe}`); L('relatorio_closers_pdf_sem_equipe', `relatorio_closers_pdf&${ap}&equipe=__sem`);
  L('relatorio_closers_pdf_mes_anterior', `relatorio_closers_pdf&ano=${ant[0]}&mes=${ant[1]}`);
  L('get_monthly_summary_mes_anterior', `get_monthly_summary&month=${ant[1]}&year=${ant[0]}`); L('get_monthly_summary_mes_anterior_excluidos', `get_monthly_summary&month=${ant[1]}&year=${ant[0]}&include_deleted=1`);
  L('get_monthly_summary_sem_parametros', 'get_monthly_summary'); L('get_monthly_summary_mes_invalido', `get_monthly_summary&month=13&year=${ano}`);
  if (COM_VARIANTES) {
    for (let i = 0; i < 14; i++) {
      const d = new Date(Date.UTC(ano, mes - 1 - i, 1)), a = d.getUTCFullYear(), m = d.getUTCMonth() + 1, q = `ano=${a}&mes=${m}`, tag = `${a}-${String(m).padStart(2, '0')}`;
      L('v_relatorio_closers_' + tag, `get_relatorio_closers&${q}`); L('v_custo_contrato_' + tag, `get_custo_contrato&${q}`); L('v_pdf_' + tag, `relatorio_closers_pdf&${q}`);
      L('v_monthly_summary_' + tag, `get_monthly_summary&month=${m}&year=${a}`); L('v_monthly_summary_excl_' + tag, `get_monthly_summary&month=${m}&year=${a}&include_deleted=1`);
    }
  }
  L('get_metas_periodo', `get_metas_periodo&${ap}`); L('get_metas_diarias_mes', `get_metas_diarias_mes&${ap}`);
  L('get_metas_excecoes', `get_metas_excecoes&${ap}`); L('get_metas_diarias_estado', `get_metas_diarias_estado&${ap}`);
  L('get_metas_excecoes_grupo_equipe', `get_metas_excecoes_grupo&${ap}&escopo=equipe`); L('get_metas_excecoes_grupo_geral', `get_metas_excecoes_grupo&${ap}&escopo=geral`);
  L('get_rankings_filtered_semana', 'get_rankings_filtered&type=semana&periodo=1&ref_type=closer'); L('get_rankings_filtered_semana_equipe', 'get_rankings_filtered&type=semana&periodo=1&ref_type=equipe'); L('get_rankings_filtered_mes', `get_rankings_filtered&type=mes&periodo=${ano}-${mm}&ref_type=closer`);
  L('get_rankings_filtered_equipe', `get_rankings_filtered&type=mes&periodo=${ano}-${mm}&ref_type=equipe`);
  ['hoje', 'semana', 'mes'].forEach(p => L('get_produtividade_' + p, `get_produtividade&periodo=${p}`));
  // módulo do líder (fase L2): a seção Líderes da Produtividade do admin
  ['hoje', 'semana', 'mes'].forEach(p => L('get_produtividade_lideres_' + p, `get_produtividade_lideres&periodo=${p}`));
  // módulo do líder (fase L3): sub-aba Atividades dos líderes e o detalhe de uma equipe
  ['hoje', 'semana', 'mes'].forEach(p => L('get_atividades_lideres_' + p, `get_atividades_lideres&periodo=${p}`));
  L('get_atividade_lider_detalhe', 'get_atividade_lider_detalhe&equipe_id=1');
  L('get_produtividade_closer', `get_produtividade_closer&${ap}&closer_id=${vid}`); L('get_acoes_closer', `get_acoes_closer&closer_id=${vid}`);

  // 3) escritas (--escritas): contrato de ERRO com corpo vazio. Mostra a validação de cada ação sem depender de dados válidos.
  const ESCRITAS = ['add_equipe', 'update_equipe', 'delete_equipe', 'add_vendedor', 'update_vendedor', 'delete_vendedor', 'restore_vendedor', 'suspend_vendedor',
    'reactivate_suspended', 'set_ferias', 'move_vendedor', 'bulk_update_vendedores', 'set_lider_equipe', 'save_contratos', 'save_tipos', 'feed_add', 'feed_update', 'feed_delete',
    'save_week_days', 'save_tiebreaker', 'save_investimento_mensal', 'delete_investimento_mensal', 'save_meta_cac', 'save_investimento', 'save_tipo_custom', 'save_tipo_fixo',
    'delete_tipo_custom', 'save_atendimento', 'save_atendimentos_bulk', 'save_semanas_mes', 'delete_semanas_mes', 'add_propaganda', 'update_propaganda', 'delete_propaganda',
    'toggle_propaganda', 'save_custom_popup', 'trigger_custom_popup', 'trigger_ceremony', 'save_rotation', 'save_metas_periodo', 'save_metas_diarias_mes', 'save_meta_excecao',
    'remove_meta_excecao', 'save_meta_excecao_grupo', 'remove_meta_excecao_grupo', 'aplicar_meta_diaria', 'save_meta_diaria_closer', 'remove_meta_diaria_excecao', 'acesso_aprovar',
    'acesso_editar', 'acesso_rejeitar', 'acesso_revogar', 'acesso_reconsiderar', 'acesso_excluir', 'acesso_convite', 'acesso_reset_token'];
  if (COM_ESCRITAS || forcarEscritas) {
    ESCRITAS.forEach(a => add({ nome: 'escrita_vazia__' + a, grupo: 'escrita', acao: a, metodo: 'POST', url: 'api.php', form: { action: a } }));
    add({ nome: 'escrita__csrf_ausente', grupo: 'escrita', acao: 'save_meta_cac', metodo: 'POST', url: 'api.php', form: { action: 'save_meta_cac', meta_cac: '1' }, semToken: true });
    add({ nome: 'escrita__login_invalido', grupo: 'escrita', acao: 'login', anonimo: true, metodo: 'POST', url: 'api.php', form: { action: 'login', user: 'golden', pass: 'senha-errada-golden' } });
  }
  return { catalogo: C, contexto: { dia, ano, mes, vendedor_id: vid, equipe_id: eid } };
}

// ── closer-api.php (--closer): fluxo completo de um closer de teste, via ajudantes dos testes locais ──
async function blocoCloser() {
  const out = [];
  const helpers = path.join(process.env.LOCALAPPDATA || '', 'dash-vendas-testdb', 'e2e', 'portal-helpers.js');
  if (!fs.existsSync(helpers)) { console.log('  (closer-api pulado: portal-helpers.js não encontrado em ' + helpers + ')'); return out; }
  const h = require(helpers);
  const reg = (nome, r) => out.push({ nome: 'closer__' + nome, grupo: 'closer', acao: nome.split('_')[0], resposta: { status: r._status || 0, tipo: 'json', corpo: normalizar(Object.assign({}, r, { _status: undefined })) } });
  const u = await h.makeCloser({ tag: 'golden', email: 'golden@teste.com', senha: 'senha1234' });
  const anon = h.Client(); await anon.page('closer-login.php');
  reg('login_invalido', await anon.api('login', { email: u.email, senha: 'errada' }));
  reg('forgot', await anon.api('forgot', { email: u.email }));
  reg('reset_token_invalido', await anon.api('reset', { email: u.email, token: 'ZZZZZZ', senha: 'novasenha1', senha2: 'novasenha1' }));
  const c = h.Client();
  reg('login_ok', await c.login(u.email, 'senha1234'));
  reg('resumo', await c.api('resumo'));
  reg('acoes_hoje', await c.api('acoes', { periodo: 'hoje' }));
  reg('acao_criar_invalida', await c.api('acao_criar', { telefone: '123', tipo: '' }));
  // horário fixo: sem ele o servidor usa a hora de agora e o snapshot mudaria a cada minuto
  const novo = await c.api('acao_criar', { telefone: '11987654321', tipo: 'ligacao', observacao: 'golden', registrado_em: hoje() + ' 00:01:00' }); reg('acao_criar_ok', novo);
  const id = novo.acao && novo.acao.id;
  reg('acao_get', await c.api('acao_get', { id }));
  reg('acao_editar', await c.api('acao_editar', { id, telefone: '11987654322', tipo: 'followup', observacao: 'golden editado', registrado_em: hoje() + ' 00:02:00' }));
  reg('acao_excluir', await c.api('acao_excluir', { id }));
  reg('evolucao', await c.api('evolucao'));
  // módulo do líder (fase L2): o closer comum não é líder (papel) e a visão do time é negada para ele
  reg('lider_papel', await c.api('lider_papel'));
  reg('lider_visao_negada', await c.api('lider_visao', { periodo: 'hoje' }));
  // fase L3: o closer comum não lista nem grava registros de liderança, e a sua lista de feedbacks recebidos começa vazia
  reg('lider_registros_negado', await c.api('lider_registros', { entidade: 'feedbacks' }));
  reg('lider_registro_salvar_negado', await c.api('lider_registro_salvar', { entidade: 'feedbacks', closer_id: 1, assunto: 'x', texto: 'y' }));
  // fase L4: o portal do líder (Dashboard, Equipe, Agenda, busca e preferências) também é negado ao closer comum
  reg('lider_dashboard_negado', await c.api('lider_dashboard'));
  reg('lider_equipe_negado', await c.api('lider_equipe'));
  reg('lider_closer_negado', await c.api('lider_closer', { closer_id: 1 }));
  reg('lider_agenda_negada', await c.api('lider_agenda'));
  reg('lider_busca_negada', await c.api('lider_busca', { q: 'teste' }));
  reg('lider_preferencias_negada', await c.api('lider_preferencias'));
  reg('lider_preferencias_salvar_negada', await c.api('lider_preferencias_salvar', { limite_dias_sem_venda: 5 }));
  reg('meus_feedbacks_vazio', await c.api('meus_feedbacks'));
  reg('meus_feedbacks_novos', await c.api('meus_feedbacks_novos'));
  reg('contratos_semana', await c.api('contratos', { periodo: 'semana' }));
  // portal do closer novo (barra lateral, 6 páginas): Meu dia, Ranking, Minhas Interações, o histórico de ações por semana/mês e os contratos de hoje
  reg('contratos_hoje', await c.api('contratos', { periodo: 'hoje' }));
  reg('contratos_mes', await c.api('contratos', { periodo: 'mes' }));
  reg('acoes_semana', await c.api('acoes', { periodo: 'semana' }));
  reg('acoes_mes', await c.api('acoes', { periodo: 'mes' }));
  reg('portal_meu_dia', await c.api('portal_meu_dia'));
  reg('portal_ranking_semana', await c.api('portal_ranking', { periodo: 'semana' }));
  reg('portal_ranking_mes', await c.api('portal_ranking', { periodo: 'mes' }));
  reg('portal_ranking_invalido', await c.api('portal_ranking', { periodo: 'ano' }));
  reg('portal_interacoes_contagem', await c.api('portal_interacoes_contagem'));
  for (const t of ['feedback', 'reuniao', 'cobranca', 'revisao', 'treinamento', 'recentes', 'xx']) reg('portal_interacoes_' + t, await c.api('portal_interacoes', { tipo: t }));
  reg('portal_interacao_vista_inexistente', await c.api('portal_interacao_vista', { tipo: 'feedback', id: 999999 }));
  reg('portal_interacao_responder_vazia', await c.api('portal_interacao_responder', { tipo: 'feedback', id: 1, texto: '  ' }));
  reg('portal_interacao_responder_inexistente', await c.api('portal_interacao_responder', { tipo: 'feedback', id: 999999, texto: 'oi' }));
  reg('portal_interacao_responder_tipo_invalido', await c.api('portal_interacao_responder', { tipo: 'reuniao', id: 1, texto: 'oi' }));
  reg('logout', await c.api('logout'));
  reg('resumo_sem_sessao', await c.api('resumo'));
  return out;
}

// ── Sucesso das escritas migradas (--sucesso): cenários em sequência, com leitura depois de cada passo ──────────
// Regra do plano de migração: antes de migrar uma escrita, o caminho de SUCESSO dela é congelado aqui (o "escrita_vazia" só mostra o erro).
// Fase 3: propaganda, rotação, pop-up, cerimônia e música de fundo. Cada servidor roda o cenário num banco recém-criado, então os ids
// se repetem. Arquivos de música criados são apagados no fim (music/bg e music/ceremony da raiz do servidor testado).
const MP3 = nome => ({ nome, tipo: 'audio/mpeg', bytes: Buffer.concat([Buffer.from('ID3'), Buffer.alloc(200, 7)]) });
const RAIZES_MUSICA = [ROOT, path.join(process.env.LOCALAPPDATA || '', 'dash-vendas-testdb', 'base-antiga')];
function limparMusicaGolden() {
  for (const raiz of RAIZES_MUSICA) for (const sub of ['bg', 'ceremony']) {
    const dir = path.join(raiz, 'music', sub);
    if (!fs.existsSync(dir)) continue;
    for (const f of fs.readdirSync(dir)) if (/^(golden|ceremony_\d+_golden)/.test(f)) { try { fs.unlinkSync(path.join(dir, f)); } catch (e) { /* já apagado */ } }
  }
}
async function blocoSucesso() {
  const out = [];
  const reg = (nome, r) => out.push({ nome: 'sucesso__' + nome, grupo: 'sucesso', acao: nome.split('_')[0], resposta: r });
  const post = async (nome, acao, form, arquivos) => { const r = await chamar({ metodo: 'POST', url: 'api.php', form: Object.assign({ action: acao }, form || {}), arquivos }); reg(nome, r); return r; };
  const ler = async (nome, qs) => { const r = await chamar({ metodo: 'GET', url: 'api.php?action=' + qs }); reg(nome, r); return r; };
  const idDe = r => (r.corpo && r.corpo.id) || 0;
  limparMusicaGolden();
  try {
    // propaganda
    await post('propaganda_add_sem_html', 'add_propaganda', { titulo: 'Sem conteúdo' });
    await post('propaganda_add_html_zero', 'add_propaganda', { titulo: 'Zero', conteudo_html: '0' });
    const a = await post('propaganda_add_a', 'add_propaganda', { titulo: '  Golden A  ', conteudo_html: '<div style="x">Olá / mundo ✓</div>', ativo: '1' });
    const b = await post('propaganda_add_b_inativa', 'add_propaganda', { titulo: 'Golden B', conteudo_html: '<p>b</p>', ativo: '0' });
    await post('propaganda_add_c_padrao', 'add_propaganda', { titulo: 'Golden C', conteudo_html: '<p>c</p>' });
    await ler('propaganda_lista', 'get_propagandas'); await ler('propaganda_lista_ativas', 'get_propagandas&ativo=1'); await ler('propaganda_lista_ativo0', 'get_propagandas&ativo=0');
    await post('propaganda_update_sem_id', 'update_propaganda', { titulo: 'x', conteudo_html: '<p>x</p>' });
    await post('propaganda_update_sem_html', 'update_propaganda', { id: String(idDe(a)), titulo: 'x' });
    await post('propaganda_update_ok', 'update_propaganda', { id: String(idDe(a)), titulo: ' Golden A2 ', conteudo_html: '<p>novo</p>', ativo: '0' });
    await ler('propaganda_lista_apos_update', 'get_propagandas');
    await post('propaganda_toggle_1', 'toggle_propaganda', { id: String(idDe(b)) }); await post('propaganda_toggle_2', 'toggle_propaganda', { id: String(idDe(b)) });
    await post('propaganda_toggle_inexistente', 'toggle_propaganda', { id: '999999' }); await post('propaganda_toggle_sem_id', 'toggle_propaganda');
    await post('propaganda_delete_sem_id', 'delete_propaganda'); await post('propaganda_delete_ok', 'delete_propaganda', { id: String(idDe(a)) });
    await ler('propaganda_lista_final', 'get_propagandas');
    // rotação (valida modos, mínimo de 5 s, propaganda sem id, item sem tipo)
    const rot = [{ type: 'podium', mode: 'semanal', duration: 3 }, { type: 'podium', mode: 'xx', duration: 30 }, { type: 'ad', propaganda_id: idDe(b), duration: 20 }, { type: 'ad', duration: 20 },
      { mode: 'geral' }, { type: 'podium', mode: 'retro_mes' }, { type: 'podium', mode: 'equipe_semanal', duration: '45' }, 'texto', 7, { type: 'ad', propaganda_id: '12abc', duration: 1 }];
    await post('rotacao_save_mista', 'save_rotation', { rotation: JSON.stringify(rot) }); await ler('rotacao_get_mista', 'get_rotation');
    await post('rotacao_save_json_invalido', 'save_rotation', { rotation: '{{' }); await post('rotacao_save_texto', 'save_rotation', { rotation: '"texto"' });
    await post('rotacao_save_sem_campo', 'save_rotation'); await ler('rotacao_get_apos_vazio', 'get_rotation');
    await post('rotacao_save_vazia', 'save_rotation', { rotation: '[]' }); await ler('rotacao_get_vazia', 'get_rotation');
    // pop-up avulso
    await post('popup_save_titulo_vazio', 'save_custom_popup', { titulo: '' }); await post('popup_trigger_vazio', 'trigger_custom_popup'); await ler('popup_get_vazio', 'get_custom_popup');
    await post('popup_save_titulo', 'save_custom_popup', { titulo: '  Golden Popup  ' }); await ler('popup_get_titulo', 'get_custom_popup');
    await post('popup_trigger_ok', 'trigger_custom_popup'); await ler('popup_get_disparado', 'get_custom_popup');
    await post('popup_save_foto_invalida', 'save_custom_popup', { titulo: 'x' }, { foto1: { nome: 'a.png', tipo: 'image/png', bytes: Buffer.from('isto nao e uma imagem') } });
    await ler('popup_get_apos_foto_invalida', 'get_custom_popup');
    // cerimônia
    await ler('cerimonia_previa', 'get_ceremony_preview'); await ler('cerimonia_get_inicial', 'get_ceremony');
    await post('cerimonia_cfg_1', 'save_ceremony_settings', { final_duration: '1' }); await post('cerimonia_cfg_9999', 'save_ceremony_settings', { final_duration: '9999' });
    await post('cerimonia_cfg_texto', 'save_ceremony_settings', { final_duration: 'abc' }); await post('cerimonia_cfg_sem_campo', 'save_ceremony_settings');
    await post('cerimonia_cfg_ext_errada', 'save_ceremony_settings', { final_duration: '20' }, { musica: { nome: 'golden.txt', tipo: 'text/plain', bytes: Buffer.from('x') } });
    await post('cerimonia_cfg_mp3', 'save_ceremony_settings', { final_duration: '25' }, { musica: MP3('golden cer (1).mp3') }); await ler('cerimonia_get_com_musica', 'get_ceremony');
    await post('cerimonia_cfg_mp3_troca', 'save_ceremony_settings', { final_duration: '30' }, { musica: MP3('golden_cer2.mp3') }); await ler('cerimonia_get_musica_trocada', 'get_ceremony');
    await post('cerimonia_cfg_so_duracao', 'save_ceremony_settings', { final_duration: '12' }); await ler('cerimonia_previa_final', 'get_ceremony_preview');
    await post('cerimonia_trigger', 'trigger_ceremony'); await ler('cerimonia_get_disparada', 'get_ceremony');
    // música de fundo
    await ler('musica_lista_inicial', 'get_bg_music'); await post('musica_up_sem_arquivo', 'upload_bg_music');
    await post('musica_up_ext_errada', 'upload_bg_music', {}, { music: { nome: 'golden.txt', tipo: 'text/plain', bytes: Buffer.from('x') } });
    await post('musica_up_nome_sujo', 'upload_bg_music', {}, { music: MP3('golden bg (1).mp3') }); await post('musica_up_b', 'upload_bg_music', {}, { music: MP3('golden_bg2.mp3') });
    await ler('musica_lista', 'get_bg_music');
    await post('musica_next_ext_errada', 'set_bg_next', { name: 'golden.txt' }); await post('musica_next_inexistente', 'set_bg_next', { name: 'golden_nao_existe.mp3' });
    await post('musica_next_sem_nome', 'set_bg_next'); await post('musica_next_caminho', 'set_bg_next', { name: '../golden_bg2.mp3' });
    const prox = async nome => { const j = await get('action=get_data'); reg(nome, { status: 200, tipo: 'json', corpo: { bg_next_track: j.bg_next_track } }); };
    await prox('musica_get_data_proxima');
    await post('musica_del_invalida', 'delete_bg_music', { name: 'golden.txt' }); await post('musica_del_b', 'delete_bg_music', { name: 'golden_bg2.mp3' }); await prox('musica_get_data_proxima_apos_excluir');
    await post('musica_del_inexistente', 'delete_bg_music', { name: 'golden_nao_existe.mp3' }); await post('musica_del_nome_sujo', 'delete_bg_music', { name: 'golden_bg__1_.mp3' });
    await ler('musica_lista_final', 'get_bg_music');
  } finally { limparMusicaGolden(); }
  return out;
}

// ── Sucesso das escritas da fase 4 (--sucesso): investimento, calendário e metas ───────────────────────────────────
// Cenários em sequência, com leitura depois de cada passo. O Meta Ads não existe mais: o investimento é só o digitado à mão. Nunca chama a API
// do Meta (a conta e o token ficam em branco no banco de teste).
async function blocoSucessoFase4() {
  const out = [];
  const reg = (nome, r) => out.push({ nome: 'sucesso__f4_' + nome, grupo: 'sucesso', acao: nome.split('_')[0], resposta: r });
  const post = async (nome, acao, form) => { const r = await chamar({ metodo: 'POST', url: 'api.php', form: Object.assign({ action: acao }, form || {}) }); reg(nome, r); return r; };
  const ler = async (nome, qs) => { const r = await chamar({ metodo: 'GET', url: 'api.php?action=' + qs }); reg(nome, r); return r; };
  const dia = hoje(), [ano, mes] = dia.split('-').map(Number), A = String(ano), M = String(mes);
  const ant = mes === 1 ? [ano - 1, 12] : [ano, mes - 1];
  const exc0 = await get(`action=get_metas_excecoes&ano=${ano}&mes=${mes}`);
  const ids = (exc0.closers || []).map(c => c.id), semanas = (exc0.semanas || []).map(s => s.num);
  const eqs = ((await get('action=get_equipes')).data || []), eid = (eqs[0] && eqs[0].id) || 1;
  const S1 = String(semanas[0] || 1), S2 = String(semanas[1] || 2);

  // meta de CAC e investimento (digitado à mão)
  await post('cac_ok', 'save_meta_cac', { meta_cac: '92,5' }); await ler('cac_leitura', 'get_investimento_historico');
  await post('cac_negativa', 'save_meta_cac', { meta_cac: '-5' }); await ler('cac_leitura_zero', 'get_investimento_historico');
  await post('cac_texto', 'save_meta_cac', { meta_cac: 'abc' });
  await post('inv_mes_invalido', 'save_investimento_mensal', { ano: A, mes: '13', investimento: '10' });
  await post('inv_sem_ano', 'save_investimento_mensal', { mes: M, investimento: '10' });
  await post('inv_mes_atual', 'save_investimento_mensal', { ano: A, mes: M, investimento: '1234,56', alcance: '50000', impressoes: '120000', obs: '  campanha de teste  ' });
  await ler('inv_hist_apos_atual', 'get_investimento_historico'); await ler('inv_painel_cac', 'get_painel'); await ler('inv_custo_contrato', `get_custo_contrato&ano=${ano}&mes=${mes}`);
  await post('inv_mes_passado', 'save_investimento_mensal', { ano: String(ant[0]), mes: String(ant[1]), investimento: '900', cpm: '7,5', frequencia: '2', obs: '0' });
  await ler('inv_hist_apos_passado', 'get_investimento_historico');
  await post('inv_avulso', 'save_investimento', { valor: '2000.75' }); await ler('inv_painel_avulso', 'get_painel');
  await post('inv_avulso_negativo', 'save_investimento', { valor: '-3' }); await post('inv_avulso_vazio', 'save_investimento');
  await post('inv_excluir_invalido', 'delete_investimento_mensal', { ano: '0', mes: '5' });
  await post('inv_excluir_passado', 'delete_investimento_mensal', { ano: String(ant[0]), mes: String(ant[1]) }); await ler('inv_hist_apos_excluir_passado', 'get_investimento_historico');
  await post('inv_excluir_atual', 'delete_investimento_mensal', { ano: A, mes: M }); await ler('inv_hist_apos_excluir_atual', 'get_investimento_historico'); await ler('inv_painel_apos_excluir', 'get_painel');

  // calendário: dias do ciclo e semanas do mês
  await post('dias_5', 'save_week_days', { week_days: '5' }); await post('dias_14', 'save_week_days', { week_days: '14' }); await post('dias_99', 'save_week_days', { week_days: '99' });
  await post('dias_zero', 'save_week_days', { week_days: '0' }); await post('dias_vazio', 'save_week_days'); await post('dias_7', 'save_week_days', { week_days: '7' });
  await ler('semanas_mes_antes', `get_month_weeks&ano=${ano}&mes=${mes}`); await ler('semanas_mes_invalido', `get_month_weeks&ano=${ano}&mes=13`);
  await post('semanas_invalidas', 'save_semanas_mes', { ano: '0', mes: M, semanas: '[]' }); await post('semanas_json_ruim', 'save_semanas_mes', { ano: A, mes: M, semanas: '{{' });
  const SEM = [{ semana_num: 1, data_inicio: `${A}-${String(mes).padStart(2, '0')}-02`, data_fim: `${A}-${String(mes).padStart(2, '0')}-08` }, { semana_num: 2, data_inicio: 'sem-formato', data_fim: `${A}-${String(mes).padStart(2, '0')}-15` },
    { data_inicio: `${A}-${String(mes).padStart(2, '0')}-16`, data_fim: `${A}-${String(mes).padStart(2, '0')}-22` }, { semana_num: 0, data_inicio: `${A}-${String(mes).padStart(2, '0')}-23`, data_fim: `${A}-${String(mes).padStart(2, '0')}-29` }];
  await post('semanas_salvar', 'save_semanas_mes', { ano: A, mes: M, semanas: JSON.stringify(SEM) });
  await ler('semanas_leitura', `get_semanas_mes&ano=${ano}&mes=${mes}`); await ler('semanas_mes_configurado', `get_month_weeks&ano=${ano}&mes=${mes}`); await ler('semanas_historico', 'get_semanas_historico');
  await ler('semanas_painel', 'get_painel'); await ler('semanas_metas_estado', `get_metas_excecoes&ano=${ano}&mes=${mes}`);
  await post('semanas_excluir_invalido', 'delete_semanas_mes', { ano: A, mes: '0' }); await post('semanas_excluir', 'delete_semanas_mes', { ano: A, mes: M });
  await ler('semanas_apos_excluir', `get_month_weeks&ano=${ano}&mes=${mes}`); await ler('semanas_historico_final', 'get_semanas_historico');

  // metas por período e metas diárias avulsas
  await post('periodo_invalido', 'save_metas_periodo', { ano: A, mes: '13' }); await post('periodo_sem_lista', 'save_metas_periodo', { ano: A, mes: M });
  await post('periodo_salvar', 'save_metas_periodo', Object.assign({ ano: A, mes: M }, campos('metas', [
    { tipo: 'closer', ref_id: ids[0], semana_num: S1, meta: 8 }, { tipo: 'closer', ref_id: ids[0], semana_num: 0, meta: 70 }, { tipo: 'closer', semana_num: S1, meta: 4 }, { tipo: 'equipe', ref_id: eid, semana_num: S1, meta: 30 },
    { tipo: 'geral', ref_id: 55, semana_num: 0, meta: 900 }, { tipo: 'closer', ref_id: ids[1], semana_num: '', meta: -4 }, { tipo: 'xx', ref_id: ids[2], semana_num: S2, meta: 3 }])));
  await ler('periodo_leitura', `get_metas_periodo&ano=${ano}&mes=${mes}`); await ler('periodo_painel', 'get_painel');
  await post('diarias_mes_invalido', 'save_metas_diarias_mes', { ano: A, mes: '0', metas: '[]' }); await post('diarias_mes_json_ruim', 'save_metas_diarias_mes', { ano: A, mes: M, metas: 'x' });
  await post('diarias_mes_salvar', 'save_metas_diarias_mes', { ano: A, mes: M, metas: JSON.stringify([{ closer_id: ids[0], dia: 3, meta: 4 }, { closer_id: ids[0], dia: 40, meta: 4 }, { closer_id: 0, dia: 5, meta: 1 }, { closer_id: ids[1], dia: 3, meta: -9 }]) });
  await ler('diarias_mes_leitura', `get_metas_diarias_mes&ano=${ano}&mes=${mes}`); await ler('diarias_mes_mes13', `get_metas_diarias_mes&ano=${ano}&mes=13`);

  // exceções de meta dos closers
  await post('exc_invalida', 'save_meta_excecao', { ano: '1999', mes: M, alvo: String(ids[0]), metas: '{}' });
  await post('exc_sem_celula', 'save_meta_excecao', { ano: A, mes: M, alvo: String(ids[0]), metas: JSON.stringify({ [S1]: '' }) });
  await post('exc_semana_inexistente', 'save_meta_excecao', { ano: A, mes: M, alvo: String(ids[0]), metas: JSON.stringify({ 77: 5 }) });
  await post('exc_numero_ruim', 'save_meta_excecao', { ano: A, mes: M, alvo: String(ids[0]), metas: JSON.stringify({ [S1]: 'abc' }) });
  await post('exc_numero_zero', 'save_meta_excecao', { ano: A, mes: M, alvo: String(ids[0]), metas: JSON.stringify({ [S1]: 0 }) });
  await post('exc_closer_inexistente', 'save_meta_excecao', { ano: A, mes: M, alvo: '999999', metas: JSON.stringify({ [S1]: 9 }) });
  await post('exc_um_closer', 'save_meta_excecao', { ano: A, mes: M, alvo: String(ids[0]), metas: JSON.stringify({ [S1]: 33, 0: 140 }) }); await ler('exc_leitura_1', `get_metas_excecoes&ano=${ano}&mes=${mes}`);
  await post('exc_outro_closer', 'save_meta_excecao', { ano: A, mes: M, alvo: String(ids[1]), metas: JSON.stringify({ [S2]: 12 }) });
  await post('exc_todos', 'save_meta_excecao', { ano: A, mes: M, alvo: 'todos', metas: JSON.stringify({ [S1]: 25 }) }); await ler('exc_leitura_todos', `get_metas_excecoes&ano=${ano}&mes=${mes}`);
  await post('exc_remover_invalido', 'remove_meta_excecao', { ano: A, mes: M }); await post('exc_remover_inexistente', 'remove_meta_excecao', { ano: A, mes: M, closer_id: '999999' });
  await post('exc_remover', 'remove_meta_excecao', { ano: A, mes: M, closer_id: String(ids[0]) }); await ler('exc_leitura_removida', `get_metas_excecoes&ano=${ano}&mes=${mes}`); await ler('exc_painel', 'get_painel');

  // exceções de meta das equipes e do Comercial (geral)
  await post('grp_invalido', 'save_meta_excecao_grupo', { escopo: 'x', ano: A, mes: M, alvo: '0', metas: '{}' }); await post('grp_sem_celula', 'save_meta_excecao_grupo', { escopo: 'geral', ano: A, mes: M, alvo: '0', metas: '{}' });
  await post('grp_semana_inexistente', 'save_meta_excecao_grupo', { escopo: 'geral', ano: A, mes: M, alvo: '0', metas: JSON.stringify({ 77: 5 }) });
  await post('grp_numero_ruim', 'save_meta_excecao_grupo', { escopo: 'equipe', ano: A, mes: M, alvo: String(eid), metas: JSON.stringify({ [S1]: 'x' }) });
  await post('grp_equipe_inexistente', 'save_meta_excecao_grupo', { escopo: 'equipe', ano: A, mes: M, alvo: '999999', metas: JSON.stringify({ [S1]: 40 }) });
  await post('grp_equipe', 'save_meta_excecao_grupo', { escopo: 'equipe', ano: A, mes: M, alvo: String(eid), metas: JSON.stringify({ [S1]: 77, 0: 300 }) });
  await ler('grp_leitura_equipe', `get_metas_excecoes_grupo&escopo=equipe&ano=${ano}&mes=${mes}`);
  await post('grp_todas', 'save_meta_excecao_grupo', { escopo: 'equipe', ano: A, mes: M, alvo: 'todos', metas: JSON.stringify({ [S2]: 55 }) });
  await post('grp_equipe_voltar_auto', 'save_meta_excecao_grupo', { escopo: 'equipe', ano: A, mes: M, alvo: String(eid), metas: JSON.stringify({ [S1]: null, 0: '' }) });
  await post('grp_geral', 'save_meta_excecao_grupo', { escopo: 'geral', ano: A, mes: M, alvo: '0', metas: JSON.stringify({ [S1]: 444, 0: 1600 }) });
  await ler('grp_leitura_geral', `get_metas_excecoes_grupo&escopo=geral&ano=${ano}&mes=${mes}`); await ler('grp_painel', 'get_painel');
  await post('grp_remover_invalido', 'remove_meta_excecao_grupo', { escopo: 'x', ano: A, mes: M }); await post('grp_remover_equipe_inexistente', 'remove_meta_excecao_grupo', { escopo: 'equipe', ano: A, mes: M, alvo: '999999' });
  await post('grp_remover_equipe', 'remove_meta_excecao_grupo', { escopo: 'equipe', ano: A, mes: M, alvo: String(eid) }); await post('grp_remover_geral', 'remove_meta_excecao_grupo', { escopo: 'geral', ano: A, mes: M });
  await ler('grp_leitura_final', `get_metas_excecoes_grupo&escopo=equipe&ano=${ano}&mes=${mes}`); await ler('grp_leitura_final_geral', `get_metas_excecoes_grupo&escopo=geral&ano=${ano}&mes=${mes}`);

  // meta diária: templates e exceções
  await ler('dia_estado_inicial', `get_metas_diarias_estado&ano=${ano}&mes=${mes}`); await ler('dia_estado_mes13', `get_metas_diarias_estado&ano=${ano}&mes=13`);
  await post('dia_aplicar_invalido', 'aplicar_meta_diaria', { ano: A, mes: M, tipo: 'xx' }); await post('dia_aplicar_pers_sem_segs', 'aplicar_meta_diaria', { ano: A, mes: M, tipo: 'personalizado', segs: '[]' });
  await post('dia_aplicar_pers_sobreposto', 'aplicar_meta_diaria', { ano: A, mes: M, tipo: 'personalizado', segs: JSON.stringify([{ de: 1, ate: 10, valor: 3 }, { de: 10, ate: 20, valor: 4 }]) });
  await post('dia_aplicar_pers_fora', 'aplicar_meta_diaria', { ano: A, mes: M, tipo: 'personalizado', segs: JSON.stringify([{ de: 1, ate: 45, valor: 3 }]) });
  await post('dia_aplicar_pers_valor', 'aplicar_meta_diaria', { ano: A, mes: M, tipo: 'personalizado', segs: JSON.stringify([{ de: 1, ate: 5, valor: 1000 }]) });
  await post('dia_aplicar_uniforme', 'aplicar_meta_diaria', { ano: A, mes: M, tipo: 'uniforme' }); await ler('dia_estado_uniforme', `get_metas_diarias_estado&ano=${ano}&mes=${mes}`);
  await post('dia_closer_editar', 'save_meta_diaria_closer', { ano: A, mes: M, closer_id: String(ids[0]), dias: JSON.stringify({ 1: 9, 2: '', 3: 0, 99: 5 }) }); await ler('dia_estado_excecao', `get_metas_diarias_estado&ano=${ano}&mes=${mes}`);
  await post('dia_closer_invalido', 'save_meta_diaria_closer', { ano: A, mes: M, closer_id: String(ids[0]), dias: JSON.stringify({ 1: 5000 }) }); await post('dia_closer_inexistente', 'save_meta_diaria_closer', { ano: A, mes: M, closer_id: '999999', dias: '{}' });
  await post('dia_closer_sem_dados', 'save_meta_diaria_closer', { ano: A, mes: M });
  await post('dia_remover_excecao', 'remove_meta_diaria_excecao', { ano: A, mes: M, closer_id: String(ids[0]) }); await ler('dia_estado_apos_remover', `get_metas_diarias_estado&ano=${ano}&mes=${mes}`);
  await post('dia_remover_inexistente', 'remove_meta_diaria_excecao', { ano: A, mes: M, closer_id: '999999' }); await post('dia_remover_invalido', 'remove_meta_diaria_excecao', { ano: A, mes: M });
  await post('dia_aplicar_crescente', 'aplicar_meta_diaria', { ano: A, mes: M, tipo: 'crescente' }); await ler('dia_estado_crescente', `get_metas_diarias_estado&ano=${ano}&mes=${mes}`);
  await post('dia_aplicar_pers', 'aplicar_meta_diaria', { ano: A, mes: M, tipo: 'personalizado', segs: JSON.stringify([{ de: 1, ate: 10, valor: 2 }, { de: 11, ate: 20, valor: 3 }]) });
  await ler('dia_estado_pers', `get_metas_diarias_estado&ano=${ano}&mes=${mes}`); await ler('dia_mes_pers', `get_metas_diarias_mes&ano=${ano}&mes=${mes}`);
  await post('dia_remover_com_template', 'remove_meta_diaria_excecao', { ano: A, mes: M, closer_id: String(ids[1]) });
  await post('dia_editar_para_comum', 'save_meta_diaria_closer', { ano: A, mes: M, closer_id: String(ids[1]), dias: JSON.stringify({ 1: 7 }) }); await ler('dia_estado_final', `get_metas_diarias_estado&ano=${ano}&mes=${mes}`);
  return out;
}

// ── Sucesso das escritas da fase 5 (--sucesso): equipes, closers, contratos, tipos de benefício, atendimentos e produtividade ──────────
// Cenários em sequência, com leitura depois de cada passo. O envio de foto só é testado no caminho de ERRO (a foto aceita ganha um nome
// aleatório). O sorteio do avatar de um closer novo (img_pravatar) fica fora da comparação (IGNORAR_CHAVES).
async function blocoSucessoFase5() {
  const out = [];
  const reg = (nome, r) => out.push({ nome: 'sucesso__f5_' + nome, grupo: 'sucesso', acao: nome.split('_')[0], resposta: r });
  const post = async (nome, acao, form, arquivos) => { const r = await chamar({ metodo: 'POST', url: 'api.php', form: Object.assign({ action: acao }, form || {}), arquivos }); reg(nome, r); return r; };
  const ler = async (nome, qs) => { const r = await chamar({ metodo: 'GET', url: 'api.php?action=' + qs }); reg(nome, r); return r; };
  const dia = hoje(), ontem = somaDias(dia, -1), amanha = somaDias(dia, 1), [ano, mes] = dia.split('-').map(Number), A = String(ano), M = String(mes);
  const FOTO_RUIM = { foto: { nome: 'a.png', tipo: 'image/png', bytes: Buffer.from('isto nao e uma imagem') } };
  const est = await get(`action=get_metas_excecoes&ano=${ano}&mes=${mes}`);
  const ids = (est.closers || []).map(c => c.id), nomes = (est.closers || []).map(c => c.nome);
  const eqs = ((await get('action=get_equipes')).data || []), eid = (eqs[0] && eqs[0].id) || 1, eid2 = (eqs[1] && eqs[1].id) || eid;
  const S = String(((est.semanas || [])[0] || {}).num || 1);
  const closerDe = async nome => ((await get(`action=get_metas_excecoes&ano=${ano}&mes=${mes}`)).closers || []).find(c => c.nome === nome);

  // equipes
  await post('eq_sem_nome', 'add_equipe', { cor: '#111111' }); await post('eq_nome_zero', 'add_equipe', { nome: '0' });
  await post('eq_foto_ruim', 'add_equipe', { nome: 'Golden Foto' }, FOTO_RUIM); await ler('eq_lista_apos_foto_ruim', 'get_equipes');
  await post('eq_add', 'add_equipe', { nome: '  Golden F5  ', cor: '#123456', meta_semanal: '12', meta_mensal: '-4' }); await post('eq_add_padrao', 'add_equipe', { nome: 'Golden F5 B' });
  const lista1 = ((await ler('eq_lista_apos_add', 'get_equipes')).corpo || {}).data || [], nova = (lista1.find(e => e.nome === 'Golden F5') || {}).id || 0, novaB = (lista1.find(e => e.nome === 'Golden F5 B') || {}).id || 0;
  await post('eq_upd_sem_id', 'update_equipe', { nome: 'x' }); await post('eq_upd_sem_nome', 'update_equipe', { id: String(nova) }); await post('eq_upd_inexistente', 'update_equipe', { id: '999999', nome: 'x' });
  await post('eq_upd_foto_ruim', 'update_equipe', { id: String(nova), nome: 'Golden F5' }, FOTO_RUIM);
  await post('eq_upd_lider_de_fora', 'update_equipe', { id: String(nova), nome: 'Golden F5', lider_id: String(ids[0]) }); await ler('eq_lista_apos_upd_lider_fora', 'get_equipes');
  await post('eq_upd_so_nome', 'update_equipe', { id: String(nova), nome: 'Golden F5 Novo', cor: '#654321' }); await post('eq_upd_com_metas', 'update_equipe', { id: String(nova), nome: 'Golden F5 Novo', meta_semanal: '30', meta_mensal: '0' });
  await ler('eq_lista_apos_upd', 'get_equipes');

  // closers: cadastro (nasce no padrão), edição, duplicidade
  await post('cl_sem_nome', 'add_vendedor', { cor: '#222222' }); await post('cl_nome_zero', 'add_vendedor', { nome: '0' }); await post('cl_duplicado', 'add_vendedor', { nome: String(nomes[0]).toUpperCase() });
  await post('cl_foto_ruim', 'add_vendedor', { nome: 'Golden F5 Foto' }, FOTO_RUIM); await ler('cl_metas_apos_foto_ruim', `get_metas_excecoes&ano=${ano}&mes=${mes}`);
  await post('cl_add_padrao', 'add_vendedor', { nome: '  Golden F5 Closer  ', cor: '#abcdef', equipe_id: String(nova) }); await ler('cl_metas_apos_padrao', `get_metas_excecoes&ano=${ano}&mes=${mes}`);
  await post('cl_add_com_metas', 'add_vendedor', { nome: 'Golden F5 Closer 2', metas: '7', meta_mensal: '33', equipe_id: '0' }); await post('cl_add_metas_zero', 'add_vendedor', { nome: 'Golden F5 Closer 3', metas: '0', equipe_id: String(eid) });
  await ler('cl_metas_apos_add', `get_metas_excecoes&ano=${ano}&mes=${mes}`); await ler('cl_equipes_apos_add', 'get_equipes'); await ler('cl_painel_apos_add', 'get_painel');
  const c1 = await closerDe('Golden F5 Closer'), c2 = await closerDe('Golden F5 Closer 2'), c3 = await closerDe('Golden F5 Closer 3'), cid = c1 ? c1.id : 0, cid2 = c2 ? c2.id : 0, cid3 = c3 ? c3.id : 0;
  await post('cl_upd_sem_id', 'update_vendedor', { nome: 'x' }); await post('cl_upd_inexistente', 'update_vendedor', { id: '999999', nome: 'x' }); await post('cl_upd_nome_em_uso', 'update_vendedor', { id: String(cid), nome: String(nomes[0]) });
  await post('cl_upd_foto_ruim', 'update_vendedor', { id: String(cid), nome: 'Golden F5 Closer' }, FOTO_RUIM);
  await post('cl_upd_ok', 'update_vendedor', { id: String(cid), nome: 'Golden F5 Closer', cor: '#fedcba', equipe_id: String(eid) }); await post('cl_upd_sem_equipe', 'update_vendedor', { id: String(cid2), nome: 'Golden F5 Closer 2', equipe_id: '' });
  await post('cl_upd_com_metas', 'update_vendedor', { id: String(cid3), nome: 'Golden F5 Closer 3', metas: '9', meta_mensal: '40', equipe_id: String(eid) }); await ler('cl_metas_apos_upd', `get_metas_excecoes&ano=${ano}&mes=${mes}`); await ler('cl_equipes_apos_upd', 'get_equipes');

  // liderança e transferências
  await post('lid_equipe_inexistente', 'set_lider_equipe', { equipe_id: '999999', lider_id: String(cid) }); await post('lid_fora_da_equipe', 'set_lider_equipe', { equipe_id: String(nova), lider_id: String(cid) });
  await post('lid_ok', 'set_lider_equipe', { equipe_id: String(eid), lider_id: String(cid) }); await ler('lid_equipes_apos', 'get_equipes'); await post('lid_remover', 'set_lider_equipe', { equipe_id: String(eid), lider_id: '0' }); await ler('lid_equipes_removido', 'get_equipes');
  await post('lid_ok_2', 'set_lider_equipe', { equipe_id: String(eid), lider_id: String(cid) });
  await post('mov_sem_ids', 'move_vendedor'); await post('mov_equipe_inexistente', 'move_vendedor', { ids: String(cid), equipe_id: '999999' });
  await post('mov_para_outra', 'move_vendedor', { ids: `${cid},${cid2},${cid},x`, equipe_id: String(eid2) }); await ler('mov_equipes_apos', 'get_equipes');
  await post('mov_id_unico_sem_equipe', 'move_vendedor', { id: String(cid3), equipe_id: '' }); await post('mov_ids_inexistentes', 'move_vendedor', { ids: '999998,999999', equipe_id: String(eid) });
  await post('mov_de_volta', 'move_vendedor', { ids: `${cid},${cid3}`, equipe_id: String(eid) }); await ler('mov_equipes_final', 'get_equipes'); await ler('mov_metas', `get_metas_excecoes&ano=${ano}&mes=${mes}`);
  await post('lote_sem_ids', 'bulk_update_vendedores', { metas: '5' }); await post('lote_nada', 'bulk_update_vendedores', { ids: `${cid},${cid2}` });
  await post('lote_metas', 'bulk_update_vendedores', { ids: `${cid},${cid2}`, metas: '0' }); await post('lote_ambos', 'bulk_update_vendedores', { ids: `${cid2},${cid3},999999`, metas: '12', meta_mensal: '55' });
  await ler('lote_metas_apos', `get_metas_excecoes&ano=${ano}&mes=${mes}`);

  // férias, arquivamento e volta
  await post('fer_sem_id', 'suspend_vendedor'); await post('fer_suspender', 'suspend_vendedor', { id: String(cid2) }); await ler('fer_lista', 'get_suspended_vendedores');
  await post('fer_reativar_sem_id', 'reactivate_suspended'); await post('fer_reativar', 'reactivate_suspended', { id: String(cid2) }); await ler('fer_lista_apos_reativar', 'get_suspended_vendedores');
  await post('fer_datas_invalidas', 'set_ferias', { id: String(cid2), inicio: '2026-13-01' }); await post('fer_fim_antes', 'set_ferias', { id: String(cid2), inicio: dia, fim: ontem });
  await post('fer_fim_passado', 'set_ferias', { id: String(cid2), inicio: somaDias(dia, -9), fim: ontem }); await post('fer_closer_inexistente', 'set_ferias', { id: '999999', inicio: dia });
  await post('fer_em_curso_sem_fim', 'set_ferias', { id: String(cid2), inicio: ontem }); await post('fer_em_curso_com_fim', 'set_ferias', { id: String(cid3), inicio: dia, fim: somaDias(dia, 6) });
  await post('fer_agendada', 'set_ferias', { id: String(cid), inicio: somaDias(dia, 3), fim: somaDias(dia, 9) });
  await ler('fer_lista_apos_set', 'get_suspended_vendedores'); await ler('fer_visao_time', 'get_time_overview'); await ler('fer_ranking', 'get_rankings_filtered&type=semana&periodo=1&ref_type=closer');
  await post('arq_sem_motivo_valido', 'delete_vendedor', { id: String(cid2), motivo: 'xx' }); await post('arq_ok', 'delete_vendedor', { id: String(cid2), motivo: 'demissao' }); await post('arq_inexistente', 'delete_vendedor', { id: '999999' });
  await post('arq_lider', 'delete_vendedor', { id: String(cid), motivo: 'transferencia' }); await ler('arq_equipes_lider_sumiu', 'get_equipes'); await ler('arq_lista', 'get_deleted_vendedores'); await ler('arq_visao_time', 'get_time_overview');
  await post('res_sem_id', 'restore_vendedor'); await post('res_mesma_equipe', 'restore_vendedor', { id: String(cid2) }); await post('res_outra_equipe', 'restore_vendedor', { id: String(cid), equipe_id: String(eid2) });
  await post('res_sem_equipe', 'restore_vendedor', { id: String(cid), equipe_id: '0' }); await ler('res_lista_apos', 'get_deleted_vendedores'); await ler('res_equipes', 'get_equipes'); await ler('res_metas', `get_metas_excecoes&ano=${ano}&mes=${mes}`);

  // contratos: totais da semana, benefícios do dia, venda rápida, feed e matriz
  await post('ct_inexistente', 'save_contratos', { vendedor_id: '999999', 'daily[0]': '2' });
  await post('ct_salvar', 'save_contratos', { vendedor_id: String(cid3), 'daily[0]': '2', 'daily[1]': '3', 'daily[2]': '0', 'daily[9]': '4', 'daily[x]': '1' }); await ler('ct_matriz_apos_salvar', `get_matriz&start=${somaDias(dia, -6)}&end=${dia}&vendedor_id=${cid3}`);
  await ler('ct_painel_apos_salvar', 'get_painel');
  await post('tp_dados_invalidos', 'save_tipos', { vendedor_id: String(cid3) }); await ler('tp_get_vazio', `get_tipos&vendedor_id=${cid3}&data_registro=${dia}`); await ler('tp_get_sem_dados', 'get_tipos');
  await post('tp_salvar', 'save_tipos', { vendedor_id: String(cid3), data_registro: dia, 'tipos[bpc_loas]': '2', 'tipos[aux_doenca]': '1', 'tipos[outros]': '3', 'tipos[inexistente]': '9', outros_desc: '  Pensão G  ' });
  await ler('tp_get_apos', `get_tipos&vendedor_id=${cid3}&data_registro=${dia}`); await ler('tp_feed_hoje', 'get_feed'); await ler('tp_matriz', `get_matriz&start=${somaDias(dia, -6)}&end=${dia}&vendedor_id=${cid3}`);
  await ler('tp_matriz_consolidada', `get_matriz&start=${somaDias(dia, -6)}&end=${dia}&vendedor_id=0`); await ler('tp_painel', 'get_painel');
  await post('tp_zerar_um', 'save_tipos', { vendedor_id: String(cid3), data_registro: dia, 'tipos[bpc_loas]': '1' }); await ler('tp_feed_apos_zerar', `get_feed&data=${dia}`);
  await post('tp_dia_anterior', 'save_tipos', { vendedor_id: String(cid3), data_registro: ontem, 'tipos[aux_acidente]': '2' }); await ler('tp_feed_ontem', `get_feed&data=${ontem}`); await ler('tp_feed_data_ruim', 'get_feed&data=ontem');
  await ler('mx_invalida', 'get_matriz&start=x&end=y'); await ler('mx_invertida', `get_matriz&start=${dia}&end=${ontem}`); await ler('mx_longa', `get_matriz&start=${somaDias(dia, -30)}&end=${dia}`);
  await post('vr_closer_inexistente', 'feed_add', { vendedor_id: '999999', data_registro: dia, tipo: 'bpc_loas', quantidade: '1' }); await post('vr_data_invalida', 'feed_add', { vendedor_id: String(cid3), data_registro: '2026-02-31', tipo: 'bpc_loas', quantidade: '1' });
  await post('vr_data_futura', 'feed_add', { vendedor_id: String(cid3), data_registro: amanha, tipo: 'bpc_loas', quantidade: '1' }); await post('vr_tipo_invalido', 'feed_add', { vendedor_id: String(cid3), data_registro: dia, tipo: 'nada', quantidade: '1' });
  await post('vr_qtd_zero', 'feed_add', { vendedor_id: String(cid3), data_registro: dia, tipo: 'bpc_loas', quantidade: '0' }); await post('vr_qtd_100', 'feed_add', { vendedor_id: String(cid3), data_registro: dia, tipo: 'bpc_loas', quantidade: '100' });
  await post('vr_ok', 'feed_add', { vendedor_id: String(cid3), data_registro: dia, tipo: 'bpc_loas', quantidade: '2' }); await post('vr_ok_outros', 'feed_add', { vendedor_id: String(cid3), data_registro: dia, tipo: 'outros', quantidade: '1', descricao: '  Rural \u0001 X  ' });
  await post('vr_ok_outros_mais', 'feed_add', { vendedor_id: String(cid3), data_registro: dia, tipo: 'outros', quantidade: '2', descricao: 'Maternidade' });
  const feed = (await ler('vr_feed', `get_feed&data=${dia}`)).corpo || {}, itens = (feed.items || []).filter(i => i.vendedor_id === cid3), iA = (itens[0] || {}).id || 0, iB = (itens[itens.length - 1] || {}).id || 0;
  await ler('vr_matriz', `get_matriz&start=${somaDias(dia, -6)}&end=${dia}&vendedor_id=${cid3}`); await ler('vr_painel', 'get_painel');
  await post('vu_inexistente', 'feed_update', { id: '999999', vendedor_id: String(cid3), data_registro: dia, tipo: 'bpc_loas', quantidade: '1' }); await post('vu_qtd_invalida', 'feed_update', { id: String(iA), vendedor_id: String(cid3), data_registro: dia, tipo: 'bpc_loas', quantidade: '0' });
  await post('vu_ok_qtd', 'feed_update', { id: String(iA), vendedor_id: String(cid3), data_registro: dia, tipo: 'bpc_loas', quantidade: '4' }); await post('vu_ok_outro_dia_e_tipo', 'feed_update', { id: String(iB), vendedor_id: String(cid3), data_registro: ontem, tipo: 'aux_doenca', quantidade: '3' });
  await post('vu_outro_closer', 'feed_update', { id: String(iA), vendedor_id: String(ids[0]), data_registro: dia, tipo: 'bpc_loas', quantidade: '4' }); await post('vu_closer_arquivado', 'feed_update', { id: String(iA), vendedor_id: String(cid2), data_registro: dia, tipo: 'bpc_loas', quantidade: '4' });
  await ler('vu_feed_hoje', `get_feed&data=${dia}`); await ler('vu_feed_ontem', `get_feed&data=${ontem}`); await ler('vu_matriz', `get_matriz&start=${somaDias(dia, -6)}&end=${dia}&vendedor_id=0`); await ler('vu_painel', 'get_painel');
  await post('vd_inexistente', 'feed_delete', { id: '999999' }); await post('vd_sem_id', 'feed_delete'); await post('vd_ok', 'feed_delete', { id: String(iA) }); await ler('vd_feed', `get_feed&data=${dia}`); await ler('vd_matriz', `get_matriz&start=${somaDias(dia, -6)}&end=${dia}&vendedor_id=${cid3}`);
  await post('dp_json_ruim', 'save_tiebreaker', { order: 'x{' }); await post('dp_texto', 'save_tiebreaker', { order: '"texto"' }); await post('dp_sem_campo', 'save_tiebreaker');
  await post('dp_ok', 'save_tiebreaker', { order: JSON.stringify(['bpc_loas', { grupo: ['aux_doenca', 'aux_acidente'] }, 'conv_rate', 'Pensão']) }); await ler('dp_get', 'get_tiebreaker'); await ler('dp_ranking', 'get_rankings_filtered&type=semana&periodo=1&ref_type=closer');

  // tipos de benefício
  await ler('tb_custom_inicial', 'get_tipos_custom'); await ler('tb_config_inicial', 'get_tipos_config');
  await post('tb_sem_nome', 'save_tipo_custom', { ativo: '1' }); await post('tb_nome_longo', 'save_tipo_custom', { label: 'x'.repeat(101) }); await post('tb_cor_invalida', 'save_tipo_custom', { label: 'Golden Tipo', cor: 'azul' });
  await post('tb_icone_invalido', 'save_tipo_custom', { label: 'Golden Tipo', icone: 'nao-existe' }); await post('tb_id_inexistente', 'save_tipo_custom', { id: '999999', label: 'Golden Tipo' });
  await post('tb_criar', 'save_tipo_custom', { label: 'Golden Pensão Rural', cor: '#A1B2C3', icone: 'home' }); await post('tb_criar_duplicado', 'save_tipo_custom', { label: 'Golden Pensão Rural' });
  await post('tb_criar_inativo', 'save_tipo_custom', { label: 'Golden Inativo', ativo: '0' }); await post('tb_criar_com_ordem', 'save_tipo_custom', { label: 'Golden Primeiro', ordem: '1' });
  const cfg = ((await ler('tb_config_apos_criar', 'get_tipos_config')).corpo || {}).custom || [], tId = (cfg.find(t => t.label === 'Golden Pensão Rural') || {}).id || 0, tId2 = (cfg.find(t => t.label === 'Golden Inativo') || {}).id || 0;
  await ler('tb_custom_apos_criar', 'get_tipos_custom'); await post('tb_editar', 'save_tipo_custom', { id: String(tId), label: 'Golden Pensão Rural 2', ativo: '1', ordem: '3' }); await post('tb_editar_limpa_cor', 'save_tipo_custom', { id: String(tId), label: 'Golden Pensão Rural 2', cor: '', icone: '' });
  await post('tb_editar_ativa', 'save_tipo_custom', { id: String(tId2), label: 'Golden Inativo', ativo: '1' }); await ler('tb_config_apos_editar', 'get_tipos_config'); await ler('tb_matriz_com_tipos', `get_matriz&start=${somaDias(dia, -6)}&end=${dia}&vendedor_id=0`);
  await post('tf_inexistente', 'save_tipo_fixo', { chave: 'nada' }); await post('tf_cor_invalida', 'save_tipo_fixo', { chave: 'bpc_loas', cor: '#12' }); await post('tf_icone_invalido', 'save_tipo_fixo', { chave: 'bpc_loas', icone: 'x' });
  await post('tf_ok', 'save_tipo_fixo', { chave: 'bpc_loas', cor: '#FF8800', icone: 'star', ordem: '2' }); await post('tf_outros_ordem', 'save_tipo_fixo', { chave: 'outros', cor: '#334455', ordem: '1' }); await ler('tf_config', 'get_tipos_config');
  await post('tf_limpar', 'save_tipo_fixo', { chave: 'bpc_loas', cor: '', icone: '' }); await post('tf_limpar_outros', 'save_tipo_fixo', { chave: 'outros', cor: '' }); await ler('tf_config_limpo', 'get_tipos_config');
  await post('td_sem_id', 'delete_tipo_custom'); await post('td_inexistente', 'delete_tipo_custom', { id: '999999' }); await post('td_ok', 'delete_tipo_custom', { id: String(tId) }); await ler('td_config', 'get_tipos_config'); await ler('td_custom', 'get_tipos_custom');

  // atendimentos
  await ler('at_sem_parametros', 'get_atendimentos'); await ler('at_vazio', `get_atendimentos&vendedor_id=${cid3}&start=${somaDias(dia, -6)}&end=${dia}`);
  await post('at_invalido', 'save_atendimento', { vendedor_id: String(cid3), data_registro: 'ontem' }); await post('at_sem_closer', 'save_atendimento', { data_registro: dia, quantidade: '3' });
  await post('at_ok', 'save_atendimento', { vendedor_id: String(cid3), data_registro: dia, quantidade: '5' }); await post('at_ok_ontem', 'save_atendimento', { vendedor_id: String(cid3), data_registro: ontem, quantidade: '-2' });
  await post('at_atualizar', 'save_atendimento', { vendedor_id: String(cid3), data_registro: dia, quantidade: '8' }); await ler('at_lista', `get_atendimentos&vendedor_id=${cid3}&start=${somaDias(dia, -6)}&end=${dia}`);
  await post('at_zero_apaga', 'save_atendimento', { vendedor_id: String(cid3), data_registro: dia, quantidade: '0' }); await ler('at_lista_apos_zero', `get_atendimentos&vendedor_id=${cid3}&start=${somaDias(dia, -6)}&end=${dia}`);
  await post('al_json_ruim', 'save_atendimentos_bulk', { vendedor_id: String(cid3), days: '{{' }); await post('al_sem_closer', 'save_atendimentos_bulk', { days: '{}' });
  await post('al_ok', 'save_atendimentos_bulk', { vendedor_id: String(cid3), days: JSON.stringify({ [dia]: 4, [ontem]: 6, [somaDias(dia, -2)]: 0, ruim: 9, '2026-02-31': 1 }) }); await ler('al_lista', `get_atendimentos&vendedor_id=${cid3}&start=${somaDias(dia, -6)}&end=${dia}`);
  await ler('al_relatorio', `get_relatorio_closers&periodo=semana`); await ler('al_ranking', 'get_rankings_filtered&type=semana&periodo=1&ref_type=closer');

  // produtividade (só leitura; depois dos lançamentos acima, que mexem em contratos)
  for (const [n, qs] of [['hoje', 'periodo=hoje'], ['semana', 'periodo=semana'], ['mes', 'periodo=mes'], ['custom', `periodo=custom&from=${somaDias(dia, -10)}&to=${dia}`], ['custom_invertido', `periodo=custom&from=${dia}&to=${somaDias(dia, -10)}`],
    ['custom_futuro', `periodo=custom&from=${amanha}&to=${somaDias(dia, 5)}`], ['equipe', `periodo=mes&equipe_id=${eid}`], ['closer', `periodo=mes&closer_id=${cid3}`], ['periodo_ruim', 'periodo=xx']]) await ler('prod_' + n, 'get_produtividade&' + qs);
  for (const [n, qs] of [['mes_atual', `closer_id=${cid3}`], ['mes_ant', `closer_id=${cid3}&ano=${mes === 1 ? ano - 1 : ano}&mes=${mes === 1 ? 12 : mes - 1}`], ['mes_invalido', `closer_id=${cid3}&mes=13`], ['ano_invalido', `closer_id=${cid3}&ano=1999`],
    ['inexistente', 'closer_id=999999'], ['sem_closer', ''], ['ano_vazio', `closer_id=${cid3}&ano=`]]) await ler('prodc_' + n, 'get_produtividade_closer&' + qs);
  for (const [n, qs] of [['basico', `closer_id=${ids[0]}`], ['pagina', `closer_id=${ids[0]}&limit=3&offset=1`], ['limite_alto', `closer_id=${ids[0]}&limit=1000`], ['tipo', `closer_id=${ids[0]}&tipo=ligacao`], ['tipo_ruim', `closer_id=${ids[0]}&tipo=xx`],
    ['datas', `closer_id=${ids[0]}&from=${somaDias(dia, -30)}&to=${dia}`], ['datas_ruins', `closer_id=${ids[0]}&from=x&to=y`], ['sem_closer', '']]) await ler('prodac_' + n, 'get_acoes_closer&' + qs);
  return out;
}

// ── Variantes de estado da TV e do Painel (--variantes-tv) ───────────────────────────────────────────────
// O estado padrão do banco de teste não passa por todas as ramificações dos rankings. Aqui o banco é mudado, passo a passo, pelas
// próprias escritas do sistema (metas por período, semanas configuradas, férias, arquivar closer, desempate, atendimentos, "outros",
// investimento) e, depois de cada estado, o mesmo conjunto de leituras é refeito. Cada servidor roda a sequência num banco recém-criado
// (diferencial com --escritas). Nunca chama o Meta Ads: a conta e o token ficam em branco.
const MAIS = (iso, n) => somaDias(iso, n);
function campos(prefixo, lista) { const o = {}; lista.forEach((it, i) => Object.entries(it).forEach(([k, v]) => { o[`${prefixo}[${i}][${k}]`] = String(v); })); return o; }
async function blocoVariantesTv() {
  const out = [];
  const dia = hoje(), [ano, mes] = dia.split('-').map(Number), mm = String(mes).padStart(2, '0');
  const ant = mes === 1 ? [ano - 1, 12] : [ano, mes - 1], aa = `${ant[0]}-${String(ant[1]).padStart(2, '0')}`;
  const ctx = await get('action=get_painel');
  const dados = await get('action=get_data');
  const ids = dados.data.map(v => v.id), equipes = (await get('action=get_equipes')).data || [], eid = (equipes[0] && equipes[0].id) || 1;
  const sem = (ctx.week && ctx.week.semana_num) || 1;
  const reg = (nome, r) => out.push({ nome: 'vartv__' + nome, grupo: 'vartv', acao: nome.split('__')[1] || 'x', resposta: r });
  const leituras = [
    ['data', 'get_data'], ['total_publico', 'get_public_contratos_total'], ['painel', 'get_painel'], ['retrospectiva', 'get_retrospectiva'], ['previa_cerimonia', 'get_ceremony_preview'],
    ...[1, 2, 3, 4, 5, 9].map(n => ['painel_s' + n, `get_painel&ano=${ano}&mes=${mes}&semana_num=${n}`]),
    ['painel_ant_s1', `get_painel&ano=${ant[0]}&mes=${ant[1]}&semana_num=1`], ['painel_ant_s4', `get_painel&ano=${ant[0]}&mes=${ant[1]}&semana_num=4`], ['painel_so_mes_ant', `get_painel&ano=${ant[0]}&mes=${ant[1]}`],
    ['painel_mes13', `get_painel&ano=${ano}&mes=13&semana_num=1`], ['painel_sem_ano', `get_painel&mes=${mes}&semana_num=1`],
    ...[1, 2, 3, 4, 5, 6].flatMap(n => ['closer', 'equipe'].map(t => [`rank_s${n}_${t}`, `get_rankings_filtered&type=semana&periodo=${n}&ref_type=${t}`])),
    ...['closer', 'equipe'].flatMap(t => [[`rank_s4_ant_${t}`, `get_rankings_filtered&type=semana&periodo=4&ref_type=${t}&ano=${ant[0]}&mes=${ant[1]}`], [`rank_s5_ant_${t}`, `get_rankings_filtered&type=semana&periodo=5&ref_type=${t}&ano=${ant[0]}&mes=${ant[1]}`],
      [`rank_mes_${t}`, `get_rankings_filtered&type=mes&periodo=${ano}-${mm}&ref_type=${t}`], [`rank_mes_ant_${t}`, `get_rankings_filtered&type=mes&periodo=${aa}&ref_type=${t}`]]),
    ['rank_mes_invalido', 'get_rankings_filtered&type=mes&periodo=abc'], ['rank_tipo_invalido', 'get_rankings_filtered&type=xx&periodo=1'], ['rank_sem_tipo', 'get_rankings_filtered'], ['rank_s0', 'get_rankings_filtered&type=semana&periodo=0'],
    ...[[], [`&vendedor_id=${ids[0]}`], [`&vendedor_id=${ids[1]}`], ['&vendedor_id=999999']].flatMap(([f], i) => [
      [`graf_week_${i}`, `get_chart_data&period=week${f || ''}`], [`graf_week_s${sem}_${i}`, `get_chart_data&period=week&ano=${ano}&mes=${mes}&semana_num=${sem}${f || ''}`], [`graf_week_inexistente_${i}`, `get_chart_data&period=week&ano=${ano}&mes=${mes}&semana_num=9${f || ''}`],
      [`graf_month_${i}`, `get_chart_data&period=month&ano=${ano}&mes=${mes}${f || ''}`], [`graf_month_ant_${i}`, `get_chart_data&period=month&ano=${ant[0]}&mes=${ant[1]}${f || ''}`], [`graf_month_sem_param_${i}`, `get_chart_data&period=month${f || ''}`],
      [`graf_year_${i}`, `get_chart_data&period=year&ano=${ano}${f || ''}`], [`graf_year_ant_${i}`, `get_chart_data&period=year&ano=${ano - 1}${f || ''}`],
      [`graf_day_${i}`, `get_chart_data&period=day${f || ''}`], [`graf_day_ontem_${i}`, `get_chart_data&period=day&date=${MAIS(dia, -1)}${f || ''}`]]),
    ['graf_month_mes13', `get_chart_data&period=month&ano=${ano}&mes=13`], ['graf_month_mes0', `get_chart_data&period=month&ano=${ano}&mes=0`], ['graf_day_data_invalida', 'get_chart_data&period=day&date=2026-13-45x'], ['graf_periodo_desconhecido', 'get_chart_data&period=foo'], ['graf_sem_periodo', 'get_chart_data'],
    ...[1, 2, 3, 4, 5].map(n => [`resumo_s${n}`, `get_week_summary&ano=${ano}&mes=${mes}&semana_num=${n}`]), ['resumo_s1_ex', `get_week_summary&ano=${ano}&mes=${mes}&semana_num=1&include_deleted=1`], [`resumo_s${sem}_ex`, `get_week_summary&ano=${ano}&mes=${mes}&semana_num=${sem}&include_deleted=1`],
    ['resumo_ant_s4', `get_week_summary&ano=${ant[0]}&mes=${ant[1]}&semana_num=4`], ['resumo_sem_param', 'get_week_summary'], ['resumo_s0', `get_week_summary&ano=${ano}&mes=${mes}&semana_num=0`], ['resumo_mes13', `get_week_summary&ano=${ano}&mes=13&semana_num=1`],
  ];
  const rodar = async estado => { for (const [nome, qs] of leituras) reg(`${estado}__${nome}`, await chamar({ metodo: 'GET', url: 'api.php?action=' + qs })); };
  const escrever = async (estado, acao, form) => reg(`${estado}__w_${acao}`, await chamar({ metodo: 'POST', url: 'api.php', form: Object.assign({ action: acao }, form) }));

  await rodar('e0_base');
  // e1: metas por período (semana atual e mês; closers, equipe e meta coletiva "geral"; e outra semana e o mês anterior)
  await escrever('e1', 'save_metas_periodo', Object.assign({ ano: String(ano), mes: String(mes) }, campos('metas', [
    { tipo: 'closer', ref_id: ids[0], semana_num: sem, meta: 3 }, { tipo: 'closer', ref_id: ids[0], semana_num: 0, meta: 50 }, { tipo: 'closer', ref_id: ids[1], semana_num: 0, meta: 5 },
    { tipo: 'closer', ref_id: ids[2], semana_num: 1, meta: 2 }, { tipo: 'equipe', ref_id: eid, semana_num: sem, meta: 7 }, { tipo: 'equipe', ref_id: eid, semana_num: 0, meta: 99 },
    { tipo: 'geral', semana_num: sem, meta: 400 }, { tipo: 'geral', semana_num: 0, meta: 1500 }])));
  await escrever('e1', 'save_metas_periodo', Object.assign({ ano: String(ant[0]), mes: String(ant[1]) }, campos('metas', [{ tipo: 'closer', ref_id: ids[0], semana_num: 4, meta: 6 }, { tipo: 'equipe', ref_id: eid, semana_num: 0, meta: 40 }, { tipo: 'geral', semana_num: 0, meta: 300 }])));
  await rodar('e1_metas');
  // e2: semanas configuradas no Calendário (mês atual com 5 semanas, começando no dia 2; mês anterior terminando no dia 1)
  const ini = `${ano}-${mm}-02`, semanas = [0, 1, 2, 3, 4].map(i => ({ semana_num: i + 1, data_inicio: MAIS(ini, 7 * i), data_fim: MAIS(ini, 7 * i + 6) }));
  const iniAnt = `${aa}-04`, semAnt = [0, 1, 2, 3].map(i => ({ semana_num: i + 1, data_inicio: MAIS(iniAnt, 7 * i), data_fim: MAIS(iniAnt, 7 * i + 6) }));
  await escrever('e2', 'save_semanas_mes', { ano: String(ano), mes: String(mes), semanas: JSON.stringify(semanas) });
  await escrever('e2', 'save_semanas_mes', { ano: String(ant[0]), mes: String(ant[1]), semanas: JSON.stringify(semAnt) });
  await rodar('e2_semanas');
  // e3: férias (agora e agendada) e suspensão
  await escrever('e3', 'set_ferias', { id: String(ids[1]), inicio: dia, fim: MAIS(dia, 10) }); await escrever('e3', 'set_ferias', { id: String(ids[2]), inicio: MAIS(dia, 5), fim: MAIS(dia, 12) });
  await escrever('e3', 'suspend_vendedor', { id: String(ids[3]) });
  await rodar('e3_ferias');
  // e4: closers arquivados (ex-closers: com e sem contratos no período)
  await escrever('e4', 'delete_vendedor', { id: String(ids[4]), motivo: 'demissao' }); await escrever('e4', 'delete_vendedor', { id: String(ids[5]), motivo: 'transferencia' });
  await rodar('e4_arquivados');
  // e5: desempate por grupo e por conversão, e atendimentos (taxa de conversão)
  await escrever('e5', 'save_tiebreaker', { order: JSON.stringify([{ grupo: ['bpc_loas', 'aux_doenca'] }, 'conv_rate', 'aux_acidente']) });
  for (const [i, q] of [[0, 10], [1, 5], [6, 8], [7, 3]]) { if (ids[i]) { await escrever('e5', 'save_atendimento', { vendedor_id: String(ids[i]), data_registro: dia, quantidade: String(q) }); await escrever('e5', 'save_atendimento', { vendedor_id: String(ids[i]), data_registro: MAIS(dia, -1), quantidade: String(q + 2) }); } }
  await rodar('e5_desempate');
  // e6: benefício "outros" com descrição (vira "outros:<descrição>" nos gráficos e no mix do Painel)
  await escrever('e6', 'save_tipos', { vendedor_id: String(ids[0]), data_registro: dia, 'tipos[outros]': '2', 'tipos[bpc_loas]': '1', outros_desc: 'Pensão X' });
  await rodar('e6_outros');
  // e7: investimento (CAC manual do mês e histórico do mês anterior)
  await escrever('e7', 'save_investimento_mensal', { ano: String(ano), mes: String(mes), investimento: '1234,50' }); await escrever('e7', 'save_investimento_mensal', { ano: String(ant[0]), mes: String(ant[1]), investimento: '987.65' });
  await rodar('e7_investimento');
  // e8: sem semanas configuradas em nenhum mês (tudo pelo cálculo automático: quarta a terça) — caminho "sem Calendário"
  for (const [a, m] of [[ano, mes], [ant[0], ant[1]]]) await escrever('e8', 'delete_semanas_mes', { ano: String(a), mes: String(m) });
  await rodar('e8_automatico');
  return out;
}

// ── execução ─────────────────────────────────────────────────────────────────────
async function coletar() {
  const { catalogo, contexto } = await montarCatalogo();
  const res = {};
  let n = 0;
  for (const c of catalogo) {
    if (SO && !SO.test(c.nome)) continue;
    const r = c.semToken ? await (async () => { const h = { cookie: SESSAO, 'content-type': 'application/x-www-form-urlencoded' }; const x = await fetch(BASE + c.url, { method: 'POST', headers: h, body: new URLSearchParams(c.form).toString() }); return resumir(x.status, x.headers.get('content-type') || '', Buffer.from(await x.arrayBuffer())); })() : await chamar(c);
    res[c.nome] = { grupo: c.grupo, acao: c.acao, ...r }; n++;
  }
  if (COM_CLOSER) for (const x of await blocoCloser()) { if (!SO || SO.test(x.nome)) { res[x.nome] = { grupo: x.grupo, acao: x.acao, ...x.resposta }; n++; } }
  if (COM_VARIANTES_TV) for (const x of await blocoVariantesTv()) { if (!SO || SO.test(x.nome)) { res[x.nome] = { grupo: x.grupo, acao: x.acao, ...x.resposta }; n++; } }
  if (COM_SUCESSO) for (const x of (await blocoSucesso()).concat(await blocoSucessoFase4(), await blocoSucessoFase5())) { if (!SO || SO.test(x.nome)) { res[x.nome] = { grupo: x.grupo, acao: x.acao, ...x.resposta }; n++; } }
  return { res, contexto, n };
}
// Cobertura: toda ação do api.php precisa ter chamada COM login (leitura ou escrita-contrato) ou uma justificativa em SEM_GOLDEN_COM_LOGIN.
// Se alguém criar uma ação nova e esquecer de cobrir, o capture/compare falha e diz qual.
async function cobertura() {
  const acoes = acoesDoApi();
  const { catalogo } = await montarCatalogo(true);
  const cobertas = new Set(catalogo.filter(c => c.grupo === 'leitura' || c.grupo === 'escrita').map(c => c.acao));
  const faltam = acoes.filter(a => !cobertas.has(a) && !SEM_GOLDEN_COM_LOGIN[a]);
  const sobra = Object.keys(SEM_GOLDEN_COM_LOGIN).filter(a => !acoes.includes(a));
  return { total: acoes.length, semLogin: acoes.filter(a => !SEM_LOGIN_PULAR.has(a)).length, comLogin: cobertas.size, justificadas: Object.keys(SEM_GOLDEN_COM_LOGIN).length - sobra.length, faltam, sobra };
}
const escrever = (dir, res, contexto) => {
  if (!SO) fs.rmSync(dir, { recursive: true, force: true });   // captura parcial (--so) não apaga os outros snapshots
  fs.mkdirSync(dir, { recursive: true });
  Object.entries(res).forEach(([nome, v]) => fs.writeFileSync(path.join(dir, nome + '.json'), JSON.stringify(v, null, 1) + '\n'));
  fs.writeFileSync(path.join(dir, '_meta.json'), JSON.stringify({ dia: contexto.dia, base: BASE, chamadas: Object.keys(res).length, escritas: COM_ESCRITAS, closer: COM_CLOSER, sucesso: COM_SUCESSO, contexto }, null, 1) + '\n');
};
const lerJson = p => JSON.parse(fs.readFileSync(p, 'utf8').replace(/^﻿/, ''));   // tolera BOM (editores do Windows)
const lerDir = dir => { const o = {}; fs.readdirSync(dir).filter(f => f.endsWith('.json') && f !== '_meta.json').forEach(f => { o[f.slice(0, -5)] = lerJson(path.join(dir, f)); }); return o; };
function diferencas(a, b, caminho = '$', acc = []) {
  if (acc.length >= 6) return acc;
  if (typeof a !== typeof b || Array.isArray(a) !== Array.isArray(b) || a === null || b === null) { if (JSON.stringify(a) !== JSON.stringify(b)) acc.push(`${caminho}: ${JSON.stringify(a)} ≠ ${JSON.stringify(b)}`.slice(0, 230)); return acc; }
  if (typeof a !== 'object') { if (a !== b) acc.push(`${caminho}: ${JSON.stringify(a)} ≠ ${JSON.stringify(b)}`.slice(0, 230)); return acc; }
  const ks = new Set([...Object.keys(a), ...Object.keys(b)]);
  for (const k of ks) { if (!(k in a)) acc.push(`${caminho}.${k}: ausente no snapshot`); else if (!(k in b)) acc.push(`${caminho}.${k}: ausente agora`); else diferencas(a[k], b[k], caminho + (Array.isArray(a) ? `[${k}]` : '.' + k), acc); if (acc.length >= 6) break; }
  return acc;
}

(async () => {
  const cob = await cobertura();
  if (cmd === 'cobertura') {
    console.log(`api.php: ${cob.total} ações | ${cob.semLogin} com chamada sem login | ${cob.comLogin} com chamada com login | ${cob.justificadas} justificadas (sem golden com login) | sem cobertura: ${cob.faltam.length ? cob.faltam.join(', ') : 'nenhuma'}${cob.sobra.length ? ' | justificativa de ação que não existe mais: ' + cob.sobra.join(', ') : ''}`);
    { process.exitCode = cob.faltam.length ? 1 : 0; return; }
  }
  if (cmd === 'capture') {
    const { res, contexto, n } = await coletar(); escrever(DIR, res, contexto);
    console.log(`capture: ${n} chamadas gravadas em ${path.relative(ROOT, DIR)} (dia ${contexto.dia}; escritas=${COM_ESCRITAS}; closer=${COM_CLOSER})`);
    if (cob.faltam.length) { console.log('ATENÇÃO — ações do api.php sem chamada no catálogo: ' + cob.faltam.join(', ')); { process.exitCode = 1; return; } }
    return;
  }
  if (cmd === 'compare') {
    if (!fs.existsSync(path.join(DIR, '_meta.json'))) { console.error('Sem snapshot em ' + DIR + '. Rode "capture" primeiro.'); { process.exitCode = 2; return; } }
    const meta = lerJson(path.join(DIR, '_meta.json'));
    if (meta.dia !== hoje()) { console.error(`Snapshot de ${meta.dia}, hoje é ${hoje()}: o seed usa datas relativas a hoje. Gere o snapshot com o código da linha de base no mesmo dia (README, "Comparar em outro dia").`); { process.exitCode = 3; return; } }
    if (meta.escritas && !COM_ESCRITAS) console.log('(o snapshot inclui escritas; rode com --escritas para compará-las)');
    if (meta.closer && !COM_CLOSER) console.log('(o snapshot inclui o closer-api; rode com --closer para compará-lo)');
    if (meta.sucesso && !COM_SUCESSO) console.log('(o snapshot inclui o sucesso das escritas; rode com --sucesso para compará-lo)');
    const antes = lerDir(DIR), { res } = await coletar();
    let ruins = 0, iguais = 0;
    for (const nome of Object.keys(res)) {
      if (!(nome in antes)) { console.log('NOVA  ' + nome + ' (sem snapshot)'); ruins++; continue; }
      const d = diferencas(JSON.parse(JSON.stringify(antes[nome])), JSON.parse(JSON.stringify(res[nome])));
      if (d.length) { ruins++; console.log('DIFF  ' + nome + '\n      ' + d.join('\n      ')); } else iguais++;
    }
    const sumiram = Object.keys(antes).filter(n => !(n in res) && (!SO || SO.test(n)) && (COM_ESCRITAS || !n.startsWith('escrita')) && (COM_CLOSER || !n.startsWith('closer__')) && (COM_SUCESSO || !n.startsWith('sucesso__')));
    sumiram.forEach(n => { ruins++; console.log('FALTA ' + n + ' (está no snapshot e não rodou)'); });
    console.log(`compare: ${iguais} iguais, ${ruins} com problema, de ${Object.keys(res).length} chamadas`);
    { process.exitCode = ruins || cob.faltam.length ? 1 : 0; return; }
  }
  if (cmd === 'diferencial') {
    if (!BASE_ANTIGA) { console.error('diferencial precisa de --base-antiga <url do servidor com o código antigo> e --base <url do código novo>'); process.exitCode = 2; return; }
    const nova = BASE;
    // Com escritas, cada servidor precisa começar do MESMO banco: as escritas do primeiro mudariam o que o segundo lê.
    const resetar = flag => {
      if (!COM_ESCRITAS) return;
      const rp = path.join(process.env.LOCALAPPDATA || '', 'dash-vendas-testdb', 'reset-local-db.ps1');
      require('child_process').execFileSync('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', rp].concat(flag ? [flag] : []), { stdio: 'ignore' });
      for (const f of ['uploads/.login_attempts.json', 'uploads/.closer_rate.json', 'storage/rate-limit/closer.json']) { try { fs.unlinkSync(path.join(ROOT, f)); } catch (e) { /* não existe */ } }
    };
    resetar();
    BASE = BASE_ANTIGA; _tok = null; const antiga = await coletar();
    resetar();
    BASE = nova; _tok = null; const atual = await coletar();
    resetar('-Mix');
    let ruins = 0, iguais = 0;
    for (const nome of Object.keys(antiga.res)) {
      if (!(nome in atual.res)) { ruins++; console.log('FALTA ' + nome + ' (só o servidor antigo respondeu)'); continue; }
      const d = diferencas(JSON.parse(JSON.stringify(antiga.res[nome])), JSON.parse(JSON.stringify(atual.res[nome])));
      if (d.length) { ruins++; console.log('DIFF  ' + nome + '\n      ' + d.join('\n      ')); } else iguais++;
    }
    console.log(`diferencial (antigo ${BASE_ANTIGA} × novo ${nova}): ${iguais} iguais, ${ruins} com diferença, de ${Object.keys(antiga.res).length} chamadas`);
    process.exitCode = ruins ? 1 : 0; return;
  }
  if (cmd === 'estabilidade') {
    const a = await coletar(), b = await coletar();
    let inst = 0;
    for (const nome of Object.keys(a.res)) { const d = diferencas(JSON.parse(JSON.stringify(a.res[nome])), JSON.parse(JSON.stringify(b.res[nome]))); if (d.length) { inst++; console.log('INSTÁVEL ' + nome + '\n         ' + d.join('\n         ')); } }
    console.log(`estabilidade: ${Object.keys(a.res).length - inst} estáveis, ${inst} instáveis`);
    { process.exitCode = inst ? 1 : 0; return; }
  }
  console.error('comandos: capture | compare | diferencial | estabilidade | cobertura'); { process.exitCode = 2; return; }
})().catch(e => { console.error('FALHA', e.stack || e.message); process.exitCode = 1; });
