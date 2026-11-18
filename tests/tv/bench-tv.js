// bench-tv.js — desempenho da TV (index.php) no ambiente LOCAL: quadros/s e % de thread ocupada, com a CPU "estrangulada" (TV fraca).
//   node -r <e2e>\patch-launch.js tests\tv\bench-tv.js <rótulo>     (ex.: antes | depois) → grava tests\tv\medicao-<rótulo>.json
// Método (o mesmo antes e depois, para a comparação valer): para cada cena e cada estrangulamento da CPU (1×, 4×, 8×), abre a TV
// do zero (viewport 1920×1080), espera a cena estabilizar, mede 6 s com requestAnimationFrame (quadros/s) e com o
// Performance.getMetrics do Chrome (TaskDuration = tempo ocupado da thread principal). Repete 3 vezes e guarda a MEDIANA.
// Cenas: "semanal" (painel normal) e "retro_semana" (Hall da Fama). Guarda e restaura a rotação do banco de teste.
const fs = require('fs'), path = require('path');
const E2E = path.join(process.env.LOCALAPPDATA, 'dash-vendas-testdb', 'e2e');
const puppeteer = require(path.join(E2E, 'node_modules', 'puppeteer-core'));
const { B, EDGE, admin } = require(path.join(E2E, 'portal-helpers'));
const ROTULO = process.argv[2] || 'antes';
const URL_TV = process.argv[3] || 'index.php';
const sleep = ms => new Promise(r => setTimeout(r, ms));
const mediana = a => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
const post = o => admin(new URLSearchParams(o).toString(), true);

const CENAS = [
  { nome: 'semanal', rot: [{ type: 'podium', mode: 'semanal', duration: 300 }], espera: '#podiumStage .tcg-card' },
  { nome: 'retro_semana', rot: [{ type: 'podium', mode: 'retro_semana', duration: 300 }], espera: '#hall.on.show' },
];
const ESTRANGULAMENTOS = [1, 4, 8];
const REPETICOES = 3, JANELA_MS = 6000;

async function medir(browser, cena, taxa) {
  const page = await browser.newPage();
  await page.setViewport({ width: 1920, height: 1080 });
  const cdp = await page.createCDPSession();
  await cdp.send('Performance.enable');
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: taxa });
  const erros = []; page.on('pageerror', e => erros.push(e.message));
  await page.goto(B + URL_TV, { waitUntil: 'domcontentloaded', timeout: 90000 });
  await page.waitForSelector(cena.espera, { timeout: 60000 });
  await sleep(4000);   // entrada animada e primeiras buscas terminam antes da janela de medição
  const m0 = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map(x => [x.name, x.value]));
  const t0 = Date.now();
  const raf = await page.evaluate(ms => new Promise(res => { let n = 0, ini = null; function f(t) { if (ini === null) ini = t; n++; if (t - ini < ms) requestAnimationFrame(f); else res({ n, ms: t - ini }); } requestAnimationFrame(f); }), JANELA_MS);
  const dt = (Date.now() - t0) / 1000;
  const m1 = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map(x => [x.name, x.value]));
  const d = k => (m1[k] - m0[k]) / dt * 100;
  await page.close();
  return { fps: raf.n / (raf.ms / 1000), ocupada: d('TaskDuration'), script: d('ScriptDuration'), layout: d('LayoutDuration'), estilo: d('RecalcStyleDuration'), erros };
}

(async () => {
  const rotOriginal = (await admin('action=get_rotation')).data || [];
  const out = { rotulo: ROTULO, url: URL_TV, quando: new Date().toISOString(), repeticoes: REPETICOES, janela_ms: JANELA_MS, cenas: {} };
  let browser;
  try {
    browser = await puppeteer.launch({ executablePath: EDGE, headless: true });
    for (const cena of CENAS) {
      await post({ action: 'save_rotation', rotation: JSON.stringify(cena.rot) });
      out.cenas[cena.nome] = {};
      for (const taxa of ESTRANGULAMENTOS) {
        const rs = [];
        for (let i = 0; i < REPETICOES; i++) rs.push(await medir(browser, cena, taxa));
        const k = n => +mediana(rs.map(r => r[n])).toFixed(1);
        out.cenas[cena.nome][taxa + 'x'] = { fps: k('fps'), ocupada_pct: k('ocupada'), script_pct: k('script'), layout_pct: k('layout'), estilo_pct: k('estilo'), erros_js: rs.reduce((s, r) => s + r.erros.length, 0), amostras_fps: rs.map(r => +r.fps.toFixed(1)) };
        console.log(`${cena.nome.padEnd(13)} CPU ${String(taxa).padStart(1)}x  ${out.cenas[cena.nome][taxa + 'x'].fps} quadros/s  thread ocupada ${out.cenas[cena.nome][taxa + 'x'].ocupada_pct}%  (script ${out.cenas[cena.nome][taxa + 'x'].script_pct}% · layout ${out.cenas[cena.nome][taxa + 'x'].layout_pct}% · estilo ${out.cenas[cena.nome][taxa + 'x'].estilo_pct}%)  amostras ${out.cenas[cena.nome][taxa + 'x'].amostras_fps}`);
      }
    }
  } finally {
    try { await post({ action: 'save_rotation', rotation: JSON.stringify(rotOriginal) }); } catch (e) { console.log('não consegui restaurar a rotação: ' + e.message); }
    if (browser) await browser.close();
  }
  const arq = path.join(__dirname, 'medicao-' + ROTULO + '.json');
  fs.writeFileSync(arq, JSON.stringify(out, null, 1) + '\n');
  console.log('gravado em ' + arq);
  process.exitCode = 0;
})().catch(e => { console.error('FALHA', e.stack || e.message); process.exitCode = 1; });
