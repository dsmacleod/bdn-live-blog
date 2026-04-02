# BDN Liveblog Improvements Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix critical bugs, harden the plugin, add video embed support and highlights/key moments feature.

**Architecture:** Nine changes across PHP (API, slug, admin) and JS/CSS (liveblog.js, liveblog.css). Bug fixes are isolated per-file changes. Highlights touches all layers (meta, API, JS reader + composer, CSS). Video embeds touch JS composer + PHP render.

**Tech Stack:** WordPress 6.x, PHP 7.4+, vanilla JS (contenteditable), CSS, WP REST API, WP oEmbed proxy.

---

### Task 1: Fix XSS in composer startEdit

**Files:**
- Modify: `public/js/liveblog.js:598-601`

The `startEdit` function sets `_se.innerHTML = e.content` where `e.content` is server-rendered HTML. While `wp_kses_post` sanitizes on save, the rendered output from `render_entry_content()` could include shortcode output with event handlers. Strip dangerous attributes before insertion.

**Step 1: Add sanitizer function near the top of the IIFE (after the `esc` function, ~line 23)**

```javascript
function sanitizeHtml(html) {
  const tmp = document.createElement('div');
  tmp.innerHTML = html;
  tmp.querySelectorAll('*').forEach(el => {
    for (const attr of [...el.attributes]) {
      if (attr.name.startsWith('on') || attr.name === 'srcdoc') el.removeAttribute(attr.name);
      if (['href','src','action','formaction'].includes(attr.name) && attr.value.trim().toLowerCase().startsWith('javascript:')) el.removeAttribute(attr.name);
    }
  });
  return tmp.innerHTML;
}
```

**Step 2: Update startEdit to use sanitizer**

In `startEdit` (line 601), change:
```javascript
const _se=document.getElementById('bdn-lbc-content'); if(_se) _se.innerHTML=e.content;
```
to:
```javascript
const _se=document.getElementById('bdn-lbc-content'); if(_se) _se.innerHTML=sanitizeHtml(e.content);
```

Also sanitize the reader entry content display in `buildEntryEl` (line 112):
```javascript
<div class="bdn-lb-content">${sanitizeHtml(entry.content)}</div>
```

And in `buildComposerEntry` (line 583):
```javascript
<div class="bdn-lbc__entry-body">${sanitizeHtml(e.content)}</div>
```

**Step 3: Commit**

```bash
git add public/js/liveblog.js
git commit -m "fix: sanitize HTML content before innerHTML insertion to prevent XSS"
```

---

### Task 2: Add exponential backoff to poll failures

**Files:**
- Modify: `public/js/liveblog.js:35-73`
- Modify: `public/css/liveblog.css` (add error notice style)

**Step 1: Add CSS for connection error notice**

Append to `liveblog.css` after the `.bdn-lb-empty` block (~line 253):

```css
/* ── Connection error ─────────────────────────────────────────────────────── */
.bdn-lb-conn-error {
  font-family: var(--lb-font-ui);
  font-size: 0.75rem;
  color: var(--lb-muted);
  text-align: center;
  padding: 0.4rem 0;
  font-style: italic;
}
```

**Step 2: Add error notice element to shortcode HTML**

In `includes/class-bdn-liveblog-shortcode.php`, after the `.bdn-lb-last-updated` span inside `.bdn-lb-header` (line 31), add:

```php
<span class="bdn-lb-conn-error" style="display:none"></span>
```

**Step 3: Update JS reader widget polling with backoff**

In `liveblog.js`, replace the polling variables and functions (lines 35-73) with:

```javascript
let latestTimestamp = 0, currentPage = 1, totalPages = 1, pollTimer = null;
let pollFailures = 0;
const connErrorEl = widget.querySelector('.bdn-lb-conn-error');
```

Replace `schedulePoll` and `pollForNew` (lines 66-73):

```javascript
function schedulePoll() {
  clearTimeout(pollTimer);
  const delay = Math.min(POLL * Math.pow(2, pollFailures), 120000);
  pollTimer = setTimeout(pollForNew, delay);
}
function pollForNew() {
  api(`entries?post_id=${postId}&after=${latestTimestamp}`).then(data => {
    pollFailures = 0;
    if (connErrorEl) { connErrorEl.style.display = 'none'; connErrorEl.textContent = ''; }
    (data.entries||[]).forEach(e => { if(e.timestamp>latestTimestamp) latestTimestamp=e.timestamp; prependEntry(e); });
    updatedEl.textContent = 'Updated '+new Date().toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',hour12:true});
    fetchStatus();
  }).catch(() => {
    pollFailures++;
    if (connErrorEl) {
      connErrorEl.style.display = '';
      connErrorEl.textContent = 'Connection lost. Retrying…';
    }
  }).finally(schedulePoll);
}
```

**Step 4: Commit**

```bash
git add public/js/liveblog.js public/css/liveblog.css includes/class-bdn-liveblog-shortcode.php
git commit -m "fix: add exponential backoff and error notice for poll failures"
```

---

### Task 3: Add mobile CSS breakpoints

**Files:**
- Modify: `public/css/liveblog.css`

**Step 1: Append mobile breakpoints to end of liveblog.css**

```css
/* ══ Mobile ═══════════════════════════════════════════════════════════════════ */
@media (max-width: 640px) {
  /* Reader entries — stack, hide time column */
  .bdn-lb-entry {
    grid-template-columns: 1fr;
    gap: 0;
  }
  .bdn-lb-time-col {
    display: none;
  }

  /* Composer split rows — stack */
  .bdn-lbc__form-row--split {
    grid-template-columns: 1fr;
  }
  .bdn-lbc__form-row--split .bdn-lbc__input {
    max-width: none !important;
  }

  /* Composer header — wrap */
  .bdn-lbc__header {
    flex-wrap: wrap;
    gap: 0.35rem;
  }

  /* Entry footer — tighter on mobile */
  .bdn-lb-entry-footer {
    gap: 0.4rem;
  }

  /* Standalone entry page */
  .bdn-lb-entry-page__inner {
    padding: 1rem 0.75rem 2rem;
  }
  .bdn-lb-entry-page__title {
    font-size: 1.5rem;
  }
  .bdn-lb-entry-page__footer {
    flex-direction: column;
    align-items: flex-start;
  }
  .bdn-lb-entry-page__share {
    margin-left: 0;
  }

  /* Toolbar — wrap buttons */
  .bdn-lbc__toolbar {
    gap: 1px;
  }

  /* Link/image bars — stack */
  .bdn-lbc__link-bar,
  .bdn-lbc__img-bar {
    flex-wrap: wrap;
  }
}
```

**Step 2: Commit**

```bash
git add public/css/liveblog.css
git commit -m "fix: add mobile CSS breakpoints for reader and composer at 640px"
```

---

### Task 4: Add error feedback for image upload failures

**Files:**
- Modify: `public/js/liveblog.js:478-546`

**Step 1: Update uploadInlineImages to track and return failures**

Replace the `uploadInlineImages` function (lines 478-510):

```javascript
async function uploadInlineImages(html) {
  const tmp = document.createElement('div');
  tmp.innerHTML = html;
  const imgs = Array.from(tmp.querySelectorAll('img[src^="data:"]'));
  if (!imgs.length) return { html, failures: 0 };

  let failures = 0;
  for (const img of imgs) {
    try {
      const dataUrl  = img.src;
      const mime     = dataUrl.match(/data:([^;]+);/)?.[1] || 'image/jpeg';
      const ext      = mime.split('/')[1] || 'jpg';
      const filename = `liveblog-inline-${Date.now()}.${ext}`;
      const blob     = await (await fetch(dataUrl)).blob();

      const form = new FormData();
      form.append('file', blob, filename);

      const res = await fetch('/wp-json/wp/v2/media', {
        method:  'POST',
        headers: { 'X-WP-Nonce': NONCE },
        body:    form,
      });
      if (!res.ok) { failures++; img.remove(); continue; }
      const data = await res.json();
      const url  = data.source_url || data.guid?.rendered;
      if (url) img.src = url; else { failures++; img.remove(); }
    } catch (e) {
      console.warn('BDN LiveBlog: inline image upload failed', e);
      failures++;
      img.remove();
    }
  }
  return { html: tmp.innerHTML, failures };
}
```

**Step 2: Update submitEntry to check for failures**

In `submitEntry` (around line 527), change:

```javascript
const cleanContent = await uploadInlineImages(content);
const payload = { title, content: cleanContent, byline, label,
```

to:

```javascript
const { html: cleanContent, failures: imgFailures } = await uploadInlineImages(content);
if (imgFailures > 0) {
  const proceed = confirm(`${imgFailures} inline image(s) failed to upload and were removed. Publish anyway?`);
  if (!proceed) { btn.disabled=false; btn.textContent=editingId?'Save changes':'Publish entry'; return; }
}
const payload = { title, content: cleanContent, byline, label,
```

**Step 3: Commit**

```bash
git add public/js/liveblog.js
git commit -m "fix: warn user when inline image uploads fail before publishing"
```

---

### Task 5: Rate limit slug regeneration

**Files:**
- Modify: `includes/class-bdn-liveblog-api.php` (regenerate_slug method, ~line 275)

**Step 1: Add rate limit check to regenerate_slug**

Replace the `regenerate_slug` method body:

```php
public static function regenerate_slug( WP_REST_Request $req ) {
    $post = get_post( $req->get_param( 'id' ) );
    if ( ! $post || $post->post_type !== BDN_Liveblog_Post_Type::CPT ) {
        return new WP_Error( 'not_found', 'Entry not found.', [ 'status' => 404 ] );
    }

    $throttle_key = 'bdn_lb_slug_regen_' . $post->ID;
    if ( get_transient( $throttle_key ) ) {
        return new WP_REST_Response( [
            'id'        => $post->ID,
            'seo_slug'  => get_post_meta( $post->ID, '_bdn_lb_seo_slug', true ),
            'entry_url' => BDN_Liveblog_Slug::get_entry_url( $post->ID, $post->post_content, get_the_title( $post ) ),
            'throttled' => true,
        ], 200 );
    }
    set_transient( $throttle_key, 1, 30 );

    $new_slug = BDN_Liveblog_Slug::regenerate_slug(
        $post->ID,
        $post->post_content,
        get_the_title( $post )
    );

    return new WP_REST_Response( [
        'id'        => $post->ID,
        'seo_slug'  => $new_slug,
        'entry_url' => BDN_Liveblog_Slug::get_entry_url( $post->ID, $post->post_content, get_the_title( $post ) ),
    ], 200 );
}
```

**Step 2: Commit**

```bash
git add includes/class-bdn-liveblog-api.php
git commit -m "fix: rate limit slug regeneration to one request per 30s per entry"
```

---

### Task 6: Add transient caching for GET /entries

**Files:**
- Modify: `includes/class-bdn-liveblog-api.php` (get_entries, create_entry, update_entry, delete_entry)

**Step 1: Add cache helper methods to the class**

Add after the `render_entry_content` method:

```php
private static function entries_cache_key( int $post_id, int $page ): string {
    return "bdn_lb_entries_{$post_id}_{$page}";
}

private static function bust_entries_cache( int $post_id ) {
    // Delete first 10 pages of cache (covers typical liveblog)
    for ( $i = 1; $i <= 10; $i++ ) {
        delete_transient( self::entries_cache_key( $post_id, $i ) );
    }
}
```

**Step 2: Add caching to get_entries**

In `get_entries`, after `$page = max(...)` and before the query, add cache read. Only cache non-polling requests (`$after == 0`):

```php
if ( $after == 0 ) {
    $cached = get_transient( self::entries_cache_key( $post_id, $page ) );
    if ( $cached !== false ) {
        return new WP_REST_Response( $cached, 200 );
    }
}
```

After building the response data array (before `return new WP_REST_Response`), add:

```php
$response_data = [
    'entries'     => $entries,
    'total'       => (int) $query->found_posts,
    'total_pages' => (int) $query->max_num_pages,
];

if ( $after == 0 ) {
    set_transient( self::entries_cache_key( $post_id, $page ), $response_data, 30 );
}

return new WP_REST_Response( $response_data, 200 );
```

**Step 3: Bust cache on create/update/delete**

In `create_entry`, after the `wp_update_post` call for parent (line ~242), add:
```php
self::bust_entries_cache( $post_id );
```

In `update_entry`, after `wp_update_post( $update )`, add:
```php
$parent_id = (int) get_post_meta( $post->ID, '_bdn_lb_parent_post', true );
if ( $parent_id ) self::bust_entries_cache( $parent_id );
```

In `delete_entry`, before `wp_delete_post`, add:
```php
$parent_id = (int) get_post_meta( $post->ID, '_bdn_lb_parent_post', true );
if ( $parent_id ) self::bust_entries_cache( $parent_id );
```

**Step 4: Commit**

```bash
git add includes/class-bdn-liveblog-api.php
git commit -m "perf: add 30s transient cache for GET /entries with bust on write"
```

---

### Task 7: Add video/embed support

**Files:**
- Modify: `public/js/liveblog.js` (composer: detect pasted URLs, preview via oEmbed proxy)
- Modify: `includes/class-bdn-liveblog-api.php` (render_entry_content: process oEmbeds)
- Modify: `public/css/liveblog.css` (embed preview styling)

**Step 1: Add oEmbed processing to render_entry_content in PHP**

In `class-bdn-liveblog-api.php`, update `render_entry_content`:

```php
public static function render_entry_content( string $raw ): string {
    // Process oEmbed URLs (bare URLs on their own line)
    global $wp_embed;
    if ( $wp_embed ) {
        $raw = $wp_embed->autoembed( $raw );
    }
    $content = wptexturize( $raw );
    $content = wpautop( $content );
    $content = shortcode_unautop( $content );
    $content = wp_filter_content_tags( $content );
    $content = do_shortcode( $content );
    $content = convert_smilies( $content );
    return $content;
}
```

**Step 2: Add CSS for embeds in entries**

Append to `liveblog.css` before the mobile breakpoints:

```css
/* ── Embeds in entries ────────────────────────────────────────────────────── */
.bdn-lb-content iframe,
.bdn-lb-entry-page__content iframe {
  max-width: 100%;
  border: 0;
}
.bdn-lb-content .wp-block-embed,
.bdn-lb-content .embed-wrap {
  margin: 0.75rem 0;
}
/* Composer embed preview */
.bdn-lbc__embed-preview {
  border: 1px solid var(--lb-border);
  border-radius: 0;
  padding: 0.5rem;
  margin: 6px 0;
  background: #f9f9f9;
  position: relative;
  user-select: none;
}
.bdn-lbc__embed-preview iframe { max-width: 100%; }
.bdn-lbc__embed-preview__remove {
  position: absolute;
  top: 4px;
  right: 4px;
  background: var(--lb-text);
  color: #fff;
  border: none;
  width: 20px;
  height: 20px;
  font-size: 12px;
  cursor: pointer;
  line-height: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}
.bdn-lbc__embed-preview__remove:hover { background: var(--lb-red); }
```

**Step 3: Add embed detection to JS composer**

In `liveblog.js`, add after the `bindToolbar()` call (~line 449):

```javascript
// ── Embed auto-detection ──────────────────────────────────────────────────
// When a bare URL is pasted on its own line in the editor, try to resolve
// it via the WP oEmbed proxy and show an inline preview.
(function initEmbedDetection() {
  const editor = document.getElementById('bdn-lbc-content');
  if (!editor) return;

  const OEMBED_REGEX = /^(https?:\/\/\S+)$/;

  editor.addEventListener('paste', async (e) => {
    const text = (e.clipboardData || window.clipboardData)?.getData('text/plain')?.trim();
    if (!text || !OEMBED_REGEX.test(text)) return;

    // Check if it looks like an embeddable URL (YouTube, Twitter/X, Vimeo, etc.)
    const embedDomains = ['youtube.com','youtu.be','twitter.com','x.com','vimeo.com','instagram.com','tiktok.com','facebook.com'];
    const url = new URL(text).hostname.replace('www.','');
    if (!embedDomains.some(d => url.includes(d))) return;

    e.preventDefault();

    // Insert a placeholder while we fetch the embed
    const placeholder = document.createElement('div');
    placeholder.className = 'bdn-lbc__embed-preview';
    placeholder.setAttribute('contenteditable', 'false');
    placeholder.dataset.embedUrl = text;
    placeholder.innerHTML = '<p style="color:#767676;font-size:0.8rem;margin:0">Loading embed…</p>';

    const sel = window.getSelection();
    if (sel && sel.rangeCount) {
      const range = sel.getRangeAt(0);
      range.collapse(false);
      range.insertNode(placeholder);
      // Move cursor after
      range.setStartAfter(placeholder);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);
    }

    try {
      const proxyUrl = `/wp-json/oembed/1.0/proxy?url=${encodeURIComponent(text)}&_wpnonce=${NONCE}`;
      const res = await fetch(proxyUrl, { headers: { 'X-WP-Nonce': NONCE } });
      if (!res.ok) throw new Error('oEmbed failed');
      const data = await res.json();
      if (data.html) {
        placeholder.innerHTML = data.html;
      } else if (data.title) {
        placeholder.innerHTML = `<p style="margin:0"><a href="${esc(text)}" target="_blank">${esc(data.title)}</a></p>`;
      } else {
        throw new Error('No embed HTML');
      }
    } catch {
      // Fallback: just show the URL as a link
      placeholder.outerHTML = `<p><a href="${esc(text)}" target="_blank">${esc(text)}</a></p>`;
      return;
    }

    // Add remove button
    const removeBtn = document.createElement('button');
    removeBtn.className = 'bdn-lbc__embed-preview__remove';
    removeBtn.innerHTML = '&times;';
    removeBtn.title = 'Remove embed';
    removeBtn.addEventListener('click', () => {
      placeholder.remove();
    });
    placeholder.appendChild(removeBtn);
  });
})();
```

**Step 4: On submit, convert embed previews back to bare URLs**

In `submitEntry`, before `uploadInlineImages`, add conversion logic. Right after `const content=(editor?.innerHTML||'').trim();` (line 514):

```javascript
// Convert embed previews back to bare URLs for server-side oEmbed processing
const editorClone = document.createElement('div');
editorClone.innerHTML = content;
editorClone.querySelectorAll('.bdn-lbc__embed-preview').forEach(el => {
  const url = el.dataset.embedUrl;
  if (url) {
    const p = document.createElement('p');
    p.textContent = url;
    el.replaceWith(p);
  } else {
    el.remove();
  }
});
const contentForSubmit = editorClone.innerHTML.trim();
```

Then change the `uploadInlineImages` call to use `contentForSubmit` instead of `content`:
```javascript
const { html: cleanContent, failures: imgFailures } = await uploadInlineImages(contentForSubmit);
```

And update the empty check:
```javascript
const contentText = editorClone.innerText.trim();
```

Actually, cleaner: do the clone before the empty check. Restructure the top of `submitEntry`:

```javascript
async function submitEntry() {
  const editor=document.getElementById('bdn-lbc-content');
  const rawHtml=(editor?.innerHTML||'').trim();
  // Convert embed previews back to bare URLs for server-side oEmbed
  const tmp = document.createElement('div');
  tmp.innerHTML = rawHtml;
  tmp.querySelectorAll('.bdn-lbc__embed-preview').forEach(el => {
    const url = el.dataset.embedUrl;
    if (url) { const p = document.createElement('p'); p.textContent = url; el.replaceWith(p); }
    else el.remove();
  });
  const content = tmp.innerHTML.trim();
  const contentText = tmp.innerText.trim();
  const title=document.getElementById('bdn-lbc-headline')?.value.trim();
  const byline=document.getElementById('bdn-lbc-byline')?.value.trim();
  const label=document.getElementById('bdn-lbc-label')?.value.trim();
  const btn=document.getElementById('bdn-lbc-submit');
  const caption = document.getElementById('bdn-lbc-caption')?.value.trim();
  const credit  = document.getElementById('bdn-lbc-credit')?.value.trim();
  if (!contentText) { flash('Entry text is required.',true); return; }
  btn.disabled=true; btn.textContent=editingId?'Saving…':'Publishing…';
  // ... rest of function unchanged from the image upload step
```

**Step 5: Commit**

```bash
git add public/js/liveblog.js includes/class-bdn-liveblog-api.php public/css/liveblog.css
git commit -m "feat: add video/embed support with composer preview and server-side oEmbed"
```

---

### Task 8: Add highlights / key moments

**Files:**
- Modify: `includes/class-bdn-liveblog-api.php` (entry_args, format_entry, get_entries, create_entry, update_entry)
- Modify: `includes/class-bdn-liveblog-shortcode.php` (add tabs to reader HTML)
- Modify: `public/js/liveblog.js` (reader tabs + composer highlight button)
- Modify: `public/css/liveblog.css` (highlight styles)

**Step 1: Add highlight to PHP API**

In `entry_args()`, add after the `pinned` arg:
```php
'highlight'     => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
```

In `format_entry()`, add to the return array after `'pinned'`:
```php
'highlight'     => (bool) get_post_meta( $post->ID, '_bdn_lb_highlight', true ),
```

In `get_entries()`, add `highlights_only` to the route args (in `register_routes`, the GET /entries args ~line 17):
```php
'highlights_only' => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
```

In `get_entries()` method body, after the existing `meta_query` line, add:
```php
if ( $req->get_param( 'highlights_only' ) ) {
    $args['meta_query'][] = [ 'key' => '_bdn_lb_highlight', 'value' => '1' ];
}
```

In `create_entry()`, after the pinned handler, add:
```php
if ( $req->has_param( 'highlight' ) ) {
    if ( absint( $req->get_param( 'highlight' ) ) ) {
        update_post_meta( $entry_id, '_bdn_lb_highlight', 1 );
    } else {
        delete_post_meta( $entry_id, '_bdn_lb_highlight' );
    }
}
```

In `update_entry()`, after the pinned handler, add:
```php
if ( $req->has_param( 'highlight' ) ) {
    if ( absint( $req->get_param( 'highlight' ) ) ) {
        update_post_meta( $post->ID, '_bdn_lb_highlight', 1 );
    } else {
        delete_post_meta( $post->ID, '_bdn_lb_highlight' );
    }
}
```

**Step 2: Add tabs HTML to shortcode**

In `class-bdn-liveblog-shortcode.php`, after the `.bdn-lb-header` div closing tag and before `.bdn-lb-entries`, add:

```php
<div class="bdn-lb-tabs">
    <button class="bdn-lb-tab bdn-lb-tab--active" data-filter="all">All updates</button>
    <button class="bdn-lb-tab" data-filter="highlights">Key moments <span class="bdn-lb-tab__count"></span></button>
</div>
```

**Step 3: Add CSS for tabs and highlighted entries**

Append to `liveblog.css` before the mobile section:

```css
/* ── Highlights / key moments ─────────────────────────────────────────────── */
.bdn-lb-tabs {
  display: flex;
  gap: 0;
  border-bottom: 1px solid var(--lb-border);
  margin-bottom: 0;
}
.bdn-lb-tab {
  font-family: var(--lb-font-ui);
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--lb-muted);
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  padding: 0.6em 1em;
  cursor: pointer;
  transition: color 0.15s, border-color 0.15s;
}
.bdn-lb-tab:hover { color: var(--lb-text); }
.bdn-lb-tab--active {
  color: var(--lb-text);
  border-bottom-color: var(--lb-red);
}
.bdn-lb-tab__count {
  font-weight: 400;
  color: var(--lb-muted);
}

/* Highlighted entry accent */
.bdn-lb-entry.is-highlight .bdn-lb-body {
  border-left: 3px solid #f5c518;
  padding-left: 1rem;
}

/* Highlight badge in meta row */
.bdn-lb-highlight-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-family: var(--lb-font-ui);
  font-size: 0.5625rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: #8a6d00;
  background: #fef9e7;
  border: 1px solid #f5c518;
  padding: 0.15em 0.5em;
}

/* Composer highlight button */
.bdn-lbc__act--highlight { color: var(--lb-muted); }
.bdn-lbc__act--highlight.bdn-lbc__act--highlighted { color: #8a6d00; }

/* Composer highlighted entry */
.bdn-lbc__entry--highlighted {
  border-left: 3px solid #f5c518;
  padding-left: 8px;
}
```

**Step 4: Add reader tab behavior in JS**

In `liveblog.js`, inside the `document.querySelectorAll('.bdn-liveblog').forEach` block, after `loadMoreBtn` event listener (~line 79), add:

```javascript
// ── Highlights tabs ────────────────────────────────────────────────────
let activeFilter = 'all';
const tabs = widget.querySelectorAll('.bdn-lb-tab');
const highlightCountEl = widget.querySelector('.bdn-lb-tab__count');

tabs.forEach(tab => {
  tab.addEventListener('click', () => {
    const filter = tab.dataset.filter;
    if (filter === activeFilter) return;
    activeFilter = filter;
    tabs.forEach(t => t.classList.toggle('bdn-lb-tab--active', t.dataset.filter === filter));
    entriesEl.innerHTML = '<div class="bdn-lb-loading"><span class="bdn-lb-spinner"></span> Loading…</div>';
    if (filter === 'highlights') {
      api(`entries?post_id=${postId}&highlights_only=1`).then(data => {
        entriesEl.innerHTML = '';
        if (!data.entries.length) { entriesEl.innerHTML = '<p class="bdn-lb-empty">No key moments yet.</p>'; return; }
        data.entries.forEach(e => appendEntry(e, false));
      }).catch(() => { entriesEl.innerHTML = '<p class="bdn-lb-empty">Could not load.</p>'; });
    } else {
      fetchEntries(1, true);
    }
  });
});

function updateHighlightCount() {
  api(`entries?post_id=${postId}&highlights_only=1`).then(data => {
    if (highlightCountEl) {
      const n = data.total || 0;
      highlightCountEl.textContent = n > 0 ? `(${n})` : '';
    }
  }).catch(() => {});
}
updateHighlightCount();
```

**Step 5: Update buildEntryEl to show highlight state**

In `buildEntryEl`, update the className line (~line 88):

```javascript
el.className='bdn-lb-entry'+(isNew?' is-new':'')+(entry.pinned?' is-pinned':'')+(entry.highlight?' is-highlight':'');
```

In the meta div inside `buildEntryEl`, add highlight badge after pin badge:

```javascript
<div class="bdn-lb-meta">${entry.pinned?'<span class="bdn-lb-pin-badge">Pinned</span>':''}${entry.highlight?'<span class="bdn-lb-highlight-badge">Key moment</span>':''}${entry.label?`<span class="bdn-lb-label">${esc(entry.label)}</span>`:''}</div>
```

**Step 6: Add highlight button in composer entry list**

In `buildComposerEntry`, update className:
```javascript
div.className='bdn-lbc__entry'+(e.pinned?' bdn-lbc__entry--pinned':'')+(e.highlight?' bdn-lbc__entry--highlighted':'');
```

In the actions div of `buildComposerEntry`, add a highlight button before the pin button:
```javascript
<button class="bdn-lbc__act bdn-lbc__act--highlight${e.highlight?' bdn-lbc__act--highlighted':''}" data-id="${e.id}">${e.highlight?'&#x2605; Key moment':'&#x2606; Key moment'}</button>
```

Add event listener in `buildComposerEntry`, after the pin listener:
```javascript
div.querySelector('.bdn-lbc__act--highlight')?.addEventListener('click',()=>toggleHighlight(e.id, !e.highlight));
```

**Step 7: Add toggleHighlight function**

After `togglePin`, add:

```javascript
async function toggleHighlight(id, shouldHighlight) {
  try {
    await api(`entries/${id}`, {
      method: 'POST',
      body: JSON.stringify({ highlight: shouldHighlight ? 1 : 0 }),
    });
    loadComposerEntries();
    // Update reader widget highlight count
    document.querySelectorAll('.bdn-liveblog').forEach(w => {
      const countEl = w.querySelector('.bdn-lb-tab__count');
      if (countEl) {
        api(`entries?post_id=${POST_ID}&highlights_only=1`).then(data => {
          const n = data.total || 0;
          countEl.textContent = n > 0 ? `(${n})` : '';
        }).catch(() => {});
      }
    });
  } catch(e) { alert('Could not update highlight: ' + e.message); }
}
```

**Step 8: Commit**

```bash
git add includes/class-bdn-liveblog-api.php includes/class-bdn-liveblog-shortcode.php public/js/liveblog.js public/css/liveblog.css
git commit -m "feat: add highlights / key moments with reader tabs and composer toggle"
```

---

### Task 9: Make Anthropic model configurable

**Files:**
- Modify: `includes/class-bdn-liveblog-slug.php:119`
- Modify: `admin/class-bdn-liveblog-admin.php` (register_settings, render_settings_page)

**Step 1: Add model option constant and update API call**

In `class-bdn-liveblog-slug.php`, add a new constant after `API_KEY_OPTION`:
```php
const MODEL_OPTION = 'bdn_liveblog_anthropic_model';
const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';
```

In `call_anthropic`, change the hardcoded model (line 119):
```php
'model'      => get_option( self::MODEL_OPTION, self::DEFAULT_MODEL ),
```

**Step 2: Add model field to settings page**

In `class-bdn-liveblog-admin.php`, in `register_settings()`, after the existing `register_setting` call, add:

```php
register_setting( 'bdn_liveblog_settings', BDN_Liveblog_Slug::MODEL_OPTION, [
    'type'              => 'string',
    'sanitize_callback' => 'sanitize_text_field',
    'default'           => BDN_Liveblog_Slug::DEFAULT_MODEL,
]);
```

After the existing `add_settings_field` call, add:

```php
add_settings_field(
    BDN_Liveblog_Slug::MODEL_OPTION,
    'Claude Model',
    function() {
        $val = get_option( BDN_Liveblog_Slug::MODEL_OPTION, BDN_Liveblog_Slug::DEFAULT_MODEL );
        ?>
        <input type="text"
               name="<?php echo esc_attr( BDN_Liveblog_Slug::MODEL_OPTION ); ?>"
               value="<?php echo esc_attr( $val ); ?>"
               class="regular-text"
               placeholder="<?php echo esc_attr( BDN_Liveblog_Slug::DEFAULT_MODEL ); ?>" />
        <p class="description">
            Model ID for slug generation. Default: <code><?php echo esc_html( BDN_Liveblog_Slug::DEFAULT_MODEL ); ?></code>.
            See <a href="https://docs.anthropic.com/en/docs/about-claude/models" target="_blank" rel="noopener">available models</a>.
        </p>
        <?php
    },
    'bdn-liveblog-settings',
    'bdn_liveblog_main'
);
```

**Step 3: Commit**

```bash
git add includes/class-bdn-liveblog-slug.php admin/class-bdn-liveblog-admin.php
git commit -m "feat: make Anthropic model configurable in Live Blog settings"
```

---

## Execution Order

Tasks 1-6 are independent bug fixes and can be done in any order or in parallel.
Task 7 (embeds) and Task 8 (highlights) both modify the same files and should be done sequentially.
Task 9 is independent.

Recommended: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9
