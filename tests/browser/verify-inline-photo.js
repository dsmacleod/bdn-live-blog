// Reproduce + diagnose the "Inline photo not working" report. Drives the
// composer on live staging, clicks Inline photo, attaches a test PNG,
// clicks Insert, and captures what (if anything) ends up in the editor.

const { chromium } = require('playwright');
const fs   = require('fs');
const path = require('path');

const STORY_URL = process.env.STAGING_URL || 'https://bangordailynews-mar2025.newspackstaging.com/2026/04/24/uncategorized/testing-2/';
const USER      = process.env.STAGING_USER;
const PASS      = process.env.STAGING_PASS;

// Tiny 1x1 PNG for testing.
const PNG_1x1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');
const pngPath = '/tmp/bdn-inline-test.png';
fs.writeFileSync(pngPath, PNG_1x1);

(async () => {
  const browser = await chromium.launch();
  const ctx     = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page    = await ctx.newPage();

  const loginUrl = new global.URL('/wp-login.php', STORY_URL).href;
  await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass',  PASS);
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1200);

  // Now apply tracker blocker for the story page.
  const blockHosts = ['doubleclick.net','googlesyndication.com','googletagservices.com','googletagmanager.com','matheranalytics.com','pelcro.com','htlbid.com','pmbmonetize.live','gist.ai','pubmatic.com','vdo.ai','prebid','sentry.io','hbopenbid','taboola.com','outbrain.com','nextmillmedia.com'];
  await ctx.route('**/*', r => blockHosts.some(h => r.request().url().includes(h)) ? r.abort() : r.continue());

  // Forward meaningful console for our plugin.
  page.on('console', msg => {
    const t = msg.text();
    if (t.includes('BDN') || t.includes('liveblog') || t.includes('bdn-lb')) {
      console.log(`[page.${msg.type()}]`, t);
    }
  });
  page.on('pageerror', err => {
    if (!/pelcro|mather|getByIP|bangordailynews\.com/i.test(err.message)) {
      console.log('[pageerror]', err.message);
    }
  });

  await page.goto(STORY_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });

  // Inject local fixed JS + CSS
  const src = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'liveblog.js'), 'utf8');
  const css = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'css', 'liveblog.css'), 'utf8');
  await page.evaluate(({src, css}) => {
    new Function(src)();
    document.querySelector('link[href*="bdn-liveblog/public/css/liveblog.css"]')?.remove();
    const s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
  }, { src, css });

  await page.waitForSelector('#bdn-lbc-tb-img', { timeout: 15000 });

  console.log('\n── Step 1: focus editor and type something ──');
  await page.click('#bdn-lbc-content');
  await page.keyboard.type('Before image. ');
  const after1 = await page.evaluate(() => document.getElementById('bdn-lbc-content').innerHTML);
  console.log('editor html:', JSON.stringify(after1));

  console.log('\n── Step 2: click Inline photo toolbar button ──');
  await page.click('#bdn-lbc-tb-img');
  const barOpen = await page.evaluate(() => {
    const bar = document.getElementById('bdn-lbc-img-bar');
    return { display: getComputedStyle(bar).display, inlineDisplay: bar.style.display };
  });
  console.log('img bar state:', barOpen);

  console.log('\n── Step 3: attach file to <input type=file> ──');
  await page.setInputFiles('#bdn-lbc-inline-file', pngPath);
  const fileState = await page.evaluate(() => {
    const i = document.getElementById('bdn-lbc-inline-file');
    return { hasFiles: i.files.length, firstName: i.files[0]?.name, firstSize: i.files[0]?.size };
  });
  console.log('file input state:', fileState);

  console.log('\n── Step 4: click Insert ──');
  await page.click('#bdn-lbc-img-insert');
  // FileReader is async; give it a beat.
  await page.waitForTimeout(800);

  const after2 = await page.evaluate(() => {
    const content = document.getElementById('bdn-lbc-content');
    const imgs = content.querySelectorAll('img');
    return {
      innerHTML:     content.innerHTML,
      imgCount:      imgs.length,
      imgFirstSrcPrefix: imgs[0]?.src.slice(0, 64),
      imgBarDisplay: document.getElementById('bdn-lbc-img-bar').style.display,
    };
  });
  console.log('after insert →');
  console.log('  imgCount:',       after2.imgCount);
  console.log('  img bar display:', after2.imgBarDisplay);
  console.log('  src prefix:',     after2.imgFirstSrcPrefix);
  console.log('  editor html:',    JSON.stringify(after2.innerHTML).slice(0, 500));

  console.log('\n── Step 5: diff-probe both media endpoints ──');
  const probes = await page.evaluate(async () => {
    const NONCE = window.BDN_LB.nonce;
    const REST  = window.BDN_LB.rest_url;
    const dataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    async function probe(url) {
      const blob = await (await fetch(dataUrl)).blob();
      const form = new FormData();
      form.append('file', blob, 'probe.png');
      const r = await fetch(url, { method: 'POST', headers: { 'X-WP-Nonce': NONCE }, body: form });
      const text = await r.text();
      return { url, status: r.status, body: text.slice(0, 300) };
    }
    return {
      core:   await probe('/wp-json/wp/v2/media'),
      plugin: await probe(REST + 'upload-inline-image'),
    };
  });
  console.log('core  /wp/v2/media           →', probes.core.status,   probes.core.body);
  console.log('plugin /bdn-liveblog/...     →', probes.plugin.status, probes.plugin.body);

  console.log('\n── Step 6: click Publish entry ──');
  const uploads = [];
  page.on('response', async res => {
    const u = res.url();
    if (u.includes('/wp-json/wp/v2/media') || u.includes('bdn-liveblog/v1/entries')) {
      let body = '';
      try { body = (await res.text()).slice(0, 400); } catch {}
      uploads.push({ url: u, status: res.status(), method: res.request().method(), body });
    }
  });
  // Handle the confirm() dialog if inline upload fails
  page.on('dialog', async d => { console.log('[dialog]', d.type(), d.message()); await d.dismiss().catch(()=>{}); });

  // Fill in required fields
  await page.fill('#bdn-lbc-byline', 'Inline Test');
  await page.click('#bdn-lbc-submit');
  await page.waitForTimeout(4000);

  console.log('\n── network activity during publish ──');
  for (const u of uploads) {
    console.log(u.method, u.status, u.url);
    if (u.status >= 400) console.log('  body:', u.body);
  }

  // After publish the entry list should include our new entry. Grab its content.
  const published = await page.evaluate(() => {
    const list = [...document.querySelectorAll('#bdn-lbc-entries .bdn-lbc__entry')];
    const first = list[0];
    const imgs  = first?.querySelectorAll('.bdn-lbc__entry-body img') || [];
    return first ? {
      id:     first.dataset.id,
      body:   first.querySelector('.bdn-lbc__entry-body')?.innerHTML,
      imgCount: imgs.length,
      imgSrcs: [...imgs].map(i => i.src.slice(0, 100)),
    } : null;
  });
  console.log('\n── published entry (top of list) ──');
  console.log(JSON.stringify(published, null, 2));

  const insertedOk = after2.imgCount > 0 && (after2.imgFirstSrcPrefix || '').startsWith('data:image/');
  const publishedWithImage = published && published.imgCount > 0 && (published.imgSrcs[0] || '').startsWith('http');

  console.log('\n── RESULT ──');
  console.log('1. Inline photo inserted into editor:   ', insertedOk ? 'YES' : 'NO');
  console.log('2. Published entry contains the image:  ', publishedWithImage ? 'YES' : 'NO');

  await browser.close();
  process.exit((insertedOk && publishedWithImage) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(3); });
