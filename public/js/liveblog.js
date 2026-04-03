/* global BDN_LB */
(function () {
  'use strict';

  const cfg = window.BDN_LB || {};
  if ( ! cfg.rest_url ) return;

  const REST     = cfg.rest_url;
  const NONCE    = cfg.nonce;
  const POLL     = cfg.poll_interval || 15000;
  const CAN_EDIT = !! cfg.can_edit;

  const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE };

  async function api( path, opts = {} ) {
    const r = await fetch( REST + path, { headers, ...opts } );
    if ( ! r.ok ) { const e = await r.json().catch(()=>({})); throw new Error(e.message||`HTTP ${r.status}`); }
    return r.json();
  }

  const STOP = new Set(['a','an','the','and','or','but','in','on','at','to','for','of','with','by','from','is','was','are','were']);
  function naiveSlug(t){ return t.toLowerCase().replace(/<[^>]+>/g,'').split(/\s+/).map(w=>w.replace(/[^a-z0-9]/g,'')).filter(w=>w&&!STOP.has(w)).slice(0,7).join('-').slice(0,60); }
  function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
  function decodeEntities(s){ const el=document.createElement('textarea'); el.innerHTML=s; return el.value; }
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
  function fmtTime(iso){ const d=new Date(iso); return d.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',hour12:true})+' · '+d.toLocaleDateString('en-US',{month:'short',day:'numeric'}); }

  // ══ Reader widget ══════════════════════════════════════════════════════════

  document.querySelectorAll('.bdn-liveblog').forEach( widget => {
    const postId    = parseInt(widget.dataset.postId, 10);
    const entriesEl = widget.querySelector('.bdn-lb-entries');
    const statusEl  = widget.querySelector('.bdn-lb-status-text');
    const updatedEl = widget.querySelector('.bdn-lb-last-updated');
    const loadMoreBtn = widget.querySelector('.bdn-lb-load-more');

    let latestTimestamp = 0, currentPage = 1, totalPages = 1, pollTimer = null;
    let pollFailures = 0;
    const connErrorEl = widget.querySelector('.bdn-lb-conn-error');

    fetchStatus();
    fetchEntries(1, true);

    function fetchStatus() {
      api(`status?post_id=${postId}`).then(data => {
        const live = data.status === 'live';
        widget.classList.toggle('is-live', live);
        statusEl.textContent = live ? 'Live' : data.status === 'scheduled' ? 'Scheduled' : 'Ended';
        if (live) schedulePoll(); else clearTimeout(pollTimer);
      }).catch(()=>{});
    }

    function fetchEntries(page, initial) {
      api(`entries?post_id=${postId}&page=${page}`).then(data => {
        if (initial) entriesEl.innerHTML = '';
        // Sort: pinned entry always first, then newest-first
        const sorted=[...(data.entries||[])].sort((a,b)=>{
          if(a.pinned&&!b.pinned) return -1;
          if(!a.pinned&&b.pinned) return 1;
          return 0;
        });
        sorted.forEach(e => { if(e.timestamp>latestTimestamp) latestTimestamp=e.timestamp; appendEntry(e,false); });
        currentPage = page; totalPages = data.total_pages||1;
        loadMoreBtn.style.display = currentPage < totalPages ? 'block' : 'none';
        if (initial && !data.entries.length) entriesEl.innerHTML = '<p class="bdn-lb-empty">No entries yet. Check back soon.</p>';
        updatedEl.textContent = 'Updated '+new Date().toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',hour12:true});
      }).catch(()=>{ if(initial) entriesEl.innerHTML='<p class="bdn-lb-empty">Could not load entries.</p>'; });
    }

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

    loadMoreBtn && loadMoreBtn.addEventListener('click', () => {
      loadMoreBtn.textContent='Loading…'; loadMoreBtn.disabled=true;
      fetchEntries(currentPage+1,false);
      loadMoreBtn.textContent='Load earlier entries'; loadMoreBtn.disabled=false;
    });

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

    // ── Story so far summary ────────────────────────────────────────────────
    const summaryEl = widget.querySelector('.bdn-lb-summary');
    const summaryText = widget.querySelector('.bdn-lb-summary__text');
    const summaryClose = widget.querySelector('.bdn-lb-summary__close');

    function fetchSummary() {
      api(`summary?post_id=${postId}`).then(data => {
        if (data.summary && data.entry_count >= 3) {
          summaryText.textContent = data.summary;
          summaryEl.style.display = '';
        }
      }).catch(() => {});
    }

    setTimeout(fetchSummary, 2000);

    summaryClose?.addEventListener('click', () => {
      summaryEl.style.display = 'none';
    });

    function buildEntryEl(entry, isNew) {
      const d=new Date(entry.published);
      const hour=d.toLocaleTimeString('en-US',{hour:'numeric',hour12:true}).replace(/\s?(AM|PM)/i,'');
      const mins=String(d.getMinutes()).padStart(2,'0');
      const ampm=d.toLocaleTimeString('en-US',{hour:'numeric',hour12:true}).match(/(AM|PM)/i)?.[0]||'';
      const shareUrl=entry.entry_url||entry.anchor_url||`${location.href}#entry-${entry.id}`;
      const el=document.createElement('article');
      el.className='bdn-lb-entry'+(isNew?' is-new':'')+(entry.pinned?' is-pinned':'')+(entry.highlight?' is-highlight':'');
      el.id='entry-'+entry.id;
      el.dataset.entryId=entry.id;
      el.innerHTML=`
        <div class="bdn-lb-time-col" aria-hidden="true">
          <time class="bdn-lb-time" datetime="${esc(entry.published)}">
            <span class="hour">${esc(hour)}:${esc(mins)}</span>
            <span class="ampm">${esc(ampm)}</span>
          </time>
        </div>
        <div class="bdn-lb-body">
          <div class="bdn-lb-meta">${entry.pinned?'<span class="bdn-lb-pin-badge">Pinned</span>':''}${entry.highlight?'<span class="bdn-lb-highlight-badge">Key moment</span>':''}${entry.label?`<span class="bdn-lb-label">${esc(entry.label)}</span>`:''}</div>
          ${entry.title?`<h2 class="bdn-lb-entry-title">${esc(decodeEntities(entry.title))}</h2>`:''}
          ${entry.image_url?`<figure class="bdn-lb-figure">
            <img src="${esc(entry.image_url)}"
                 ${entry.image_srcset?`srcset="${esc(entry.image_srcset)}" sizes="(max-width:600px) 100vw, 600px"`:''}
                 alt="${esc(entry.image_alt||'')}"
                 class="bdn-lb-figure__img"
                 loading="lazy" />
            ${(entry.image_caption||entry.image_credit)?`<figcaption class="bdn-lb-figure__caption">
              ${entry.image_caption?`<span class="bdn-lb-figure__cap-text">${esc(entry.image_caption)}</span>`:''}
              ${entry.image_credit?`<span class="bdn-lb-figure__credit">${esc(entry.image_credit)}</span>`:''}
            </figcaption>`:''}
          </figure>`:''}
          <div class="bdn-lb-content">${sanitizeHtml(entry.content)}</div>
          <div class="bdn-lb-entry-footer">
            <span class="bdn-lb-entry-byline">${esc(entry.byline)}</span>
            <button class="bdn-lb-copy-link" data-url="${esc(shareUrl)}"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>Copy link</button>
          </div>
        </div>`;
      el.querySelector('.bdn-lb-copy-link')?.addEventListener('click',function(){navigator.clipboard.writeText(this.dataset.url).then(()=>{this.textContent='Copied!';setTimeout(()=>{this.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>Copy link';},2000);});});
      return el;
    }

    function appendEntry(entry,isNew){ if(document.getElementById('entry-'+entry.id)) return; entriesEl.appendChild(buildEntryEl(entry,isNew)); }
    function prependEntry(entry){ if(document.getElementById('entry-'+entry.id)) return; entriesEl.insertBefore(buildEntryEl(entry,true),entriesEl.firstChild); }

    // Public API so the composer can push new entries in immediately.
    widget._prependEntry  = prependEntry;
    widget._updateEntry   = function(entry){ const el=document.getElementById('entry-'+entry.id); if(el){ el.remove(); prependEntry(entry); } };
    widget._removeEntry   = function(id){ document.getElementById('entry-'+id)?.remove(); };
    widget._buildEntryEl  = buildEntryEl;
  });

  // ══ Composer (logged-in editors only) ════════════════════════════════════

  const composerRoot = document.getElementById('bdn-lb-composer-root');
  if (!composerRoot || !CAN_EDIT) return;

  const POST_ID = parseInt(composerRoot.dataset.postId, 10);
  let editingId   = null;
  let selectedImg = { id: 0, url: '', thumb: '', alt: '' }; // currently selected image

  // Pre-fetch reporter profile for byline placeholder.
  api('me').then(p => {
    const bf = document.getElementById('bdn-lbc-byline');
    if (bf && p.name) bf.placeholder = p.name;
  }).catch(()=>{});

  composerRoot.innerHTML = `
<div class="bdn-lbc" id="bdn-lbc">
  <div class="bdn-lbc__header">
    <div class="bdn-lbc__header-left">
      <span class="bdn-lbc__indicator" id="bdn-lbc-indicator"></span>
      <span class="bdn-lbc__title" id="bdn-lbc-title">Live blog</span>
    </div>
    <div class="bdn-lbc__header-right">
      <select id="bdn-lbc-status" class="bdn-lbc__status-select" aria-label="Change live blog status">
        <option value="live">&#x25CF; Live</option>
        <option value="scheduled">&#x25D4; Scheduled</option>
        <option value="ended">&#x25A0; Ended</option>
      </select>
      <button class="bdn-lbc__toggle" id="bdn-lbc-toggle" aria-expanded="true" title="Collapse">&#x25B2;</button>
    </div>
  </div>
  <div class="bdn-lbc__panel" id="bdn-lbc-panel">
    <div class="bdn-lbc__form">
      <input id="bdn-lbc-headline" type="text" placeholder="Headline (optional)" class="bdn-lbc__input" />

      <!-- Featured photo — sits above the editor -->
      <div class="bdn-lbc__photo-row">
        <div class="bdn-lbc__photo-preview" id="bdn-lbc-photo-preview" style="display:none">
          <img id="bdn-lbc-photo-thumb" src="" alt="" />
          <button class="bdn-lbc__photo-remove" id="bdn-lbc-photo-remove" title="Remove photo">&times;</button>
        </div>
        <div class="bdn-lbc__photo-meta" id="bdn-lbc-photo-meta" style="display:none">
          <input id="bdn-lbc-caption" type="text" placeholder="Caption (optional)" class="bdn-lbc__input" />
          <input id="bdn-lbc-credit"  type="text" placeholder="Photo credit (optional)" class="bdn-lbc__input" />
        </div>
        <button type="button" class="bdn-lbc__photo-btn" id="bdn-lbc-photo-btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          Add photo
        </button>
      </div>

      <!-- Rich text editor -->
      <div class="bdn-lbc__editor-wrap">
        <div class="bdn-lbc__toolbar">
          <button type="button" class="bdn-lbc__tb-btn" data-cmd="bold"      title="Bold"><b>B</b></button>
          <button type="button" class="bdn-lbc__tb-btn" data-cmd="italic"    title="Italic"><i>I</i></button>
          <span class="bdn-lbc__tb-sep"></span>
          <button type="button" class="bdn-lbc__tb-btn" id="bdn-lbc-tb-link" title="Insert link">Link</button>
          <button type="button" class="bdn-lbc__tb-btn" id="bdn-lbc-tb-img"  title="Inline photo">Inline photo</button>
        </div>
        <div class="bdn-lbc__link-bar" id="bdn-lbc-link-bar" style="display:none">
          <input type="url" id="bdn-lbc-link-url" class="bdn-lbc__link-input" placeholder="https://&#x2026;" />
          <button type="button" class="bdn-lbc__link-go"     id="bdn-lbc-link-insert">Insert</button>
          <button type="button" class="bdn-lbc__link-cancel" id="bdn-lbc-link-cancel">Cancel</button>
        </div>
        <div class="bdn-lbc__img-bar" id="bdn-lbc-img-bar" style="display:none">
          <input type="file" id="bdn-lbc-inline-file" accept="image/*" class="bdn-lbc__file-input" />
          <button type="button" class="bdn-lbc__link-go"     id="bdn-lbc-img-insert">Insert</button>
          <button type="button" class="bdn-lbc__link-cancel" id="bdn-lbc-img-cancel">Cancel</button>
        </div>
        <div id="bdn-lbc-content"
             class="bdn-lbc__editor"
             contenteditable="true"
             data-placeholder="Write your update&#x2026;"
             spellcheck="true"
             aria-label="Entry body"></div>
      </div>

      <div class="bdn-lbc__form-row--split">
        <input id="bdn-lbc-byline" type="text" placeholder="Byline" class="bdn-lbc__input" />
        <input id="bdn-lbc-label" type="text" placeholder="Label e.g. BREAKING" class="bdn-lbc__input" style="max-width:160px" />
      </div>
      <div class="bdn-lbc__slug-preview" id="bdn-lbc-slug-preview" style="display:none">
        <span class="bdn-lbc__slug-prefix">URL:</span>
        <code id="bdn-lbc-slug-val"></code>
        <span class="bdn-lbc__slug-ai" id="bdn-lbc-slug-ai"></span>
      </div>
      <div class="bdn-lbc__form-actions">
        <button id="bdn-lbc-submit" class="bdn-lbc__btn--primary">Publish entry</button>
        <button id="bdn-lbc-cancel" class="bdn-lbc__btn--ghost" style="display:none">Cancel</button>
        <span id="bdn-lbc-msg" class="bdn-lbc__msg"></span>
      </div>
    </div>
    <div class="bdn-lbc__divider">
      <span class="bdn-lbc__divider-label">Published entries</span>
      <span id="bdn-lbc-count" class="bdn-lbc__count"></span>
    </div>
    <div id="bdn-lbc-entries" class="bdn-lbc__entries">
      <p class="bdn-lbc__loading">Loading&#x2026;</p>
    </div>
  </div>
</div>`;

  // Bind all controls now that HTML is in the DOM.
  bindComposer();

  function bindComposer() {
    // Status
    api(`status?post_id=${POST_ID}`).then(d => {
      const s = document.getElementById('bdn-lbc-status');
      if (s) s.value = d.status || 'live';
      syncIndicator(d.status);
    }).catch(()=>{});

    document.getElementById('bdn-lbc-status')?.addEventListener('change', function() {
      api('status',{method:'POST',body:JSON.stringify({post_id:POST_ID,status:this.value})})
        .then(()=>{ syncIndicator(this.value); flash('Status saved.'); })
        .catch(e=>flash('Error: '+e.message,true));
    });

    // Collapse
    document.getElementById('bdn-lbc-toggle')?.addEventListener('click', function() {
      const p=document.getElementById('bdn-lbc-panel');
      const open=p.style.display!=='none';
      p.style.display=open?'none':'';
      this.innerHTML=open?'&#x25BC;':'&#x25B2;';
      this.title=open?'Expand':'Collapse';
    });

    // Photo picker — uses WP Media Library (available on front-end when user is logged in
    // and wp_enqueue_media() has been called, which our plugin does for logged-in editors).
    let mediaFrame = null;
    document.getElementById('bdn-lbc-photo-btn')?.addEventListener('click', () => {
      if (!window.wp?.media) { alert('Media library not available. Please ensure you are logged in.'); return; }
      if (!mediaFrame) {
        mediaFrame = wp.media({
          title:    'Select or Upload Photo',
          button:   { text: 'Use this photo' },
          multiple: false,
          library:  { type: 'image' },
        });
        mediaFrame.on('select', () => {
          const att = mediaFrame.state().get('selection').first().toJSON();
          selectedImg = {
            id:    att.id,
            url:   att.url,
            thumb: att.sizes?.medium?.url || att.url,
            alt:   att.alt || att.title || '',
          };
          const thumb = document.getElementById('bdn-lbc-photo-thumb');
          const preview = document.getElementById('bdn-lbc-photo-preview');
          const meta    = document.getElementById('bdn-lbc-photo-meta');
          const btn     = document.getElementById('bdn-lbc-photo-btn');
          thumb.src = selectedImg.thumb;
          thumb.alt = selectedImg.alt;
          preview.style.display = 'flex';
          meta.style.display    = '';
          btn.textContent = 'Change photo';
        });
      }
      mediaFrame.open();
    });

    document.getElementById('bdn-lbc-photo-remove')?.addEventListener('click', () => {
      selectedImg = { id: 0, url: '', thumb: '', alt: '' };
      document.getElementById('bdn-lbc-photo-preview').style.display = 'none';
      document.getElementById('bdn-lbc-photo-meta').style.display    = 'none';
      document.getElementById('bdn-lbc-photo-btn').textContent = 'Add photo';
      document.getElementById('bdn-lbc-caption').value = '';
      document.getElementById('bdn-lbc-credit').value  = '';
    });

    ['bdn-lbc-headline', 'bdn-lbc-content'].forEach(id => {
      document.getElementById(id)?.addEventListener('input', updateSlugPreview);
    });

    // Submit / cancel
    document.getElementById('bdn-lbc-submit')?.addEventListener('click', submitEntry);
    document.getElementById('bdn-lbc-cancel')?.addEventListener('click', cancelEdit);

    loadComposerEntries();
  }

  // ── Rich text toolbar ─────────────────────────────────────────────────────

  function bindToolbar() {
    const editor = document.getElementById('bdn-lbc-content');
    if (!editor) return;

    let savedRange = null;

    // Save selection before focus leaves editor (link/img buttons steal focus)
    editor.addEventListener('mouseup',  saveRange);
    editor.addEventListener('keyup',    saveRange);
    editor.addEventListener('input',    updateSlugPreview);

    function saveRange() {
      const sel = window.getSelection();
      if (sel && sel.rangeCount) savedRange = sel.getRangeAt(0).cloneRange();
    }
    function restoreRange() {
      if (!savedRange) return;
      editor.focus();
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(savedRange);
    }

    // B / I buttons
    document.querySelectorAll('.bdn-lbc__tb-btn[data-cmd]').forEach(btn => {
      btn.addEventListener('mousedown', e => {
        e.preventDefault(); // keep editor focus + selection
        document.execCommand(btn.dataset.cmd, false, null);
      });
    });

    // ── Link bar ──────────────────────────────────────────────────────────────
    const linkBar    = document.getElementById('bdn-lbc-link-bar');
    const linkUrl    = document.getElementById('bdn-lbc-link-url');
    const linkInsert = document.getElementById('bdn-lbc-link-insert');
    const linkCancel = document.getElementById('bdn-lbc-link-cancel');

    document.getElementById('bdn-lbc-tb-link')?.addEventListener('click', () => {
      saveRange();
      closeImgBar();
      const open = linkBar.style.display !== 'none';
      linkBar.style.display = open ? 'none' : 'flex';
      if (!open) {
        linkUrl.value = 'https://';
        linkUrl.focus();
        linkUrl.select();
      }
    });

    function closeLinkBar() { linkBar.style.display = 'none'; }

    linkCancel?.addEventListener('click', closeLinkBar);

    linkInsert?.addEventListener('click', () => {
      const url = linkUrl.value.trim();
      if (!url || url === 'https://') { closeLinkBar(); return; }
      restoreRange();
      const sel = window.getSelection();
      if (sel && sel.rangeCount) {
        if (!sel.isCollapsed) {
          // Wrap selected text in <a>
          document.execCommand('createLink', false, url);
        } else {
          // No selection — insert URL as link text
          const a = document.createElement('a');
          a.href = url;
          a.textContent = url;
          const range = sel.getRangeAt(0);
          range.insertNode(a);
          range.setStartAfter(a);
          range.collapse(true);
          sel.removeAllRanges();
          sel.addRange(range);
        }
      }
      closeLinkBar();
      updateSlugPreview();
    });

    linkUrl?.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); linkInsert.click(); }
      if (e.key === 'Escape') closeLinkBar();
    });

    // ── Inline image bar ──────────────────────────────────────────────────────
    const imgBar    = document.getElementById('bdn-lbc-img-bar');
    const imgFile   = document.getElementById('bdn-lbc-inline-file');
    const imgInsert = document.getElementById('bdn-lbc-img-insert');
    const imgCancel = document.getElementById('bdn-lbc-img-cancel');

    document.getElementById('bdn-lbc-tb-img')?.addEventListener('click', () => {
      saveRange();
      closeLinkBar();
      const open = imgBar.style.display !== 'none';
      imgBar.style.display = open ? 'none' : 'flex';
      if (!open) { imgFile.value = ''; }
    });

    function closeImgBar() { imgBar.style.display = 'none'; }

    imgCancel?.addEventListener('click', closeImgBar);

    imgInsert?.addEventListener('click', () => {
      const file = imgFile.files[0];
      if (!file) { closeImgBar(); return; }
      const reader = new FileReader();
      reader.onload = evt => {
        restoreRange();
        const img = document.createElement('img');
        img.src = evt.target.result;
        img.alt = file.name.replace(/\.[^.]+$/, '');
        img.style.cssText = 'max-width:100%;height:auto;display:block;margin:6px 0';
        const sel = window.getSelection();
        if (sel && sel.rangeCount) {
          const range = sel.getRangeAt(0);
          range.collapse(false);
          range.insertNode(img);
          // Move cursor after image
          const br = document.createElement('br');
          range.setStartAfter(img);
          range.insertNode(br);
          range.setStartAfter(br);
          range.collapse(true);
          sel.removeAllRanges();
          sel.addRange(range);
        }
        closeImgBar();
        updateSlugPreview();
      };
      reader.readAsDataURL(file);
    });
  }

  bindToolbar();

  // ── Embed auto-detection ──────────────────────────────────────────────────
  (function initEmbedDetection() {
    const editor = document.getElementById('bdn-lbc-content');
    if (!editor) return;

    const OEMBED_REGEX = /^(https?:\/\/\S+)$/;

    editor.addEventListener('paste', async (e) => {
      const text = (e.clipboardData || window.clipboardData)?.getData('text/plain')?.trim();
      if (!text || !OEMBED_REGEX.test(text)) return;

      const embedDomains = ['youtube.com','youtu.be','twitter.com','x.com','vimeo.com','instagram.com','tiktok.com','facebook.com'];
      try {
        const url = new URL(text).hostname.replace('www.','');
        if (!embedDomains.some(d => url.includes(d))) return;
      } catch { return; }

      e.preventDefault();

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
        placeholder.outerHTML = `<p><a href="${esc(text)}" target="_blank">${esc(text)}</a></p>`;
        return;
      }

      const removeBtn = document.createElement('button');
      removeBtn.className = 'bdn-lbc__embed-preview__remove';
      removeBtn.innerHTML = '&times;';
      removeBtn.title = 'Remove embed';
      removeBtn.addEventListener('click', () => { placeholder.remove(); });
      placeholder.appendChild(removeBtn);
    });
  })();


  function syncIndicator(status) {
    const dot=document.getElementById('bdn-lbc-indicator');
    const ttl=document.getElementById('bdn-lbc-title');
    if (!dot||!ttl) return;
    dot.className='bdn-lbc__indicator'+(status==='live'?' is-live':'');
    ttl.textContent=status==='live'?'Live blog — live':status==='scheduled'?'Live blog — scheduled':'Live blog — ended';
  }

  function updateSlugPreview() {
    const text=document.getElementById('bdn-lbc-headline')?.value.trim()||document.getElementById('bdn-lbc-content')?.innerText.trim();
    const p=document.getElementById('bdn-lbc-slug-preview');
    if (!p) return;
    if (!text) { p.style.display='none'; return; }
    const d=new Date(); const ymd=`${d.getFullYear()}/${String(d.getMonth()+1).padStart(2,'0')}/${String(d.getDate()).padStart(2,'0')}`;
    document.getElementById('bdn-lbc-slug-val').textContent=`…/${ymd}/liveblog/${naiveSlug(text)}/`;
    document.getElementById('bdn-lbc-slug-ai').textContent=cfg.has_api_key?'AI-refined on publish':'keyword fallback';
    p.style.display='flex';
  }


  // ── Inline image uploader ──────────────────────────────────────────────────
  // Finds <img src="data:..."> nodes in HTML, uploads each file to the WP
  // media library via the core /wp/v2/media REST endpoint, and replaces the
  // data URL with the permanent attachment URL before the entry is saved.
  // This keeps wp_kses_post happy and ensures images are in the media library.

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
    try {
      // Upload any base64 inline images to the WP media library before submitting,
      // so wp_kses_post on the server doesn't strip them.
      const { html: cleanContent, failures: imgFailures } = await uploadInlineImages(content);
      if (imgFailures > 0) {
        const proceed = confirm(`${imgFailures} inline image(s) failed to upload and were removed. Publish anyway?`);
        if (!proceed) { btn.disabled=false; btn.textContent=editingId?'Save changes':'Publish entry'; return; }
      }
      const payload = { title, content: cleanContent, byline, label,
        image_id:      selectedImg.id || 0,
        image_caption: caption,
        image_credit:  credit,
      };
      const entry=editingId
        ? await api(`entries/${editingId}`,{method:'POST',body:JSON.stringify(payload)})
        : await api('entries',{method:'POST',body:JSON.stringify({post_id:POST_ID,...payload})});
      flash(editingId?'Entry updated.':'Published.');
      cancelEdit();
      loadComposerEntries();
      // Push to reader widget immediately — no polling delay.
      document.querySelectorAll('.bdn-liveblog').forEach(w=>{
        if (parseInt(w.dataset.postId,10)!==POST_ID) return;
        editingId ? w._updateEntry?.(entry) : w._prependEntry?.(entry);
      });
    } catch(e) { flash('Error: '+e.message,true); }
    finally { btn.disabled=false; btn.textContent='Publish entry'; }
  }

  function cancelEdit() {
    editingId=null; selectedImg={id:0,url:'',thumb:'',alt:''};
    ['bdn-lbc-headline','bdn-lbc-byline','bdn-lbc-label','bdn-lbc-caption','bdn-lbc-credit'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
    const _ed=document.getElementById('bdn-lbc-content'); if(_ed) _ed.innerHTML='';
    document.getElementById('bdn-lbc-slug-preview').style.display='none';
    document.getElementById('bdn-lbc-photo-preview').style.display='none';
    document.getElementById('bdn-lbc-photo-meta').style.display='none';
    document.getElementById('bdn-lbc-photo-btn').textContent='Add photo';
    document.getElementById('bdn-lbc-submit').textContent='Publish entry';
    document.getElementById('bdn-lbc-cancel').style.display='none';
  }

  async function loadComposerEntries() {
    const el=document.getElementById('bdn-lbc-entries'); if(!el) return;
    try {
      const data=await api(`entries?post_id=${POST_ID}`);
      document.getElementById('bdn-lbc-count').textContent=`(${data.total})`;
      el.innerHTML='';
      if (!data.entries.length) { el.innerHTML='<p class="bdn-lbc__loading">No entries yet.</p>'; return; }
      data.entries.forEach(e=>el.appendChild(buildComposerEntry(e)));
    } catch(err) { el.innerHTML='<p class="bdn-lbc__loading">Could not load.</p>'; }
  }

  function buildComposerEntry(e) {
    const div=document.createElement('div');
    div.className='bdn-lbc__entry'+(e.pinned?' bdn-lbc__entry--pinned':'')+(e.highlight?' bdn-lbc__entry--highlighted':''); div.dataset.id=e.id;
    const pinLabel=e.pinned?'&#x1F4CC; Pinned':'Pin';
    div.innerHTML=`
      <div class="bdn-lbc__entry-meta">
        ${e.pinned?'<span class="bdn-lbc__pin-badge">Pinned</span>':''}
        ${e.label?`<span class="bdn-lbc__entry-label">${esc(e.label)}</span>`:''}
        <span class="bdn-lbc__entry-time">${fmtTime(e.published)}</span>
        <span class="bdn-lbc__entry-byline">${esc(e.byline)}</span>
      </div>
      ${e.title?`<strong class="bdn-lbc__entry-title">${esc(decodeEntities(e.title))}</strong>`:''}

      <div class="bdn-lbc__entry-body">${sanitizeHtml(e.content)}</div>
      <div class="bdn-lbc__entry-actions">
        <a href="${esc(e.entry_url||e.anchor_url||'#')}" target="_blank" class="bdn-lbc__entry-url">${esc(e.seo_slug||'#'+e.id)}</a>
        <button class="bdn-lbc__act bdn-lbc__act--highlight${e.highlight?' bdn-lbc__act--highlighted':''}" data-id="${e.id}">${e.highlight?'&#x2605; Key moment':'&#x2606; Key moment'}</button>
        <button class="bdn-lbc__act bdn-lbc__act--pin${e.pinned?' bdn-lbc__act--pinned':''}" data-id="${e.id}">${pinLabel}</button>
        <button class="bdn-lbc__act bdn-lbc__act--edit" data-id="${e.id}">Edit</button>
        <button class="bdn-lbc__act bdn-lbc__act--regen" data-id="${e.id}">&#x21BB; Slug</button>
        <button class="bdn-lbc__act bdn-lbc__act--delete" data-id="${e.id}">Delete</button>

      </div>`;
    div.querySelector('.bdn-lbc__act--pin')?.addEventListener('click',()=>togglePin(e.id, !e.pinned));
    div.querySelector('.bdn-lbc__act--highlight')?.addEventListener('click',()=>toggleHighlight(e.id, !e.highlight));
    div.querySelector('.bdn-lbc__act--edit')?.addEventListener('click',()=>startEdit(e));
    div.querySelector('.bdn-lbc__act--regen')?.addEventListener('click',()=>regenSlug(e.id,div));
    div.querySelector('.bdn-lbc__act--delete')?.addEventListener('click',()=>deleteEntry(e.id));
    return div;
  }

  function startEdit(e) {
    editingId=e.id;
    document.getElementById('bdn-lbc-headline').value=e.title||'';
    const _se=document.getElementById('bdn-lbc-content'); if(_se) _se.innerHTML=sanitizeHtml(e.content);
    document.getElementById('bdn-lbc-byline').value=e.byline||'';
    document.getElementById('bdn-lbc-label').value=e.label||'';
    document.getElementById('bdn-lbc-caption').value=e.image_caption||'';
    document.getElementById('bdn-lbc-credit').value=e.image_credit||'';
    // Restore photo state
    if (e.image_id) {
      selectedImg={id:e.image_id, url:e.image_url||'', thumb:e.image_thumb||e.image_url||'', alt:e.image_alt||''};
      const thumb=document.getElementById('bdn-lbc-photo-thumb');
      thumb.src=selectedImg.thumb; thumb.alt=selectedImg.alt;
      document.getElementById('bdn-lbc-photo-preview').style.display='flex';
      document.getElementById('bdn-lbc-photo-meta').style.display='';
      document.getElementById('bdn-lbc-photo-btn').textContent='Change photo';
    }
    document.getElementById('bdn-lbc-submit').textContent='Save changes';
    document.getElementById('bdn-lbc-cancel').style.display='';
    document.getElementById('bdn-lbc-content')?.scrollIntoView({behavior:'smooth',block:'center'});
    document.getElementById('bdn-lbc-content')?.focus();
  }

  async function togglePin(id, shouldPin) {
    try {
      await api(`entries/${id}`, {
        method: 'POST',
        body: JSON.stringify({ pinned: shouldPin ? 1 : 0 }),
      });
      // Reload composer list so pinned state reflects everywhere
      loadComposerEntries();
      // Reload reader feed so pinned entry floats to top
      document.querySelectorAll('.bdn-liveblog').forEach(w => {
        if (parseInt(w.dataset.postId, 10) !== POST_ID) return;
        // Trigger a fresh fetch rather than re-sorting in memory
        // so the server-authoritative pin state is used
        const entriesEl = w.querySelector('.bdn-lb-entries');
        if (!entriesEl) return;
        entriesEl.innerHTML = '<div class="bdn-lb-loading"><span class="bdn-lb-spinner"></span></div>';
        api(`entries?post_id=${POST_ID}`).then(data => {
          entriesEl.innerHTML = '';
          // Pinned entry first, then chronological
          const sorted = [...(data.entries||[])].sort((a,b) => {
            if (a.pinned && !b.pinned) return -1;
            if (!a.pinned && b.pinned) return 1;
            return 0; // already newest-first from API
          });
          sorted.forEach(entry => {
            const el = w._buildEntryEl ? w._buildEntryEl(entry, false) : null;
            if (el) entriesEl.appendChild(el);
          });
        }).catch(() => {});
      });
    } catch(e) { alert('Could not update pin: ' + e.message); }
  }

  async function toggleHighlight(id, shouldHighlight) {
    try {
      await api(`entries/${id}`, {
        method: 'POST',
        body: JSON.stringify({ highlight: shouldHighlight ? 1 : 0 }),
      });
      loadComposerEntries();
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

  async function deleteEntry(id) {
    if (!confirm('Delete this entry?')) return;
    try {
      await api(`entries/${id}`,{method:'DELETE'});
      document.querySelector(`.bdn-lbc__entry[data-id="${id}"]`)?.remove();
      document.querySelectorAll('.bdn-liveblog').forEach(w=>w._removeEntry?.(id));
      const c=document.getElementById('bdn-lbc-count');
      if (c) { const n=parseInt(c.textContent.replace(/\D/g,''),10)||1; c.textContent=`(${n-1})`; }
    } catch(e) { alert('Delete failed: '+e.message); }
  }

  async function regenSlug(id,row) {
    try {
      const data=await api(`entries/${id}/regenerate-slug`,{method:'POST'});
      const a=row?.querySelector('.bdn-lbc__entry-url');
      if (a) { a.textContent=data.seo_slug||a.textContent; a.href=data.entry_url||a.href; }
    } catch(e) { alert('Slug regen failed: '+e.message); }
  }

  function flash(msg,isError) {
    const el=document.getElementById('bdn-lbc-msg'); if(!el) return;
    el.textContent=msg; el.style.color=isError?'#c8102e':'#1a5936';
    clearTimeout(el._t); el._t=setTimeout(()=>{el.textContent='';},4000);
  }

})();
