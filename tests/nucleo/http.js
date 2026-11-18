// Testes HTTP do núcleo MVC (fase 1). Precisam de: o servidor do núcleo na raiz (8788), o núcleo "montado em /nova" (8789)
// e o servidor do sistema atual (8787), para provar que a sessão e o token CSRF são compartilhados. Use o rodar.ps1.
//   node tests/nucleo/http.js
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const RAIZ = path.resolve(__dirname, '..', '..');
const RAIZ_URL = 'http://127.0.0.1:8788';    // núcleo na raiz do site
const NOVA_URL = 'http://127.0.0.1:8789';    // núcleo montado em /nova (o prefixo faz parte da URL)
const LEGADO = 'http://127.0.0.1:8787';      // sistema atual
const SESSAO = 'PHPSESSID=testadmin0001';
const CHAVE = /'chave_teste'\s*=>\s*'([0-9a-f]+)'/.exec(fs.readFileSync(path.join(RAIZ, 'config', 'app.php'), 'utf8'))[1];

let pass = 0, fail = 0;
const ok = (nome, cond, extra) => { cond ? pass++ : fail++; console.log((cond ? 'PASS ' : 'FAIL ') + nome + (!cond && extra !== undefined ? '  → ' + JSON.stringify(extra).slice(0, 400) : '')); };

async function http(base, metodo, caminho, { cookie, headers = {}, body, redirect = 'manual' } = {}) {
  const h = Object.assign({}, headers);
  if (cookie) h.cookie = cookie;
  const r = await fetch(base + caminho, { method: metodo, headers: h, body, redirect });
  const texto = await r.text();
  let json = null; try { json = JSON.parse(texto); } catch (e) { /* html */ }
  return { status: r.status, headers: r.headers, texto, json };
}
const k = `k=${CHAVE}`;
const tokenDoLegado = async () => (await (await fetch(LEGADO + '/api.php?action=get_csrf', { headers: { cookie: SESSAO } })).json()).token;

(async () => {
  // limpa o controle de tentativas (a janela é de 60 s; sem isso uma segunda rodada logo em seguida já começaria bloqueada)
  const rl = path.join(RAIZ, 'storage', 'rate-limit');
  if (fs.existsSync(rl)) fs.readdirSync(rl).filter(f => f.endsWith('.json')).forEach(f => fs.unlinkSync(path.join(rl, f)));

  // ── /health ──
  const h = await http(RAIZ_URL, 'GET', `/health?${k}`);
  ok('H1 /health com a chave: 200, status ok, PHP e banco', h.status === 200 && h.json && h.json.success === true && h.json.status === 'ok' && typeof h.json.php === 'string' && h.json.banco && h.json.banco.ok === true, h.json || h.texto);
  ok('H2 /health informa a pasta de gravação e as extensões, e o núcleo na raiz ("/")', h.json.storage_gravavel === true && h.json.extensoes.pdo_mysql === true && h.json.montado_em === '/', h.json);
  // credenciais lidas SÓ em memória, para provar que nunca aparecem na resposta
  const seg = fs.readFileSync(path.join(RAIZ, 'config.secrets.php'), 'utf8');
  const cred = ['DB_PASS', 'DB_USER', 'DB_HOST', 'DB_NAME'].map(n => new RegExp("define\\('" + n + "',\\s*'([^']*)'").exec(seg)).filter(Boolean).map(m => m[1]).filter(v => v.length >= 4);
  const caminhoDeDisco = /[A-Za-z]:[\\/]|\/home\/|\/var\/www/;
  ok('H3 /health não mostra senha, usuário, host nem nome do banco, nem caminho de disco (' + cred.length + ' credenciais conferidas)', cred.length >= 3 && cred.every(c => !h.texto.includes(c)) && !caminhoDeDisco.test(h.texto), h.texto.slice(0, 200));
  ok('H4 cabeçalhos: JSON em UTF-8, nosniff, SAMEORIGIN, no-store, e nenhum cookie de sessão em rota pública', /application\/json; charset=utf-8/.test(h.headers.get('content-type')) && h.headers.get('x-content-type-options') === 'nosniff' && h.headers.get('x-frame-options') === 'SAMEORIGIN' && h.headers.get('cache-control') === 'no-store' && !h.headers.get('set-cookie'), [...h.headers.entries()]);
  const sem = await http(RAIZ_URL, 'GET', '/health'), errada = await http(RAIZ_URL, 'GET', '/health?k=errada'), nada = await http(RAIZ_URL, 'GET', '/nao-existe');
  ok('H5 /health sem chave ou com chave errada = 404, idêntico à rota que não existe', sem.status === 404 && errada.status === 404 && sem.texto === nada.texto && errada.texto === nada.texto && nada.json.message === 'Rota não encontrada.', [sem.texto, nada.texto]);
  const head = await http(RAIZ_URL, 'HEAD', `/health?${k}`);
  ok('H6 HEAD /health responde 200 sem corpo', head.status === 200 && head.texto === '', head.status);

  // ── roteamento ──
  const m405 = await http(RAIZ_URL, 'POST', '/health');
  ok('R1 método errado = 405 com cabeçalho Allow', m405.status === 405 && /GET/.test(m405.headers.get('allow') || '') && m405.json.success === false, [m405.status, m405.headers.get('allow')]);
  const p1 = await http(RAIZ_URL, 'GET', `/_teste/parametro/42?${k}`), p2 = await http(RAIZ_URL, 'GET', `/_teste/parametro/abc?${k}`);
  ok('R2 parâmetro de rota com restrição numérica: /parametro/42 passa, /parametro/abc = 404', p1.status === 200 && p1.json.id === '42' && p2.status === 404, [p1.json, p2.status]);
  const barra = await http(RAIZ_URL, 'GET', `/health/?${k}`), idx = await http(RAIZ_URL, 'GET', `/index.php/health?${k}`);
  ok('R3 barra no fim e /index.php/ na frente chegam na mesma rota', barra.status === 200 && idx.status === 200, [barra.status, idx.status]);
  const acento = await http(RAIZ_URL, 'GET', `/relat%C3%B3rio?${k}`);
  ok('R4 caminho com acento codificado = 404 JSON limpo (sem erro de PHP)', acento.status === 404 && acento.json && acento.json.success === false, acento.texto);
  const trav = await Promise.all(['/..%2f..%2fconfig%2fapp.php', '/../config/app.php', '/app/Core/Config.php', '/config/app.php', '/storage/logs/.gitkeep', '/%2e%2e/config/app.php'].map(u => http(RAIZ_URL, 'GET', u)));
  ok('R5 código e configuração não são alcançáveis pela pasta pública (travessia de pasta e caminhos diretos = 404)', trav.every(t => t.status === 404 && !/<\?php|chave_teste|DB_PASS/.test(t.texto)), trav.map(t => [t.status, t.texto.slice(0, 60)]));

  // ── entrada: query, formulário, JSON ──
  const eco = await http(RAIZ_URL, 'POST', `/_teste/eco?${k}&x=da-query&y=2`, { headers: { 'content-type': 'application/json' }, body: JSON.stringify({ x: 'do-json', lista: [1, 2] }) });
  ok('E1 corpo JSON é lido (e vence a query): entrada_x = "do-json", lista preservada', eco.status === 200 && eco.json.entrada_x === 'do-json' && eco.json.json === true && JSON.stringify(eco.json.corpo.lista) === '[1,2]' && eco.json.query.y === '2', eco.json);
  const form = await http(RAIZ_URL, 'POST', `/_teste/eco?${k}`, { headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: 'x=do-form&acento=a%C3%A7%C3%A3o' });
  ok('E2 formulário é lido, com acentos', form.json.entrada_x === 'do-form' && form.json.corpo.acento === 'ação' && form.json.json === false, form.json);
  const ruim = await http(RAIZ_URL, 'POST', `/_teste/eco?${k}`, { headers: { 'content-type': 'application/json' }, body: '{quebrado' });
  ok('E3 JSON inválido não derruba: corpo vazio e resposta 200', ruim.status === 200 && Array.isArray(ruim.json.corpo) && ruim.json.corpo.length === 0, ruim.json || ruim.texto);

  // ── CSRF e sessão compartilhada com o sistema atual ──
  const t0 = await http(RAIZ_URL, 'POST', `/_teste/csrf?${k}`);
  ok('C1 POST sem sessão e sem token = 419 (código csrf)', t0.status === 419 && t0.json.code === 'csrf', t0.json);
  const t1 = await http(RAIZ_URL, 'POST', `/_teste/csrf?${k}`, { cookie: SESSAO });
  ok('C2 POST com a sessão do admin mas SEM token = 419', t1.status === 419, t1.json);
  const tok = await tokenDoLegado();
  ok('C3 o token vem do sistema atual (get_csrf do api.php): 64 caracteres hexadecimais', /^[0-9a-f]{64}$/.test(tok), tok);
  const t2 = await http(RAIZ_URL, 'POST', `/_teste/csrf?${k}`, { cookie: SESSAO, headers: { 'x-csrf-token': tok } });
  ok('C4 o MESMO token vale na rota nova (sessão e token compartilhados com o sistema antigo): 200', t2.status === 200 && t2.json.token_valido === true, t2.json);
  const t3 = await http(RAIZ_URL, 'POST', `/_teste/csrf?${k}`, { cookie: SESSAO, headers: { 'x-csrf-token': tok.slice(0, -1) + (tok.endsWith('0') ? '1' : '0') } });
  ok('C5 token com 1 caractere trocado = 419', t3.status === 419, t3.json);
  const t4 = await http(RAIZ_URL, 'POST', `/_teste/csrf?${k}`, { cookie: SESSAO, headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: `_csrf=${tok}` });
  ok('C6 o token também vale no campo _csrf do formulário', t4.status === 200, t4.json);
  const t5 = await http(RAIZ_URL, 'GET', `/_teste/eco?${k}`);
  ok('C7 leitura (GET) não exige token', t5.status === 200, t5.json);

  // ── login (auth:admin) ──
  const a1 = await http(RAIZ_URL, 'GET', `/_teste/admin?${k}`, { headers: { accept: 'text/html,application/xhtml+xml' } });
  ok('A1 sem login, navegador pedindo página: 302 para /login', a1.status === 302 && a1.headers.get('location') === '/login', [a1.status, a1.headers.get('location')]);
  const a2 = await http(RAIZ_URL, 'GET', `/_teste/admin?${k}`, { headers: { accept: 'application/json' } });
  ok('A2 sem login, chamada de API: 401 JSON "Não autenticado"', a2.status === 401 && a2.json.message === 'Não autenticado', a2.json);
  const a3 = await http(RAIZ_URL, 'GET', `/_teste/admin?${k}`, { cookie: SESSAO });
  ok('A3 com a sessão de admin do sistema atual: 200 e o usuário da sessão', a3.status === 200 && a3.json.usuario === 'teste', a3.json);
  const a4 = await http(RAIZ_URL, 'GET', `/_teste/admin?${k}`, { cookie: 'PHPSESSID=sessaoquenaoexiste1234567890ab', headers: { accept: 'application/json' } });
  ok('A4 cookie de sessão inexistente = 401 (não cria login)', a4.status === 401, a4.json);

  // ── limite de tentativas ──
  const lim = [];
  for (let i = 0; i < 4; i++) lim.push(await http(RAIZ_URL, 'GET', `/_teste/limite?${k}`));
  ok('L1 limite 3 por minuto: 3 passam e a 4ª recebe 429 com Retry-After', lim.slice(0, 3).every(x => x.status === 200) && lim[3].status === 429 && Number(lim[3].headers.get('retry-after')) > 0 && Number(lim[3].headers.get('retry-after')) <= 60, lim.map(x => x.status));
  ok('L2 o controle fica em storage/rate-limit (fora da pasta pública) e não guarda o IP em texto', fs.readdirSync(rl).filter(f => f.endsWith('.json')).length >= 1 && fs.readdirSync(rl).every(f => !f.includes('127.0.0.1')), fs.readdirSync(rl));

  // ── view e assets ──
  const v = await http(RAIZ_URL, 'GET', `/_teste/visao?${k}&nome=${encodeURIComponent('<script>alert(1)</script>')}`);
  ok('V1 a view escapa o texto da URL (XSS): sem <script> cru, com &lt;script&gt;', v.status === 200 && /text\/html; charset=utf-8/.test(v.headers.get('content-type')) && v.texto.includes('&lt;script&gt;alert(1)&lt;/script&gt;') && !v.texto.includes('<script>alert(1)'), v.texto.slice(0, 300));
  const css = /href="([^"]+base\.css\?v=\d+)"/.exec(v.texto);
  ok('V2 o CSS vem com ?v=<data do arquivo> (o navegador não usa cópia antiga)', css && Number(css[1].split('v=')[1]) > 1.7e9, css && css[1]);
  const c = css ? await http(RAIZ_URL, 'GET', css[1]) : { status: 0, headers: new Headers(), texto: '' };
  ok('V3 o asset é servido (200, text/css) e a view tem layout e rodapé', c.status === 200 && /text\/css/.test(c.headers.get('content-type')) && v.texto.includes('<!DOCTYPE html>') && v.texto.includes('núcleo MVC (fase 1)'), [c.status, c.headers.get('content-type')]);

  // ── núcleo montado em /nova (como na HostGator) ──
  const n = await http(NOVA_URL, 'GET', `/nova/health?${k}`);
  ok('N1 montado em /nova: /nova/health responde e informa montado_em "/nova" e caminho "/health"', n.status === 200 && n.json.montado_em === '/nova' && n.json.caminho === '/health' && n.json.banco.ok === true, n.json || n.texto);
  const n404 = await http(NOVA_URL, 'GET', '/nova/nao-existe');
  ok('N2 em /nova: rota inexistente = 404 JSON', n404.status === 404 && n404.json.success === false && n404.json.message === 'Rota não encontrada.', [n404.status, n404.texto]);
  const nv = await http(NOVA_URL, 'GET', `/nova/_teste/visao?${k}&nome=x`);
  const ncss = /href="([^"]+base\.css\?v=\d+)"/.exec(nv.texto);
  ok('N3 em /nova os assets saem com o prefixo (/nova/assets/...) e carregam', ncss && ncss[1].startsWith('/nova/assets/css/base.css?v=') && (await http(NOVA_URL, 'GET', ncss[1])).status === 200, ncss && ncss[1]);
  const na = await http(NOVA_URL, 'GET', `/nova/_teste/admin?${k}`, { headers: { accept: 'text/html' } });
  ok('N4 em /nova o login continua em /login.php (a raiz do site), não em /nova/login.php', na.status === 302 && na.headers.get('location') === '/login.php', na.headers.get('location'));
  const nc = await http(NOVA_URL, 'POST', `/nova/_teste/csrf?${k}`, { cookie: SESSAO, headers: { 'x-csrf-token': tok } });
  ok('N5 em /nova o token do sistema atual também vale (mesmo cookie de sessão: caminho "/")', nc.status === 200, nc.json);

  // ── Relatórios (fase 2): as rotas novas e as URLs antigas do api.php (pela ponte) devolvem EXATAMENTE o mesmo corpo ──
  const zlib = require('zlib');
  // hash do desenho de cada página do PDF (descomprimido), sem a hora de geração do rodapé
  const hashPdf = buf => { const txt = buf.toString('latin1'), partes = []; const re = /stream\n([\s\S]*?)\nendstream/g; let m; while ((m = re.exec(txt))) { let b = Buffer.from(m[1], 'latin1'); try { b = zlib.inflateSync(b); } catch (e) { /* sem compressão */ } partes.push(b.toString('latin1').replace(/Gerado em \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/g, 'Gerado em <data>')); } return crypto.createHash('sha1').update(partes.join('\n--\n')).digest('hex'); };
  const bin = async (url, cookie) => { const r = await fetch(url, { headers: cookie ? { cookie } : {}, redirect: 'manual' }); return { status: r.status, headers: r.headers, buf: Buffer.from(await r.arrayBuffer()) }; };
  const Q = 'ano=2026&mes=10';
  const nega = await http(RAIZ_URL, 'GET', `/api/relatorios/closers?${Q}`, { headers: { accept: 'application/json' } });
  ok('RL1 sem login a rota nova responde 401 JSON "Não autenticado" (e a página pedida pelo navegador vai para o login)', nega.status === 401 && nega.json.message === 'Não autenticado' && (await http(RAIZ_URL, 'GET', `/api/relatorios/closers?${Q}`, { headers: { accept: 'text/html' } })).status === 302, [nega.status, nega.texto]);
  const legSem = await http(LEGADO, 'GET', `/api.php?action=get_relatorio_closers&${Q}`);
  ok('RL2 sem login a URL antiga do api.php continua respondendo {"success":false,"message":"Não autenticado"}', legSem.json && legSem.json.success === false && legSem.json.message === 'Não autenticado', legSem.texto);
  const pares = [['closers', `/api/relatorios/closers?${Q}`, `/api.php?action=get_relatorio_closers&${Q}`], ['custo-contrato', `/api/relatorios/custo-contrato?${Q}`, `/api.php?action=get_custo_contrato&${Q}`],
    ['resumo-mensal', '/api/relatorios/resumo-mensal?year=2026&month=10', '/api.php?action=get_monthly_summary&year=2026&month=10'], ['resumo-mensal com desligados', '/api/relatorios/resumo-mensal?year=2026&month=10&include_deleted=1', '/api.php?action=get_monthly_summary&year=2026&month=10&include_deleted=1'],
    ['closers sem parâmetros', '/api/relatorios/closers', '/api.php?action=get_relatorio_closers']];
  for (const [nome, nova, antiga] of pares) {
    const a = await http(RAIZ_URL, 'GET', nova, { cookie: SESSAO }), b = await http(LEGADO, 'GET', antiga, { cookie: SESSAO });
    ok(`RL3 ${nome}: a rota nova (núcleo na raiz) e a URL antiga (api.php, pela ponte) devolvem o MESMO corpo JSON (${a.texto.length} bytes)`, a.status === 200 && b.status === 200 && a.json && a.json.success === true && a.texto === b.texto && /application\/json/.test(a.headers.get('content-type')), [a.status, b.status, a.texto.slice(0, 120), b.texto.slice(0, 120)]);
  }
  const nvRel = await http(NOVA_URL, "GET", `/nova/api/relatorios/closers?${Q}`, { cookie: SESSAO });
  ok('RL4 montado em /nova: /nova/api/relatorios/closers responde com a sessão do sistema atual e traz o mesmo JSON', nvRel.status === 200 && nvRel.json.success === true && nvRel.texto === (await http(LEGADO, 'GET', `/api.php?action=get_relatorio_closers&${Q}`, { cookie: SESSAO })).texto, [nvRel.status, nvRel.texto.slice(0, 100)]);
  const inv = await http(RAIZ_URL, 'GET', '/api/relatorios/closers?ano=1999&mes=13', { cookie: SESSAO }), atualJ = await http(RAIZ_URL, 'GET', '/api/relatorios/closers', { cookie: SESSAO });
  ok('RL5 ano 1999 e mês 13 caem no mês corrente (mesmos dados da chamada sem parâmetros)', inv.json.ano === atualJ.json.ano && inv.json.mes === atualJ.json.mes && JSON.stringify(inv.json.data) === JSON.stringify(atualJ.json.data), [inv.json.ano, inv.json.mes]);
  ok('RL6 método errado na rota nova = 405 (só GET)', (await http(RAIZ_URL, 'POST', `/api/relatorios/closers?${Q}`, { cookie: SESSAO, headers: { 'x-csrf-token': await tokenDoLegado() } })).status === 405);
  const pdfN = await bin(RAIZ_URL + `/api/relatorios/closers.pdf?${Q}`, SESSAO), pdfL = await bin(LEGADO + `/api.php?action=relatorio_closers_pdf&${Q}`, SESSAO);
  ok('RL7 PDF pela rota nova: 200, application/pdf, download "relatorio-closers-2026-10.pdf", começa com %PDF e tem Content-Length certo', pdfN.status === 200 && pdfN.headers.get('content-type') === 'application/pdf' && pdfN.headers.get('content-disposition') === 'attachment; filename="relatorio-closers-2026-10.pdf"' && pdfN.buf.slice(0, 5).toString() === '%PDF-' && Number(pdfN.headers.get('content-length')) === pdfN.buf.length, [pdfN.status, pdfN.headers.get('content-type'), pdfN.headers.get('content-disposition')]);
  ok('RL8 o PDF da rota nova e o da URL antiga têm o MESMO conteúdo (desenho de cada página idêntico, hora de geração à parte)', pdfL.status === 200 && pdfL.headers.get('content-type') === 'application/pdf' && hashPdf(pdfN.buf) === hashPdf(pdfL.buf), [hashPdf(pdfN.buf), hashPdf(pdfL.buf)]);
  const pdfF = await bin(RAIZ_URL + `/api/relatorios/closers.pdf?${Q}&equipe=__sem`, SESSAO), pdfFL = await bin(LEGADO + `/api.php?action=relatorio_closers_pdf&${Q}&equipe=__sem`, SESSAO);
  ok('RL9 o filtro de equipe no PDF funciona nas duas portas (e muda o conteúdo em relação ao PDF completo)', hashPdf(pdfF.buf) === hashPdf(pdfFL.buf) && hashPdf(pdfF.buf) !== hashPdf(pdfN.buf), [hashPdf(pdfF.buf).slice(0, 8), hashPdf(pdfFL.buf).slice(0, 8), hashPdf(pdfN.buf).slice(0, 8)]);
  const pdfSem = await bin(RAIZ_URL + `/api/relatorios/closers.pdf?${Q}`, null);
  ok('RL10 PDF sem login não entrega o arquivo (401)', pdfSem.status === 401 && pdfSem.buf.slice(0, 4).toString() !== '%PDF', pdfSem.status);
  const aba = await http(LEGADO, 'GET', '/admin.php', { cookie: SESSAO });
  ok('RL11 o admin.php (com a sessão de teste) monta a aba Relatórios pela View do núcleo: sub-abas, filtros e painéis no HTML', aba.status === 200 && /id="tabRelatorios"/.test(aba.texto) && /id="rpPerf"/.test(aba.texto) && /id="rpCusto"/.test(aba.texto) && /Performance de Closers/.test(aba.texto) && !/núcleo \(pasta/.test(aba.texto), [aba.status, aba.texto.length]);

  // ── fase 3: TV (propaganda, pop-up, cerimônia, música de fundo, rotação, retrospectiva) ──
  const paresTv = [['/api/propagandas', 'get_propagandas'], ['/api/popup', 'get_custom_popup'], ['/api/cerimonia', 'get_ceremony'], ['/api/musica-fundo', 'get_bg_music'], ['/api/rotacao', 'get_rotation'], ['/api/retrospectiva', 'get_retrospectiva']];
  for (const [rota, acao] of paresTv) {
    const a = await http(RAIZ_URL, 'GET', rota), b = await http(LEGADO, 'GET', `/api.php?action=${acao}`);
    ok(`TV1 ${acao}: leitura pública (a TV não tem login): a rota nova ${rota} e a URL antiga devolvem o MESMO JSON (${a.texto.length} bytes)`, a.status === 200 && b.status === 200 && a.json && a.json.success === true && a.texto === b.texto, [a.status, b.status, a.texto.slice(0, 100), b.texto.slice(0, 100)]);
  }
  const prevN = await http(RAIZ_URL, 'GET', '/api/cerimonia/previa', { cookie: SESSAO }), prevL = await http(LEGADO, 'GET', '/api.php?action=get_ceremony_preview', { cookie: SESSAO });
  ok('TV2 a prévia da cerimônia só com login: sem login 401 (rota) e "Não autenticado" (URL antiga); com login as duas devolvem o mesmo JSON', (await http(RAIZ_URL, 'GET', '/api/cerimonia/previa')).status === 401 && (await http(LEGADO, 'GET', '/api.php?action=get_ceremony_preview')).json.message === 'Não autenticado' && prevN.status === 200 && prevN.texto === prevL.texto, [prevN.status, prevN.texto.slice(0, 80)]);
  const escritas = ['/api/propagandas', '/api/propagandas/atualizar', '/api/propagandas/excluir', '/api/propagandas/alternar', '/api/popup', '/api/popup/disparar', '/api/cerimonia/configuracao', '/api/cerimonia/disparar', '/api/musica-fundo', '/api/musica-fundo/excluir', '/api/musica-fundo/proxima', '/api/rotacao'];
  const semLogin = await Promise.all(escritas.map(e => http(RAIZ_URL, 'POST', e, { headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: 'id=1' })));
  ok(`TV3 as ${escritas.length} escritas da TV sem login respondem 401 e não gravam`, semLogin.every(r => r.status === 401 && r.json && r.json.message === 'Não autenticado'), semLogin.map(r => r.status));
  const semToken = await Promise.all(escritas.map(e => http(RAIZ_URL, 'POST', e, { cookie: SESSAO, headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: 'id=1' })));
  const tokenErrado = await http(RAIZ_URL, 'POST', '/api/rotacao', { cookie: SESSAO, headers: { 'content-type': 'application/x-www-form-urlencoded', 'x-csrf-token': 'adulterado' }, body: 'rotation=[]' });
  ok('TV4 logado mas sem o token CSRF (ou com token adulterado), toda escrita da TV responde 419', semToken.every(r => r.status === 419) && tokenErrado.status === 419, [semToken.map(r => r.status), tokenErrado.status]);
  const formTv = { 'content-type': 'application/x-www-form-urlencoded' }, tkTv = await tokenDoLegado();
  const rotOriginal = (await http(LEGADO, 'GET', '/api.php?action=get_rotation')).json.data;
  const rot = [{ type: 'podium', mode: 'semanal', duration: 3 }, { type: 'podium', mode: 'xx' }, { type: 'podium', mode: 'retro_mes', duration: 45 }];
  const salva = await http(RAIZ_URL, 'POST', '/api/rotacao', { cookie: SESSAO, headers: Object.assign({ 'x-csrf-token': tkTv }, formTv), body: 'rotation=' + encodeURIComponent(JSON.stringify(rot)) });
  const lida = await http(LEGADO, 'GET', '/api.php?action=get_rotation');
  ok('TV5 salvar a rotação pela rota nova (login + CSRF): grava só os itens válidos (mínimo 5 s) e a URL antiga lê o mesmo', salva.status === 200 && salva.json.success === true && JSON.stringify(lida.json.data) === JSON.stringify([{ type: 'podium', mode: 'semanal', duration: 5 }, { type: 'podium', mode: 'retro_mes', duration: 45 }]), [salva.texto, lida.texto]);
  const viaLegado = await http(LEGADO, 'POST', '/api.php', { cookie: SESSAO, headers: Object.assign({ 'x-csrf-token': tkTv }, formTv), body: 'action=save_rotation&rotation=' + encodeURIComponent(JSON.stringify(rotOriginal)) });
  ok('TV6 a URL antiga (api.php?action=save_rotation, pela ponte) também salva, com login e CSRF; sem token = 419', viaLegado.json && viaLegado.json.success === true && viaLegado.json.message === 'Rotação salva!' && JSON.stringify((await http(LEGADO, 'GET', '/api.php?action=get_rotation')).json.data) === JSON.stringify(rotOriginal) && (await http(LEGADO, 'POST', '/api.php', { cookie: SESSAO, headers: formTv, body: 'action=save_rotation&rotation=[]' })).status === 419, [viaLegado.texto]);
  const pAdd = await http(RAIZ_URL, 'POST', '/api/propagandas', { cookie: SESSAO, headers: Object.assign({ 'x-csrf-token': tkTv }, formTv), body: 'titulo=%20HTTP%20teste%20&conteudo_html=' + encodeURIComponent('<p>olá / http</p>') + '&ativo=1' });
  const idP = pAdd.json && pAdd.json.id;
  const pTog = await http(RAIZ_URL, 'POST', '/api/propagandas/alternar', { cookie: SESSAO, headers: Object.assign({ 'x-csrf-token': tkTv }, formTv), body: 'id=' + idP });
  const pUpd = await http(RAIZ_URL, 'POST', '/api/propagandas/atualizar', { cookie: SESSAO, headers: Object.assign({ 'x-csrf-token': tkTv }, formTv), body: `id=${idP}&titulo=Novo&conteudo_html=${encodeURIComponent('<p>novo</p>')}&ativo=1` });
  const listaAntes = (await http(LEGADO, 'GET', '/api.php?action=get_propagandas')).json.data.find(p => p.id === idP);
  const pDel = await http(RAIZ_URL, 'POST', '/api/propagandas/excluir', { cookie: SESSAO, headers: Object.assign({ 'x-csrf-token': tkTv }, formTv), body: 'id=' + idP });
  const aposDel = (await http(LEGADO, 'GET', '/api.php?action=get_propagandas')).json.data.find(p => p.id === idP);
  ok('TV7 ciclo completo da propaganda pelas rotas novas (cadastrar, ligar/desligar, atualizar, excluir), visível na URL antiga a cada passo', pAdd.json.success === true && idP > 0 && pTog.json.ativo === false && pTog.json.message === 'Desativada' && pUpd.json.message === 'Propaganda atualizada' && listaAntes && listaAntes.titulo === 'Novo' && pDel.json.message === 'Propaganda removida' && !aposDel, [pAdd.texto, pTog.texto, pUpd.texto, pDel.texto]);
  const falha = await http(RAIZ_URL, 'POST', '/api/propagandas', { cookie: SESSAO, headers: Object.assign({ 'x-csrf-token': tkTv }, formTv), body: 'titulo=sem+html' });
  ok('TV8 falha de validação volta com HTTP 200 e {success:false, message} (o admin.php depende desse formato)', falha.status === 200 && falha.json.success === false && falha.json.message === 'Conteúdo HTML obrigatório', [falha.status, falha.texto]);
  const mp3 = new Blob([Buffer.concat([Buffer.from('ID3'), Buffer.alloc(200, 7)])], { type: 'audio/mpeg' });
  const fd = new FormData(); fd.append('music', mp3, 'http teste (1).mp3');
  const up = await http(RAIZ_URL, 'POST', '/api/musica-fundo', { cookie: SESSAO, headers: { 'x-csrf-token': tkTv }, body: fd });
  const nomeMp3 = up.json && up.json.name;
  const lista = await http(LEGADO, 'GET', '/api.php?action=get_bg_music'), achou = lista.json.data.find(m => m.name === nomeMp3);
  const prox = await http(RAIZ_URL, 'POST', '/api/musica-fundo/proxima', { cookie: SESSAO, headers: Object.assign({ 'x-csrf-token': tkTv }, formTv), body: 'name=' + encodeURIComponent(String(nomeMp3)) });
  const dados = (await http(LEGADO, 'GET', '/api.php?action=get_data')).json.bg_next_track;
  const del = await http(RAIZ_URL, 'POST', '/api/musica-fundo/excluir', { cookie: SESSAO, headers: Object.assign({ 'x-csrf-token': tkTv }, formTv), body: 'name=' + encodeURIComponent(String(nomeMp3)) });
  const dadosApos = (await http(LEGADO, 'GET', '/api.php?action=get_data')).json.bg_next_track;
  ok('TV9 envio de MP3 por multipart de verdade (rota nova): nome limpo, aparece na lista da URL antiga, vira "próxima" no get_data, e apagar limpa a "próxima"', up.json.success === true && nomeMp3 === 'http_teste__1_.mp3' && achou && achou.size === 203 && prox.json.success === true && dados === nomeMp3 && del.json.success === true && dadosApos === '', [up.texto, prox.texto, del.texto, dados, dadosApos]);
  const nvRetro = await http(NOVA_URL, 'GET', '/nova/api/retrospectiva');
  ok('TV10 montado em /nova: /nova/api/retrospectiva e /nova/api/rotacao respondem sem login, com o mesmo JSON da URL antiga', nvRetro.status === 200 && nvRetro.texto === (await http(LEGADO, 'GET', '/api.php?action=get_retrospectiva')).texto && (await http(NOVA_URL, 'GET', '/nova/api/rotacao')).texto === (await http(LEGADO, 'GET', '/api.php?action=get_rotation')).texto, [nvRetro.status, nvRetro.texto.slice(0, 80)]);
  const verbo = await http(RAIZ_URL, 'GET', '/api/rotacao/salvar-errado');
  ok('TV11 caminho que não existe = 404 JSON, e GET numa rota só de escrita = 405', verbo.status === 404 && (await http(RAIZ_URL, 'GET', '/api/propagandas/excluir', { cookie: SESSAO })).status === 405, [verbo.status]);

  // ── fase 3B: rankings (TV e Painel) ──
  const semTs = j => { const c = JSON.parse(JSON.stringify(j)); delete c.timestamp; return JSON.stringify(c); };
  const dN = await http(RAIZ_URL, 'GET', '/api/tv/dados'), dL = await http(LEGADO, 'GET', '/api.php?action=get_data');
  ok('TV12 leitura pública do ranking ao vivo: /api/tv/dados e api.php?action=get_data devolvem o MESMO JSON (menos o carimbo de hora); a TV não precisa de login', dN.status === 200 && dN.json.success === true && dN.json.data.length > 0 && semTs(dN.json) === semTs(dL.json), [dN.status, dN.texto.length, dL.texto.length]);
  const tN = await http(RAIZ_URL, 'GET', '/api/tv/contratos-total'), tL = await http(LEGADO, 'GET', '/api.php?action=get_public_contratos_total');
  ok('TV13 total público de contratos do mês: igual nas duas portas e só com o agregado (total, ano, mês)', tN.status === 200 && tN.texto === tL.texto && Object.keys(tN.json).sort().join() === 'month,success,total,year', tN.texto);
  const hoje = new Date(), qAno = hoje.getFullYear(), qMes = hoje.getMonth() + 1, mm2 = String(qMes).padStart(2, '0');
  const painel = [['/api/painel', 'get_painel', ''], ['/api/painel', 'get_painel', `&ano=${qAno}&mes=${qMes}&semana_num=1`], ['/api/painel/rankings', 'get_rankings_filtered', '&type=semana&periodo=1&ref_type=equipe'],
    ['/api/painel/rankings', 'get_rankings_filtered', `&type=mes&periodo=${qAno}-${mm2}&ref_type=closer`], ['/api/painel/graficos', 'get_chart_data', '&period=month'], ['/api/painel/graficos', 'get_chart_data', '&period=year'],
    ['/api/painel/graficos', 'get_chart_data', '&period=week'], ['/api/painel/resumo-semana', 'get_week_summary', `&ano=${qAno}&mes=${qMes}&semana_num=1&include_deleted=1`]];
  for (const [rota, acao, qs] of painel) {
    const a = await http(RAIZ_URL, 'GET', rota + '?' + qs.replace(/^&/, ''), { cookie: SESSAO }), b = await http(LEGADO, 'GET', `/api.php?action=${acao}${qs}`, { cookie: SESSAO });
    ok(`TV14 ${acao}${qs.slice(0, 40)}: a rota nova ${rota} e a URL antiga devolvem o MESMO JSON (${a.texto.length} bytes)`, a.status === 200 && a.json && b.json && a.texto === b.texto, [a.status, a.texto.slice(0, 100), b.texto.slice(0, 100)]);
  }
  const semLoginP = await Promise.all(['/api/painel', '/api/painel/rankings?type=semana&periodo=1', '/api/painel/graficos', '/api/painel/resumo-semana'].map(u => http(RAIZ_URL, 'GET', u)));
  ok('TV15 as leituras do Painel exigem login: sem sessão as 4 rotas respondem 401 (e a URL antiga, "Não autenticado")', semLoginP.every(x => x.status === 401 && x.json.message === 'Não autenticado') && (await http(LEGADO, 'GET', '/api.php?action=get_painel')).json.message === 'Não autenticado', semLoginP.map(x => x.status));
  const vazio = await http(RAIZ_URL, 'GET', '/api/painel/graficos?period=foo', { cookie: SESSAO }), vazioL = await http(LEGADO, 'GET', '/api.php?action=get_chart_data&period=foo', { cookie: SESSAO });
  ok('TV16 gráfico com período desconhecido: resposta vazia com HTTP 200 nas duas portas, como sempre foi', vazio.status === 200 && vazio.texto === '' && vazioL.status === 200 && vazioL.texto === '', [vazio.status, vazio.texto, vazioL.texto]);
  const param = await http(RAIZ_URL, 'GET', '/api/painel/resumo-semana?ano=2026&mes=13&semana_num=1', { cookie: SESSAO });
  ok('TV17 parâmetro inválido: HTTP 200 com {success:false, message} (o admin.php lê esse formato)', param.status === 200 && param.json.success === false && param.json.message === 'Parâmetros inválidos', param.texto);

  // ── fase 3C: a TV (index.php) é uma casca; CSS e JS saem em arquivos próprios ──
  const tv = await http(LEGADO, 'GET', '/index.php');
  const tvCss = /href="([^"]*assets\/css\/tv\.css\?v=\d+)"/.exec(tv.texto), tvJs = /<script src="([^"]*assets\/js\/tv\.js\?v=\d+)"/.exec(tv.texto);
  ok('TV18 a TV (index.php, mesmo endereço de sempre) responde HTML 200 com o CSS e o JS em arquivos próprios (?v=<data do arquivo>) e sem CSS nem JS embutidos', tv.status === 200 && /text\/html/.test(tv.headers.get('content-type')) && tvCss && tvJs && !/<style/.test(tv.texto) && (tv.texto.match(/<script/g) || []).length === 3 && tv.texto.length < 20000 && /id="tvStage"/.test(tv.texto) && /id="hall"/.test(tv.texto), [tv.status, tv.texto.length, !!tvCss, !!tvJs]);
  const cssR = await http(LEGADO, 'GET', '/' + tvCss[1].replace(/^\//, '')), jsR = await http(LEGADO, 'GET', '/' + tvJs[1].replace(/^\//, ''));
  ok('TV19 os dois arquivos são servidos (200) e trazem o conteúdo da TV: o CSS do palco e o JS da rotação e do Hall, sem PHP dentro', cssR.status === 200 && /\.tv-stage\{/.test(cssR.texto) && /@keyframes float/.test(cssR.texto) && jsR.status === 200 && /function esc\(s\)/.test(jsR.texto) && /function showHall\(/.test(jsR.texto) && !/<\?php|<\?=/.test(cssR.texto + jsR.texto), [cssR.status, jsR.status]);
  const cfg = /window\.TV_CFG=(\{.*?\});<\/script>/.exec(tv.texto), trilhas = cfg ? JSON.parse(cfg[1]) : null;
  const noDisco = fs.readdirSync(path.join(RAIZ, 'music')).filter(f => /\.mp3$/.test(f)).map(f => 'music/' + f).concat(fs.readdirSync(RAIZ).filter(f => /\.mp3$/.test(f)));
  ok('TV20 as músicas vêm do servidor: a lista da página bate com os MP3 que existem em disco (comemoração) e em music/bg (fundo), em JSON seguro dentro do HTML', trilhas && JSON.stringify([...trilhas.celebrationTracks].sort()) === JSON.stringify([...new Set(noDisco.length ? noDisco : ['celebration.mp3'])].sort()) && Array.isArray(trilhas.bgTracks), [cfg && cfg[1], noDisco]);
  ok('TV21 o rodapé do site continua na página (escondido pelo estilo da TV) e o crédito do palco também', /<footer class="site-footer">/.test(tv.texto) && /class="tv-credit"/.test(tv.texto) && /body>\.site-footer\{display:none/.test(cssR.texto), [tv.texto.includes('site-footer')]);

  // ── módulo do líder, fase L1: rota de escopo e migração protegidas ──
  const liderSem = await http(RAIZ_URL, 'GET', '/api/lider/escopo');
  ok('LD1 área do líder sem login: 401 JSON "Não autenticado" (o admin logado também não entra: não é líder)', liderSem.status === 401 && liderSem.json && liderSem.json.message === 'Não autenticado' && (await http(RAIZ_URL, 'GET', '/api/lider/escopo', { cookie: SESSAO })).status === 401, [liderSem.status, liderSem.texto]);
  const migSem = await http(RAIZ_URL, 'GET', '/_migracao/lider');
  ok('LD2 rota de migração do líder removida: 404 idêntico ao de rota inexistente (não revela que existe)', migSem.status === 404 && migSem.texto === (await http(RAIZ_URL, 'GET', '/nao-existe-xyz')).texto, [migSem.status]);
  const migCom = await http(RAIZ_URL, 'GET', `/_migracao/lider?${k}`);
  ok('LD3 a rota de migração do líder saiu na fase 8: nem com a chave ela responde (404)', migCom.status === 404, [migCom.status]);

  // ── módulo do líder, fase L2: visão do time, papel e seção Líderes do admin (leitura; sem login nada responde) ──
  const lsVisao = await http(RAIZ_URL, 'GET', '/api/lider/visao?equipe_id=2'), lsPapel = await http(RAIZ_URL, 'GET', '/api/lider/papel');
  ok('LD5 visão e papel do líder sem login: 401 "Não autenticado" (o admin logado também não entra na visão do líder)', lsVisao.status === 401 && lsPapel.status === 401 && lsVisao.json.message === 'Não autenticado' && (await http(RAIZ_URL, 'GET', '/api/lider/visao', { cookie: SESSAO })).status === 401, [lsVisao.status, lsPapel.status]);
  const lsAdmSem = await http(RAIZ_URL, 'GET', '/api/admin/lideres');
  ok('LD6 seção Líderes do admin sem login: 401', lsAdmSem.status === 401 && lsAdmSem.json && lsAdmSem.json.message === 'Não autenticado', [lsAdmSem.status, lsAdmSem.texto.slice(0, 100)]);
  const lsAdm = await http(RAIZ_URL, 'GET', '/api/admin/lideres?periodo=semana', { cookie: SESSAO });
  const eq0 = lsAdm.json && lsAdm.json.equipes && lsAdm.json.equipes[0];
  ok('LD7 seção Líderes do admin logado: 200 e uma linha por equipe com líder, closers, indicadores, funil e painel', lsAdm.status === 200 && lsAdm.json.success === true && Array.isArray(lsAdm.json.equipes) && lsAdm.json.equipes.length > 0 && eq0 && ['equipe_id', 'equipe_nome', 'lider_nome', 'lider_acesso', 'closers', 'kpis', 'prev', 'funil', 'contratos_total', 'melhores', 'painel'].every(c => c in eq0), [lsAdm.status, lsAdm.texto.slice(0, 200)]);
  const lsNova = await http(NOVA_URL, 'GET', '/nova/api/admin/lideres?periodo=semana', { cookie: SESSAO });
  ok('LD8 a mesma resposta pelo núcleo montado em /nova (como na HostGator)', lsNova.status === 200 && JSON.stringify(lsNova.json) === JSON.stringify(lsAdm.json), [lsNova.status]);
  const lsLeg = await http(LEGADO, 'GET', '/api.php?action=get_produtividade_lideres&periodo=semana', { cookie: SESSAO }), lsLegSem = await http(LEGADO, 'GET', '/api.php?action=get_produtividade_lideres&periodo=semana');
  ok('LD9 a URL do sistema atual (api.php?action=get_produtividade_lideres) devolve o mesmo JSON do núcleo e exige o login do admin', lsLeg.json && JSON.stringify(lsLeg.json) === JSON.stringify(lsAdm.json) && lsLegSem.json && lsLegSem.json.success === false && /autenticado/i.test(lsLegSem.json.message), [lsLeg.status, lsLegSem.texto.slice(0, 100)]);

  // ── módulo do líder, fase L3: registros, "Meus feedbacks" e atividades dos líderes ──
  const json = { 'content-type': 'application/json' };
  const lrSem = await http(RAIZ_URL, 'GET', '/api/lider/registros/feedbacks'), lrAdm = await http(RAIZ_URL, 'GET', '/api/lider/registros/feedbacks', { cookie: SESSAO });
  ok('LD10 registros do líder sem login: 401; o admin logado também (não é a área dele)', lrSem.status === 401 && lrAdm.status === 401 && lrSem.json.message === 'Não autenticado', [lrSem.status, lrAdm.status]);
  const lrPost = await http(RAIZ_URL, 'POST', '/api/lider/registros/feedbacks', { headers: json, body: JSON.stringify({ closer_id: 1, assunto: 'x', texto: 'y' }) });
  const lrPostAdm = await http(RAIZ_URL, 'POST', '/api/lider/registros/feedbacks', { cookie: SESSAO, headers: json, body: JSON.stringify({ closer_id: 1, assunto: 'x', texto: 'y' }) });
  ok('LD11 escrever registro sem login ou só com a sessão do admin: 401 e nada é gravado', lrPost.status === 401 && lrPostAdm.status === 401, [lrPost.status, lrPostAdm.status]);
  const lrDel = await http(RAIZ_URL, 'DELETE', '/api/lider/registros/feedbacks/1', { cookie: SESSAO });
  ok('LD12 não existe rota de exclusão de registro (DELETE: 404 ou 405)', lrDel.status === 404 || lrDel.status === 405, lrDel.status);
  const mfSem = await http(RAIZ_URL, 'GET', '/api/closer/feedbacks'), mfPost = await http(RAIZ_URL, 'POST', '/api/closer/feedbacks/vistos', { headers: json, body: '{}' });
  ok('LD13 "Meus feedbacks" sem login de closer: 401 (leitura e "visto")', mfSem.status === 401 && mfPost.status === 401, [mfSem.status, mfPost.status]);
  const atSem = await http(RAIZ_URL, 'GET', '/api/admin/atividades-lideres'), atDetSem = await http(RAIZ_URL, 'GET', '/api/admin/atividades-lideres/1');
  const atAdm = await http(RAIZ_URL, 'GET', '/api/admin/atividades-lideres?periodo=semana', { cookie: SESSAO });
  const at0 = atAdm.json && atAdm.json.equipes && atAdm.json.equipes[0];
  ok('LD14 atividades dos líderes: sem login 401; com o admin, uma linha por equipe com contagens, última atividade e alerta', atSem.status === 401 && atDetSem.status === 401 && atAdm.status === 200 && at0 && ['equipe_id', 'lider_nome', 'lider_acesso', 'reunioes', 'feedbacks', 'cobrancas', 'revisoes', 'treinamentos', 'total', 'proprios', 'ultima_atividade', 'dias_sem_atividade', 'alerta'].every(c => c in at0) && atAdm.json.limite_dias === 3, [atSem.status, atAdm.status, atAdm.texto.slice(0, 200)]);
  const atNova = await http(NOVA_URL, 'GET', '/nova/api/admin/atividades-lideres?periodo=semana', { cookie: SESSAO });
  const atLeg = await http(LEGADO, 'GET', '/api.php?action=get_atividades_lideres&periodo=semana', { cookie: SESSAO }), atLegSem = await http(LEGADO, 'GET', '/api.php?action=get_atividades_lideres&periodo=semana');
  ok('LD15 as atividades pelo /nova e pela URL do sistema atual são o mesmo JSON; a URL antiga exige o login do admin', JSON.stringify(atNova.json) === JSON.stringify(atAdm.json) && JSON.stringify(atLeg.json) === JSON.stringify(atAdm.json) && atLegSem.json.success === false && /autenticado/i.test(atLegSem.json.message), [atNova.status, atLeg.status]);
  const detalhe = await http(LEGADO, 'GET', `/api.php?action=get_atividade_lider_detalhe&equipe_id=${at0.equipe_id}`, { cookie: SESSAO });
  ok('LD16 detalhe do líder pela URL do sistema atual: 200 com a equipe e a lista de registros; equipe inexistente 404', detalhe.json && detalhe.json.success === true && Array.isArray(detalhe.json.registros) && (await http(RAIZ_URL, 'GET', '/api/admin/atividades-lideres/999999', { cookie: SESSAO })).status === 404, [detalhe.status, detalhe.texto.slice(0, 120)]);

  // ── módulo do líder, fase L4: o portal do líder (Dashboard, Equipe, Agenda, busca e preferências) ──
  const rotasL4 = ['/api/lider/dashboard', '/api/lider/equipe', '/api/lider/closer/1', '/api/lider/agenda', '/api/lider/busca?q=teste', '/api/lider/preferencias'];
  const l4Sem = [], l4Adm = [];
  for (const r of rotasL4) { l4Sem.push((await http(RAIZ_URL, 'GET', r)).status); l4Adm.push((await http(RAIZ_URL, 'GET', r, { cookie: SESSAO })).status); }
  ok('LD17 as 6 rotas de leitura do portal do líder sem login: 401; com a sessão do admin também (a área é só do líder)', l4Sem.every(s => s === 401) && l4Adm.every(s => s === 401), [l4Sem, l4Adm]);
  const l4Post = await http(RAIZ_URL, 'POST', '/api/lider/preferencias', { headers: json, body: JSON.stringify({ limite_dias_sem_venda: 5 }) });
  const l4PostAdm = await http(RAIZ_URL, 'POST', '/api/lider/preferencias', { cookie: SESSAO, headers: json, body: JSON.stringify({ limite_dias_sem_venda: 5 }) });
  ok('LD18 salvar preferências sem login ou só com a sessão do admin: 401 e nada é gravado', l4Post.status === 401 && l4PostAdm.status === 401, [l4Post.status, l4PostAdm.status]);
  const l4Del = await http(RAIZ_URL, 'DELETE', '/api/lider/preferencias', { cookie: SESSAO }), l4Ruim = await http(RAIZ_URL, 'GET', '/api/lider/closer/abc', { cookie: SESSAO });
  ok('LD19 não há exclusão de preferências (DELETE 404 ou 405) e o id do closer precisa ser número (404)', (l4Del.status === 404 || l4Del.status === 405) && l4Ruim.status === 404, [l4Del.status, l4Ruim.status]);
  const l4Nova = await http(NOVA_URL, 'GET', '/nova/api/lider/dashboard');
  ok('LD20 o mesmo pelo núcleo montado em /nova (como na HostGator): 401 sem login', l4Nova.status === 401 && l4Nova.json && l4Nova.json.message === 'Não autenticado', [l4Nova.status, l4Nova.texto.slice(0, 80)]);

  // ── o sistema atual segue respondendo igual ──
  const l1 = await http(LEGADO, 'GET', '/index.php'), l2 = await http(LEGADO, 'GET', '/api.php?action=get_data'), l3 = await http(LEGADO, 'GET', '/admin.php'), l4 = await http(LEGADO, 'GET', '/login.php');
  ok('S1 sistema atual intacto: index.php 200, get_data com dados, admin.php 302 para o login, login.php 200', l1.status === 200 && l2.json && l2.json.success === true && l2.json.data.length > 0 && l3.status === 302 && l4.status === 200, [l1.status, l2.status, l3.status, l4.status]);

  console.log(`\n${pass} passaram, ${fail} falharam`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('FALHA', e.stack || e.message); process.exit(1); });
