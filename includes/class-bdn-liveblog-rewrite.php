<?php
/**
 * BDN_Liveblog_Rewrite
 *
 * Registers WordPress rewrite rules so that:
 *   /YYYY/MM/DD/liveblog/{slug}/
 * resolves to a standalone entry page with proper SEO meta.
 *
 * Also handles the wp_head output (canonical, og:, twitter:) for those pages.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BDN_Liveblog_Rewrite {

    /** Query var used internally to identify an entry page request. */
    const QV_ENTRY = 'bdn_lb_entry_slug';

    public static function register() {
        add_action( 'init',                   [ __CLASS__, 'add_rules' ] );
        add_filter( 'query_vars',             [ __CLASS__, 'add_query_vars' ] );
        add_action( 'template_redirect',      [ __CLASS__, 'maybe_render_entry' ] );
        add_action( 'wp_head',                [ __CLASS__, 'inject_seo_meta' ], 1 );
        add_filter( 'document_title_parts',   [ __CLASS__, 'filter_title' ] );
    }

    // ── Rewrite rule ──────────────────────────────────────────────────────────

    public static function add_rules() {
        // Match /2020/03/21/liveblog/some-seo-slug/
        add_rewrite_rule(
            '^(\d{4})/(\d{2})/(\d{2})/liveblog/([^/]+)/?$',
            'index.php?' . self::QV_ENTRY . '=$matches[4]',
            'top'
        );
    }

    public static function add_query_vars( array $vars ): array {
        $vars[] = self::QV_ENTRY;
        return $vars;
    }

    // ── Template redirect ─────────────────────────────────────────────────────

    public static function maybe_render_entry() {
        $slug = get_query_var( self::QV_ENTRY );
        if ( ! $slug ) return;

        $entry = self::find_entry_by_slug( sanitize_title( $slug ) );

        if ( ! $entry ) {
            // Slug not found — send a real 404.
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
            // Fall through to WordPress's normal 404 template.
            return;
        }

        // Store on the global so other hooks (wp_head, etc.) can read it.
        $GLOBALS['bdn_lb_current_entry'] = $entry;

        // Render the standalone entry page using our template.
        self::render_entry_page( $entry );
        exit;
    }

    // ── SEO meta (canonical + OG) injected into wp_head ──────────────────────

    public static function inject_seo_meta() {
        $entry = $GLOBALS['bdn_lb_current_entry'] ?? null;
        if ( ! $entry ) return;

        $canonical   = BDN_Liveblog_Slug::get_entry_url( $entry->ID, $entry->post_content, get_the_title( $entry ) );
        $parent_id   = (int) get_post_meta( $entry->ID, '_bdn_lb_parent_post', true );
        $parent_url  = $parent_id ? get_permalink( $parent_id ) : home_url();
        $parent_title = $parent_id ? get_the_title( $parent_id ) : get_bloginfo( 'name' );
        $description = get_post_meta( $entry->ID, '_bdn_lb_meta_description', true )
                       ?: wp_trim_words( wp_strip_all_tags( $entry->post_content ), 30 );
        $title       = get_the_title( $entry ) ?: wp_trim_words( wp_strip_all_tags( $entry->post_content ), 12 );
        $full_title  = $title . ' — ' . $parent_title . ' | Bangor Daily News';
        $published   = get_post_time( 'c', true, $entry );
        $modified    = get_post_modified_time( 'c', true, $entry );
        $author_name = get_post_meta( $entry->ID, '_bdn_lb_byline', true )
                       ?: get_the_author_meta( 'display_name', $entry->post_author );

        // Thumbnail / OG image — fall back to parent post's featured image.
        $og_image = '';
        if ( has_post_thumbnail( $entry->ID ) ) {
            $og_image = get_the_post_thumbnail_url( $entry->ID, 'large' );
        } elseif ( $parent_id && has_post_thumbnail( $parent_id ) ) {
            $og_image = get_the_post_thumbnail_url( $parent_id, 'large' );
        }

        ?>
<!-- BDN Live Blog entry SEO meta -->
<link rel="canonical" href="<?php echo esc_url( $canonical ); ?>" />

<!-- Open Graph -->
<meta property="og:type"               content="article" />
<meta property="og:url"                content="<?php echo esc_attr( $canonical ); ?>" />
<meta property="og:title"              content="<?php echo esc_attr( $full_title ); ?>" />
<meta property="og:description"        content="<?php echo esc_attr( $description ); ?>" />
<meta property="og:site_name"          content="Bangor Daily News" />
<meta property="article:published_time" content="<?php echo esc_attr( $published ); ?>" />
<meta property="article:modified_time"  content="<?php echo esc_attr( $modified ); ?>" />
<meta property="article:author"         content="<?php echo esc_attr( $author_name ); ?>" />
<?php if ( $og_image ) : ?>
<meta property="og:image"              content="<?php echo esc_attr( $og_image ); ?>" />
<?php endif; ?>

<?php
$keywords = get_post_meta( $entry->ID, '_bdn_lb_keywords', true );
if ( $keywords ) : ?>
<meta name="keywords" content="<?php echo esc_attr( $keywords ); ?>" />
<?php endif; ?>

<!-- Twitter Card -->
<meta name="twitter:card"        content="summary_large_image" />
<meta name="twitter:title"       content="<?php echo esc_attr( $full_title ); ?>" />
<meta name="twitter:description" content="<?php echo esc_attr( $description ); ?>" />
<?php if ( $og_image ) : ?>
<meta name="twitter:image"       content="<?php echo esc_attr( $og_image ); ?>" />
<?php endif; ?>

<!-- Schema.org NewsArticle -->
<script type="application/ld+json">
<?php echo wp_json_encode( [
    '@context'         => 'https://schema.org',
    '@type'            => 'NewsArticle',
    'headline'         => $title,
    'description'      => $description,
    'url'              => $canonical,
    'datePublished'    => $published,
    'dateModified'     => $modified,
    'author'           => [ '@type' => 'Person', 'name' => $author_name ],
    'publisher'        => [
        '@type' => 'NewsMediaOrganization',
        'name'  => 'Bangor Daily News',
        'url'   => 'https://www.bangordailynews.com',
        'logo'  => [
            '@type' => 'ImageObject',
            'url'   => 'https://bdn-data.s3.amazonaws.com/uploads/2024/09/Just_BDN_Square-180px.jpg',
        ],
    ],
    'isPartOf' => [
        '@type' => 'LiveBlogPosting',
        'url'   => $parent_url,
        'name'  => $parent_title,
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); ?>
</script>
        <?php
    }

    public static function filter_title( array $parts ): array {
        $entry = $GLOBALS['bdn_lb_current_entry'] ?? null;
        if ( ! $entry ) return $parts;

        $title = get_the_title( $entry ) ?: wp_trim_words( wp_strip_all_tags( $entry->post_content ), 12 );
        $parent_id = (int) get_post_meta( $entry->ID, '_bdn_lb_parent_post', true );
        $parts['title'] = $title;
        if ( $parent_id ) {
            $parts['site'] = get_the_title( $parent_id ) . ' | Bangor Daily News';
        }
        return $parts;
    }

    // ── Standalone entry page HTML ────────────────────────────────────────────

    private static function render_entry_page( WP_Post $entry ) {
        $parent_id    = (int) get_post_meta( $entry->ID, '_bdn_lb_parent_post', true );
        $parent_url   = $parent_id ? get_permalink( $parent_id ) : home_url();
        $parent_title = $parent_id ? get_the_title( $parent_id ) : 'Live Blog';
        $anchor_url   = BDN_Liveblog_Slug::get_anchor_url( $entry->ID );
        $canonical    = BDN_Liveblog_Slug::get_entry_url( $entry->ID, $entry->post_content, get_the_title( $entry ) );
        $title        = get_the_title( $entry );
        $content      = BDN_Liveblog_API::render_entry_content( $entry->post_content );
        $byline_override = get_post_meta( $entry->ID, '_bdn_lb_byline', true );
        if ( $byline_override ) {
            $byline = $byline_override;
            $avatar = get_avatar_url( $entry->post_author, [ 'size' => 48 ] );
        } else {
            $profile = BDN_Liveblog_Profiles::get_byline_data( (int) $entry->post_author );
            $byline  = $profile['name'];
            $avatar  = $profile['photo_url'];
        }
        $label      = get_post_meta( $entry->ID, '_bdn_lb_label', true );
        $time_human = get_post_time( 'g:i a', false, $entry );
        $time_date  = get_post_time( 'F j, Y', false, $entry );
        $time_iso   = get_post_time( 'c', true, $entry );

        // We intentionally call get_header() / get_footer() to inherit the
        // active theme's chrome (nav, ads, etc.) just like any other page.
        get_header();
        ?>
        <main id="main" class="bdn-lb-entry-page">
            <div class="bdn-lb-entry-page__inner">

                <!-- Breadcrumb back to live blog -->
                <nav class="bdn-lb-breadcrumb" aria-label="Breadcrumb">
                    <a href="<?php echo esc_url( $parent_url ); ?>">
                        ← Back to live blog: <?php echo esc_html( $parent_title ); ?>
                    </a>
                </nav>

                <article id="entry-<?php echo $entry->ID; ?>"
                         class="bdn-lb-entry-page__article"
                         itemscope itemtype="https://schema.org/NewsArticle">

                    <header class="bdn-lb-entry-page__header">
                        <?php if ( $label ) : ?>
                            <span class="bdn-lb-label"><?php echo esc_html( $label ); ?></span>
                        <?php endif; ?>

                        <?php if ( $title ) : ?>
                            <h1 class="bdn-lb-entry-page__title" itemprop="headline">
                                <?php echo esc_html( $title ); ?>
                            </h1>
                        <?php endif; ?>

                        <div class="bdn-lb-entry-page__byline">
                            <?php if ( $avatar ) : ?>
                                <img src="<?php echo esc_url( $avatar ); ?>"
                                     alt="<?php echo esc_attr( $byline ); ?>"
                                     class="bdn-lb-avatar"
                                     width="40" height="40" />
                            <?php endif; ?>
                            <div class="bdn-lb-entry-page__byline-text">
                                <span class="bdn-lb-entry-page__author" itemprop="author"><?php echo esc_html( $byline ); ?></span>
                                <time class="bdn-lb-entry-page__time"
                                      datetime="<?php echo esc_attr( $time_iso ); ?>"
                                      itemprop="datePublished">
                                    <?php echo esc_html( $time_human . ' · ' . $time_date ); ?>
                                </time>
                            </div>
                        </div>
                    </header>

                    <div class="bdn-lb-entry-page__content entry-content" itemprop="articleBody">
                        <?php echo $content; // already filtered through the_content ?>
                    </div>

                    <footer class="bdn-lb-entry-page__footer">
                        <a class="bdn-lb-entry-page__view-context" href="<?php echo esc_url( $anchor_url ); ?>">
                            View in context → <?php echo esc_html( $parent_title ); ?>
                        </a>

                        <!-- Share buttons -->
                        <div class="bdn-lb-entry-page__share">
                            <span>Share:</span>
                            <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode( $canonical ); ?>"
                               target="_blank" rel="noopener noreferrer" aria-label="Share on X">X / Twitter</a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode( $canonical ); ?>"
                               target="_blank" rel="noopener noreferrer" aria-label="Share on Facebook">Facebook</a>
                            <button class="bdn-lb-copy-link"
                                    data-url="<?php echo esc_attr( $canonical ); ?>"
                                    aria-label="Copy link">Copy link</button>
                        </div>
                    </footer>

                </article>
            </div>
        </main>
        <script>
        document.querySelector('.bdn-lb-copy-link')?.addEventListener('click', function() {
            navigator.clipboard.writeText(this.dataset.url).then(() => {
                this.textContent = 'Copied!';
                setTimeout(() => { this.textContent = 'Copy link'; }, 2000);
            });
        });
        </script>
        <?php
        get_footer();
    }

    // ── Lookup ────────────────────────────────────────────────────────────────

    /**
     * Find a published bdn_lb_entry post whose _bdn_lb_seo_slug meta matches.
     */
    private static function find_entry_by_slug( string $slug ): ?WP_Post {
        $posts = get_posts( [
            'post_type'   => BDN_Liveblog_Post_Type::CPT,
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_query'  => [
                [ 'key' => '_bdn_lb_seo_slug', 'value' => $slug ],
            ],
        ] );
        return $posts[0] ?? null;
    }
}
