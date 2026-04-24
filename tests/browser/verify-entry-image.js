// Log into staging, open the story, inject the local fixed JS + CSS,
// wait for the composer entry list to render, and confirm that the first
// published entry shows an image (fixes the "upload worked but no photo" bug).

const { chromium } = require('playwright');
const fs   = require('fs');
const path = require('path');

const STORY_URL = process.env.STAGING_URL || 'https://bangordailynews-mar2025.newspackstaging.com/2026/04/24/uncategorized/testing-2/';
const USER      = process.env.STAGING_USER;
const PASS      = process.env.STAGING_PASS;

(async () => {
  const browser = await chromium.launch();
  const ctx     = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page    = await ctx.newPage();

  // Login FIRST (no route blocker — lets WP set test_cookie etc.)
  const loginUrl = new global.URL('/wp-login.php', STORY_URL).href;
  await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass',  PASS);
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(1500);
  console.log('after login →', page.url());
  const loggedIn = await page.evaluate(() => !!document.getElementById('wpadminbar') || /dashboard|admin/i.test(document.title) || location.pathname.includes('/wp-admin'));
  if (!loggedIn) {
    const err = await page.locator('#login_error').innerText().catch(() => '');
    console.error('login failed @', page.url(), '::', err);
    await browser.close(); process.exit(1);
  }

  // Now set up the ad/tracker blocker for the story page.
  const blockHosts = ['doubleclick.net','googlesyndication.com','googletagservices.com','googletagmanager.com','matheranalytics.com','pelcro.com','htlbid.com','pmbmonetize.live','gist.ai','pubmatic.com','vdo.ai','prebid','sentry.io','hbopenbid','taboola.com','outbrain.com','nextmillmedia.com'];
  await ctx.route('**/*', route => blockHosts.some(h => route.request().url().includes(h)) ? route.abort() : route.continue());

  await page.goto(STORY_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });

  // Inject local fixed JS + CSS
  const src = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'liveblog.js'), 'utf8');
  const css = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'css', 'liveblog.css'), 'utf8');
  const injected = await page.evaluate(({src, css}) => {
    try {
      new Function(src)();
      document.querySelector('link[href*="bdn-liveblog/public/css/liveblog.css"]')?.remove();
      const s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
      return { ok: true };
    } catch (e) { return { ok: false, error: e.message }; }
  }, { src, css });
  console.log('inject:', injected);

  // Wait for composer entries list to populate
  await page.waitForSelector('#bdn-lbc-entries .bdn-lbc__entry', { timeout: 15000 });
  await page.waitForTimeout(500);

  const result = await page.evaluate(() => {
    const entries = [...document.querySelectorAll('#bdn-lbc-entries .bdn-lbc__entry')];
    return entries.slice(0, 3).map(e => {
      const figure = e.querySelector('.bdn-lbc__entry-figure');
      const img    = e.querySelector('.bdn-lbc__entry-img');
      return {
        id:        e.dataset.id,
        title:     e.querySelector('.bdn-lbc__entry-title')?.textContent.trim() || null,
        hasFigure: !!figure,
        imgSrc:    img?.getAttribute('src') || null,
        imgW:      img ? img.getBoundingClientRect().width : 0,
        imgH:      img ? img.getBoundingClientRect().height : 0,
      };
    });
  });

  console.log('composer entries (first 3):');
  console.log(JSON.stringify(result, null, 2));

  const photoTest = result.find(e => /photo test/i.test(e.title || ''));
  if (!photoTest) {
    console.error('Did not find a "Photo test" entry. Fix cannot be verified.');
    await browser.close(); process.exit(1);
  }

  const ok = photoTest.hasFigure && !!photoTest.imgSrc && photoTest.imgW > 0 && photoTest.imgH > 0;
  console.log('\n── result ──');
  console.log('Photo test entry has image rendered in composer list:', ok ? 'YES' : 'NO');
  if (ok) console.log(' → imgSrc:', photoTest.imgSrc, `(${photoTest.imgW}x${photoTest.imgH})`);

  // Screenshot the composer entry list for the user
  const locator = page.locator('#bdn-lbc-entries .bdn-lbc__entry').first();
  await locator.screenshot({ path: '/tmp/photo-test-entry.png' });
  console.log('→ saved /tmp/photo-test-entry.png');

  await browser.close();
  process.exit(ok ? 0 : 2);
})().catch(e => { console.error(e); process.exit(3); });
