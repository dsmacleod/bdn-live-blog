# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project context

This is a WordPress plugin for the Bangor Daily News. It was vibe-coded by non-developer newsroom staff and is now in the DevOps pipeline. Changes made here go to a dev site for review before production. The primary users — reporters and editors — work entirely from the **public story URL** while logged in; they rarely visit WP Admin after initial setup.

## Deployment

There is no build step. The plugin is deployed by uploading the directory to WordPress (`wp-content/plugins/bdn-liveblog/`). No npm, no Composer, no compilation.

**After uploading changes to the dev site:**
- CSS and JS are cache-busted by the version string. Bump `BDN_LIVEBLOG_VERSION` in `bdn-liveblog.php` (both the plugin header `Version:` comment and the `define()` constant) whenever `liveblog.css` or `liveblog.js` change, otherwise browsers serve the cached old files.
- If entry URLs return 404 after a fresh install, go to **Settings → Live Blog → Flush Rewrite Rules** once.

## Architecture

### Data model

Entries are stored as a custom post type (`bdn_lb_entry`) — not post meta — so they get WordPress revisions and caching for free. The parent story post is a standard `post` type. The relationship is stored in `_bdn_lb_parent_post` meta on each entry.

Key post meta on entries:
- `_bdn_lb_parent_post` — ID of the parent story post
- `_bdn_lb_seo_slug` — cached AI-generated URL slug
- `_bdn_lb_pinned`, `_bdn_lb_highlight`, `_bdn_lb_label`, `_bdn_lb_byline`
- `_bdn_lb_image_id`, `_bdn_lb_image_caption`, `_bdn_lb_image_credit`
- `_bdn_lb_meta_description`, `_bdn_lb_keywords`, `_bdn_lb_entities` (NOTA-generated)

Key post meta on the parent story post:
- `_bdn_liveblog_enabled` — boolean toggle
- `_bdn_liveblog_status` — `live` | `scheduled` | `ended`

### Request / render flow

1. **Block editor sidebar** (`admin/editor-panel.js`) — Gutenberg panel that writes `_bdn_liveblog_enabled` and `_bdn_liveblog_status` to the parent post via WP REST.
2. **Auto-inject** (`bdn-liveblog.php` hooks into `the_content`) — when `_bdn_liveblog_enabled` is true the shortcode output is appended automatically. The `[bdn_liveblog]` shortcode can also be placed manually.
3. **Shortcode** (`includes/class-bdn-liveblog-shortcode.php`) — renders the reader widget HTML and the composer root div. The composer is invisible to readers; it appears only for logged-in users with `edit_posts`.
4. **Front-end JS** (`public/js/liveblog.js`) — single IIFE. On load it fetches entries via the REST API, polls every 15 s (exponential backoff on failure), and manages the composer UI (publish, edit, delete, pin, highlight, embed preview).
5. **REST API** (`includes/class-bdn-liveblog-api.php`) — all editor write actions and reader reads go through `/wp-json/bdn-liveblog/v1/`. GET `/entries` responses are cached in WP transients (30 s TTL); the cache is busted on any write.

### Entry URL / SEO pipeline

When an entry is published the JS calls `POST /entries`, which triggers slug generation (`includes/class-bdn-liveblog-slug.php`):
1. Anthropic API (Claude Haiku by default, configurable at **Settings → Live Blog**)
2. NOTA SUM API (if the Nota plugin is installed)
3. Local NLP fallback (three-pass: proper nouns → news tokens → action words)

The slug is stored in `_bdn_lb_seo_slug` and cached in a 30-day transient. The rewrite rule (`includes/class-bdn-liveblog-rewrite.php`) matches `/YYYY/MM/DD/liveblog/{slug}/` and renders a standalone entry page using the theme's `get_header()` / `get_footer()` with full OG, Twitter Card, and Schema.org `NewsArticle` JSON-LD injected via `wp_head`.

### Content rendering

Entry content is run through `render_entry_content()` in `BDN_Liveblog_API` rather than `apply_filters('the_content', ...)` to avoid oEmbed timeouts and the plugin's own `the_content` filter recursing. The chain is: `$wp_embed->autoembed()` → `wptexturize` → `wpautop` → `shortcode_unautop` → `wp_filter_content_tags` → `do_shortcode` → `convert_smilies`.

### NOTA integration

`includes/class-bdn-liveblog-nota.php` wraps the Nota WordPress plugin's stored credentials (`nota_api_url`, `nota_api_key`). On entry publish, NOTA is called for meta description, keywords, and entities; results are cached in post meta. The `/summary` endpoint calls NOTA SUM to produce a "story so far" narrative, cached for 5 minutes.

### Composer embed flow

When a reporter pastes a URL from YouTube, Twitter/X, Vimeo, etc. into the composer body, the JS fetches a preview via `/wp-json/oembed/1.0/proxy` and inserts a `.bdn-lbc__embed-preview` placeholder (non-editable). On submit, those placeholders are converted back to bare URLs before sending to the API, so PHP's `$wp_embed->autoembed()` can render them server-side.

## CSS / JS conventions

- No framework, no build tooling. Vanilla JS (ES2020+), plain CSS with custom properties defined at `:root` in `liveblog.css`.
- CSS custom properties: `--lb-red` (BDN red), `--lb-green` (BDN green for bylines), `--lb-text`, `--lb-muted`, `--lb-border`, `--lb-font` (Libre Franklin), `--lb-font-ui`.
- The `.bdn-lb-time-col` class exists in the CSS but is set to `display:none` — it's a remnant of a previous two-column layout. The current layout renders the timestamp inline inside `.bdn-lb-meta`.
- Mobile breakpoint is 640 px.

## Active improvement plan

`docs/plans/2026-04-02-liveblog-improvements.md` contains a nine-task implementation plan (bug fixes + features) that has been partially executed. Refer to it before making changes to understand intended direction. Tasks 1–6 are independent bug fixes; Tasks 7–8 (embeds, highlights) touch the same files and must be done sequentially; Task 9 (configurable model) is independent.
