<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BDN_Liveblog_Shortcode {

    public static function register() {
        add_shortcode( 'bdn_liveblog', [ __CLASS__, 'render' ] );
    }

    public static function render( $atts ) {
        $atts     = shortcode_atts( [ 'post_id' => get_the_ID() ], $atts, 'bdn_liveblog' );
        $post_id  = absint( $atts['post_id'] );
        $can_edit = current_user_can( 'edit_posts' );

        ob_start();

        if ( $can_edit ) : ?>
        <div id="bdn-lb-composer-root"
             data-post-id="<?php echo $post_id; ?>"></div>
        <?php endif; ?>

        <div id="bdn-liveblog-<?php echo $post_id; ?>"
             class="bdn-liveblog"
             data-post-id="<?php echo $post_id; ?>"
             aria-live="polite"
             aria-label="Live Blog">

            <div class="bdn-lb-header">
                <span class="bdn-lb-badge" aria-hidden="true"></span>
                <span class="bdn-lb-status-text">Loading&hellip;</span>
                <span class="bdn-lb-last-updated"></span>
                <span class="bdn-lb-conn-error" style="display:none"></span>
            </div>

            <div class="bdn-lb-entries" role="feed">
                <div class="bdn-lb-loading">
                    <span class="bdn-lb-spinner" aria-hidden="true"></span>
                    <span>Loading entries&hellip;</span>
                </div>
            </div>

            <div class="bdn-lb-pagination">
                <button class="bdn-lb-load-more" style="display:none">Load earlier entries</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

BDN_Liveblog_Shortcode::register();
