/* global BDN_LB_Admin */
(function () {
  'use strict';

  const root = document.getElementById('bdn-lb-admin-root');
  if (!root) return;

  const POST_ID   = parseInt(root.dataset.postId, 10);
  const REST_URL  = root.dataset.restUrl;
  const NONCE     = root.dataset.nonce;
  const HAS_KEY   = root.dataset.hasApiKey === 'true';

  let currentStatus = root.dataset.status || 'ended';
  let entries = [];

  const headers = {
    'Content-Type': 'application/json',
    'X-WP-Nonce': NONCE,
  };

  // ── Render ──────────────────────────────────────────────────────────────────

  function render() {
    root.innerHTML = `
      <div class="bdn-lb-admin">

        <!-- Status bar -->
        <div class="bdn-lb-admin__status-bar">
          <span class="bdn-lb-admin__status-dot ${currentStatus === 'live' ? 'is-live' : ''}"></span>
          <strong>${currentStatus === 'live' ? 'LIVE' : currentStatus === 'scheduled' ? 'Scheduled' : 'Ended'}</strong>
          <span class="bdn-lb-admin__status-actions">
            ${currentStatus !== 'live'     ? '<button data-set-status="live">Go Live</button>' : ''}
            ${currentStatus !== 'ended'    ? '<button data-set-status="ended">End Blog</button>' : ''}
            ${currentStatus !== 'scheduled'? '<button data-set-status="scheduled">Schedule</button>' : ''}
          </span>
        </div>

        <!-- Composer -->
        <div class="bdn-lb-admin__composer">
          <h4>New Entry</h4>
          <input type="text"  id="lb-new-title"   placeholder="Headline (optional)" />
          <textarea           id="lb-new-content"  rows="5" placeholder="Entry text…"></textarea>
          <div class="bdn-lb-admin__composer-row">
            <input type="text" id="lb-new-byline"  placeholder="Byline override (optional)" />
            <input type="text" id="lb-new-label"   placeholder="Label e.g. BREAKING" />
          </div>
          <div class="bdn-lb-admin__slug-preview" id="lb-slug-preview" style="display:none">
            <span class="bdn-lb-admin__slug-label">URL preview:</span>
            <code id="lb-slug-value"></code>
            <span class="bdn-lb-admin__slug-note">${HAS_KEY ? '(AI-generated after save)' : '(keyword fallback — add API key for AI slugs)'}</span>
          </div>
          <div class="bdn-lb-admin__composer-actions">
            <button id="lb-publish-btn" class="button button-primary">Publish Entry</button>
            <span   id="lb-publish-status" class="bdn-lb-admin__publish-status"></span>
          </div>
        </div>

        <!-- Entry list -->
        <div class="bdn-lb-admin__entries" id="lb-entry-list">
          <h4>Entries <span id="lb-entry-count" class="bdn-lb-admin__count"></span></h4>
          <div id="lb-entries-inner">
            <p class="bdn-lb-admin__loading">Loading entries…</p>
          </div>
        </div>
      </div>
    `;

    bindComposer();
    bindStatusButtons();
    loadEntries();

    // Show a naive slug preview while the user types.
    document.getElementById('lb-new-content').addEventListener('input', updateSlugPreview);
    document.getElementById('lb-new-title').addEventListener('input', updateSlugPreview);
  }

  // ── Slug preview (client-side naive version) ─────────────────────────────

  const STOP = new Set(['a','an','the','and','or','but','in','on','at','to','for','of','with','by','from','is','was','are','were','be','been']);

  function naiveSlug(text) {
    return text.toLowerCase()
      .replace(/<[^>]+>/g, '')
      .split(/\s+/)
      .map(w => w.replace(/[^a-z0-9]/g, ''))
      .filter(w => w && !STOP.has(w))
      .slice(0, 8)
      .join('-')
      .slice(0, 60);
  }

  function updateSlugPreview() {
    const title   = document.getElementById('lb-new-title').value.trim();
    const content = document.getElementById('lb-new-content').value.trim();
    const text    = title || content;
    if (!text) {
      document.getElementById('lb-slug-preview').style.display = 'none';
      return;
    }
    const today  = new Date();
    const ymd    = `${today.getFullYear()}/${String(today.getMonth()+1).padStart(2,'0')}/${String(today.getDate()).padStart(2,'0')}`;
    const slug   = naiveSlug(text);
    document.getElementById('lb-slug-value').textContent = `bangordailynews.com/${ymd}/liveblog/${slug}/`;
    document.getElementById('lb-slug-preview').style.display = 'flex';
  }

  // ── API calls ────────────────────────────────────────────────────────────

  async function apiFetch(path, opts = {}) {
    const res = await fetch(REST_URL + path, { headers, ...opts });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || `HTTP ${res.status}`);
    }
    return res.json();
  }

  async function loadEntries() {
    try {
      const data = await apiFetch(`entries?post_id=${POST_ID}`);
      entries = data.entries || [];
      renderEntryList();
      document.getElementById('lb-entry-count').textContent = `(${data.total})`;
    } catch (e) {
      document.getElementById('lb-entries-inner').innerHTML = `<p class="bdn-lb-admin__error">Error loading entries: ${e.message}</p>`;
    }
  }

  function renderEntryList() {
    const el = document.getElementById('lb-entries-inner');
    if (!entries.length) {
      el.innerHTML = '<p class="bdn-lb-admin__empty">No entries yet.</p>';
      return;
    }
    el.innerHTML = entries.map(e => `
      <div class="bdn-lb-admin__entry" data-id="${e.id}">
        <div class="bdn-lb-admin__entry-meta">
          ${e.label ? `<span class="bdn-lb-label">${esc(e.label)}</span>` : ''}
          <time>${formatTime(e.published)}</time>
          <span class="bdn-lb-admin__entry-author">${esc(e.byline)}</span>
        </div>
        ${e.title ? `<strong class="bdn-lb-admin__entry-title">${esc(e.title)}</strong>` : ''}
        <div class="bdn-lb-admin__entry-body">${e.content}</div>
        <div class="bdn-lb-admin__entry-urls">
          <a href="${esc(e.entry_url)}" target="_blank" title="Canonical entry URL">
            🔗 ${esc(e.seo_slug || '(generating…)')}
          </a>
          <button class="bdn-lb-admin__regen-slug button button-small" data-id="${e.id}" title="Regenerate AI slug">↻ Regen slug</button>
          <button class="bdn-lb-admin__delete button button-small" data-id="${e.id}">Delete</button>
        </div>
      </div>
    `).join('');

    el.querySelectorAll('.bdn-lb-admin__delete').forEach(btn => {
      btn.addEventListener('click', () => deleteEntry(parseInt(btn.dataset.id, 10)));
    });
    el.querySelectorAll('.bdn-lb-admin__regen-slug').forEach(btn => {
      btn.addEventListener('click', () => regenSlug(parseInt(btn.dataset.id, 10)));
    });
  }

  // ── Composer ─────────────────────────────────────────────────────────────

  function bindComposer() {
    document.getElementById('lb-publish-btn').addEventListener('click', publishEntry);
  }

  async function publishEntry() {
    const title   = document.getElementById('lb-new-title').value.trim();
    const content = document.getElementById('lb-new-content').value.trim();
    const byline  = document.getElementById('lb-new-byline').value.trim();
    const label   = document.getElementById('lb-new-label').value.trim();
    const statusEl = document.getElementById('lb-publish-status');
    const btn      = document.getElementById('lb-publish-btn');

    if (!content) { statusEl.textContent = 'Content is required.'; return; }

    btn.disabled = true;
    statusEl.textContent = 'Publishing…';

    try {
      const entry = await apiFetch('entries', {
        method: 'POST',
        body: JSON.stringify({ post_id: POST_ID, title, content, byline, label }),
      });
      entries.unshift(entry);
      renderEntryList();
      document.getElementById('lb-entry-count').textContent = `(${entries.length})`;
      // Clear composer
      ['lb-new-title','lb-new-content','lb-new-byline','lb-new-label'].forEach(id => {
        document.getElementById(id).value = '';
      });
      document.getElementById('lb-slug-preview').style.display = 'none';
      statusEl.textContent = '✓ Published';
      setTimeout(() => { statusEl.textContent = ''; }, 3000);
    } catch (e) {
      statusEl.textContent = 'Error: ' + e.message;
    } finally {
      btn.disabled = false;
    }
  }

  // ── Slug regeneration ─────────────────────────────────────────────────────

  async function regenSlug(id) {
    try {
      const data = await apiFetch(`entries/${id}/regenerate-slug`, { method: 'POST' });
      const entry = entries.find(e => e.id === id);
      if (entry) {
        entry.seo_slug  = data.seo_slug;
        entry.entry_url = data.entry_url;
        renderEntryList();
      }
    } catch (e) {
      alert('Slug regeneration failed: ' + e.message);
    }
  }

  // ── Delete ────────────────────────────────────────────────────────────────

  async function deleteEntry(id) {
    if (!confirm('Delete this entry?')) return;
    try {
      await apiFetch(`entries/${id}`, { method: 'DELETE' });
      entries = entries.filter(e => e.id !== id);
      renderEntryList();
      document.getElementById('lb-entry-count').textContent = `(${entries.length})`;
    } catch (e) {
      alert('Delete failed: ' + e.message);
    }
  }

  // ── Status buttons ────────────────────────────────────────────────────────

  function bindStatusButtons() {
    root.querySelectorAll('[data-set-status]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const status = btn.dataset.setStatus;
        try {
          await apiFetch('status', {
            method: 'POST',
            body: JSON.stringify({ post_id: POST_ID, status }),
          });
          currentStatus = status;
          render(); // full re-render to update status bar
        } catch (e) {
          alert('Could not update status: ' + e.message);
        }
      });
    });
  }

  // ── Utilities ─────────────────────────────────────────────────────────────

  function esc(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function formatTime(iso) {
    const d = new Date(iso);
    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })
      + ' · ' + d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  // ── Boot ──────────────────────────────────────────────────────────────────

  // Pre-fetch the current reporter's byline data so the composer can
  // show their name and photo without them having to type anything.
  let reporterProfile = null;

  fetch(REST_URL + 'me', { headers })
    .then(r => r.json())
    .then(data => {
      reporterProfile = data;
      const bylineField = document.getElementById('lb-new-byline');
      if (bylineField && !bylineField.value && data.name) {
        bylineField.placeholder = data.name + ' (your profile)';
      }
    })
    .catch(() => {});

  render();
})();
