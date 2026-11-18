// e2e-registros.js — as atividades da liderança no NAVEGADOR (Edge headless): reunião, feedback, cobrança, revisão e treinamento nascem em "+ Nova atividade" da Agenda
// do portal do líder (lider.php; não há mais seção Registros), o "Meus feedbacks" do closer (com o aviso de novo) e a sub-aba "Atividades dos líderes" do admin. Só local (127.0.0.1:8787, banco de teste). Os dois usuários
// de teste vêm do preparar-local.php (líder e closer da "EQUIPE LÍDER TESTE"); o teste começa e termina com os registros dessa equipe apagados.
//   cd %LOCALAPPDATA%\dash-vendas-testdb\e2e ; node -r .\patch-launch.js C:\...\tests\lider\e2e-registros.js <pasta-de-capturas>
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
const ok = (nome, cond, extra) => { cond ? pass++ : fail++; console.log((cond ? 'PASS ' : 'FAIL ') + nome + (!cond && extra !== undefined ? '  → ' + JSON.stringify(extra).slice(0, 400) : '')); };
const prep = modo => JSON.parse(cp.execFileSync('C:\\php\\php.exe', ['-d', 'extension_dir=C:\\php\\ext', '-d', 'extension=pdo_mysql', PREP, modo], { encoding: 'utf8' }));
const soma = (iso, dias) => new Date(Date.parse(iso + 'T12:00:00Z') + dias * 864e5).toISOString().slice(0, 10);

(async () => {
  resetRate();
  const dados = prep('preparar'); prep('limpar');
  const EQ = dados.equipe_id;
  const lider = Client(); const l = await lider.login('lider.teste@teste.com', SENHA);
  const closer = Client(); const c = await closer.login('closer.teste@teste.com', SENHA);
  if (!l.success || !c.success) throw new Error('login de teste falhou: ' + JSON.stringify([l, c]));
  const cookiesDe = cli => Object.entries(cli.jar).map(([name, value]) => ({ name, value, domain: '127.0.0.1', path: '/' }));
  const hoje = (await admin('action=get_painel')).week.today, amanha = soma(hoje, 1), ontem = soma(hoje, -1);

  const browser = (global.__browser = await puppeteer.launch({ executablePath: EDGE, headless: true }));
  const erros = [];
  const nova = async (cli, largura) => {
    const pg = await browser.newPage();
    await pg.setViewport({ width: largura || 1400, height: 1000 });
    pg.on('pageerror', e => erros.push('PAGEERROR: ' + e.message));
    pg.on('console', m => { if (m.type() === 'error' && !/pravatar|fonts\.g|net::ERR|Failed to load resource|40[34]|41[9]/.test(m.text())) erros.push('CONSOLE: ' + m.text()); });
    if (cli) await pg.setCookie(...cookiesDe(cli));
    return pg;
  };
  // tudo pela Agenda: "+ Nova atividade" escolhe a atividade e abre o formulário; o painel do dia e as próximas atividades têm os cartões e os botões
  const noAgenda = async pg => {
    if (await pg.$eval('#pane-agenda', e => e.hidden)) { if (await pg.evaluate(() => window.innerWidth <= 960)) await pg.click('#lpBurger'); await pg.click('.lp-nav-b[data-tab="agenda"]'); await sleep(300); }
    await pg.waitForSelector('#agCal .lp-dia', { timeout: 20000 });
  };
  const CAMPO_DO_FORM = { reunioes: '#f_titulo', feedbacks: '#f_closer_id', cobrancas: '#f_closer_id', revisoes: '#f_telefone', treinamentos: '#f_assunto' };
  const novaAtividade = async (pg, ent) => {
    await noAgenda(pg);
    await pg.click('#agNovo');                                              // clique de verdade: o foco vai para o botão, e é para ele que o Esc devolve
    await pg.waitForSelector('dialog.ld-modal[open] input[name="tipo"]', { timeout: 8000 });
    await pg.click(`dialog.ld-modal[open] input[value="${ent}"]`);
    await pg.click('dialog.ld-modal[open] button[type="submit"]');          // o formulário abre logo depois: não dá para esperar "nenhum modal aberto"
    await pg.waitForSelector('dialog.ld-modal[open] ' + CAMPO_DO_FORM[ent], { timeout: 15000 });
  };
  const itens = async ent => (await lider.api('lider_registros', { entidade: ent })).itens;
  const abrirDia = async (pg, iso) => {
    await noAgenda(pg);
    if (iso.slice(0, 7) !== hoje.slice(0, 7)) { const t = await pg.$eval('#agTitulo', e => e.textContent); await pg.$eval(iso < hoje ? '#agPrev' : '#agNext', e => e.click()); await pg.waitForFunction(x => document.getElementById('agTitulo').textContent !== x, { timeout: 15000 }, t); }
    await pg.waitForSelector(`#agCal [data-dia="${iso}"]`, { timeout: 15000 });
    await pg.$eval(`#agCal [data-dia="${iso}"]`, e => e.click());
    await pg.waitForSelector('dialog.ld-drawer[open] .lp-evc', { timeout: 8000 });
  };
  const voltaHoje = async pg => { if (await pg.$('dialog.ld-drawer[open]')) { await pg.keyboard.press('Escape'); await sleep(300); } await pg.$eval('#agHoje', e => e.click()); await sleep(500); };
  const lerCartoes = (pg, area) => pg.$$eval(area + ' .lp-evc', c => c.map(x => ({ chave: x.dataset.chave, h: x.querySelector('h4').textContent.trim(), txt: x.textContent.replace(/\s+/g, ' ').trim(), botoes: [...x.querySelectorAll('.lp-evc-a button')].map(b => b.textContent.trim()) })));
  const doDia = pg => lerCartoes(pg, 'dialog.ld-drawer[open]');
  const proximas = pg => lerCartoes(pg, '#agProx');
  const modalAberto = pg => pg.$('dialog.ld-modal[open]').then(Boolean);
  const campo = (pg, id, valor) => pg.$eval('#' + id, (e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, valor);
  const enviar = async pg => {
    await pg.click('dialog.ld-modal[open] button[type="submit"]');
    await pg.waitForFunction(() => !document.querySelector('dialog.ld-modal[open]') || document.querySelector('dialog.ld-modal[open] .ld-err:not(:empty), dialog.ld-modal[open] [data-topo]:not([hidden])'), { timeout: 12000 });
    await sleep(250);
  };
  const erroDoCampo = (pg, nome) => pg.$eval(`dialog.ld-modal[open] [data-erro-de="${nome}"]`, e => e.textContent.trim()).catch(() => null);
  const aguardaFechar = pg => pg.waitForFunction(() => !document.querySelector('dialog.ld-modal[open]'), { timeout: 12000 });
  const contraste = (pg, sel) => pg.evaluate(s => {
    const el = document.querySelector(s); if (!el) return null;
    const [r, g, b, a = 1] = getComputedStyle(el).color.match(/[\d.]+/g).map(Number), bg = [19, 19, 19];
    const mix = [r, g, b].map((v, i) => v * a + bg[i] * (1 - a));
    const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); };
    const lum = x => .2126 * f(x[0]) + .7152 * f(x[1]) + .0722 * f(x[2]);
    const L1 = lum(mix), L2 = lum(bg); return (Math.max(L1, L2) + .05) / (Math.min(L1, L2) + .05);
  }, sel);

  // ═══ PORTAL: o líder ═══════════════════════════════════════════════════════════════════════════
  let pg = await nova(lider);
  await pg.goto(B + 'closer.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  ok('R0 o líder que abre o portal do closer é levado ao portal do líder (lider.php)', /\/lider(?:[#?]|$)/.test(pg.url()), pg.url());
  await pg.waitForSelector('#lpNome', { timeout: 20000 });
  await noAgenda(pg);
  const menu = await pg.$$eval('.lp-nav-b span', s => s.map(x => x.textContent.trim()));
  ok('R1 não há seção Registros: o menu tem Dashboard, Equipe, Agenda, Meu dia, Meus contratos e Configurações, e as atividades nascem em "+ Nova atividade" da Agenda', menu.join('|') === 'Dashboard|Equipe|Agenda|Meu dia|Meus contratos|Configurações' && (await pg.$eval('#agNovo', e => e.textContent.trim())) === '+ Nova atividade', menu);
  const vazio = await pg.$eval('#agProx', e => e.textContent.replace(/\s+/g, ' ').trim());
  ok('R2 sem atividades: o estado vazio explica o que fazer, com o botão de criar', /Nada nos próximos 7 dias/.test(vazio) && /Nova atividade/.test(vazio), vazio);
  await pg.screenshot({ path: path.join(OUT, 'atividades-vazio.png') });

  // reuniões: criar, validar, realizar, cancelar
  await novaAtividade(pg, 'reunioes');
  ok('R3 "Nova atividade" › Reunião abre um modal com título e horário', await modalAberto(pg) && await pg.$('#f_titulo') !== null && await pg.$('#f_data_hora_marcada') !== null);
  await enviar(pg);
  ok('R4 enviar vazio: o erro aparece ao lado do campo, o campo fica inválido e o modal continua aberto', await modalAberto(pg) && /título/i.test(await erroDoCampo(pg, 'titulo') || '') && await pg.$eval('#f_titulo', e => e.getAttribute('aria-invalid')) === 'true');
  await pg.keyboard.press('Escape'); await aguardaFechar(pg);
  const focoVolta = await pg.evaluate(() => document.activeElement && document.activeElement.id);
  ok('R5 Esc fecha o modal e o foco volta para o botão "+ Nova atividade"', focoVolta === 'agNovo', focoVolta);
  for (const [titulo, quando] of [['Alinhamento da semana', amanha + 'T12:00'], ['Reunião de ontem', ontem + 'T10:00']]) {
    await novaAtividade(pg, 'reunioes');
    await campo(pg, 'f_titulo', titulo); await campo(pg, 'f_data_hora_marcada', quando);
    await enviar(pg); await aguardaFechar(pg);
  }
  await sleep(700);
  let reu = await itens('reunioes');
  const cardAmanha = (await proximas(pg)).find(c => /Alinhamento da semana/.test(c.h));
  ok('R6 as duas reuniões entram como "Agendada"; a de amanhã aparece nas próximas atividades com as ações de uma reunião agendada', reu.length === 2 && reu.every(r => r.status === 'agendada') && !!cardAmanha && ['Registrar como realizada', 'Editar', 'Cancelar', 'Adicionar nota'].every(b => cardAmanha.botoes.includes(b)), [reu.map(r => r.status), cardAmanha]);
  const ontemReu = reu.find(r => r.titulo === 'Reunião de ontem');
  await abrirDia(pg, ontem);
  await pg.$eval(`dialog.ld-drawer[open] .lp-evc[data-chave="reuniao:${ontemReu.id}"] [data-ev="realizar"]`, e => e.click());
  await pg.waitForSelector('dialog.ld-modal[open] #f_data_hora_realizada', { timeout: 10000 });
  await campo(pg, 'f_data_hora_realizada', ontem + 'T09:00');
  await enviar(pg);
  ok('R7 horário realizado antes do marcado é recusado, com a mensagem ao lado do campo', await modalAberto(pg) && /antes do horário marcado/.test(await erroDoCampo(pg, 'data_hora_realizada') || ''), await erroDoCampo(pg, 'data_hora_realizada'));
  await campo(pg, 'f_data_hora_realizada', ontem + 'T10:30');
  await enviar(pg); await aguardaFechar(pg); await sleep(700);
  reu = await itens('reunioes');
  const feita = reu.find(r => r.titulo === 'Reunião de ontem');
  await abrirDia(pg, ontem);
  const cFeita = (await doDia(pg)).find(c => /Reunião de ontem/.test(c.h));
  ok('R8 realizada: a reunião vira "Realizada" (horário realizado gravado) e o cartão deixa só "Editar" e "Adicionar nota"', feita.status === 'realizada' && String(feita.data_hora_realizada).slice(0, 16).replace(' ', 'T') === ontem + 'T10:30' && /Realizada/.test(cFeita.txt) && cFeita.botoes.join('|') === 'Editar|Adicionar nota', [feita, cFeita]);
  await voltaHoje(pg);
  const alin = reu.find(r => r.titulo === 'Alinhamento da semana');
  await pg.$eval(`#agProx .lp-evc[data-chave="reuniao:${alin.id}"] [data-ev="cancelar"]`, e => e.click());
  await pg.waitForSelector('dialog.ld-modal[open]', { timeout: 8000 });
  ok('R9 cancelar pede confirmação antes', await modalAberto(pg) && /Alinhamento da semana.*não pode mais ser alterado/.test(await pg.$eval('dialog.ld-modal[open]', e => e.textContent)));
  await enviar(pg); await aguardaFechar(pg);
  await pg.waitForFunction(id => !document.querySelector(`#agProx [data-chave="reuniao:${id}"]`), { timeout: 15000 }, alin.id);
  reu = await itens('reunioes');
  await abrirDia(pg, amanha);
  const cCancelada = (await doDia(pg)).find(c => /Alinhamento da semana/.test(c.h));
  ok('R10 cancelada: a reunião vira "Cancelada", sai das próximas atividades e o cartão não oferece mais nenhuma ação', reu.find(r => r.titulo === 'Alinhamento da semana').status === 'cancelada' && /Cancelada/.test(cCancelada.txt) && cCancelada.botoes.length === 0, cCancelada);
  await voltaHoje(pg);
  await pg.screenshot({ path: path.join(OUT, 'atividades-reunioes.png') });

  // feedbacks
  await novaAtividade(pg, 'feedbacks');
  const opcoes = await pg.$$eval('#f_closer_id option', o => o.map(x => x.textContent.trim()));
  ok('R11 o menu de closer traz só os closers da equipe, sem o próprio líder', opcoes.join('|') === 'Escolha o closer|CLOSER TESTE', opcoes);
  await enviar(pg);
  ok('R12 sem escolher o closer nem escrever nada: erros nos campos certos', /closer/i.test(await erroDoCampo(pg, 'closer_id') || '') && await modalAberto(pg), await erroDoCampo(pg, 'closer_id'));
  await pg.select('#f_closer_id', String(dados.closer.vendedor_id));
  await campo(pg, 'f_assunto', 'Ritmo de ligações'); await campo(pg, 'f_texto', 'Você precisa aumentar o ritmo de ligações pela manhã.');
  ok('R13 o campo de texto mostra o contador de caracteres', /\d+ \/ 2000/.test(await pg.$eval('[data-conta="texto"]', e => e.textContent)));
  await enviar(pg); await aguardaFechar(pg); await sleep(700);
  let fb = await itens('feedbacks');
  await abrirDia(pg, hoje);
  const cFb = (await doDia(pg)).find(c => c.chave === 'feedback:' + fb[0].id);
  ok('R14 o feedback entra no painel do dia (só a data, sem horário) com o closer, o texto e "Ainda não visto"', fb.length === 1 && fb[0].assunto === 'Ritmo de ligações' && !!cFb && /Closer: CLOSER TESTE/.test(cFb.txt) && /Ainda não visto/.test(cFb.txt) && /aumentar o ritmo/.test(cFb.txt) && cFb.botoes.join('|') === 'Editar', [fb, cFb]);
  await pg.$eval(`dialog.ld-drawer[open] .lp-evc[data-chave="feedback:${fb[0].id}"] [data-ev="editar"]`, e => e.click());
  await pg.waitForSelector('dialog.ld-modal[open] #f_texto', { timeout: 10000 });
  ok('R15 ao editar, o closer e a data aparecem só para leitura (não mudam)', await pg.$eval('dialog.ld-modal[open]', e => e.querySelectorAll('input[readonly]').length >= 2));
  await campo(pg, 'f_texto', 'Texto revisado: ligações pela manhã e follow-up à tarde.');
  await enviar(pg); await aguardaFechar(pg); await sleep(700);
  fb = await itens('feedbacks');
  await abrirDia(pg, hoje);
  const cFb2 = (await doDia(pg)).find(c => c.chave === 'feedback:' + fb[0].id);
  ok('R16 editar muda o texto e marca a edição (atualizado_em), e o cartão do dia mostra o texto novo', /Texto revisado/.test(fb[0].texto) && !!fb[0].atualizado_em && /Texto revisado/.test(cFb2.txt), [fb[0], cFb2]);
  await voltaHoje(pg);

  // cobranças
  await novaAtividade(pg, 'cobrancas');
  await pg.select('#f_closer_id', String(dados.closer.vendedor_id));
  await enviar(pg); await aguardaFechar(pg); await sleep(700);
  const cb = await itens('cobrancas');
  await abrirDia(pg, hoje);
  const cCb = (await doDia(pg)).find(c => c.chave === 'cobranca:' + cb[0].id);
  ok('R17 cobrança de cadastramento registrada sem observação (ela é opcional): cartão "Cobrança de cadastramento" com o closer', cb.length === 1 && cb[0].observacao === '' && !!cCb && /Cobrança de cadastramento/.test(cCb.h) && /Closer: CLOSER TESTE/.test(cCb.txt), [cb, cCb]);
  await voltaHoje(pg);

  // revisões
  await novaAtividade(pg, 'revisoes');
  await pg.select('#f_closer_id', String(dados.closer.vendedor_id));
  await pg.type('#f_telefone', '123');
  await enviar(pg);
  ok('R18 telefone inválido é recusado com a mensagem do portal, ao lado do campo', /Telefone inválido/.test(await erroDoCampo(pg, 'telefone') || ''), await erroDoCampo(pg, 'telefone'));
  await pg.$eval('#f_telefone', e => { e.value = ''; });
  await pg.type('#f_telefone', '11987654321');
  ok('R19 o telefone é mascarado enquanto digita: (11) 98765-4321', (await pg.$eval('#f_telefone', e => e.value)) === '(11) 98765-4321');
  await campo(pg, 'f_observacao', 'Cliente pediu retorno na sexta.');
  await enviar(pg); await aguardaFechar(pg); await sleep(700);
  const rv = await itens('revisoes');
  await abrirDia(pg, hoje);
  const cRv = (await doDia(pg)).find(c => c.chave === 'revisao:' + rv[0].id);
  ok('R20 revisão registrada com o telefone formatado e a observação (cartão "Revisão de atendimento")', rv.length === 1 && rv[0].telefone_fmt === '(11) 98765-4321' && !!cRv && /\(11\) 98765-4321/.test(cRv.txt) && /retorno na sexta/.test(cRv.txt), [rv, cRv]);
  await voltaHoje(pg);

  // treinamentos e presença
  await novaAtividade(pg, 'treinamentos');
  await campo(pg, 'f_assunto', 'Objeções de preço'); await campo(pg, 'f_data_hora', amanha + 'T15:00');
  await enviar(pg); await aguardaFechar(pg); await sleep(700);
  let tr = await itens('treinamentos');
  const cTr = (await proximas(pg)).find(c => /Objeções de preço/.test(c.h));
  ok('R21 treinamento registrado, sem presença ainda, nas próximas atividades com "Marcar presença"', tr.length === 1 && tr[0].presentes.length === 0 && !!cTr && cTr.botoes.includes('Marcar presença'), [tr, cTr]);
  await pg.$eval(`#agProx .lp-evc[data-chave="treinamento:${tr[0].id}"] [data-ev="presenca"]`, e => e.click());
  await pg.waitForSelector('dialog.ld-modal[open] .ld-check', { timeout: 10000 });
  const checks = await pg.$$eval('dialog.ld-modal[open] .ld-check span', s => s.map(x => x.textContent.replace(/\s+/g, ' ').trim()));
  ok('R22 a lista de presença traz toda a equipe, e o líder aparece como "(você)"', checks.length === 2 && checks.some(x => /LÍDER TESTE \(você\)/.test(x)) && checks.some(x => /CLOSER TESTE/.test(x)), checks);
  for (const ch of await pg.$$('dialog.ld-modal[open] input[name="presentes"]')) await ch.click();
  await enviar(pg); await aguardaFechar(pg); await sleep(700);
  tr = await itens('treinamentos');
  ok('R23 presença salva: os 2 da equipe constam como presentes', tr[0].presentes.length === 2, tr[0]);

  // sem exclusão e contraste
  await abrirDia(pg, hoje);
  const botoes = (await pg.$$eval('#pane-agenda button, dialog.ld-drawer[open] button', b => b.map(x => x.textContent))).concat(await pg.$$eval('#agProx .lp-evc button', b => b.map(x => x.textContent)));
  ok('R24 na Agenda (calendário, próximas atividades e painel do dia) não existe botão de excluir, apagar ou remover', !botoes.some(t => /exclu|apag|remov|delet/i.test(t)), botoes);
  const ratios = {};
  for (const sel of ['#agProx .lp-evc-m', '#agProx .lp-evc h4', '.lp-legenda span', 'dialog.ld-drawer[open] .lp-evc-t', 'dialog.ld-drawer[open] .lp-evc-m']) ratios[sel.split(' ').slice(-2).join(' ')] = Math.round(((await contraste(pg, sel)) || 0) * 10) / 10;
  ok('R27 contraste do texto na Agenda e nos cartões das atividades: pelo menos 4,5:1 (' + JSON.stringify(ratios) + ')', Object.values(ratios).every(r => r >= 4.5), ratios);
  await pg.screenshot({ path: path.join(OUT, 'atividades-dia.png') });
  await voltaHoje(pg);

  // ═══ PORTAL: o closer vê e é avisado ═══════════════════════════════════════════════════════════
  const antes = await closer.api('meus_feedbacks_novos');
  ok('R28 o closer tem 2 avisos novos (o feedback e a cobrança) antes de abrir o portal', antes.success && antes.novos === 2, antes);
  const pc = await nova(closer);
  const c0 = await closer.api('portal_interacoes_contagem');      // feedback + cobrança + revisão ainda não vistos (a revisão também conta no portal novo)
  await pc.goto(B + 'closer.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await pc.waitForSelector('#mdMeta', { timeout: 20000 });
  await pc.waitForFunction(n => { const b = document.querySelector('[data-badge="interacoes"]'); return b && !b.hidden && b.textContent === String(n); }, { timeout: 20000 }, c0.total);
  await pc.click('#nav-interacoes'); await pc.waitForSelector('#inLista .cp-int', { timeout: 20000 }); await sleep(500);
  const fbs = await pc.$$eval('#inLista .cp-int', c => c.map(x => ({ h: x.querySelector('.cp-int-t').textContent.trim(), novo: x.classList.contains('novo'), txt: x.textContent.replace(/\s+/g, ' ').trim() })));
  await pc.click('#inAbas [data-tipo="cobranca"]'); await sleep(900);
  const cbs = await pc.$$eval('#inLista .cp-int', c => c.map(x => ({ h: x.querySelector('.cp-int-t').textContent.trim(), novo: x.classList.contains('novo'), txt: x.textContent.replace(/\s+/g, ' ').trim() })));
  ok('R29 "Minhas interações": o menu avisa os novos (feedback + cobrança + revisão); o feedback e a cobrança aparecem destacados como novos e com o nome do líder', fbs.length === 1 && cbs.length === 1 && fbs[0].novo && cbs[0].novo && /LÍDER TESTE/.test(fbs[0].txt + cbs[0].txt) && fbs[0].h === 'Ritmo de ligações' && /Cobrança de cadastramento/.test(cbs[0].h), { fbs, cbs });
  await pc.click('#inAbas [data-tipo="feedback"]'); await sleep(900);
  await pc.click('#inLista .cp-int-h'); await sleep(500);
  const textoFb = await pc.$eval('#inLista .cp-int-x', e => e.textContent);
  ok('R30 o texto revisado pelo líder é o que o closer lê (ao abrir o item)', /Texto revisado/.test(textoFb), textoFb);
  await pc.screenshot({ path: path.join(OUT, 'closer-minhas-interacoes.png') });
  await sleep(600);
  const depois1 = await closer.api('meus_feedbacks_novos');
  ok('R31 abrir um item marca SÓ aquele como visto no servidor (sobra 1 novo: a cobrança) e o contador do menu cai', depois1.novos === 1 && await pc.$eval('[data-badge="interacoes"]', (e, n) => e.textContent === String(n), c0.total - 1), depois1);
  ok('R32 nesta visita o item aberto passa a mostrar "Lido"', /Lido/.test(await pc.$eval('#inLista .cp-int', e => e.textContent)));
  await pc.click('#inAbas [data-tipo="cobranca"]'); await sleep(900);
  await pc.click('#inLista .cp-int-h'); await sleep(700);
  const depois2 = await closer.api('meus_feedbacks_novos');
  ok('R33 abrindo a cobrança, feedback e cobrança estão vistos (novos = 0) e o contador do menu cai (sobra a revisão, que ainda não foi aberta)', depois2.novos === 0 && await pc.$eval('[data-badge="interacoes"]', (e, n) => (n === 0 ? e.hidden : e.textContent === String(n)), c0.total - 2), depois2);
  await pc.reload({ waitUntil: 'domcontentloaded' });
  await pc.waitForSelector('#mdMeta', { timeout: 20000 }); await sleep(900);
  ok('R33b ao recarregar o portal, o contador do menu continua só com o que não foi aberto', await pc.$eval('[data-badge="interacoes"]', (e, n) => (n === 0 ? e.hidden : e.textContent === String(n)), c0.total - 2));
  const pCloser = await pc.$('#ltSec'), urlCloser = pc.url();
  ok('R34 o closer comum fica no portal do closer: sem "Meu time", sem telas de registros e sem ser levado ao portal do líder', pCloser === null && /closer\.php/.test(urlCloser) && !/lider\.php/.test(urlCloser), urlCloser);
  const pc2 = await nova(closer); await pc2.goto(B + 'lider.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  ok('R34 e se o closer comum abrir lider.php na mão, volta para o portal do closer', /\/closer(?:[#?]|$)/.test(pc2.url()), pc2.url());
  await pc2.close();
  await pc.close();
  // o líder edita o feedback: o closer volta a ser avisado
  const itensFb = (await lider.api('lider_registros', { entidade: 'feedbacks' })).itens;
  await lider.api('lider_registro_salvar', { entidade: 'feedbacks', id: itensFb[0].id, assunto: 'Ritmo de ligações', texto: 'Texto revisado de novo.' });
  const reaviso = await closer.api('meus_feedbacks_novos');
  ok('R35 quando o líder edita o feedback, o closer volta a ter 1 aviso', reaviso.novos === 1, reaviso);
  const lista2 = await closer.api('meus_feedbacks');
  ok('R36 e o item aparece como "atualizado" e novo', lista2.itens.some(i => i.tipo === 'feedback' && i.novo && i.atualizado), lista2.itens);

  // ═══ isolamento e CSRF pela API real ═══════════════════════════════════════════════════════════
  const painelAdm = await admin('action=get_painel');
  const deOutraEquipe = painelAdm.closers.find(x => x.equipe_id && x.equipe_id !== EQ);
  const fora = await lider.api('lider_registro_salvar', { entidade: 'feedbacks', closer_id: deOutraEquipe.id, assunto: 'Invasão', texto: 'X' });
  ok('R37 o líder não consegue registrar feedback para closer de OUTRA equipe', fora.success === false && fora.errors && fora.errors.closer_id, fora);
  const invasao = await lider.api('lider_registro_salvar', { entidade: 'reunioes', titulo: 'Na equipe alheia', data_hora_marcada: amanha + 'T10:00', equipe_id: 2 });
  const reunioesLider = await lider.api('lider_registros', { entidade: 'reunioes' });
  ok('R38 mandar "equipe_id" no corpo não adianta: a reunião vai para a equipe do líder', invasao.success && invasao.item && reunioesLider.itens.some(r => r.titulo === 'Na equipe alheia'), invasao);
  const negado = await closer.api('lider_registros', { entidade: 'feedbacks' }), negado2 = await closer.api('lider_registro_salvar', { entidade: 'feedbacks', closer_id: 1, assunto: 'x', texto: 'y' });
  ok('R39 o closer comum não lista nem cria registros de liderança (negado nas duas ações)', negado.success === false && negado2.success === false, [negado, negado2]);
  const semCsrf = await fetch(B + 'closer-api.php', { method: 'POST', headers: { 'content-type': 'application/json', 'x-csrf-token': 'token-errado', cookie: Object.entries(lider.jar).map(([k, v]) => k + '=' + v).join('; ') }, body: JSON.stringify({ action: 'lider_registro_salvar', entidade: 'feedbacks', closer_id: dados.closer.vendedor_id, assunto: 'Sem token', texto: 'x' }) }).then(r => r.json());
  const total = (await lider.api('lider_registros', { entidade: 'feedbacks' })).itens.length;
  ok('R40 escrita com o token errado é recusada (CSRF) e não grava', semCsrf.success === false && semCsrf.code === 'csrf' && total === 1, [semCsrf, total]);
  const naoEx = await lider.api('lider_registro_acao', { entidade: 'feedbacks', id: 1, acao: 'excluir' });
  ok('R41 não existe ação de excluir: o servidor responde "não encontrado" e nada some', naoEx.success === false && (await lider.api('lider_registros', { entidade: 'feedbacks' })).itens.length === 1, naoEx);
  await pg.close();

  // ═══ PORTAL no celular ═════════════════════════════════════════════════════════════════════════
  pg = await nova(lider, 390);
  await pg.goto(B + 'lider.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await pg.waitForSelector('#lpNome', { timeout: 20000 });
  await noAgenda(pg); await sleep(500);
  const lg = await pg.evaluate(() => ({ s: document.documentElement.scrollWidth, w: window.innerWidth }));
  ok(`R42 celular, Agenda: a página não rola para o lado (${lg.s} ≤ ${lg.w})`, lg.s <= lg.w + 1, lg);
  await novaAtividade(pg, 'feedbacks');
  await sleep(500);                                       // espera a animação de subida da folha terminar
  const caixa = await pg.$eval('dialog.ld-modal[open]', e => { const r = e.getBoundingClientRect(); return { l: r.left, r: r.right, b: r.bottom, h: window.innerHeight, w: window.innerWidth, alvos: [...e.querySelectorAll('button')].map(x => Math.round(x.getBoundingClientRect().height)) }; });
  ok('R43 celular: o formulário sobe como folha ocupando a largura da tela, encostada embaixo', caixa.l <= 1 && caixa.r >= caixa.w - 1 && Math.abs(caixa.b - caixa.h) <= 2, caixa);
  ok('R44 celular: os botões do formulário têm pelo menos 44px de altura (alvo de toque)', caixa.alvos.every(a => a >= 44), caixa.alvos);
  await pg.screenshot({ path: path.join(OUT, 'registros-celular-folha.png') });
  await pg.keyboard.press('Escape'); await aguardaFechar(pg);
  const stack = await pg.evaluate(() => ({ cards: document.querySelectorAll('#agProx .lp-evc').length }));
  ok('R45 celular: as atividades são cartões (sem tabela cortada)', stack.cards >= 1 && (await pg.$('#pane-agenda table')) === null, stack);
  await pg.close();

  // ═══ ADMIN: sub-aba Atividades dos líderes ═════════════════════════════════════════════════════
  pg = await nova(null);
  await pg.setCookie({ name: 'PHPSESSID', value: 'testadmin0001', domain: '127.0.0.1', path: '/' });
  // limpa a memória do navegador só na primeira carga desta aba (senão o recarregar apagaria o que o teste quer ver lembrado)
  await pg.evaluateOnNewDocument(() => { try { if (!sessionStorage.getItem('__limpo')) { localStorage.removeItem('prPer'); localStorage.removeItem('prSub'); localStorage.removeItem('sbCollapsed'); sessionStorage.setItem('__limpo', '1'); } } catch (e) { /* nada */ } });
  await pg.goto(B + 'admin.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await pg.waitForSelector('#pnKpis .pn-kpi-val:not(.pn-skel)', { timeout: 30000 }); await sleep(800);
  await pg.evaluate(() => showTab('produtividade'));
  await pg.waitForSelector('#prKpis .pr-kpi', { timeout: 20000 });
  const subs = await pg.$$eval('#prSubTabs button', b => b.map(x => x.textContent.trim()));
  ok('A1 a Produtividade ganhou as sub-abas Closers, Líderes e Atividades dos líderes', subs.join('|') === 'Closers|Líderes|Atividades dos líderes', subs);
  const k6 = await pg.$$eval('#prKpis .pr-kpi', k => k.length);
  const apiClosers = await admin('action=get_produtividade&periodo=hoje');
  const kpiTela = await pg.$eval('#prKpis .pr-kpi:nth-child(4) .pr-kpi-v', e => e.textContent.replace(/\s+/g, ''));
  ok('A2 a sub-aba "Closers" é a de sempre: mesmos 6 indicadores e os mesmos números da API antiga', k6 === 6 && kpiTela === String(apiClosers.kpis.contratos), [k6, kpiTela, apiClosers.kpis.contratos]);
  ok('A3 em "Closers" o filtro de closer aparece; nas outras sub-abas ele some (não vale para líderes)', await pg.$eval('#prCl', e => e.offsetParent !== null));
  await pg.click('#prSubTabs [data-sub="ativ"]');
  await pg.waitForSelector('#prAtivLista table tbody tr', { timeout: 20000 });
  ok('A3 em "Atividades dos líderes" o filtro de closer some e o painel dos closers fica escondido', await pg.$eval('#prCl', e => e.offsetParent === null) && await pg.$eval('#prPanelClosers', e => e.hidden));
  const ativ = await admin('action=get_atividades_lideres&periodo=hoje');
  const linhas = await pg.$$eval('#prAtivLista tbody tr', r => r.map(tr => ({ equipe: tr.dataset.equipe, txt: tr.textContent.replace(/\s+/g, ' ').trim() })));
  ok(`A4 uma linha por equipe (${ativ.equipes.length}), na ordem da API`, linhas.length === ativ.equipes.length && linhas.map(x => x.equipe).join() === ativ.equipes.map(x => String(x.equipe_id)).join(), [linhas.length, ativ.equipes.length]);
  const meu = linhas.find(x => x.equipe === String(EQ)), api7 = ativ.equipes.find(x => x.equipe_id === EQ);
  ok('A5 a equipe de teste mostra o que o líder criou hoje: 3 reuniões marcadas (1 cancelada hoje; a realizada foi ontem), 1 feedback, 1 cobrança, 1 revisão e o treinamento de amanhã fora do período (total 6)', api7.reunioes.marcadas === 3 && api7.reunioes.realizadas === 0 && api7.reunioes.canceladas === 1 && api7.feedbacks === 1 && api7.cobrancas === 1 && api7.revisoes === 1 && api7.treinamentos.dados === 0 && api7.total === 6, api7);
  ok('A5 a linha na tela traz o líder, a equipe e o total da API', /LÍDER TESTE/.test(meu.txt) && /EQUIPE LÍDER TESTE/.test(meu.txt) && (await pg.$eval(`#prAtivLista tr[data-equipe="${EQ}"] td[data-label="Total"]`, e => e.textContent.trim())) === String(api7.total), meu.txt);
  const semRegistros = ativ.equipes.filter(x => x.lider_nome && x.equipe_id !== EQ && x.ultima_atividade === null);
  ok('A6 líderes que nunca registraram nada aparecem com o alerta "Nunca registrou atividade de liderança"', semRegistros.length > 0 && semRegistros.every(x => linhas.find(l => l.equipe === String(x.equipe_id)).txt.includes('Nunca registrou atividade')), semRegistros.length);
  ok('A6 e eles vêm primeiro (sem atividade antes de quem atuou), com a equipe de teste logo depois', (() => { const ids = linhas.map(l => l.equipe); return ids.indexOf(String(EQ)) >= semRegistros.length; })(), linhas.map(l => l.equipe));
  await pg.screenshot({ path: path.join(OUT, 'admin-atividades.png'), fullPage: true });
  await pg.click(`#prAtivLista tr[data-equipe="${EQ}"]`);
  await pg.waitForSelector('dialog.ld-drawer[open] .ld-det-l li', { timeout: 15000 });
  const detalhe = await pg.$$eval('dialog.ld-drawer[open] .ld-det-l li', li => li.map(x => x.textContent.replace(/\s+/g, ' ').trim()));
  ok('A7 clicar na linha abre o painel lateral com os últimos registros do líder (7 itens, o mais recente primeiro)', detalhe.length === 7 && /Reunião|Treinamento|Revisão|Cobrança|Feedback/.test(detalhe[0]), detalhe);
  ok('A7 e mostra os tipos: reunião, feedback, cobrança, revisão e treinamento', ['Reunião', 'Feedback', 'Cobrança', 'Revisão', 'Treinamento'].every(t => detalhe.some(d => d.includes(t))), detalhe);
  await pg.screenshot({ path: path.join(OUT, 'admin-atividades-detalhe.png') });
  await pg.keyboard.press('Escape'); await sleep(300);
  const focoLinha = await pg.evaluate(() => document.activeElement && document.activeElement.dataset && document.activeElement.dataset.equipe);
  ok('A8 Esc fecha o painel e o foco volta para a linha que o abriu', !(await pg.$('dialog.ld-drawer[open]')) && focoLinha === String(EQ), focoLinha);
  await pg.focus(`#prAtivLista tr[data-equipe="${EQ}"]`); await pg.keyboard.press('Enter');
  await pg.waitForSelector('dialog.ld-drawer[open]', { timeout: 10000 });
  ok('A8 com o teclado, Enter na linha também abre o painel', true);
  await pg.keyboard.press('Escape'); await sleep(300);
  await pg.select('#prEq', String(EQ)); await sleep(1400);
  await pg.waitForFunction(() => !document.getElementById('prAtivLista').hasAttribute('aria-busy'), { timeout: 15000 });
  const so1 = await pg.$$eval('#prAtivLista tbody tr', r => r.map(x => x.dataset.equipe));
  ok('A9 o filtro de equipe da aba vale para as atividades: só a equipe escolhida', so1.length === 1 && so1[0] === String(EQ), so1);
  await pg.select('#prEq', ''); await sleep(1400);
  await pg.evaluate(() => prSetPer('mes')); await sleep(1500);
  await pg.waitForFunction(() => !document.getElementById('prAtivLista').hasAttribute('aria-busy'), { timeout: 15000 });
  const mes = await admin('action=get_atividades_lideres&periodo=mes');
  const m7 = mes.equipes.find(x => x.equipe_id === EQ);
  const txtMes = await pg.$eval(`#prAtivLista tr[data-equipe="${EQ}"] td[data-label="Total"]`, e => e.textContent.trim());
  ok('A10 trocar o período para "Mês" recarrega as atividades com o mesmo período (total ' + m7.total + ')', txtMes === String(m7.total), txtMes);
  await pg.reload({ waitUntil: 'domcontentloaded' });
  await pg.waitForSelector('#pnKpis .pn-kpi-val:not(.pn-skel)', { timeout: 30000 }); await sleep(600);
  await pg.evaluate(() => showTab('produtividade'));
  await pg.waitForSelector('#prAtivLista tbody tr', { timeout: 20000 });
  ok('A11 a sub-aba escolhida é lembrada ao recarregar a página', await pg.$eval('#prSubTabs [aria-selected="true"]', e => e.dataset.sub) === 'ativ' && !(await pg.$eval('#prPanelAtiv', e => e.hidden)));
  await pg.focus('#prSubTabs [data-sub="ativ"]'); await pg.keyboard.press('ArrowLeft');
  ok('A12 teclado nas sub-abas: seta para a esquerda vai para "Líderes" e mostra os cartões por equipe', await pg.$eval('#prSubTabs [aria-selected="true"]', e => e.dataset.sub) === 'lideres' && !!(await pg.waitForSelector('#prLidList .ld-team', { timeout: 15000 }).catch(() => null)));
  await pg.click('#prSubTabs [data-sub="closers"]');
  ok('A13 voltar para "Closers" mostra a tela de sempre e o filtro de closer de volta', !(await pg.$eval('#prPanelClosers', e => e.hidden)) && await pg.$eval('#prCl', e => e.offsetParent !== null));
  await pg.setViewport({ width: 390, height: 900 });
  await pg.click('#prSubTabs [data-sub="ativ"]'); await pg.waitForSelector('#prAtivLista tbody tr', { timeout: 15000 }); await sleep(500);
  const lm = await pg.evaluate(() => ({ s: document.documentElement.scrollWidth, w: window.innerWidth, cabecalhoVisivel: (() => { const th = document.querySelector('#prAtivLista thead'); const r = th.getBoundingClientRect(); return r.width > 2 && r.height > 2; })() }));
  ok(`A14 admin no celular: a página não rola para o lado (${lm.s} ≤ ${lm.w}) e a tabela vira cartões (cabeçalho só para leitor de tela)`, lm.s <= lm.w + 1 && !lm.cabecalhoVisivel, lm);
  await pg.screenshot({ path: path.join(OUT, 'admin-atividades-celular.png'), fullPage: true });
  await pg.close();

  ok('Z1 nenhum erro de JavaScript nem de console durante todo o teste', erros.length === 0, erros.slice(0, 5));
  await browser.close();
  prep('limpar');
  console.log(`\n${pass} passaram, ${fail} falharam`);
  process.exitCode = fail ? 1 : 0;
})().catch(async e => { console.error('FALHA', e.stack || e.message); try { if (global.__browser) await global.__browser.close(); } catch (x) { /* nada */ } try { prep('limpar'); prep('devolver'); } catch (x) { /* nada */ } process.exitCode = 1; });
