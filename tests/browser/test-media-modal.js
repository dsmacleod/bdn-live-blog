// Browser test: open the WP media modal on a simulated story page with
// Newspack-like hostile theme CSS and verify the "Use this photo" primary
// button is VISIBLE, WITHIN THE VIEWPORT, and CLICKABLE.
//
// We test two states:
//   (A) liveblog.css fix applied   → button must be reachable
//   (B) liveblog.css fix REMOVED  → reproduces the staging bug
//
// Run:  node tests/browser/test-media-modal.js

const { chromium } = require('playwright');
const http         = require('http');
const fs           = require('fs');
const path         = require('path');

const ROOT = __dirname;

// Serve the plugin CSS, the WP core CSS (downloaded earlier), and the fixture HTML.
function startServer() {
  const files = {
    '/liveblog.css':    { path: '/tmp/liveblog.css',                       type: 'text/css' },
    '/media-views.css': { path: '/tmp/media-views.css',                    type: 'text/css' },
    '/':                { path: path.join(ROOT, 'fixture.html'),           type: 'text/html' },
    '/fixture.html':    { path: path.join(ROOT, 'fixture.html'),           type: 'text/html' },
  };
  const server = http.createServer((req, res) => {
    const url = req.url.split('?')[0];
    const f   = files[url];
    if (!f) { res.writeHead(404); res.end('not found'); return; }
    res.writeHead(200, { 'Content-Type': f.type });
    res.end(fs.readFileSync(f.path));
  });
  return new Promise((resolve) => {
    server.listen(0, () => resolve({ server, port: server.address().port }));
  });
}

// Swap the plugin CSS file served by our test server between the FIXED version
// (the real file on disk) and a STRIPPED version that deletes everything
// after "── WordPress media modal overrides (front-end) ──" — i.e. removes
// exactly the block we added to fix the bug. This lets us test both states
// against the same fixture without editing the real source.
function setCssState(state) {
  const real   = fs.readFileSync(path.join(ROOT, '..', '..', 'public', 'css', 'liveblog.css'), 'utf8');
  const marker = '/* ── WordPress media modal overrides (front-end) ──';
  const hasFix = real.includes(marker);
  if (state === 'fixed') {
    fs.writeFileSync('/tmp/liveblog.css', real);
    return { hasFix: true };
  }
  // 'unfixed': strip our modal-override block if present; otherwise the file
  // already represents the baseline.
  if (hasFix) {
    fs.writeFileSync('/tmp/liveblog.css', real.slice(0, real.indexOf(marker)));
  } else {
    fs.writeFileSync('/tmp/liveblog.css', real);
  }
  return { hasFix };
}

async function runOnce({ page, port, viewport, label }) {
  await page.setViewportSize(viewport);
  await page.goto(`http://localhost:${port}/`, { waitUntil: 'networkidle' });
  await page.click('#open-picker');
  // Wait for selection-enable timer in fixture.
  await page.waitForFunction(
    () => !document.querySelector('.media-button-select').disabled
  );

  const diag = await page.evaluate(() => {
    const btn  = document.querySelector('.media-button-select');
    const bar  = document.querySelector('.media-frame-toolbar');
    const modal = document.querySelector('.media-modal');
    if (!btn || !bar || !modal) return { present: false };

    const btnRect = btn.getBoundingClientRect();
    const barRect = bar.getBoundingClientRect();
    const style   = getComputedStyle(bar);
    const btnStyle = getComputedStyle(btn);

    return {
      present:          true,
      btn:              { x: btnRect.x, y: btnRect.y, w: btnRect.width, h: btnRect.height },
      bar:              { x: barRect.x, y: barRect.y, w: barRect.width, h: barRect.height },
      barDisplay:       style.display,
      barVisibility:    style.visibility,
      barPosition:      style.position,
      barBottom:        style.bottom,
      btnDisplay:       btnStyle.display,
      btnVisibility:    btnStyle.visibility,
      vpW:              window.innerWidth,
      vpH:              window.innerHeight,
    };
  });

  // Is the button inside the viewport?
  const inViewport =
    diag.present &&
    diag.btn.w > 0 && diag.btn.h > 0 &&
    diag.btn.x >= 0 && diag.btn.y >= 0 &&
    diag.btn.x + diag.btn.w <= diag.vpW &&
    diag.btn.y + diag.btn.h <= diag.vpH;

  // Can Playwright actually click it without timing out / being intercepted?
  let clickable = false, clickErr = null;
  try {
    await page.click('.media-button-select', { timeout: 2000, trial: true });
    clickable = true;
  } catch (e) { clickErr = e.message.split('\n')[0]; }

  // Collect stacking-context winner at button center: is something covering it?
  const topElAtBtnCenter = await page.evaluate(() => {
    const btn = document.querySelector('.media-button-select');
    const r   = btn.getBoundingClientRect();
    const top = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
    return top ? (top.className || top.tagName) + ' | sameAsBtn=' + (top === btn || btn.contains(top)) : null;
  });

  return { label, inViewport, clickable, clickErr, topElAtBtnCenter, diag };
}

function pretty(r) {
  console.log(`── ${r.label} ──`);
  if (!r.diag.present) { console.log('  button not in DOM'); return; }
  console.log(`  button rect:     x=${r.diag.btn.x.toFixed(0)} y=${r.diag.btn.y.toFixed(0)} w=${r.diag.btn.w.toFixed(0)} h=${r.diag.btn.h.toFixed(0)}`);
  console.log(`  viewport:        ${r.diag.vpW}x${r.diag.vpH}`);
  console.log(`  toolbar:         display=${r.diag.barDisplay} visibility=${r.diag.barVisibility} position=${r.diag.barPosition} bottom=${r.diag.barBottom}`);
  console.log(`  topmost @ btn:   ${r.topElAtBtnCenter}`);
  console.log(`  inViewport:      ${r.inViewport ? 'YES' : 'NO'}`);
  console.log(`  clickable:       ${r.clickable ? 'YES' : 'NO' + (r.clickErr ? ` (${r.clickErr})` : '')}`);
}

(async () => {
  const { server, port } = await startServer();
  const browser = await chromium.launch();
  const ctx     = await browser.newContext();
  const page    = await ctx.newPage();

  const viewports = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'laptop',  width: 1280, height: 720 },
    { name: 'mobile',  width:  390, height: 720 },
  ];

  const results = [];
  const { hasFix } = setCssState('fixed'); // inspect current file state once

  const statesToRun = hasFix ? ['unfixed', 'fixed'] : ['baseline'];
  for (const state of statesToRun) {
    setCssState(state === 'baseline' ? 'unfixed' : state);
    for (const vp of viewports) {
      const r = await runOnce({ page, port, viewport: { width: vp.width, height: vp.height }, label: `${state.toUpperCase()} @ ${vp.name} ${vp.width}x${vp.height}` });
      pretty(r);
      results.push({ state, vp: vp.name, ...r });
    }
  }

  setCssState('fixed'); // restore /tmp copy

  await browser.close();
  server.close();

  console.log('\n═════════════════════════════════════════════════════════');
  if (hasFix) {
    const unfixedAnyFail = results.some(r => r.state === 'unfixed' && (!r.inViewport || !r.clickable));
    const fixedAllPass   = results.filter(r => r.state === 'fixed').every(r => r.inViewport && r.clickable);
    console.log('Bug reproduced without fix: ' + (unfixedAnyFail ? 'YES' : 'NO — bug did not manifest'));
    console.log('All viewports pass with fix: ' + (fixedAllPass   ? 'YES' : 'NO'));
    process.exit(unfixedAnyFail && fixedAllPass ? 0 : 1);
  } else {
    const baselineAllPass = results.every(r => r.inViewport && r.clickable);
    console.log('No CSS fix currently in liveblog.css — ran BASELINE only.');
    console.log('Baseline (plain core + hostile theme CSS) passes all viewports: ' + (baselineAllPass ? 'YES' : 'NO'));
    console.log('Interpretation: if baseline PASSES but staging fails, the staging');
    console.log('bug is NOT generic CSS — it is either Newspack-specific CSS not in');
    console.log('this fixture, or a JS/dependency issue (wp.media state, missing');
    console.log('enqueue). Use the in-plugin console diagnostics on staging.');
    process.exit(baselineAllPass ? 0 : 1);
  }
})().catch(err => { console.error(err); process.exit(2); });
