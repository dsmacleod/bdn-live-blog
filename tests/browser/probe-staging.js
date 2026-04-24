// Log into staging, open the story, click "Add photo", select an image,
// and capture everything needed to diagnose the missing primary button.
//
// Run: node tests/browser/probe-staging.js
//
// NOTE: credentials are passed via env so they don't get committed.
//   STAGING_USER, STAGING_PASS, STAGING_URL

const { chromium } = require('playwright');
const fs   = require('fs');
const path = require('path');

const STORY_URL = process.env.STAGING_URL  || 'https://bangordailynews-mar2025.newspackstaging.com/2026/04/24/uncategorized/testing-2/';
const USER      = process.env.STAGING_USER || 'editor';
const PASS      = process.env.STAGING_PASS || '';

if (!PASS) { console.error('STAGING_PASS not set'); process.exit(2); }

const loginUrl = new global.URL('/wp-login.php', STORY_URL).href;

(async () => {
  const browser = await chromium.launch({ headless: true });
  const ctx     = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page    = await ctx.newPage();

  page.on('console', msg => {
    const text = msg.text();
    if (text.includes('BDN') || text.includes('liveblog') || text.includes('bdn-lb')) {
      console.log(`[page.${msg.type()}]`, text);
    }
  });
  // ALL pageerrors — catches our script's errors (we had filtered them out).
  page.on('pageerror', err => console.log('[pageerror]', err.message));

  // Track requests to our plugin — if the script ran, it should call /wp-json/bdn-liveblog/v1/status + /entries on load.
  const pluginRequests = [];
  page.on('request', req => {
    const u = req.url();
    if (u.includes('bdn-liveblog')) pluginRequests.push({ method: req.method(), url: u });
  });
  global.pluginRequests = pluginRequests;

  console.log('→ Logging in at', loginUrl);
  await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass',  PASS);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('#wp-submit'),
  ]);

  // Verify login success
  const loggedIn = await page.evaluate(() => !!document.getElementById('wpadminbar') || /dashboard|admin/i.test(document.title));
  console.log('→ Logged in:', loggedIn);
  if (!loggedIn) {
    const err = await page.locator('#login_error').innerText().catch(() => '');
    console.error('Login appears to have failed. Error:', err || '(none)');
    await browser.close();
    process.exit(1);
  }

  console.log('→ Navigating to story', STORY_URL);
  // Block third-party ad/tracker domains so the page can settle.
  const blockHosts = [
    'doubleclick.net','googlesyndication.com','googletagservices.com',
    'googletagmanager.com','matheranalytics.com','pelcro.com','htlbid.com',
    'pmbmonetize.live','gist.ai','pubmatic.com','vdo.ai','prebid','sentry.io',
    'hbopenbid','taboola.com','outbrain.com','nextmillmedia.com'
  ];
  await ctx.route('**/*', (route) => {
    const u = route.request().url();
    if (blockHosts.some(h => u.includes(h))) return route.abort();
    return route.continue();
  });
  await page.goto(STORY_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });

  // INJECT THE LOCAL FIXED SCRIPT to prove the fix works against real staging,
  // before the user uploads. Because staging's deployed liveblog.js is broken
  // and the IIFE never runs, no side effects are in play — we just re-execute
  // with the patched source against the same page/env.
  const localSrc = fs.readFileSync(
    path.join(__dirname, '..', '..', 'public', 'js', 'liveblog.js'),
    'utf8'
  );
  const localCss = fs.readFileSync(
    path.join(__dirname, '..', '..', 'public', 'css', 'liveblog.css'),
    'utf8'
  );
  const injectResult = await page.evaluate(({ src, css }) => {
    try {
      new Function(src)();
      // Replace the page's liveblog.css <link> with our local version so the
      // .search-form override ships with this test. Also append a <style>
      // block as belt-and-braces so cascade order matches production.
      const existing = document.querySelector('link[href*="bdn-liveblog/public/css/liveblog.css"]');
      if (existing) existing.remove();
      const tag = document.createElement('style');
      tag.id = 'bdn-liveblog-local-css';
      tag.textContent = css;
      document.head.appendChild(tag);
      return { ok: true };
    } catch (e) {
      return { ok: false, error: e.message, stack: e.stack };
    }
  }, { src: localSrc, css: localCss });
  console.log('→ injected local fixed script + CSS:', JSON.stringify(injectResult));

  try {
    await page.waitForSelector('#bdn-lbc-photo-btn', { timeout: 20000 });
  } catch {
    console.log('→ composer did not render within 20s');
  }

  // The composer is bdn-lbc-photo-btn.
  const photoBtn = page.locator('#bdn-lbc-photo-btn');
  const count    = await photoBtn.count();
  console.log('→ photo button present:', count);
  if (count === 0) {
    const diag = await page.evaluate(() => {
      const root = document.getElementById('bdn-lb-composer-root');
      return {
        hasRoot:         !!root,
        rootClasses:     root?.className,
        rootChildren:    root?.children.length,
        rootInnerLen:    root?.innerHTML.length,
        bdnLbLocalized:  !!window.BDN_LB,
        canEdit:         window.BDN_LB?.can_edit,
        hasWp:           !!window.wp,
        hasWpMedia:      !!(window.wp && window.wp.media),
        hasJQuery:       !!window.jQuery,
        scriptTag:       !!document.querySelector('script[src*="bdn-liveblog/public/js/liveblog.js"]'),
      };
    });
    console.log('→ diag:', JSON.stringify(diag, null, 2));
    console.log('→ plugin HTTP requests observed:', pluginRequests.length);
    for (const r of pluginRequests.slice(0, 10)) console.log('   ', r.method, r.url);

    // Try fetching our JS file directly to make sure staging is serving the version we think.
    const jsProbe = await page.evaluate(async () => {
      const r = await fetch('/wp-content/plugins/bdn-liveblog/public/js/liveblog.js?ver=1.2.4', { cache: 'no-store' });
      const text = await r.text();
      return {
        status:       r.status,
        length:       text.length,
        hasMountLine: text.includes("getElementById('bdn-lb-composer-root')"),
        hasBindCall:  text.includes('bindComposer()'),
        hasDiagBlock: text.includes("[BDN Liveblog] media modal diagnostics"),
        firstChars:   text.slice(0, 120),
        lastChars:    text.slice(-120),
      };
    });
    console.log('→ JS file probe:', JSON.stringify(jsProbe, null, 2));

    // Re-run the whole plugin script in a try/catch to surface any error it threw.
    const rerun = await page.evaluate(async () => {
      try {
        const r    = await fetch('/wp-content/plugins/bdn-liveblog/public/js/liveblog.js?ver=1.2.4', { cache: 'no-store' });
        const src  = await r.text();
        // Wrap in try so we capture the error
        try {
          // eslint-disable-next-line no-new-func
          new Function(src)();
          return { ok: true, note: 'Script executed without throwing on replay', rootChildren: document.getElementById('bdn-lb-composer-root').children.length };
        } catch (e) {
          return { ok: false, error: e.message, stack: e.stack };
        }
      } catch (e) {
        return { ok: false, fetchError: e.message };
      }
    });
    console.log('→ script replay result:', JSON.stringify(rerun, null, 2));

    const html = await page.content();
    fs.writeFileSync('/tmp/staging-story.html', html);
    console.log('saved HTML to /tmp/staging-story.html');
    await browser.close();
    process.exit(1);
  }

  // Check wp.media availability before clicking
  const preClick = await page.evaluate(() => ({
    hasWp:       !!window.wp,
    hasWpMedia:  !!(window.wp && window.wp.media),
    hasJQuery:   !!window.jQuery,
    bdnLB:       !!window.BDN_LB,
    canEdit:     window.BDN_LB?.can_edit,
  }));
  console.log('→ pre-click globals:', preClick);

  console.log('→ Clicking Add photo...');
  await photoBtn.scrollIntoViewIfNeeded();
  await photoBtn.click();

  // Wait for modal
  try {
    await page.waitForSelector('.media-modal', { state: 'attached', timeout: 5000 });
  } catch {
    console.log('→ .media-modal did not appear within 5s');
    await browser.close();
    process.exit(1);
  }

  // Give the modal time to fully render its library + toolbar
  await page.waitForTimeout(1500);

  console.log('→ Modal is open. Capturing initial state...');
  const snap1 = await snapshot(page);
  console.log('── SNAPSHOT 1 (modal just opened, no selection) ──');
  console.log(JSON.stringify(snap1, null, 2));

  // Screenshot BEFORE selection
  await page.screenshot({ path: '/tmp/staging-modal-1-open.png', fullPage: false });

  // Try to select the first image in the library
  const libItem = page.locator('.media-frame-content .attachment').first();
  const libCount = await libItem.count();
  console.log('→ attachments visible in library:', libCount);

  if (libCount > 0) {
    await libItem.click();
    await page.waitForTimeout(800);

    const snap2 = await snapshot(page);
    console.log('── SNAPSHOT 2 (after selecting first image) ──');
    console.log(JSON.stringify(snap2, null, 2));
    await page.screenshot({ path: '/tmp/staging-modal-2-selected.png', fullPage: false });
  }

  // Save full rendered DOM for inspection
  const modalHtml = await page.evaluate(() => document.querySelector('.media-modal')?.outerHTML || '(no modal)');
  fs.writeFileSync('/tmp/staging-modal.html', modalHtml);
  console.log('→ Wrote modal outerHTML to /tmp/staging-modal.html (' + modalHtml.length + ' bytes)');

  // Save list of all stylesheets in play so we know what overrides exist
  const sheets = await page.evaluate(() => {
    return [...document.styleSheets].map(s => ({
      href: s.href,
      ownerTag: s.ownerNode?.tagName,
      rulesCount: (() => { try { return s.cssRules?.length || 0; } catch { return 'crossorigin'; } })(),
    }));
  });
  fs.writeFileSync('/tmp/staging-stylesheets.json', JSON.stringify(sheets, null, 2));
  console.log('→ Stylesheets:', sheets.length, '(saved to /tmp/staging-stylesheets.json)');

  await browser.close();
})().catch(e => { console.error(e); process.exit(3); });

async function snapshot(page) {
  return page.evaluate(() => {
    const q       = s => document.querySelector(s);
    const rect    = el => el ? el.getBoundingClientRect().toJSON() : null;
    const cs      = el => {
      if (!el) return null;
      const s = getComputedStyle(el);
      const pick = ['display','visibility','position','top','right','bottom','left',
                    'width','height','zIndex','transform','overflow','opacity','float'];
      const out = {};
      for (const k of pick) out[k] = s[k];
      return out;
    };

    const modal    = q('.media-modal');
    const frame    = q('.media-frame');
    const content  = q('.media-frame-content');
    const toolbar  = q('.media-frame-toolbar');
    const primary  = q('.media-frame-toolbar .media-button-select, .media-frame-toolbar .media-button.button-primary, .media-frame-toolbar button.media-button');
    const attachments = document.querySelectorAll('.attachments-browser .attachment').length;

    // Walk up to find any ancestor with a transform (which breaks fixed positioning)
    let transformed = null;
    for (let el = modal?.parentElement; el; el = el.parentElement) {
      const t = getComputedStyle(el).transform;
      if (t && t !== 'none') { transformed = el.tagName + (el.id ? '#' + el.id : '') + '.' + (el.className || ''); break; }
    }

    // Whether the button (if any) is actually within the viewport and on top
    let btnInViewport = null, topElAtBtnCenter = null, btnFullyVisible = null;
    if (primary) {
      const r  = primary.getBoundingClientRect();
      const vw = innerWidth, vh = innerHeight;
      btnInViewport = (r.width > 0 && r.height > 0 &&
                       r.x >= 0 && r.y >= 0 &&
                       r.x + r.width <= vw && r.y + r.height <= vh);
      btnFullyVisible = primary.offsetParent !== null;
      const cx = r.x + r.width/2, cy = r.y + r.height/2;
      const topEl = document.elementFromPoint(cx, cy);
      topElAtBtnCenter = topEl ? (topEl.tagName + (topEl.id ? '#' + topEl.id : '') + '.' + (topEl.className || ''))
                               : null;
    }

    return {
      viewport:     { w: innerWidth, h: innerHeight },
      modal:        { present: !!modal,   rect: rect(modal),   css: cs(modal), classes: modal?.className },
      frame:        { present: !!frame,   rect: rect(frame),   css: cs(frame), classes: frame?.className, mode: frame?.dataset?.mode },
      content:      { present: !!content, rect: rect(content), css: cs(content) },
      toolbar:      { present: !!toolbar, rect: rect(toolbar), css: cs(toolbar) },
      primaryBtn:   {
        present: !!primary,
        text:    primary?.textContent?.trim(),
        rect:    rect(primary),
        css:     cs(primary),
        disabled: primary?.disabled,
        offsetParent: primary?.offsetParent ? primary.offsetParent.tagName + '.' + (primary.offsetParent.className || '') : null,
      },
      attachments,
      transformedAncestor: transformed,
      btnInViewport,
      btnFullyVisible,
      topElAtBtnCenter,
    };
  });
}
