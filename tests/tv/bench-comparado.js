// bench-comparado.js — compara a TV ANTIGA e a NOVA medindo-as ALTERNADAMENTE, no mesmo navegador e com o mesmo banco, para a oscilação
// da máquina (que passa de 10 pontos entre rodadas seguidas) afetar as duas versões do mesmo jeito.
//   node -r <e2e>\patch-launch.js tests\tv\bench-comparado.js [rodadas=8] [taxa-de-CPU=8] [cena=semanal|retro_semana]
// Precisa das duas versões no ar: a antiga em 127.0.0.1:8790 (tests\golden\base-antiga.ps1 criar, ANTES de mexer no código) e a nova em 8787.
// Mesmo método do bench-tv.js: viewport 1920×1080, 4 s de espera, janela de 6 s, quadros por requestAnimationFrame e TaskDuration do Chrome.
const fs = require('fs'), path = require('path');
const E2E = path.join(process.env.LOCALAPPDATA, 'dash-vendas-testdb', 'e2e');
const puppeteer = require(path.join(E2E, 'node_modules', 'puppeteer-core'));
const { EDGE, admin } = require(path.join(E2E, 'portal-helpers'));
const RODADAS = +(process.argv[2] || 8), TAXA = +(process.argv[3] || 8), CENA = process.argv[4] || 'semanal';
const ANTIGA = 'http://127.0.0.1:8790/', NOVA = 'http://127.0.0.1:8787/';
const sleep = ms => new Promise(r => setTimeout(r, ms));
const mediana = a => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
const media = a => a.reduce((s, x) => s + x, 0) / a.length;
const ROT = { semanal: [{ type: 'podium', mode: 'semanal', duration: 300 }], retro_semana: [{ type: 'podium', mode: 'retro_semana', duration: 300 }] };
const ESPERA = { semanal: '#podiumStage .tcg-card', retro_semana: '#hall.on.show' };

async function medir(browser, base) {
  const page = await browser.newPage();
  await page.setViewport({ width: 1920, height: 1080 });
  const cdp = await page.createCDPSession();
  await cdp.send('Performance.enable');
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: TAXA });
  await page.goto(base + 'index.php', { waitUntil: 'domcontentloaded', timeout: 90000 });
  await page.waitForSelector(ESPERA[CENA], { timeout: 60000 });
  await sleep(4000);
  const m0 = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map(x => [x.name, x.value]));
  const t0 = Date.now();
  const raf = await page.evaluate(ms => new Promise(res => { let n = 0, ini = null; function f(t) { if (ini === null) ini = t; n++; if (t - ini < ms) requestAnimationFrame(f); else res({ n, ms: t - ini }); } requestAnimationFrame(f); }), 6000);
  const dt = (Date.now() - t0) / 1000;
  const m1 = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map(x => [x.name, x.value]));
  await page.close();
  return { fps: raf.n / (raf.ms / 1000), ocupada: (m1.TaskDuration - m0.TaskDuration) / dt * 100 };
}

(async () => {
  const rotOriginal = (await admin('action=get_rotation')).data || [];
  const post = o => admin(new URLSearchParams(o).toString(), true);
  let browser; const R = { antiga: [], nova: [] };
  try {
    await post({ action: 'save_rotation', rotation: JSON.stringify(ROT[CENA]) });
    browser = await puppeteer.launch({ executablePath: EDGE, headless: true });
    for (let i = 0; i < RODADAS; i++) {
      const ordem = i % 2 === 0 ? ['antiga', 'nova'] : ['nova', 'antiga'];   // alterna quem vai primeiro
      for (const v of ordem) R[v].push(await medir(browser, v === 'antiga' ? ANTIGA : NOVA));
      console.log(`rodada ${i + 1}: antiga ${R.antiga[i].ocupada.toFixed(1)}% (${R.antiga[i].fps.toFixed(1)} q/s) · nova ${R.nova[i].ocupada.toFixed(1)}% (${R.nova[i].fps.toFixed(1)} q/s)`);
    }
  } finally {
    try { await post({ action: 'save_rotation', rotation: JSON.stringify(rotOriginal) }); } catch (e) { console.log('não consegui restaurar a rotação: ' + e.message); }
    if (browser) await browser.close();
  }
  const resumo = v => ({ ocupada_mediana: +mediana(R[v].map(r => r.ocupada)).toFixed(1), ocupada_media: +media(R[v].map(r => r.ocupada)).toFixed(1), ocupada_min: +Math.min(...R[v].map(r => r.ocupada)).toFixed(1), ocupada_max: +Math.max(...R[v].map(r => r.ocupada)).toFixed(1), fps_min: +Math.min(...R[v].map(r => r.fps)).toFixed(1) });
  const out = { cena: CENA, cpu: TAXA + 'x', rodadas: RODADAS, antiga: resumo('antiga'), nova: resumo('nova'), amostras: R };
  console.log('\nANTIGA', JSON.stringify(out.antiga)); console.log('NOVA  ', JSON.stringify(out.nova));
  fs.writeFileSync(path.join(__dirname, `medicao-comparado-${CENA}-${TAXA}x.json`), JSON.stringify(out, null, 1) + '\n');
  process.exitCode = 0;
})().catch(e => { console.error('FALHA', e.stack || e.message); process.exitCode = 1; });
