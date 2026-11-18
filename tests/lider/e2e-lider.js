// e2e-lider.js — a seção "Líderes" da Produtividade do admin (fases L2/L3) no NAVEGADOR (Edge headless). Desde a fase L4 o cartão "Meu time" do
// portal do closer não existe mais: o portal do líder (lider.php) é testado em e2e-portal-lider.js. Só local (127.0.0.1:8787 = sistema atual
// com o núcleo ao lado). Os números esperados vêm do 'semear' do preparar-local.php (totais conhecidos) e das rotas ANTIGAS do admin
// (get_produtividade, get_painel), que não passam pelo código novo.
//   cd %LOCALAPPDATA%\dash-vendas-testdb\e2e ; node -r .\patch-launch.js C:\...\tests\lider\e2e-lider.js <pasta-de-capturas>
const path = require('path'), cp = require('child_process'), fs = require('fs');
const E2E = path.join(process.env.LOCALAPPDATA, 'dash-vendas-testdb', 'e2e');
module.paths.push(path.join(E2E, 'node_modules'));
const puppeteer = require('puppeteer-core');
const { B, EDGE, admin, resetRate, Client } = require(path.join(E2E, 'portal-helpers'));
const OUT = process.argv[2] || '.';
const PREP = path.join(__dirname, 'preparar-local.php');
const SENHA = 'senha1234';
const sleep = ms => new Promise(r => setTimeout(r, ms));
let pass = 0, fail = 0;
const ok = (nome, cond, extra) => { cond ? pass++ : fail++; console.log((cond ? 'PASS ' : 'FAIL ') + nome + (!cond && extra !== undefined ? '  → ' + JSON.stringify(extra).slice(0, 400) : '')); };
const prep = modo => JSON.parse(cp.execFileSync('C:\\php\\php.exe', ['-d', 'extension_dir=C:\\php\\ext', '-d', 'extension=pdo_mysql', PREP, modo], { encoding: 'utf8' }));
const nf = n => String(n).replace('.', ',');

(async () => {
  resetRate();
  const dados = prep('preparar'); prep('semear');
  const EQ = dados.equipe_id;
  const lider = Client(); const l = await lider.login('lider.teste@teste.com', SENHA);
  const closer = Client(); const c = await closer.login('closer.teste@teste.com', SENHA);
  if (!l.success || !c.success) throw new Error('login de teste falhou: ' + JSON.stringify([l, c]));
  const cookiesDe = cli => Object.entries(cli.jar).map(([name, value]) => ({ name, value, domain: '127.0.0.1', path: '/' }));

  const browser = (global.__browser = await puppeteer.launch({ executablePath: EDGE, headless: true }));
  const erros = [];
  const nova = async (cli, largura) => {
    const pg = await browser.newPage();
    await pg.setViewport({ width: largura || 1400, height: 1000 });
    pg.on('pageerror', e => erros.push('PAGEERROR: ' + e.message));
    pg.on('console', m => { if (m.type() === 'error' && !/pravatar|fonts\.g|net::ERR|Failed to load resource|404/.test(m.text())) erros.push('CONSOLE: ' + m.text()); });
    if (cli) await pg.setCookie(...cookiesDe(cli));
    return pg;
  };
  const linhas = (pg, sel) => pg.$$eval(sel + ' tbody tr', r => r.map(tr => [...tr.children].map(td => td.textContent.replace(/\s+/g, ' ').trim())));

  // (as telas do líder no portal — antes o cartão "Meu time" — são testadas em e2e-portal-lider.js e e2e-registros.js desde a fase L4)
  let pg;

  // ── ADMIN: Produtividade → Líderes ──────────────────────────────────────────
  pg = await nova(null);
  await pg.setCookie({ name: 'PHPSESSID', value: 'testadmin0001', domain: '127.0.0.1', path: '/' });
  await pg.evaluateOnNewDocument(() => { try { localStorage.removeItem('prPer'); localStorage.removeItem('sbCollapsed'); } catch (e) {} });
  await pg.goto(B + 'admin.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await pg.waitForSelector('#pnKpis .pn-kpi-val:not(.pn-skel)', { timeout: 30000 }); await sleep(800);
  await pg.evaluate(() => showTab('produtividade'));
  await pg.click('#prSubTabs [data-sub="lideres"]');      // a seção Líderes agora é a sub-aba "Líderes" da Produtividade (fase L3)
  await pg.waitForSelector('#prLidList .ld-team', { timeout: 20000 });
  const lid = await admin('action=get_produtividade_lideres&periodo=hoje');
  const cartoes = await pg.$$eval('#prLidList .ld-team', c => c.map(x => ({ id: x.dataset.equipe, nome: x.querySelector('h3').textContent.trim(), txt: x.textContent.replace(/\s+/g, ' ').trim() })));
  ok(`A1 seção "Líderes" dentro da Produtividade, com um cartão por equipe (${lid.equipes.length})`, (await pg.$eval('#tabProdutividade #prLid h2', e => e.textContent.trim())) === 'Líderes' && cartoes.length === lid.equipes.length, [cartoes.length, lid.equipes.length]);
  const meu = cartoes.find(x => x.id === String(EQ));
  ok('A1 o cartão da equipe de teste mostra o líder e os números de hoje (8 ligações, 4 follow-ups, 2 leads quentes, 3 contratos)', meu && /Líder: LÍDER TESTE/.test(meu.txt) && /Ligações\s*8\s*Follow-ups\s*4\s*Leads q\.\s*2\s*Contratos\s*3/.test(meu.txt), meu);
  const semLider = lid.equipes.filter(t => t.lider_nome === null);
  ok(`A2 equipe sem líder aparece como "Sem líder definido" (${semLider.length})`, semLider.every(t => /Sem líder definido/.test(cartoes.find(x => x.id === String(t.equipe_id)).txt)), semLider.map(t => t.equipe_nome));
  const semAcesso = lid.equipes.filter(t => t.lider_acesso === false);
  ok(`A2 líder sem conta ativa no portal vem sinalizado (${semAcesso.length} equipe(s)); com conta, não`, lid.equipes.every(t => /sem acesso ao portal/.test(cartoes.find(x => x.id === String(t.equipe_id)).txt) === (t.lider_acesso === false)), lid.equipes.map(t => [t.equipe_nome, t.lider_acesso]));
  // o líder que "tem acesso" é exatamente quem passa na regra do núcleo
  const t7 = lid.equipes.find(t => t.equipe_id === EQ);
  ok('A2 a equipe de teste: líder com acesso ao portal', t7.lider_acesso === true && t7.lider_nome === 'LÍDER TESTE', t7);
  await pg.screenshot({ path: path.join(OUT, 'admin-lideres.png'), fullPage: true });

  // período e equipe seguem o filtro da aba
  await pg.evaluate(() => prSetPer('semana')); await sleep(1200);
  await pg.waitForFunction(() => !document.getElementById('prLidList').hasAttribute('aria-busy'), { timeout: 15000 });
  const sem = await admin('action=get_produtividade_lideres&periodo=semana');
  const t7s = sem.equipes.find(t => t.equipe_id === EQ);
  const txt7 = await pg.$eval(`#prLidList .ld-team[data-equipe="${EQ}"]`, e => e.textContent.replace(/\s+/g, ' ').trim());
  ok(`A3 filtro "Semana": a seção recarrega (contratos da equipe de teste = ${t7s.kpis.contratos})`, new RegExp(`Ligações\\s*${t7s.kpis.ligacoes}\\s*Follow-ups\\s*${t7s.kpis.followups}\\s*Leads q\\.\\s*${t7s.kpis.leads_quentes}\\s*Contratos\\s*${t7s.kpis.contratos}`).test(txt7), txt7);
  const eqOutra = lid.equipes.find(t => t.equipe_id !== EQ).equipe_id;
  const legSem = await admin('action=get_produtividade&periodo=semana&equipe_id=' + eqOutra);
  const tOutra = sem.equipes.find(t => t.equipe_id === eqOutra);
  ok(`A3 os números de cada equipe batem com a Produtividade antiga filtrada pela equipe (equipe ${eqOutra})`, legSem.kpis.contratos === tOutra.kpis.contratos && legSem.kpis.ligacoes === tOutra.kpis.ligacoes && legSem.kpis.leads_unicos === tOutra.kpis.leads_unicos, [legSem.kpis, tOutra.kpis]);
  await pg.select('#prEq', String(EQ)); await sleep(1300);
  await pg.waitForFunction(() => !document.getElementById('prLidList').hasAttribute('aria-busy'), { timeout: 15000 });
  const so1 = await pg.$$eval('#prLidList .ld-team', c => c.map(x => x.dataset.equipe));
  ok('A4 filtro de equipe: só o cartão da equipe escolhida', so1.length === 1 && so1[0] === String(EQ), so1);
  await pg.select('#prEq', ''); await sleep(1300);
  await pg.waitForFunction(() => !document.getElementById('prLidList').hasAttribute('aria-busy'), { timeout: 15000 });
  ok('A4 sem filtro de equipe: todos os cartões de volta', (await pg.$$eval('#prLidList .ld-team', c => c.length)) === lid.equipes.length);

  // celular: o admin também não rola para o lado
  await pg.setViewport({ width: 390, height: 900 }); await sleep(500);
  const la = await pg.evaluate(() => ({ s: document.documentElement.scrollWidth, w: window.innerWidth }));
  ok(`A5 admin no celular: a página não rola para o lado (${la.s} ≤ ${la.w})`, la.s <= la.w + 1, la);
  await pg.close();

  ok('Z1 nenhum erro de JavaScript nem de console durante todo o teste', erros.length === 0, erros.slice(0, 5));
  await browser.close();
  prep('limpar');
  console.log(`\n${pass} passaram, ${fail} falharam`);
  process.exitCode = fail ? 1 : 0;
})().catch(async e => { console.error('FALHA', e.stack || e.message); try { if (global.__browser) await global.__browser.close(); } catch (x) { /* nada */ } try { prep('limpar'); prep('devolver'); } catch (x) { /* nada */ } process.exitCode = 1; });
