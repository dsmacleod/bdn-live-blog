<?php
/**
 * BDN_Liveblog_Admin
 *
 * - Adds a "Live Blog" meta box to the post editor (enable toggle + entry composer).
 * - Adds a Settings > Live Blog page for the Anthropic API key.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BDN_Liveblog_Admin {

    public static function register() {
        add_action( 'add_meta_boxes',   [ __CLASS__, 'add_meta_box' ] );
        add_action( 'admin_menu',       [ __CLASS__, 'add_settings_page' ] );
        add_action( 'admin_init',       [ __CLASS__, 'register_settings' ] );
    }

    // ── Meta box ──────────────────────────────────────────────────────────────

    public static function add_meta_box() {
        add_meta_box(
            'bdn-liveblog',
            'Live Blog',
            [ __CLASS__, 'render_meta_box' ],
            'post',
            'normal',
            'high'
        );
    }

    public static function render_meta_box( WP_Post $post ) {
        $enabled = (bool) get_post_meta( $post->ID, '_bdn_liveblog_enabled', true );
        $status  = get_post_meta( $post->ID, '_bdn_liveblog_status', true ) ?: 'ended';
        $api_key = get_option( BDN_Liveblog_Slug::API_KEY_OPTION, '' );
        wp_nonce_field( 'bdn_liveblog_meta', 'bdn_liveblog_nonce' );
        ?>
        <div id="bdn-lb-admin-root"
             data-post-id="<?php echo (int) $post->ID; ?>"
             data-enabled="<?php echo $enabled ? 'true' : 'false'; ?>"
             data-status="<?php echo esc_attr( $status ); ?>"
             data-rest-url="<?php echo esc_url( rest_url( 'bdn-liveblog/v1/' ) ); ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
             data-has-api-key="<?php echo $api_key ? 'true' : 'false'; ?>">
            <p class="description" style="margin:8px 0 0">
                Loading Live Blog controls…
                <?php if ( ! $api_key ) : ?>
                    <br><strong style="color:#c8102e;">⚠ No Anthropic API key set.</strong>
                    AI slug generation will use the keyword fallback.
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=bdn-liveblog-settings' ) ); ?>">Add key →</a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    // ── Settings page ─────────────────────────────────────────────────────────

    public static function add_settings_page() {
        add_options_page(
            'Live Blog Settings',
            'Live Blog',
            'manage_options',
            'bdn-liveblog-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings() {
        register_setting( 'bdn_liveblog_settings', BDN_Liveblog_Slug::API_KEY_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        add_settings_section(
            'bdn_liveblog_main',
            'AI Slug Generation',
            function() {
                echo '<p>BDN Live Blog uses the Anthropic API (Claude Haiku) to generate '
                   . 'SEO-optimized URL slugs for each live blog entry. '
                   . 'If no key is provided, a keyword-extraction fallback is used instead.</p>';
            },
            'bdn-liveblog-settings'
        );

        add_settings_field(
            BDN_Liveblog_Slug::API_KEY_OPTION,
            'Anthropic API Key',
            function() {
                $val = get_option( BDN_Liveblog_Slug::API_KEY_OPTION, '' );
                ?>
                <input type="password"
                       name="<?php echo esc_attr( BDN_Liveblog_Slug::API_KEY_OPTION ); ?>"
                       value="<?php echo esc_attr( $val ); ?>"
                       class="regular-text"
                       autocomplete="off" />
                <p class="description">
                    Get a key at <a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>.
                    Stored encrypted at rest via WordPress options.
                </p>
                <?php
            },
            'bdn-liveblog-settings',
            'bdn_liveblog_main'
        );
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>Live Blog Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'bdn_liveblog_settings' );
                do_settings_sections( 'bdn-liveblog-settings' );
                submit_button( 'Save Settings' );
                ?>
            </form>

            <hr>
            <h2>How entry URLs work</h2>
            <p>Each live blog entry gets a unique canonical URL:</p>
            <code>https://bangordailynews.com/<strong>YYYY/MM/DD</strong>/liveblog/<strong>ai-generated-slug</strong>/</code>
            <p>This matches BDN's existing URL structure and is indexed by Google as a standalone
            <code>NewsArticle</code> with full Open Graph and Schema.org markup.</p>
            <p>The entry also retains a <code>#entry-{ID}</code> anchor on the parent article
            for social sharing and backward compatibility.</p>

            <h2>Flush rewrite rules</h2>
            <p>If entry URLs return 404 after activation, click below to flush WordPress rewrite rules.</p>
            <form method="post">
                <?php wp_nonce_field( 'bdn_lb_flush', 'bdn_lb_flush_nonce' ); ?>
                <input type="hidden" name="bdn_lb_action" value="flush_rewrites" />
                <?php submit_button( 'Flush Rewrite Rules', 'secondary' ); ?>
            </form>
            <?php
            if (
                isset( $_POST['bdn_lb_action'] ) &&
                $_POST['bdn_lb_action'] === 'flush_rewrites' &&
                check_admin_referer( 'bdn_lb_flush', 'bdn_lb_flush_nonce' )
            ) {
                flush_rewrite_rules();
                echo '<div class="notice notice-success"><p>Rewrite rules flushed.</p></div>';
            }
            ?>
        </div>
        <?php
    }
}
