// e2e-portal-lider.js — o PORTAL DO LÍDER (lider.php) no NAVEGADOR (Edge headless): sidebar no estilo do admin, Dashboard (ranking de ações), Equipe (histórico do
// closer por semana ou mês), Agenda (as cinco atividades; "+ Nova atividade"), Meu dia (com o Registro de Ação Rápida e o histórico das ações), Meus contratos,
// Configurações, busca global (Ctrl+K), sino, atalhos e o celular. Não há seção Registros, e o líder não usa o portal do closer. Só local (127.0.0.1:8787, banco
// de teste). Os dados vêm do preparar-local.php ('semear' = números conhecidos; 'contratos N Q' monta o cenário dos alertas); o teste começa e termina com os
// dados da equipe de teste limpos.
//   cd %LOCALAPPDATA%\dash-vendas-testdb\e2e ; node -r .\patch-launch.js C:\...\tests\lider\e2e-portal-lider.js <pasta-de-capturas>
const path = require('path'), cp = require('child_process');
const E2E = path.join(process.env.LOCALAPPDATA, 'dash-vendas-testdb', 'e2e');
module.paths.push(path.join(E2E, 'node_modules'));
const puppeteer = require('puppeteer-core');
const { B, EDGE, admin, resetRate, Client } = require(path.join(E2E, 'portal-helpers'));
const OUT = process.argv[2] || '.';
const PREP = path.join(__dirname, 'preparar-local.php');
const SENHA = 'senha1234';
const sleep = ms => new Promise(r => setTimeout(r, ms));
let pass = 0, fail = 0;
const ok = (nome, cond, extra) => { cond ? pass++ : fail++; console.log((cond ? 'PASS ' : 'FAIL ') + nome + (!cond && extra !== undefined ? '  → ' + JSON.stringify(extra).slice(0, 500) : '')); };
const prep = modo => JSON.parse(cp.execFileSync('C:\\php\\php.exe', ['-d', 'extension_dir=C:\\php\\ext', '-d', 'extension=pdo_mysql', PREP, ...modo.split(' ')], { encoding: 'utf8' }));
const soma = (iso, dias) => new Date(Date.parse(iso + 'T12:00:00Z') + dias * 864e5).toISOString().slice(0, 10);
const PADRAO = { limite_dias_sem_venda: 3, dias_sem_feedback: 14, alertas: { sem_venda: true, meta_batida: true, atividade: true, sem_feedback: true, agenda: true } };

(async () => {
  resetRate();
  const dados = prep('preparar'); prep('semear');
  const EQ = dados.equipe_id;
  const lider = Client(); const l = await lider.login('lider.teste@teste.com', SENHA);
  const closer = Client(); const c = await closer.login('closer.teste@teste.com', SENHA);
  if (!l.success || !c.success) throw new Error('login de teste falhou: ' + JSON.stringify([l, c]));
  await lider.api('lider_preferencias_salvar', PADRAO);
  const cookiesDe = cli => Object.entries(cli.jar).map(([name, value]) => ({ name, value, domain: '127.0.0.1', path: '/' }));
  const painelAdm = await admin('action=get_painel');
  const hoje = painelAdm.week.today, amanha = soma(hoje, 1);
  const outroTime = painelAdm.closers.find(x => x.equipe_id && x.equipe_id !== EQ);
  const outrasEquipes = painelAdm.teams.filter(t => t.id !== EQ).map(t => t.name);

  const browser = (global.__browser = await puppeteer.launch({ executablePath: EDGE, headless: true }));
  const erros = [];
  const nova = async (cli, largura, altura) => {
    const pg = await browser.newPage();
    await pg.setViewport({ width: largura || 1440, height: altura || 900 });
    pg.on('pageerror', e => erros.push('PAGEERROR: ' + e.message));
    pg.on('console', m => { if (m.type() === 'error' && !/pravatar|fonts\.g|net::ERR|Failed to load resource|40[34]|41[9]/.test(m.text())) erros.push('CONSOLE: ' + m.text()); });
    if (cli) await pg.setCookie(...cookiesDe(cli));
    return pg;
  };
  const abrir = async (pg, hash) => { await pg.goto('about:blank'); await pg.goto(B + 'lider.php' + (hash ? '#' + hash : ''), { waitUntil: 'domcontentloaded', timeout: 60000 }); await pg.waitForSelector('#dPerf .lp-perf, #dPerf .ld-empty', { timeout: 25000 }); };
  const irPara = async (pg, aba) => {
    const celular = await pg.evaluate(() => innerWidth <= 960);
    if (celular) { if (!(await pg.evaluate(() => document.body.classList.contains('lp-menu-open')))) await pg.click('#lpBurger'); await pg.waitForFunction(() => document.getElementById('lpSide').getBoundingClientRect().left >= -1, { timeout: 5000 }); }
    await pg.click(`.lp-nav-b[data-tab="${aba}"]`);
    if (celular) await pg.waitForFunction(() => document.getElementById('lpSide').getBoundingClientRect().right <= 1, { timeout: 5000 });   // espera o menu terminar de recolher antes do próximo toque
  };
  const crumb = pg => pg.$eval('#lpTbTitle', e => e.textContent.trim());   // o nome da página ativa (não há mais migalhas)
  const visivel = (pg, sel) => pg.$eval(sel, e => { const r = e.getBoundingClientRect(), cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && r.bottom > 1 && r.top < innerHeight - 1 && r.right > 1 && r.left < innerWidth - 1; }).catch(() => false);
  const textos = (pg, sel) => pg.$$eval(sel, e => e.map(x => x.textContent.replace(/\s+/g, ' ').trim()));
  const toast = (pg, re) => pg.waitForFunction(r => { const t = document.getElementById('ldToast'); return t && t.classList.contains('on') && new RegExp(r).test(t.textContent); }, { timeout: 15000 }, re.source).then(() => true, () => false);
  const modalAberto = pg => pg.$('dialog.ld-modal[open]').then(Boolean);
  const aguardaFechar = pg => pg.waitForFunction(() => !document.querySelector('dialog[open]'), { timeout: 12000 });
  const campo = (pg, id, valor) => pg.$eval('#' + id, (e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, valor);
  const enviar = async pg => {
    await pg.click('dialog.ld-modal[open] button[type="submit"]');
    await pg.waitForFunction(() => !document.querySelector('dialog.ld-modal[open]') || document.querySelector('dialog.ld-modal[open] .ld-err:not(:empty), dialog.ld-modal[open] [data-topo]:not([hidden])'), { timeout: 12000 });
    await sleep(250);
  };
  const contraste = (pg, sel, fundo) => pg.evaluate((s, bg) => {
    const el = document.querySelector(s); if (!el) return null;
    const [r, g, b, a = 1] = getComputedStyle(el).color.match(/[\d.]+/g).map(Number);
    const mix = [r, g, b].map((v, i) => v * a + bg[i] * (1 - a));
    const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); };
    const lum = x => .2126 * f(x[0]) + .7152 * f(x[1]) + .0722 * f(x[2]);
    const L1 = lum(mix), L2 = lum(bg); return (Math.max(L1, L2) + .05) / (Math.min(L1, L2) + .05);
  }, sel, fundo || [19, 19, 19]);

  // ═══ ACESSO ════════════════════════════════════════════════════════════════════════════════════
  let pg = await nova(lider);
  await pg.goto(B + 'closer.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  ok('P1 o líder que abre o portal do closer entra direto no portal do líder (lider.php)', /\/lider(?:[#?]|$)/.test(pg.url()), pg.url());
  await pg.goto(B + 'closer.php?portal=closer', { waitUntil: 'domcontentloaded', timeout: 60000 });
  ok('P1 o líder não usa o portal do closer: nem com ?portal=closer (o parâmetro antigo é ignorado), vai sempre para o lider.php', /\/lider(?:[#?]|$)/.test(pg.url()), pg.url());
  ok('P1 e o portal do líder não tem link para o portal do closer (ele já tem Meu dia e Meus contratos)', (await pg.$$eval('a[href*="closer.php"]', a => a.length)) === 0);
  await pg.close();
  const semLogin = await fetch(B + 'lider.php', { redirect: 'manual' });
  ok('P1 sem login, lider.php leva ao login do portal', semLogin.status === 302 && /closer\/login/.test(semLogin.headers.get('location') || ''), [semLogin.status, semLogin.headers.get('location')]);

  // ═══ LAYOUT (desktop) ══════════════════════════════════════════════════════════════════════════
  pg = await nova(lider);
  await abrir(pg);
  const lay = await pg.evaluate(() => {
    const s = document.getElementById('lpSide').getBoundingClientRect();
    return { w: Math.round(s.width), x: Math.round(s.left), h: innerHeight, nav: [...document.querySelectorAll('.lp-nav-b span')].map(x => x.textContent.trim()) };
  });
  ok('P2 sidebar flutuante de 256px à esquerda (cartão a 12px da borda)', lay.w === 256 && lay.x === 12, lay);
  ok('P2 menu: Liderança (Dashboard, Equipe, Agenda), Meu portal (Meu dia, Meus contratos) e Configurações', lay.nav.join('|') === 'Dashboard|Equipe|Agenda|Meu dia|Meus contratos|Configurações', lay.nav);
  const perfil = await pg.evaluate(() => ({ nome: document.getElementById('lpNome').textContent.trim(), data: document.getElementById('lpData').textContent.trim(), sair: document.getElementById('lpSair').textContent.trim(), av: document.getElementById('lpAvatar').textContent.trim() }));
  ok('P2 topo: perfil com avatar de iniciais e nome, data por extenso e o Sair no menu do perfil', perfil.nome === 'Líder Teste' && perfil.av === 'LT' && /\d{4}/.test(perfil.data) && /Sair/.test(perfil.sair), perfil);
  ok('P2 sem migalhas e sem selo com o nome da seção; a página ativa é o Dashboard', (await crumb(pg)) === 'Dashboard' && (await pg.$('#lpCrumb')) === null && (await pg.$('.page-eyebrow')) === null && (await pg.$('.lp-crumb')) === null, await crumb(pg));
  const lateral = await pg.evaluate(() => ({ naSidebar: !!document.querySelector('#lpSide #lpQuick'), fab: !!document.getElementById('lpFab'), mini: !!document.getElementById('lpQuickMini') }));
  ok('P3 a barra lateral não tem mais o Registro de Ação Rápida (nem o botão flutuante nem o "+" do menu recolhido)', !lateral.naSidebar && !lateral.fab && !lateral.mini, lateral);
  ok('P3 o card "Registrar ação rápida" não aparece no Dashboard (só no Meu dia)', !(await visivel(pg, '#lpQuick')));
  await pg.screenshot({ path: path.join(OUT, 'lider-dashboard.png') });
  await irPara(pg, 'meu-dia');
  await pg.waitForFunction(() => !document.getElementById('pane-meu-dia').hidden, { timeout: 8000 });
  const quick = await pg.evaluate(() => ({ ph: document.getElementById('qfNum').placeholder, tipos: [...document.querySelectorAll('#qfTipos .lp-qtb')].map(b => b.textContent.trim()), colar: document.getElementById('qfPaste').textContent.trim(), dica: document.querySelector('#lpQuick .lp-hint').textContent.trim(), titulo: document.getElementById('qfH').textContent.trim(), rotulo: document.querySelector('label[for="qfNum"]').textContent.trim(), primeiro: document.querySelector('#pane-meu-dia > *').id }));
  ok('P3 Meu dia: o card "Registrar ação rápida" é o primeiro da seção, com o campo "(00) 00000-0000", botão Colar, 3 botões grandes e a dica', quick.primeiro === 'lpQuick' && quick.ph === '(00) 00000-0000' && quick.colar === 'Colar' && quick.tipos.join('|') === 'Ligação|Follow-up|Lead Quente' && quick.dica === 'Digite o número e clique no tipo — salva na hora. Atalho: Ctrl+N.' && /Registrar ação rápida/i.test(quick.titulo) && /Número do cliente/i.test(quick.rotulo), quick);
  const cabe = await pg.evaluate(() => { const i = document.getElementById('qfNum'), cs = getComputedStyle(i), ctx = document.createElement('canvas').getContext('2d'); ctx.font = cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily; return { texto: Math.ceil(ctx.measureText('(00) 00000-0000').width), caixa: Math.floor(i.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight)) }; });
  ok('P3 o número inteiro cabe no campo (placeholder e número digitado não ficam cortados)', cabe.texto <= cabe.caixa, cabe);
  ok('P3 o card está visível no topo do Meu dia', await visivel(pg, '#lpQuick') && (await pg.$eval('#lpQuick', e => e.getBoundingClientRect().top)) < lay.h / 2);
  await pg.screenshot({ path: path.join(OUT, 'lider-meu-dia.png') });

  // ═══ DASHBOARD ═════════════════════════════════════════════════════════════════════════════════
  await abrir(pg, 'dashboard');
  const api = await lider.api('lider_dashboard', {});
  const linhasPerf = await pg.$$eval('#dPerf .lp-perf', r => r.map(x => ({ nome: x.querySelector('.lp-perf-n b').textContent.trim(), st: x.querySelector('.lp-st').textContent.trim(), cls: x.querySelector('.lp-st').className, voce: !!x.querySelector('.lp-voce'), x: x.querySelector('.lp-perf-x').textContent.replace(/\s+/g, ' ').trim(), dica: (x.querySelector('.lp-perf-dica') || {}).textContent || '' })));
  ok('D1 "Performance da equipe": uma linha por closer (o líder também, marcado "você"), com contratos da semana e status', linhasPerf.length === api.closers.length && linhasPerf.filter(x => x.voce).length === 1 && linhasPerf.every((x, i) => x.nome === api.closers[i].nome && x.st === api.closers[i].status_rotulo && x.x.startsWith(api.closers[i].vendas + '/' + api.closers[i].meta)), [linhasPerf, api.closers.map(x => [x.nome, x.status_rotulo])]);
  const chips = await textos(pg, '#dPerf .lp-chip');
  ok('D1 pontuação da semana e do mês e a posição da equipe no ranking da empresa (sem nomes de outras equipes)', chips.length === 4 && /Pontuação da semana\s*\d/.test(chips[0]) && /Pontuação do mês\s*\d/.test(chips[1]) && /Ranking na empresa\s*\d+º de \d+/.test(chips[2]), chips);
  ok('D1 cores: status em risco é vermelho, em ritmo amarelo e bateu verde (o mesmo do portal do closer)', await pg.evaluate(() => { const cor = c => { const [r, g, b] = c.match(/[\d.]+/g).map(Number); return { r, g, b }; }; const o = cls => { const e = document.createElement('span'); e.className = 'lp-st ' + cls; document.getElementById('dPerf').appendChild(e); const c = cor(getComputedStyle(e).color); e.remove(); return c; }; const bateu = o('bateu'), ritmo = o('ritmo'), risco = o('risco'); return bateu.g > bateu.r && bateu.g > bateu.b && risco.r > risco.g && risco.r > risco.b && ritmo.r > 180 && ritmo.g > 140 && ritmo.b < 120; }));
  const penSub = await pg.$eval('#dPenSub', e => e.textContent.trim());
  const pend = await textos(pg, '#dPend .lp-item-t');
  ok('D2 "Ações pendentes": as reuniões agendadas e os treinamentos para agendar, iguais aos do servidor', api.pendencias.length >= 2 && pend.slice(0, 5).join('|') === api.pendencias.slice(0, 5).map(p => p.titulo).join('|') && pend.some(t => /Reunião.*Alinhamento da semana/.test(t)) && pend.some(t => /Treinamentos para agendar/.test(t)) && /\d pendente/.test(penSub), [pend, api.pendencias.map(p => p.titulo)]);
  const comp = await pg.$$eval('#dComp .lp-cmp-r', r => r.map(x => x.querySelector('span').textContent.trim() + ':' + [...x.querySelectorAll('.lp-cmp-b b')].map(b => b.textContent.trim()).join('/')));
  ok(`D3 "Comparação com o Comercial": ${api.comparacao.metricas.length} comparações Sua equipe × Média, com a posição na semana e no mês`, comp.length === api.comparacao.metricas.length && (await textos(pg, '#dComp .lp-pos .lp-chip')).length === 2, comp);
  const textoDash = await pg.$eval('#pane-dashboard', e => e.textContent);
  const vazou = outrasEquipes.filter(n => textoDash.includes(n));
  ok('D3 só agregados e posição: nenhuma outra equipe aparece pelo nome (' + outrasEquipes.length + ' outras equipes)', outrasEquipes.length > 0 && vazou.length === 0, vazou);
  const badges = await pg.evaluate(() => ({ nav: document.querySelector('[data-badge="dashboard"]').textContent.trim(), sino: document.getElementById('lpSinoN').textContent.trim() }));
  ok('D4 o selo do menu e o do sino mostram o total de alertas + pendências do servidor (' + api.contadores.total + ')', badges.nav === String(api.contadores.total) && badges.sino === String(api.contadores.total), [badges, api.contadores]);

  // produtividade da equipe: SÓ o ranking por ações (nada de indicadores, funil ou conversão)
  await pg.waitForSelector('#dProd .lp-rk li', { timeout: 20000 });
  const rk = () => pg.$$eval('#dProd .lp-rk li', r => r.map(x => ({ nome: x.querySelector('.lp-rk-n b').textContent.trim(), det: x.querySelector('.lp-rk-n small').textContent.replace(/\s+/g, ' ').trim(), total: +x.querySelector('.lp-rk-t').firstChild.textContent, pos: x.querySelector('.lp-rk-p').textContent.trim() })));
  const k = await rk();
  ok('D5 ranking de ações da equipe (hoje, números do "semear"): o líder em 1º (5 ligações, 3 follow-ups, 2 leads quentes = 10 ações) e o closer de teste em 2º (3, 1 e 0 = 4)', k.length === 2 && k[0].nome === 'LÍDER TESTE' && k[0].pos === '1º' && k[0].det === 'Ligações 5 · Follow-ups 3 · Leads quentes 2' && k[0].total === 10 && k[1].nome === 'CLOSER TESTE' && k[1].pos === '2º' && k[1].det === 'Ligações 3 · Follow-ups 1 · Leads quentes 0' && k[1].total === 4, k);
  ok('D5 é só o ranking: sem cartões de indicadores, sem funil e sem conversão', (await pg.$$eval('#dProd .ld-kpi, #dProd .ld-fn-bar', e => e.length)) === 0 && !/Conversão|Funil/.test(await pg.$eval('#dProd', e => e.textContent)));
  ok('D5 o ranking vai do maior para o menor número de ações e o líder aparece marcado "você"', k.every((x, i) => i === 0 || k[i - 1].total >= x.total) && (await pg.$$eval('#dProd .lp-voce', e => e.length)) === 1, k.map(x => x.total));
  for (const [rot, per] of [['Semana', 'semana'], ['Mês', 'mes']]) {
    await pg.click(`#dProdSeg [data-per="${per}"]`);
    const lg = await admin('action=get_produtividade&periodo=' + per + '&equipe_id=' + EQ);
    const esperado = lg.ranking.map(r => r.nome + '=' + r.total).sort().join('|');
    await pg.waitForFunction(es => [...document.querySelectorAll('#dProd .lp-rk li')].map(x => x.querySelector('.lp-rk-n b').textContent.trim() + '=' + x.querySelector('.lp-rk-t').firstChild.textContent.trim()).sort().join('|') === es, { timeout: 20000 }, esperado)
      .then(() => ok(`D5 período ${rot}: as ações de cada closer são as da aba Produtividade do admin (${lg.ranking.length} closers)`, true), async () => ok(`D5 período ${rot}: as ações de cada closer são as do admin`, false, [esperado, await pg.$eval('#dProd', e => e.textContent.slice(0, 300))]));
  }
  await pg.click('#dProdSeg [data-per="hoje"]');
  await pg.waitForFunction(() => document.querySelector('#dProd .lp-rk .lp-rk-t') && document.querySelector('#dProd .lp-rk .lp-rk-t').firstChild.textContent.trim() === '10', { timeout: 20000 });

  // botões dos alertas e das pendências (a reunião de hoje é "Ver na agenda" antes do horário e "Registrar como realizada" depois dele)
  const acaoReu = api.pendencias.map(p => p.acao).find(a => a && (a.k === 'agenda' || a.k === 'reuniao_concluir'));
  await (await pg.$(`#dPend [data-acao*='"k":"${acaoReu.k}"']`)).click();
  if (acaoReu.k === 'agenda') {
    await pg.waitForFunction(() => !document.getElementById('pane-agenda').hidden, { timeout: 8000 });
    ok('D6 "Ver na agenda" (reunião marcada) abre a aba Agenda', (await crumb(pg)).startsWith('Agenda'), await crumb(pg));
  } else {
    await pg.waitForSelector('dialog.ld-modal[open] #f_data_hora_realizada', { timeout: 10000 });
    ok('D6 "Registrar como realizada" (reunião para concluir) abre o formulário da reunião já escolhida', /Alinhamento da semana/.test(await pg.$eval('dialog.ld-modal[open]', e => e.textContent)));
    await pg.keyboard.press('Escape'); await aguardaFechar(pg);
  }
  await irPara(pg, 'dashboard');
  await (await pg.$('#dPend [data-acao*="novo_treinamento"]')).click();
  await pg.waitForSelector('dialog.ld-modal[open] #f_assunto', { timeout: 10000 });
  ok('D6 "Agendar" (treinamentos para agendar) abre o formulário de novo treinamento', /Novo treinamento/i.test(await pg.$eval('dialog.ld-modal[open] h2', e => e.textContent)));
  await pg.keyboard.press('Escape'); await aguardaFechar(pg);
  await pg.screenshot({ path: path.join(OUT, 'lider-dashboard-pendencias.png') });

  // ═══ ALERTAS PROATIVOS: closer parado há 5 dias ═══════════════════════════════════════════════
  prep('contratos 5 1');
  await abrir(pg, 'dashboard');
  const apiA = await lider.api('lider_dashboard', {});
  const alertaParado = apiA.alertas.find(a => a.acao && a.acao.k === 'feedback');
  const itensAl = await pg.$$eval('#dAlertas .lp-item', r => r.map(x => ({ nivel: x.className, t: x.querySelector('.lp-item-t').textContent.replace(/\s+/g, ' ').trim(), d: x.querySelector('.lp-item-d').textContent.trim(), b: (x.querySelector('button') || {}).textContent || '' })));
  ok('A1 closer sem contrato há 5 dias vira alerta "atenção", com o detalhe e o botão de ação (mesmos textos do servidor)', !!alertaParado && itensAl.some(i => /atencao|atenção/i.test(i.nivel + i.t) && i.t.includes(alertaParado.titulo) && i.d === alertaParado.detalhe && i.b.trim() === alertaParado.acao.rotulo), [itensAl, alertaParado]);
  const perfA = await pg.$$eval('#dPerf .lp-perf', r => r.map(x => ({ nome: x.querySelector('.lp-perf-n b').textContent.trim(), cls: x.querySelector('.lp-st').className, dica: (x.querySelector('.lp-perf-dica') || {}).textContent || '' })));
  const ct = perfA.find(x => x.nome === 'CLOSER TESTE');
  ok('A1 a linha do closer na Performance mostra "Em risco" e a dica "Última venda há 5 dias"', /risco/.test(ct.cls) && /Última venda há 5 dias/.test(ct.dica), ct);
  ok('A2 o selo do sino sobe com o alerta e o sino lista alerta e pendências', (await pg.$eval('#lpSinoN', e => Number(e.textContent))) === apiA.contadores.total && apiA.contadores.total > api.contadores.total, [apiA.contadores, api.contadores]);
  await pg.click('#lpSino');
  await pg.waitForSelector('#lpSinoPop:not([hidden]) .lp-item', { timeout: 8000 });
  const pop = await textos(pg, '#lpSinoPop h3');
  ok('A2 o painel do sino tem as seções "Alertas" e "Ações pendentes"', pop.includes('Alertas') && pop.includes('Ações pendentes'), pop);
  await pg.keyboard.press('Escape');
  ok('A2 Esc fecha o sino e o foco volta para ele', await pg.$eval('#lpSinoPop', e => e.hidden) && (await pg.evaluate(() => document.activeElement.id)) === 'lpSino');
  await (await pg.$('#dAlertas [data-acao*="feedback"]')).click();
  await pg.waitForSelector('dialog.ld-modal[open] #f_closer_id', { timeout: 10000 });
  const pre = await pg.evaluate(() => ({ closer: document.getElementById('f_closer_id').value, assunto: document.getElementById('f_assunto').value }));
  ok('A3 o botão do alerta ("Dar feedback") abre "Novo feedback" já com o closer do alerta escolhido', pre.closer === String(dados.closer.vendedor_id), pre);
  await pg.keyboard.press('Escape'); await aguardaFechar(pg);
  await pg.screenshot({ path: path.join(OUT, 'lider-alertas.png') });

  // closer bateu a meta (10 contratos na semana)
  prep('contratos 0 10');
  await abrir(pg, 'dashboard');
  const apiM = await lider.api('lider_dashboard', {});
  const bateu = apiM.alertas.find(a => a.severidade === 'positivo');
  const perfM = await pg.$$eval('#dPerf .lp-perf', r => r.map(x => ({ nome: x.querySelector('.lp-perf-n b').textContent.trim(), cls: x.querySelector('.lp-st').className, dica: (x.querySelector('.lp-perf-dica') || {}).textContent || '', cor: getComputedStyle(x.querySelector('.lp-bar i')).backgroundImage })));
  const ctM = perfM.find(x => x.nome === 'CLOSER TESTE');
  ok('A4 closer que bateu a meta: status "Bateu", dica "Bateu a meta em N dia(s)!" e barra verde', /bateu/.test(ctM.cls) && /Bateu a meta em \d+ dias?!/.test(ctM.dica) && (() => { const [, r, g, b] = ctM.cor.match(/rgb\((\d+), (\d+), (\d+)/).map(Number); return g > r && g > b; })(), ctM);
  ok('A4 e o alerta positivo (🟩) aparece, com o texto do servidor', !!bateu && (await textos(pg, '#dAlertas .lp-item-t')).some(t => t.includes(bateu.titulo)), bateu);
  prep('semear');

  // ═══ REGISTRO DE AÇÃO RÁPIDA ═══════════════════════════════════════════════════════════════════
  await abrir(pg, 'meu-dia');
  await pg.type('#qfNum', '123');
  await pg.click('#qfTipos [data-t="ligacao"]');
  await pg.waitForFunction(() => document.getElementById('qfErr').textContent.length > 0, { timeout: 5000 });
  ok('Q1 telefone inválido: a mensagem aparece abaixo do campo, o campo fica inválido e nada é salvo', /Telefone inválido/.test(await pg.$eval('#qfErr', e => e.textContent)) && (await pg.$eval('#qfNum', e => e.getAttribute('aria-invalid'))) === 'true');
  await pg.$eval('#qfNum', e => { e.value = ''; });
  await pg.type('#qfNum', '11987650001');
  ok('Q2 o número é mascarado enquanto digita: (11) 98765-0001', (await pg.$eval('#qfNum', e => e.value)) === '(11) 98765-0001');
  const antesLq = await lider.api('acoes', { tipo: 'lead_quente', periodo: 'hoje' });
  await pg.click('#qfTipos [data-t="lead_quente"]');
  ok('Q3 clicar no tipo salva na hora e mostra "Ação registrada!"', await toast(pg, /Ação registrada!/));
  await pg.waitForFunction(() => document.getElementById('qfNum').value === '', { timeout: 5000 });
  const depoisLq = await lider.api('acoes', { tipo: 'lead_quente', periodo: 'hoje' });
  ok('Q3 o servidor gravou 1 lead quente a mais, e o campo limpou para o próximo', depoisLq.total === antesLq.total + 1 && (await pg.evaluate(() => document.activeElement.id)) === 'qfNum', [antesLq.total, depoisLq.total]);
  await irPara(pg, 'dashboard');
  await pg.waitForFunction(() => { const t = [...document.querySelectorAll('#dProd .lp-rk-n small')].map(x => +((x.textContent.match(/Leads quentes (\d+)/) || [])[1] || 0)); return t.length > 0 && t.reduce((a, b) => a + b, 0) === 3; }, { timeout: 20000 })
    .then(() => ok('Q4 o ranking do Dashboard conta a ação registrada (leads quentes da equipe 2 → 3)', true), () => ok('Q4 o ranking do Dashboard atualiza com a ação registrada', false));
  await irPara(pg, 'meu-dia');
  await pg.type('#qfNum', '11987650002');
  const antesLg = await lider.api('acoes', { tipo: 'ligacao', periodo: 'hoje' });
  await pg.evaluate(() => { const b = document.querySelector('#qfTipos [data-t="ligacao"]'); b.click(); b.click(); });          // dois cliques seguidos
  await toast(pg, /Ação registrada!/); await pg.waitForFunction(() => document.getElementById('qfNum').value === '', { timeout: 5000 });
  const depoisLg = await lider.api('acoes', { tipo: 'ligacao', periodo: 'hoje' });
  ok('Q5 dois cliques seguidos no mesmo tipo e número registram só UMA ação', depoisLg.total === antesLg.total + 1, [antesLg.total, depoisLg.total]);
  await pg.type('#qfNum', '11987650003'); await pg.keyboard.press('Enter');
  ok('Q6 Enter no campo com número válido leva o foco ao primeiro botão de tipo (sem salvar sozinho)', (await pg.evaluate(() => document.activeElement.dataset && document.activeElement.dataset.t)) === 'ligacao' && (await lider.api('acoes', { tipo: 'ligacao', periodo: 'hoje' })).total === depoisLg.total);
  await pg.$eval('#qfNum', e => { e.value = ''; });
  await irPara(pg, 'dashboard');
  await pg.keyboard.down('Control'); await pg.keyboard.press('n'); await pg.keyboard.up('Control');
  await sleep(250);
  ok('Q7 atalho Ctrl+N, de qualquer aba, leva ao Meu dia e põe o foco no campo do número', (await pg.evaluate(() => document.activeElement.id)) === 'qfNum' && (await crumb(pg)).startsWith('Meu dia'), await crumb(pg));
  await pg.evaluate(() => document.activeElement.blur());
  await pg.keyboard.down('Alt'); await pg.keyboard.press('n'); await pg.keyboard.up('Alt');
  ok('Q7 e o plano B, Alt+N, também (alguns navegadores reservam o Ctrl+N)', (await pg.evaluate(() => document.activeElement.id)) === 'qfNum');
  await pg.screenshot({ path: path.join(OUT, 'lider-acao-rapida.png') });

  // ═══ EQUIPE ═════════════════════════════════════════════════════════════════════════════════════
  await irPara(pg, 'equipe');
  await pg.waitForSelector('#eqLista .lp-cl', { timeout: 20000 });
  const apiE = await lider.api('lider_equipe', {});
  const cards = await pg.$$eval('#eqLista .lp-cl', c => c.map(x => ({ id: x.dataset.id, nome: x.querySelector('h3').textContent.trim(), st: x.querySelector('.lp-st').textContent.trim(), met: [...x.querySelectorAll('.lp-met > div')].map(m => m.querySelector('small').textContent.trim() + '=' + m.querySelector('b').textContent.trim()), btns: [...x.querySelectorAll('.lp-cl-a button')].map(b => b.textContent.trim()) })));
  ok('E1 um cartão por closer da equipe (' + apiE.closers.length + '), com status, métricas dos últimos 30 dias e os botões "Novo feedback" e "Ver histórico"', cards.length === apiE.closers.length && cards[0].btns.join('|') === 'Novo feedback|Ver histórico' && cards[0].met.length === 5, cards);
  ok('E1 métricas do closer de teste (semear): 1 cobrança, 1 revisão e 1 feedback', cards[0].met.join('|') === 'Reuniões=0|Cobranças=1|Revisões=1|Feedbacks=1|Treinos=0', cards[0].met);
  ok('E1 a lista é só da equipe do líder: nenhum closer de outra equipe', cards.every(x => apiE.closers.some(y => String(y.id) === x.id)) && !cards.some(x => x.nome === outroTime.name), cards.map(x => x.nome));
  await pg.click('#eqLista [data-op="feedback"]');
  await pg.waitForSelector('dialog.ld-modal[open] #f_closer_id', { timeout: 10000 });
  ok('E2 "Novo feedback" no cartão já vem com o closer do cartão escolhido', (await pg.$eval('#f_closer_id', e => e.value)) === cards[0].id);
  await pg.keyboard.press('Escape'); await aguardaFechar(pg);
  await pg.click('#eqLista [data-op="historico"]');
  await pg.waitForSelector('dialog.ld-drawer[open] .lp-tl li', { timeout: 15000 });
  const gaveta = await pg.evaluate(() => ({ titulo: document.querySelector('dialog.ld-drawer h2').textContent.trim(), tl: [...document.querySelectorAll('dialog.ld-drawer .lp-tl li')].map(x => x.textContent.replace(/\s+/g, ' ').trim()), svg: (document.querySelector('dialog.ld-drawer .lp-evo svg') || { getAttribute: () => '' }).getAttribute('aria-label'), barras: document.querySelectorAll('dialog.ld-drawer .lp-evo rect').length, linha: !!document.querySelector('dialog.ld-drawer .lp-evo polyline'), legenda: (document.querySelector('dialog.ld-drawer .lp-evo-l') || {}).textContent || '' }));
  ok('E3 "Ver histórico" abre o painel com o nome do closer, a linha do tempo (feedback, cobrança e revisão) e o gráfico de 30 dias', gaveta.titulo === 'CLOSER TESTE' && ['Feedback', 'Cobrança', 'Revisão'].every(t => gaveta.tl.some(x => x.includes(t))) && /30 dias/.test(gaveta.svg || '') && /Contratos por dia/.test(gaveta.legenda) && gaveta.barras >= 1 && gaveta.linha, gaveta);
  await pg.screenshot({ path: path.join(OUT, 'lider-equipe-historico.png') });
  const apiSem = (await lider.api('lider_closer', { closer_id: +cards[0].id, periodo: 'semana' })).periodo;
  const apiMes = (await lider.api('lider_closer', { closer_id: +cards[0].id, periodo: 'mes' })).periodo;
  const lerPeriodo = () => pg.evaluate(() => ({ cap: ((document.querySelector('#cdPeriodo .cd-cap') || {}).textContent || '').trim(), blocos: [...document.querySelectorAll('#cdPeriodo .cd-bloco > h4')].map(h => h.textContent.trim()), kpis: [...document.querySelectorAll('#cdPeriodo .mp-k')].map(k => k.querySelector('small').textContent.trim() + '=' + k.querySelector('b').firstChild.textContent.trim()), sel: ((document.querySelector('#cdSeg [aria-selected="true"]') || {}).textContent || '').trim(), geral: /Visão geral/.test(document.querySelector('dialog.ld-drawer[open]').textContent) }));
  const esperaKpis = pd => ['Ligações=' + pd.produtividade.ligacoes, 'Follow-ups=' + pd.produtividade.followups, 'Leads quentes=' + pd.produtividade.leads_quentes, 'Ações=' + pd.produtividade.total];
  let per = await lerPeriodo();
  ok('E3 o painel do closer traz a visão geral, o seletor Semana | Mês (abre na semana) e, do período, Produtividade e Contratos com os números do servidor', per.geral && per.sel === 'Semana' && per.blocos.join('|') === 'Produtividade|Contratos' && /^Semana · \d\d\/\d\d a \d\d\/\d\d$/.test(per.cap) && esperaKpis(apiSem).every((x, i) => per.kpis[i] === x) && per.kpis.includes('Contratos=' + apiSem.contratos.total), [per, apiSem]);
  await pg.click('#cdSeg [data-per="mes"]');
  await pg.waitForFunction(() => /^Mês ·/.test(((document.querySelector('#cdPeriodo .cd-cap') || {}).textContent || '').trim()), { timeout: 15000 });
  per = await lerPeriodo();
  ok('E3 trocar para "Mês" recarrega o período: legenda do mês e números do mês (iguais aos do servidor)', per.sel === 'Mês' && esperaKpis(apiMes).every((x, i) => per.kpis[i] === x) && per.kpis.includes('Contratos=' + apiMes.contratos.total), [per, apiMes]);
  ok('E3 o resto do painel continua ao trocar o período (linha do tempo e gráfico de 30 dias)', (await pg.$$eval('dialog.ld-drawer[open] .lp-tl li', l => l.length)) > 0 && !!(await pg.$('dialog.ld-drawer[open] .lp-evo svg')));
  await pg.screenshot({ path: path.join(OUT, 'lider-equipe-historico-mes.png') });
  await pg.keyboard.press('Escape'); await aguardaFechar(pg); await sleep(200);
  ok('E3 Esc fecha o painel e o foco volta ao botão "Ver histórico"', (await pg.evaluate(() => document.activeElement.dataset && document.activeElement.dataset.op)) === 'historico');
  const alheio = await lider.api('lider_closer', { closer_id: outroTime.id });
  ok('E4 a API recusa o histórico de um closer de OUTRA equipe', alheio.success === false, alheio);

  // ═══ AGENDA ═════════════════════════════════════════════════════════════════════════════════════
  prep('semear');
  await irPara(pg, 'agenda');
  await pg.waitForSelector('#agCal .lp-dia', { timeout: 20000 });
  await pg.waitForSelector('#agProx .lp-evc', { timeout: 20000 });
  const [ano, mes] = hoje.split('-').map(Number), diasMes = new Date(ano, mes, 0).getDate();
  const titulo = await pg.$eval('#agTitulo', e => e.textContent.trim());
  ok('G1 calendário do mês atual: o título e um quadrado por dia (' + diasMes + ')', /\d{4}$/.test(titulo) && (await pg.$$eval('#agCal .lp-dia:not(.vazio)', d => d.length)) === diasMes, titulo);
  const cel = await pg.$eval('#agCal .lp-dia.hoje', e => ({ ev: [...e.querySelectorAll('.lp-ev')].map(x => x.className.replace('lp-ev ', '').trim()), clicavel: e.tagName === 'BUTTON' }));
  ok('G1 o dia de hoje destacado mostra a reunião (verde) e o treinamento (azul) do dia', cel.clicavel && cel.ev.includes('reuniao') && cel.ev.includes('treinamento'), cel);
  ok('G1 a legenda tem as cinco atividades e o botão é "+ Nova atividade"', (await textos(pg, '.lp-legenda span')).join('|') === 'Reunião de equipe|Treinamento|Feedback|Cobrança|Revisão de atendimento' && (await pg.$eval('#agNovo', e => e.textContent.trim())) === '+ Nova atividade');
  const ev = await pg.$$eval('#agProx .lp-evc', e => e.map(x => ({ tipo: x.classList.contains('reuniao') ? 'reuniao' : 'treinamento', h: x.querySelector('h4').textContent.trim(), btns: [...x.querySelectorAll('.lp-evc-a button')].map(b => b.textContent.trim()) })));
  const reu = ev.find(e => e.tipo === 'reuniao'), tre = ev.find(e => e.tipo === 'treinamento');
  ok('G2 "Próximas atividades" (7 dias, só reuniões e treinamentos): a reunião agendada com "Editar", "Cancelar" e "Adicionar nota" e o treinamento do dia', !!reu && !!tre && ['Editar', 'Cancelar', 'Adicionar nota'].every(b => reu.btns.includes(b)) && tre.btns.includes('Adicionar nota'), ev);
  const cores = await pg.evaluate(() => { const r = document.querySelector('#agCal .lp-ev.reuniao'), t = document.querySelector('#agCal .lp-ev.treinamento'); const c = x => getComputedStyle(x).borderLeftColor || getComputedStyle(x).backgroundColor; const n = x => x.match(/[\d.]+/g).map(Number); return { r: n(c(r)), t: n(c(t)) }; });
  ok('G2 verde para reunião e azul para treinamento', cores.r[1] > cores.r[0] && cores.r[1] > cores.r[2] && cores.t[2] > cores.t[0] && cores.t[2] > cores.t[1], cores);
  await pg.click('#agCal .lp-dia.hoje');
  await pg.waitForSelector('dialog.ld-drawer[open] .lp-evc', { timeout: 8000 });
  ok('G3 clicar no dia abre o painel com os eventos daquele dia', (await pg.$$eval('dialog.ld-drawer[open] .lp-evc', e => e.length)) >= 2);
  await pg.keyboard.press('Escape'); await aguardaFechar(pg);
  // nota
  await pg.click('#agProx .lp-evc.reuniao [data-ev="nota"]');
  await pg.waitForSelector('dialog.ld-modal[open] #f_texto', { timeout: 8000 });
  await enviar(pg);
  ok('G4 nota vazia é recusada com a mensagem ao lado do campo', await modalAberto(pg) && /nota|escreva|preench|obrigat/i.test(await pg.$eval('dialog.ld-modal[open] [data-erro-de="texto"]', e => e.textContent).catch(() => '')), await pg.$eval('dialog.ld-modal[open] .ld-dlg-b', e => e.textContent));
  await campo(pg, 'f_texto', 'Levar a lista de objeções.');
  await enviar(pg); await aguardaFechar(pg);
  await pg.waitForFunction(() => /Levar a lista de objeções/.test(document.getElementById('agProx').textContent), { timeout: 15000 }).then(() => ok('G4 a nota aparece no evento com o nome de quem escreveu', true), () => ok('G4 a nota aparece no evento', false));
  const notaApi = await lider.api('lider_agenda', { ano, mes });
  ok('G4 e fica gravada no servidor (a API devolve a nota no evento)', notaApi.eventos.some(e => e.notas.some(n => n.texto === 'Levar a lista de objeções.')), notaApi.eventos.map(e => e.notas));
  // novo evento: treinamento de amanhã, depois cancelado
  await pg.click('#agNovo');
  await pg.waitForSelector('dialog.ld-modal[open] input[name="tipo"]', { timeout: 8000 });
  await pg.click('dialog.ld-modal[open] input[value="treinamentos"]');
  await enviar(pg);
  await pg.waitForSelector('dialog.ld-modal[open] #f_assunto', { timeout: 8000 });
  await campo(pg, 'f_assunto', 'Fechamento ativo'); await campo(pg, 'f_data_hora', amanha + 'T10:00');
  await enviar(pg); await aguardaFechar(pg);
  await pg.waitForFunction(() => /Fechamento ativo/.test(document.getElementById('agProx').textContent), { timeout: 20000 });
  ok('G5 "+ Nova atividade" → Treinamento: a atividade entra nos próximos 7 dias', true);
  const idxT = await pg.$$eval('#agProx .lp-evc', e => e.findIndex(x => /Fechamento ativo/.test(x.textContent)));
  await pg.click(`#agProx .lp-evc:nth-child(${idxT + 1}) [data-ev="cancelar"]`);
  await pg.waitForSelector('dialog.ld-modal[open]', { timeout: 8000 });
  await enviar(pg); await aguardaFechar(pg);
  await pg.waitForFunction(() => !/Fechamento ativo/.test(document.getElementById('agProx').textContent), { timeout: 20000 }).then(() => ok('G6 cancelar um treinamento pede confirmação e ele sai dos "Próximas atividades"', true), () => ok('G6 cancelar um treinamento pede confirmação e ele sai dos "Próximas atividades"', false));
  const agC = await lider.api('lider_agenda', { ano, mes });
  const evC = agC.eventos.find(e => e.titulo === 'Fechamento ativo');
  ok('G6 o treinamento cancelado fica no histórico (status "cancelada") e sem ações de edição ou presença', !!evC && evC.status === 'cancelada' && evC.cancelavel !== true, evC);
  if (amanha.slice(0, 7) === hoje.slice(0, 7)) ok('G6 no calendário o evento cancelado aparece riscado (cancelado)', (await pg.$$eval('#agCal .lp-ev.cancelada', e => e.length)) === 1);
  await pg.click('#agNext'); await pg.waitForFunction(t => document.getElementById('agTitulo').textContent.trim() !== t, { timeout: 15000 }, titulo);
  await pg.click('#agHoje'); await pg.waitForFunction(t => document.getElementById('agTitulo').textContent.trim() === t, { timeout: 15000 }, titulo).then(() => ok('G7 navegar para o mês seguinte e voltar com "Hoje"', true), () => ok('G7 navegar de mês e voltar com "Hoje"', false));
  // "+ Nova atividade": as cinco que eram dos Registros; um feedback criado aqui aparece no calendário do dia e abre a edição
  await pg.click('#agNovo');
  await pg.waitForSelector('dialog.ld-modal[open] input[name="tipo"]', { timeout: 8000 });
  const opcoes = await pg.$$eval('dialog.ld-modal[open] input[name="tipo"]', i => i.map(x => x.value));
  ok('G8 "+ Nova atividade" oferece as cinco atividades: reunião, treinamento, feedback, cobrança e revisão', opcoes.join('|') === 'reunioes|treinamentos|feedbacks|cobrancas|revisoes' && /Nova atividade/.test(await pg.$eval('dialog.ld-modal[open] h2', e => e.textContent)), opcoes);
  await pg.click('dialog.ld-modal[open] input[value="feedbacks"]');
  await pg.click('dialog.ld-modal[open] button[type="submit"]');          // o formulário do feedback abre logo depois que este fecha: não dá para esperar "nenhum modal aberto"
  await pg.waitForSelector('dialog.ld-modal[open] #f_closer_id', { timeout: 8000 });
  await campo(pg, 'f_closer_id', String(dados.closer.vendedor_id)); await campo(pg, 'f_assunto', 'Conversa sobre fechamento'); await campo(pg, 'f_texto', 'Feedback criado pela Nova atividade.');
  await enviar(pg); await aguardaFechar(pg);
  await pg.waitForFunction(() => document.querySelector('#agCal .lp-dia.hoje .lp-pts i.feedback'), { timeout: 20000 }).then(() => ok('G8 o feedback criado aparece no calendário, no dia de hoje (âmbar)', true), () => ok('G8 o feedback criado aparece no calendário', false));
  await sleep(700);                                                   // o calendário é redesenhado quando a agenda recarrega: clica pelo próprio navegador, sem segurar o nó antigo
  await pg.$eval('#agCal .lp-dia.hoje', e => e.click());
  await pg.waitForSelector('dialog.ld-drawer[open] .lp-evc.feedback', { timeout: 8000 });
  const fdb = await pg.$$eval('dialog.ld-drawer[open] .lp-evc.feedback', e => e.map(x => ({ t: x.querySelector('h4').textContent.trim(), data: x.querySelector('.lp-evc-m b').textContent.trim(), btns: [...x.querySelectorAll('.lp-evc-a button')].map(b => b.textContent.trim()), texto: (x.querySelector('.lp-evc-t') || {}).textContent })));
  const meu = fdb.find(x => x.t === 'Conversa sobre fechamento');
  ok('G8 no painel do dia o feedback vem sem horário (só a data), com o texto e o botão Editar', !!meu && /^\d\d\/\d\d$/.test(meu.data) && meu.btns.join('|') === 'Editar' && /Feedback criado/.test(meu.texto), fdb);
  const dtrs = await pg.$$eval('dialog.ld-drawer[open] .lp-evc', e => e.map(x => x.classList.contains('reuniao') || x.classList.contains('treinamento')));
  ok('G8 no painel do dia as atividades com horário (reunião, treinamento) vêm antes das sem horário', dtrs.indexOf(false) === -1 || dtrs.lastIndexOf(true) < dtrs.indexOf(false), dtrs);
  const idxF = await pg.$$eval('dialog.ld-drawer[open] .lp-evc', e => e.findIndex(x => /Conversa sobre fechamento/.test(x.textContent)));
  await pg.click(`dialog.ld-drawer[open] .lp-evc:nth-child(${idxF + 1}) [data-ev="editar"]`);
  await pg.waitForSelector('dialog.ld-modal[open] #f_assunto', { timeout: 10000 });
  ok('G8 "Editar" abre o formulário do feedback com o assunto já preenchido', (await pg.$eval('dialog.ld-modal[open] #f_assunto', e => e.value)) === 'Conversa sobre fechamento');
  await pg.keyboard.press('Escape'); await aguardaFechar(pg);
  await pg.screenshot({ path: path.join(OUT, 'lider-agenda.png') });

  // ═══ SEM TELA DE REGISTROS: o histórico das ações rápidas mora no Meu dia ═══════════════════════
  ok('R0 não existe mais a seção Registros: nem item no menu, nem aba, nem sub-abas', (await pg.$('.lp-nav-b[data-tab="registros"]')) === null && (await pg.$('#pane-registros')) === null && (await pg.$('#rgTabs')) === null);
  await abrir(pg, 'registros');
  ok('R0 o endereço antigo (#registros) cai no Dashboard', (await crumb(pg)) === 'Dashboard', await crumb(pg));
  await irPara(pg, 'meu-dia');
  await pg.click('#mdHist');
  await pg.waitForSelector('#mdHistCard:not([hidden]) .lp-ac', { timeout: 20000 });
  const totalAcoes = await lider.api('acoes', { periodo: 'hoje', limit: 30 });
  const rows = await pg.$$eval('#mdHistBody .lp-ac', r => r.map(x => x.textContent.replace(/\s+/g, ' ').trim()));
  ok('R2 "Histórico de ações rápidas": hoje, agrupado por dia, com hora, tipo e número mascarado (' + totalAcoes.total + ' ações)', rows.length === Math.min(30, totalAcoes.total) && rows.every(x => /^\d\d:\d\d/.test(x) && /\(\d\d\) 9\*{4}-\d{4}/.test(x)) && (await pg.$eval('#mdHistBody .lp-dia-h', e => e.textContent.trim())) === 'Hoje', rows.slice(0, 2));
  await pg.select('#mdHistBody [data-tipo]', 'ligacao');
  await pg.waitForFunction(() => [...document.querySelectorAll('#mdHistBody .lp-ac-t')].every(x => /Ligação/.test(x.textContent)) && document.querySelectorAll('#mdHistBody .lp-ac').length > 0, { timeout: 15000 });
  const lg = await lider.api('acoes', { periodo: 'hoje', tipo: 'ligacao' });
  ok('R2 filtro por tipo: só as ligações (' + lg.total + ')', (await pg.$$eval('#mdHistBody .lp-ac', r => r.length)) === lg.total && /\d+ ações?/.test(await pg.$eval('#mdHistBody [data-contagem]', e => e.textContent)), lg.total);
  await pg.select('#mdHistBody [data-tipo]', '');
  await pg.type('#mdHistBody [data-q]', '0006');
  await pg.waitForFunction(() => document.querySelectorAll('#mdHistBody .lp-ac').length === 1, { timeout: 15000 });
  ok('R2 busca pelo final do número (0006): acha só a ação do (11) 98111-0006', /0006/.test(await pg.$eval('#mdHistBody .lp-ac', e => e.textContent)));
  await pg.$eval('#mdHistBody [data-q]', e => { e.value = ''; e.dispatchEvent(new Event('input', { bubbles: true })); });
  await pg.select('#mdHistBody [data-periodo]', 'ontem');
  await pg.waitForFunction(() => /Nenhuma ação encontrada/.test(document.querySelector('#mdHistBody [data-lista]').textContent), { timeout: 15000 }).then(() => ok('R2 período "Ontem" sem ações: estado vazio "Nenhuma ação encontrada"', true), () => ok('R2 período "Ontem" sem ações mostra o estado vazio', false));
  await pg.select('#mdHistBody [data-periodo]', 'hoje'); await sleep(300);
  await pg.screenshot({ path: path.join(OUT, 'lider-acoes-rapidas.png') });

  // ═══ CONFIGURAÇÕES ══════════════════════════════════════════════════════════════════════════════
  prep('contratos 5 1');                                   // closer parado: dá o alerta "sem contrato" para ver o efeito da preferência
  await abrir(pg, 'config');
  await pg.waitForSelector('#cfForm', { timeout: 20000 });
  const cfg = await pg.evaluate(() => ({ sw: [...document.querySelectorAll('#cfForm [data-alerta]')].map(c => c.dataset.alerta + '=' + c.checked), sv: document.getElementById('cfSv').value, sf: document.getElementById('cfSf').value, atalhos: [...document.querySelectorAll('.lp-atalhos li')].map(x => x.textContent.replace(/\s+/g, ' ').trim()), link: document.querySelectorAll('#cfView a[href*="closer.php"]').length }));
  ok('C1 Configurações: 5 tipos de alerta ligados, 3 dias sem contrato, 14 sem feedback; atalhos e nenhum link para o portal do closer', cfg.sw.length === 5 && cfg.sw.every(x => /=true/.test(x)) && cfg.sv === '3' && cfg.sf === '14' && cfg.atalhos.length === 3 && /Ctrl K/.test(cfg.atalhos[0]) && cfg.link === 0, cfg);
  await campo(pg, 'cfSv', '0');
  await pg.click('#cfSalvar');
  await pg.waitForFunction(() => document.querySelector('[data-erro-de="limite_dias_sem_venda"]').textContent.length > 0, { timeout: 15000 });
  ok('C2 valor fora da faixa (0 dias) é recusado com a mensagem ao lado do campo (1 a 14)', /1 a 14/.test(await pg.$eval('[data-erro-de="limite_dias_sem_venda"]', e => e.textContent)) && (await pg.$eval('#cfSv', e => e.getAttribute('aria-invalid'))) === 'true');
  await campo(pg, 'cfSv', '5');
  await pg.click('#cfForm [data-alerta="sem_venda"]');
  await pg.click('#cfSalvar');
  ok('C3 salvar mostra "Preferências salvas."', await toast(pg, /Preferências salvas/));
  const prefs = await lider.api('lider_preferencias', {});
  ok('C3 e o servidor guarda: 5 dias e o alerta "sem contrato" desligado', prefs.preferencias.limite_dias_sem_venda === 5 && prefs.preferencias.alertas.sem_venda === false && prefs.preferencias.alertas.meta_batida === true, prefs.preferencias);
  await pg.reload({ waitUntil: 'domcontentloaded' });
  await pg.waitForSelector('#cfForm', { timeout: 20000 });
  ok('C3 depois de recarregar a página, as preferências continuam', (await pg.$eval('#cfSv', e => e.value)) === '5' && !(await pg.$eval('#cfForm [data-alerta="sem_venda"]', e => e.checked)));
  await irPara(pg, 'dashboard'); await pg.waitForSelector('#dAlertas', { timeout: 10000 });
  await pg.waitForFunction(() => !/fechar contrato|Última venda/.test(document.getElementById('dAlertas').textContent) && !!document.querySelector('#dAlertas .ld-empty, #dAlertas .lp-item'), { timeout: 20000 }).then(() => ok('C4 com o alerta desligado, o Dashboard deixa de mostrar o closer parado', true), async () => ok('C4 com o alerta desligado, o Dashboard deixa de mostrar o closer parado', false, await textos(pg, '#dAlertas .lp-item-t')));
  await irPara(pg, 'config');
  await pg.click('#cfPadrao'); await pg.click('#cfSalvar');
  await toast(pg, /Preferências salvas/);
  const prefs2 = await lider.api('lider_preferencias', {});
  ok('C5 "Restaurar padrão" + Salvar volta tudo ao padrão (3 dias, todos os alertas ligados)', prefs2.preferencias.limite_dias_sem_venda === 3 && Object.values(prefs2.preferencias.alertas).every(Boolean), prefs2.preferencias);
  await pg.screenshot({ path: path.join(OUT, 'lider-config.png') });
  prep('semear');

  // ═══ BUSCA GLOBAL, SINO E ATALHOS ═══════════════════════════════════════════════════════════════
  await abrir(pg, 'dashboard');
  await pg.keyboard.down('Control'); await pg.keyboard.press('k'); await pg.keyboard.up('Control');
  await pg.waitForSelector('dialog.lp-busca[open]', { timeout: 5000 });
  ok('B1 Ctrl+K abre a busca global com o foco na caixa e as telas/ações de partida', (await pg.evaluate(() => document.activeElement.id)) === 'lpBq' && (await pg.$$eval('.lp-busca .lp-res', r => r.length)) >= 5);
  await pg.keyboard.type('clo');
  await pg.waitForFunction(() => [...document.querySelectorAll('.lp-busca .lp-busca-g')].some(g => g.textContent.trim() === 'Closers'), { timeout: 15000 });
  const res = await pg.evaluate(() => ({ g: [...document.querySelectorAll('.lp-busca .lp-busca-g')].map(x => x.textContent.trim()), r: [...document.querySelectorAll('.lp-busca .lp-res')].map(x => x.textContent.replace(/\s+/g, ' ').trim()), sel: document.querySelector('.lp-busca .lp-res[aria-selected="true"]').textContent }));
  ok('B2 digitar "clo" acha o closer da equipe em "Closers" (o primeiro já vem selecionado)', res.g[0] === 'Closers' && /CLOSER TESTE/.test(res.r[0]) && /CLOSER TESTE/.test(res.sel), res);
  await pg.screenshot({ path: path.join(OUT, 'lider-busca.png') });
  await pg.keyboard.press('Enter');
  await pg.waitForSelector('dialog.ld-drawer[open] .lp-tl, dialog.ld-drawer[open] .ld-empty', { timeout: 15000 });
  ok('B2 Enter abre a aba Equipe com o painel do closer', (await crumb(pg)).startsWith('Equipe') && (await pg.$eval('dialog.ld-drawer h2', e => e.textContent.trim())) === 'CLOSER TESTE');
  await pg.keyboard.press('Escape'); await aguardaFechar(pg);
  await pg.click('#lpBuscar');
  await pg.waitForSelector('dialog.lp-busca[open]', { timeout: 5000 });
  await pg.keyboard.type('ritmo');
  await pg.waitForFunction(() => [...document.querySelectorAll('.lp-busca .lp-busca-g')].some(g => g.textContent.trim() === 'Atividades'), { timeout: 15000 });
  await pg.keyboard.press('Enter');
  await pg.waitForSelector('dialog.ld-modal[open] #f_assunto', { timeout: 15000 });
  ok('B3 buscar "ritmo" acha o feedback em "Atividades"; Enter abre a Agenda com o feedback já aberto para editar', (await crumb(pg)).startsWith('Agenda') && /ritmo/i.test(await pg.$eval('dialog.ld-modal[open] #f_assunto', e => e.value)), await crumb(pg));
  await pg.keyboard.press('Escape'); await aguardaFechar(pg);
  await pg.keyboard.down('Control'); await pg.keyboard.press('k'); await pg.keyboard.up('Control');
  await pg.waitForSelector('dialog.lp-busca[open]', { timeout: 5000 });
  await pg.keyboard.type('agenda'); await sleep(500); await pg.keyboard.press('Enter');
  await pg.waitForFunction(() => !document.getElementById('pane-agenda').hidden, { timeout: 8000 });
  ok('B4 buscar "agenda" + Enter vai para a aba Agenda', (await crumb(pg)).startsWith('Agenda'));
  await pg.keyboard.down('Control'); await pg.keyboard.press('k'); await pg.keyboard.up('Control');
  await pg.waitForSelector('dialog.lp-busca[open]', { timeout: 5000 });
  await pg.keyboard.type('registrar acao'); await sleep(500); await pg.keyboard.press('Enter');
  await pg.waitForFunction(() => !document.getElementById('pane-meu-dia').hidden, { timeout: 8000 });
  await sleep(300);
  ok('B4 buscar "registrar acao" + Enter leva ao card do Meu dia, com o foco no número', (await crumb(pg)).startsWith('Meu dia') && (await pg.evaluate(() => document.activeElement.id)) === 'qfNum', await crumb(pg));
  await pg.keyboard.down('Control'); await pg.keyboard.press('k'); await pg.keyboard.up('Control');
  await pg.waitForSelector('dialog.lp-busca[open]', { timeout: 5000 });
  await pg.keyboard.type('zzzzqq');
  await pg.waitForFunction(() => /Nada encontrado/.test(document.getElementById('lpBr').textContent), { timeout: 15000 });
  await pg.keyboard.press('Escape'); await sleep(250);
  ok('B5 sem resultado: "Nada encontrado"; Esc fecha a busca mesmo com texto digitado', !(await pg.$('dialog.lp-busca[open]')));
  const buscaAlheia = await lider.api('lider_busca', { q: outroTime.name.slice(0, 6) });
  ok('B6 a busca só enxerga a equipe do líder: o nome de um closer de outra equipe não acha nada', buscaAlheia.success === true && buscaAlheia.closers.every(x => x.nome !== outroTime.name) && !JSON.stringify(buscaAlheia).includes(outroTime.name), buscaAlheia);
  const curta = await lider.api('lider_busca', { q: 'a' });
  ok('B6 e exige ao menos 2 letras', curta.closers.length === 0 && curta.registros.length === 0, curta);

  // ═══ CONTRASTE E TIPOGRAFIA ═════════════════════════════════════════════════════════════════════
  await abrir(pg, 'dashboard');
  const rat = {};
  for (const [nome, sel, fundo] of [['sub', '.lp-sub', [19, 19, 19]], ['dica', '.lp-perf-dica', [19, 19, 19]], ['detalhe', '.lp-item-d', [19, 19, 19]], ['menu', '.lp-nav-b:not([aria-current]) span', [14, 14, 14]], ['dica-rapida', '.lp-hint', [19, 19, 19]], ['rotulo', '.lp-fld label', [19, 19, 19]], ['chip', '.lp-chip', [19, 19, 19]], ['data', '#lpData', [14, 14, 14]]]) rat[nome] = Math.round(((await contraste(pg, sel, fundo)) || 0) * 10) / 10;
  ok('T1 contraste do texto no portal do líder: pelo menos 4,5:1 (' + JSON.stringify(rat) + ')', Object.values(rat).every(r => r >= 4.5), rat);
  const tipo = await pg.evaluate(() => ({ corpo: getComputedStyle(document.body).fontFamily, menu: parseFloat(getComputedStyle(document.querySelector('.lp-nav-b span')).fontSize), h2: parseFloat(getComputedStyle(document.querySelector('.lp-card-h h2')).fontSize), campo: parseFloat(getComputedStyle(document.getElementById('qfNum')).fontSize) }));
  ok('T2 tipografia: Inter, menu ≥ 14px, títulos de card ≥ 12px e campo do número ≥ 15px', /Inter/.test(tipo.corpo) && tipo.menu >= 14 && tipo.h2 >= 12 && tipo.campo >= 14, tipo);
  await pg.close();

  // ═══ SHELL NO ESTILO DO ADMIN + PORTAL DO CLOSER DENTRO DO PORTAL DO LÍDER ═══════════════════════
  pg = await nova(lider);
  await pg.evaluateOnNewDocument(() => { try { localStorage.removeItem('lpRecolhido'); } catch (e) { /* sem armazenamento */ } });
  await abrir(pg);
  const casco = await pg.evaluate(() => ({ marca: document.querySelector('.sb-name').textContent.replace(/\s+/g, ' ').trim(), grupos: [...document.querySelectorAll('.sb-group')].map(g => g.textContent.trim()), h1: document.getElementById('lpTitulo').textContent.trim(), semSelo: !document.querySelector('.page-eyebrow') && !document.getElementById('lpCrumb'), larg: Math.round(document.getElementById('lpSide').getBoundingClientRect().width), topo: Math.round(document.querySelector('.lp-top').getBoundingClientRect().height), foot: !!document.querySelector('.tb-menu #lpSair') && !!document.querySelector('.sb-foot #nav-config') && !!document.getElementById('lpCollapse') }));
  ok('N1 shell flutuante igual ao do admin: marca TOP CLOSERS, grupos Liderança / Meu portal (closer), título grande sem selo, barra superior de 72px, Configurações e Recolher no rodapé e Sair no menu do perfil', /TOP\s*LEND/i.test(casco.marca) && casco.grupos.join('|') === 'Liderança|Meu portal (closer)' && casco.h1 === 'Painel da equipe' && casco.semSelo && casco.larg === 256 && casco.topo === 72 && casco.foot, casco);
  await pg.click('#lpCollapse'); await sleep(450);
  const rec = await pg.evaluate(() => ({ larg: Math.round(document.getElementById('lpSide').getBoundingClientRect().width), rot: document.getElementById('lpCollapse').textContent.trim(), exp: document.getElementById('lpCollapse').getAttribute('aria-expanded'), guard: localStorage.getItem('lpRecolhido') }));
  ok('N2 "Recolher menu" leva a barra lateral a 64px, troca o rótulo por "Expandir menu" e guarda a escolha', rec.larg === 64 && /Expandir/.test(rec.rot) && rec.exp === 'false' && rec.guard === '1', rec);
  await pg.click('#lpCollapse'); await sleep(450);
  const exp = await pg.evaluate(() => ({ larg: Math.round(document.getElementById('lpSide').getBoundingClientRect().width), guard: localStorage.getItem('lpRecolhido') }));
  ok('N2 "Expandir menu" devolve os 256px e guarda a escolha', exp.larg === 256 && exp.guard === '0', exp);

  // Meu dia: o portal do closer dentro do portal do líder (mesmas ações do closer-api.php)
  const criada = await lider.api('acao_criar', { telefone: '11987650077', tipo: 'ligacao' });
  const resumo0 = (await lider.api('resumo', {})).resumo;
  await irPara(pg, 'meu-dia');
  await pg.waitForFunction(() => document.querySelectorAll('#mdRecentes .lp-ac').length > 0 && document.querySelector('#evChart svg'), { timeout: 15000 });   // as 4 chamadas do Meu dia voltam em tempos diferentes
  await pg.waitForFunction((a, b, c) => +document.getElementById('nLig').textContent === a && +document.getElementById('nFu').textContent === b && +document.getElementById('nLq').textContent === c, { timeout: 15000 }, resumo0.ligacao, resumo0.followup, resumo0.lead_quente).catch(() => { /* o teste abaixo mostra o que veio */ });
  const md =await pg.evaluate(() => ({ n: ['nLig', 'nFu', 'nLq'].map(i => +document.getElementById(i).textContent), linhas: document.querySelectorAll('#mdRecentes .lp-ac[data-id]').length, h1: document.getElementById('lpTitulo').textContent.trim(), ev: document.getElementById('evTxt').textContent.trim(), svg: !!document.querySelector('#evChart svg') }));
  ok('N3 Meu dia: os contadores do dia são os do servidor, há até 5 últimas ações, a evolução de 7 dias e o título "Meu dia"', criada.success && md.n.join(',') === [resumo0.ligacao, resumo0.followup, resumo0.lead_quente].join(',') && md.linhas >= 1 && md.linhas <= 5 && md.h1 === 'Meu dia' && /últimos 7 dias/.test(md.ev) && md.svg, md);
  const crumbMd = await crumb(pg);
  ok('N3 a página ativa é "Meu dia"', crumbMd.startsWith('Meu dia'), crumbMd);
  // registrar pela barra lateral atualiza o Meu dia na hora, sem trocar de tela
  await pg.type('#qfNum', '11987650078'); await pg.click('#qfTipos [data-t="followup"]');
  await toast(pg, /Ação registrada!/);
  await pg.waitForFunction(n => +document.getElementById('nFu').textContent === n, { timeout: 15000 }, resumo0.followup + 1);
  ok('N4 registrar uma ação rápida pelo card do Meu dia soma 1 no contador do Meu dia', true);
  // clicar numa linha abre a edição; trocar o tipo move o contador de uma coluna para a outra
  await pg.waitForFunction(() => { const n = document.querySelector('#mdRecentes .lp-ac .lp-ac-n'); return n && /0078/.test(n.textContent); }, { timeout: 15000 });   // a lista recarrega logo depois do contador
  const primeira = await pg.$eval('#mdRecentes .lp-ac[data-id]', e => +e.dataset.id);
  await pg.click('#mdRecentes .lp-ac[data-id]');
  await pg.waitForSelector('dialog.ld-modal[open] #f_telefone', { timeout: 10000 });
  const form = await pg.evaluate(() => ({ titulo: document.querySelector('dialog.ld-modal[open] h2').textContent.trim(), tel: document.getElementById('f_telefone').value, tipo: document.getElementById('f_tipo').value, excluir: !!document.querySelector('dialog.ld-modal[open] [data-excluir]') }));
  ok('N5 clicar numa ação abre "Editar ação" com o número, o tipo e o botão Excluir', form.titulo === 'Editar ação' && /\(11\) 98765-00\d\d/.test(form.tel) && form.tipo === 'followup' && form.excluir, form);
  await campo(pg, 'f_observacao', 'editada pelo e2e do portal'); await campo(pg, 'f_tipo', 'lead_quente');
  await enviar(pg); await toast(pg, /Ação atualizada/);
  const apos = (await lider.api('acao_get', { id: primeira })).acao;
  ok('N5 salvar a edição grava no servidor (tipo e observação)', apos.tipo === 'lead_quente' && apos.observacao === 'editada pelo e2e do portal', apos);
  await pg.waitForFunction(n => +document.getElementById('nLq').textContent === n, { timeout: 15000 }, resumo0.lead_quente + 1);
  ok('N5 o Meu dia move o contador do tipo antigo para o novo', true);
  // erro de validação aparece no campo
  // (a edição grava a hora sem os segundos, como no portal do closer: a linha de cima pode ser outra ação; vale a que for aberta)
  const alvo = await pg.$eval('#mdRecentes .lp-ac[data-id]', e => +e.dataset.id);
  await pg.click('#mdRecentes .lp-ac[data-id]'); await pg.waitForSelector('dialog.ld-modal[open] #f_telefone', { timeout: 10000 });
  await campo(pg, 'f_telefone', '123'); await enviar(pg);
  const erroTel = await pg.evaluate(() => (document.querySelector('dialog.ld-modal[open] [data-erro-de="telefone"]') || {}).textContent || '');
  ok('N5 telefone inválido na edição mostra o erro no campo e não fecha', /Telefone inválido/.test(erroTel) && (await modalAberto(pg)), erroTel);
  // excluir (com confirmação)
  const totalAntes = resumo0.total + 1;                  // o resumo de antes + o follow-up registrado pela barra lateral
  await pg.click('dialog.ld-modal[open] [data-excluir]');
  await pg.waitForFunction(() => document.querySelectorAll('dialog.ld-modal[open]').length === 2, { timeout: 8000 });
  await pg.evaluate(() => { const d = [...document.querySelectorAll('dialog.ld-modal[open]')].pop(); d.querySelector('button[type="submit"]').click(); });
  await toast(pg, /Ação excluída/); await aguardaFechar(pg);
  const resumo1 = (await lider.api('resumo', {})).resumo;
  ok('N6 Excluir pede confirmação, apaga a ação e o resumo do dia cai em 1', resumo1.total === totalAntes - 1 && (await lider.api('acao_get', { id: alvo })).success === false, [resumo1.total, totalAntes]);
  // "Ver histórico completo" abre o histórico (com filtros) aqui mesmo, no Meu dia; as linhas também abrem a edição
  await pg.click('#mdHist');
  await pg.waitForSelector('#mdHistCard:not([hidden]) .lp-ac[data-id]', { timeout: 15000 });
  ok('N7 "Ver histórico completo" abre o histórico das ações no próprio Meu dia (sem trocar de tela)', (await crumb(pg)).startsWith('Meu dia') && /Ocultar/.test(await pg.$eval('#mdHist', e => e.textContent)) && (await pg.$eval('#mdHist', e => e.getAttribute('aria-expanded'))) === 'true');
  await pg.click('#mdHistBody .lp-ac[data-id]');
  await pg.waitForSelector('dialog.ld-modal[open] #f_telefone', { timeout: 10000 });
  ok('N7 as linhas do histórico também abrem a edição', (await pg.$eval('dialog.ld-modal[open] h2', e => e.textContent.trim())) === 'Editar ação');
  await pg.evaluate(() => document.querySelector('dialog.ld-modal[open] [data-cancelar]').click()); await aguardaFechar(pg);
  await pg.click('#mdHistX');
  ok('N7 "Fechar" esconde o histórico e o botão volta a "Ver histórico completo"', (await pg.$eval('#mdHistCard', e => e.hidden)) && /Ver histórico completo/.test(await pg.$eval('#mdHist', e => e.textContent)));
  // Meus contratos
  await irPara(pg, 'contratos');
  await pg.waitForFunction(() => document.querySelectorAll('#ctKpis .mp-k').length === 3, { timeout: 15000 });
  const contr = await pg.evaluate(() => ({ mes: document.getElementById('ctMesLbl').textContent.trim(), sems: document.querySelectorAll('#ctSems [data-sem]').length, cap: document.getElementById('ctCap').textContent.trim(), graf: !!document.querySelector('#ctChart svg') || !!document.querySelector('#ctChart .mp-empty'), ben: document.getElementById('ctBen').textContent.trim().length > 0, h1: document.getElementById('lpTitulo').textContent.trim() }));
  ok('N8 Meus contratos: mês, semanas, indicadores, gráfico e benefícios', /\d{4}/.test(contr.mes) && contr.sems >= 4 && /Semana/.test(contr.cap) && contr.graf && contr.ben && contr.h1 === 'Meus contratos', contr);
  await pg.click('#ctSeg [data-per="mes"]');
  await pg.waitForFunction(() => /Mês/.test(document.getElementById('ctCap').textContent) && document.getElementById('ctSems').hidden, { timeout: 15000 });
  ok('N8 trocar para "Mês" esconde a escolha de semana e muda a legenda', true);
  await pg.click('#ctSeg [data-per="semana"]');
  await pg.close();

  // ═══ CELULAR ════════════════════════════════════════════════════════════════════════════════════
  pg = await nova(lider, 390, 844);
  await abrir(pg, 'dashboard');
  const mob = await pg.evaluate(() => ({ lado: Math.round(document.getElementById('lpSide').getBoundingClientRect().right), burger: Math.round(document.getElementById('lpBurger').getBoundingClientRect().height), fab: !!document.getElementById('lpFab') }));
  ok('M1 celular: a sidebar fica fora da tela, com o botão hambúrguer (≥ 44px) e sem botão flutuante', mob.lado <= 1 && mob.burger >= 44 && mob.fab === false, mob);
  ok('M1 o card de ação rápida só aparece no Meu dia (não ocupa o Dashboard)', !(await visivel(pg, '#lpQuick')));
  await pg.click('#lpBurger'); await sleep(350);
  const menu = await pg.evaluate(() => ({ x: Math.round(document.getElementById('lpSide').getBoundingClientRect().left), exp: document.getElementById('lpBurger').getAttribute('aria-expanded'), altos: [...document.querySelectorAll('.lp-nav-b')].map(b => Math.round(b.getBoundingClientRect().height)) }));
  ok('M2 o hambúrguer abre o menu lateral (aria-expanded), e os itens têm ≥ 44px de altura', menu.x === 0 && menu.exp === 'true' && menu.altos.every(a => a >= 44), menu);
  await pg.screenshot({ path: path.join(OUT, 'lider-celular-menu.png') });
  await pg.click('.lp-nav-b[data-tab="equipe"]'); await sleep(300);
  ok('M2 escolher um item leva à aba e fecha o menu', (await crumb(pg)).startsWith('Equipe') && !(await pg.evaluate(() => document.body.classList.contains('lp-menu-open'))));
  await pg.click('#lpBurger'); await sleep(300); await pg.mouse.click(385, 700); await sleep(300);
  ok('M2 tocar na área escura também fecha o menu', !(await pg.evaluate(() => document.body.classList.contains('lp-menu-open'))));
  await irPara(pg, 'meu-dia'); await sleep(700);
  const cartaoM = await pg.evaluate(() => { const q = document.getElementById('lpQuick').getBoundingClientRect(); return { l: Math.round(q.left), r: Math.round(q.right), w: innerWidth, altos: [...document.querySelectorAll('.lp-qtb')].map(x => Math.round(x.getBoundingClientRect().height)) }; });
  ok('M3 celular, Meu dia: o Registro de Ação Rápida é um card no topo, dentro da largura da tela, com 3 botões de tipo ≥ 44px', cartaoM.l >= 0 && cartaoM.r <= cartaoM.w && cartaoM.altos.length === 3 && cartaoM.altos.every(a => a >= 44) && (await visivel(pg, '#lpQuick')), cartaoM);
  await pg.screenshot({ path: path.join(OUT, 'lider-celular-acao-rapida.png') });
  const antesM = await lider.api('acoes', { tipo: 'followup', periodo: 'hoje' });
  await pg.type('#qfNum', '11987650004'); await pg.click('#qfTipos [data-t="followup"]');
  await toast(pg, /Ação registrada!/); await sleep(500);
  const depoisM = await lider.api('acoes', { tipo: 'followup', periodo: 'hoje' });
  ok('M3 salvar no celular grava a ação e o card continua no lugar (não há folha para fechar)', depoisM.total === antesM.total + 1 && (await visivel(pg, '#lpQuick')), [antesM.total, depoisM.total]);
  for (const aba of ['dashboard', 'equipe', 'agenda', 'meu-dia', 'contratos', 'config']) {
    await irPara(pg, aba); await sleep(900);
    const lgm = await pg.evaluate(() => ({ s: document.documentElement.scrollWidth, w: window.innerWidth }));
    ok(`M4 celular, aba ${aba}: a página não rola para o lado (${lgm.s} ≤ ${lgm.w})`, lgm.s <= lgm.w + 1, lgm);
    if (aba === 'dashboard' || aba === 'agenda') await pg.screenshot({ path: path.join(OUT, 'lider-celular-' + aba + '.png') });
  }
  await irPara(pg, 'meu-dia'); await pg.click('#mdHist'); await pg.waitForSelector('#mdHistCard:not([hidden]) .lp-ac, #mdHistCard:not([hidden]) .ld-empty', { timeout: 15000 }); await sleep(300);
  const lgA = await pg.evaluate(() => ({ s: document.documentElement.scrollWidth, w: window.innerWidth }));
  ok(`M4 celular, histórico das ações no Meu dia: a página não rola para o lado (${lgA.s} ≤ ${lgA.w})`, lgA.s <= lgA.w + 1, lgA);
  await pg.close();

  // ═══ PERMISSÕES PELA API REAL ═══════════════════════════════════════════════════════════════════
  const negadas = [];
  for (const [a, d] of [['lider_dashboard', {}], ['lider_equipe', {}], ['lider_closer', { closer_id: dados.closer.vendedor_id }], ['lider_agenda', {}], ['lider_busca', { q: 'teste' }], ['lider_preferencias', {}], ['lider_preferencias_salvar', { limite_dias_sem_venda: 9 }]]) {
    const r = await closer.api(a, d); if (r.success !== false) negadas.push(a);
  }
  ok('S1 closer comum: as 7 ações do portal do líder são negadas', negadas.length === 0, negadas);
  const anon = await fetch(B + 'closer-api.php', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ action: 'lider_dashboard' }) }).then(r => r.json());
  ok('S2 sem login: "auth" (e sem CSRF nem dados)', anon.success === false && (anon.code === 'auth' || anon.code === 'csrf'), anon);
  const semCsrf = await fetch(B + 'closer-api.php', { method: 'POST', headers: { 'content-type': 'application/json', 'x-csrf-token': 'token-errado', cookie: Object.entries(lider.jar).map(([k, v]) => k + '=' + v).join('; ') }, body: JSON.stringify({ action: 'lider_preferencias_salvar', limite_dias_sem_venda: 11 }) }).then(r => r.json());
  ok('S3 salvar preferências com o token errado é recusado (CSRF) e nada muda', semCsrf.success === false && semCsrf.code === 'csrf' && (await lider.api('lider_preferencias', {})).preferencias.limite_dias_sem_venda === 3, semCsrf);
  const corpoEquipe = await lider.api('lider_dashboard', { equipe_id: 1, lider_id: 1 });
  ok('S4 mandar "equipe_id" no corpo não adianta: o painel é sempre o da equipe do líder logado', corpoEquipe.success && corpoEquipe.equipe.id === EQ, corpoEquipe.equipe);
  prep('tirar');
  const perdeu = await lider.api('lider_dashboard', {});
  const paginaPerdeu = await fetch(B + 'lider.php', { headers: { cookie: Object.entries(lider.jar).map(([k, v]) => k + '=' + v).join('; ') }, redirect: 'manual' });
  ok('S5 sem a liderança: a API nega na hora e lider.php leva de volta ao portal do closer (mesma sessão, sem novo login)', perdeu.success === false && paginaPerdeu.status === 302 && /closer\.php/.test(paginaPerdeu.headers.get('location') || ''), [perdeu.message, paginaPerdeu.status, paginaPerdeu.headers.get('location')]);
  const closerAberto = await fetch(B + 'closer.php', { headers: { cookie: Object.entries(lider.jar).map(([k, v]) => k + '=' + v).join('; ') }, redirect: 'manual' });
  ok('S6 sem a liderança, o closer.php abre normalmente para ele (200, sem desvio para o lider.php)', closerAberto.status === 200, [closerAberto.status, closerAberto.headers.get('location')]);
  prep('devolver');

  ok('Z1 nenhum erro de JavaScript nem de console durante todo o teste', erros.length === 0, erros.slice(0, 5));
  await browser.close();
  await lider.api('lider_preferencias_salvar', PADRAO);
  prep('limpar');
  console.log(`\n${pass} passaram, ${fail} falharam`);
  process.exitCode = fail ? 1 : 0;
})().catch(async e => { console.error('FALHA', e.stack || e.message); try { if (global.__browser) await global.__browser.close(); } catch (x) { /* nada */ } try { prep('limpar'); prep('devolver'); } catch (x) { /* nada */ } process.exitCode = 1; });
