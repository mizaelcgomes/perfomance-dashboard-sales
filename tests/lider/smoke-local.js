// smoke-local.js — repete, sem navegador, o passo a passo do tests\lider\testar-local.ps1: login REAL no portal do closer (porta 8787,
// sistema atual) e chamada ao núcleo (porta 8788) com o mesmo cookie de sessão. Só local; usa as contas de teste do preparar-local.php.
//   .\tests\lider\testar-local.ps1        (prepara o banco e sobe a 8788)   e depois   node tests\lider\smoke-local.js
const path = require('path'), cp = require('child_process');
const E2E = path.join(process.env.LOCALAPPDATA, 'dash-vendas-testdb', 'e2e');
const { Client, resetRate } = require(path.join(E2E, 'portal-helpers'));
const NUCLEO = 'http://127.0.0.1:8788', SENHA = 'senha1234';
const PREP = path.join(__dirname, 'preparar-local.php');
let pass = 0, fail = 0;
const ok = (nome, cond, extra) => { cond ? pass++ : fail++; console.log((cond ? 'PASS ' : 'FAIL ') + nome + (!cond && extra !== undefined ? '  → ' + JSON.stringify(extra).slice(0, 300) : '')); };
const prep = modo => JSON.parse(cp.execFileSync('C:\\php\\php.exe', ['-d', 'extension_dir=C:\\php\\ext', '-d', 'extension=pdo_mysql', PREP, modo], { encoding: 'utf8' }));
const cookie = c => Object.entries(c.jar).map(([k, v]) => k + '=' + v).join('; ');
const escopo = async c => { const r = await fetch(NUCLEO + '/api/lider/escopo', { headers: c ? { cookie: cookie(c) } : {}, redirect: 'manual' }); return { status: r.status, json: await r.json().catch(() => null) }; };

(async () => {
  try { await fetch(NUCLEO + '/_nao-existe'); } catch (e) { console.error('O núcleo (8788) não está no ar: rode .\\tests\\lider\\testar-local.ps1 antes.'); process.exitCode = 2; return; }
  const dados = prep('preparar');
  resetRate();
  ok('S1 sem login nenhum: 401 "Não autenticado"', await escopo(null).then(r => r.status === 401 && r.json.message === 'Não autenticado'));

  const lider = Client(); const l = await lider.login('lider.teste@teste.com', SENHA);
  ok('S2 o líder de teste entra no portal (login real pela 8787)', l.success === true, l);
  const e1 = await escopo(lider);
  ok(`S3 logado como líder, a 8788 reconhece a mesma sessão: 200 e a equipe dele (${dados.equipe_id})`, e1.status === 200 && e1.json.success === true && e1.json.equipe_id === dados.equipe_id, e1);

  const closer = Client(); const c = await closer.login('closer.teste@teste.com', SENHA);
  ok('S4 o closer de teste entra no portal', c.success === true, c);
  const e2 = await escopo(closer);
  ok('S5 logado como closer comum: 403 "Acesso restrito a líderes de equipe."', e2.status === 403 && /restrito a líderes/.test(e2.json.message), e2);

  prep('tirar');
  const e3 = await escopo(lider);
  ok('S6 liderança removida: o MESMO líder, com a MESMA sessão, passa a receber 403 na requisição seguinte', e3.status === 403, e3);
  const portal = await fetch('http://127.0.0.1:8787/closer-api.php', { method: 'POST', headers: { 'content-type': 'application/json', cookie: cookie(lider), 'x-csrf-token': lider.csrf }, body: JSON.stringify({ action: 'resumo' }) }).then(r => r.json());
  ok('S7 e o portal do closer continua funcionando para ele (a conta segue ativa)', portal.success === true, portal);

  prep('devolver');
  const e4 = await escopo(lider);
  ok('S8 liderança devolvida: volta a dar 200 sem novo login', e4.status === 200 && e4.json.equipe_id === dados.equipe_id, e4);

  console.log(`\n${pass} passaram, ${fail} falharam`);
  process.exitCode = fail ? 1 : 0;
})().catch(e => { console.error('FALHA', e.stack || e.message); process.exitCode = 1; });
