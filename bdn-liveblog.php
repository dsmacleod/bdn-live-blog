<?php
/**
 * Plugin Name: BDN Live Blog
 * Plugin URI:  https://bangordailynews.com
 * Description: Live blog plugin for the Bangor Daily News. Enable in the block editor sidebar, then post entries directly from the public story URL.
 * Version:     1.1.0
 * Author:      Michael Shepherd/Bangor Daily News
 * Text Domain: bdn-liveblog
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BDN_LIVEBLOG_VERSION', '1.1.0' );
define( 'BDN_LIVEBLOG_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BDN_LIVEBLOG_URL',     plugin_dir_url( __FILE__ ) );

require_once BDN_LIVEBLOG_DIR . 'includes/class-bdn-liveblog-post-type.php';
require_once BDN_LIVEBLOG_DIR . 'includes/class-bdn-liveblog-slug.php';
require_once BDN_LIVEBLOG_DIR . 'includes/class-bdn-liveblog-api.php';
require_once BDN_LIVEBLOG_DIR . 'includes/class-bdn-liveblog-rewrite.php';
require_once BDN_LIVEBLOG_DIR . 'includes/class-bdn-liveblog-shortcode.php';
require_once BDN_LIVEBLOG_DIR . 'includes/class-bdn-liveblog-profiles.php';
require_once BDN_LIVEBLOG_DIR . 'includes/class-bdn-liveblog-nota.php';
require_once BDN_LIVEBLOG_DIR . 'admin/class-bdn-liveblog-admin.php';

// ── Activation / deactivation ─────────────────────────────────────────────────

register_activation_hook( __FILE__, function () {
    BDN_Liveblog_Post_Type::register();
    BDN_Liveblog_Rewrite::add_rules();
    flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

// ── Core hooks ────────────────────────────────────────────────────────────────

add_action( 'init',          [ 'BDN_Liveblog_Post_Type', 'register' ] );
add_action( 'rest_api_init', [ 'BDN_Liveblog_API',       'register_routes' ] );

BDN_Liveblog_Rewrite::register();
BDN_Liveblog_Admin::register();
BDN_Liveblog_Profiles::register();
add_action( 'admin_enqueue_scripts', [ 'BDN_Liveblog_Profiles', 'enqueue_media' ] );

// ── Public assets (front-end) ─────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function () {
    // Load WP media library for logged-in editors so the photo picker works
    // on the public story page without requiring an admin visit.
    if ( current_user_can( 'edit_posts' ) ) {
        wp_enqueue_media();
    }

    wp_enqueue_style(
        'bdn-liveblog',
        BDN_LIVEBLOG_URL . 'public/css/liveblog.css',
        [],
        BDN_LIVEBLOG_VERSION
    );
    wp_enqueue_script(
        'bdn-liveblog',
        BDN_LIVEBLOG_URL . 'public/js/liveblog.js',
        [],
        BDN_LIVEBLOG_VERSION,
        true
    );
    wp_localize_script( 'bdn-liveblog', 'BDN_LB', [
        'rest_url'      => esc_url_raw( rest_url( 'bdn-liveblog/v1/' ) ),
        'nonce'         => wp_create_nonce( 'wp_rest' ),
        'poll_interval' => 15000,
        'can_edit'      => current_user_can( 'edit_posts' ),
        'has_api_key'   => (bool) get_option( BDN_Liveblog_Slug::API_KEY_OPTION, '' ),
    ] );
} );

// ── Gutenberg sidebar panel ───────────────────────────────────────────────────

add_action( 'enqueue_block_editor_assets', function () {
    // The editor panel depends on wp-plugins, wp-edit-post, wp-element, wp-components, wp-data.
    wp_enqueue_script(
        'bdn-liveblog-editor',
        BDN_LIVEBLOG_URL . 'admin/editor-panel.js',
        [ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ],
        BDN_LIVEBLOG_VERSION,
        true
    );
    wp_localize_script( 'bdn-liveblog-editor', 'BDN_LB_Editor', [
        'rest_url' => esc_url_raw( rest_url( 'bdn-liveblog/v1/' ) ),
        'nonce'    => wp_create_nonce( 'wp_rest' ),
        'post_url' => get_permalink( get_the_ID() ) ?: '',
    ] );
} );

// ── Admin composer assets (post.php fallback for Classic Editor) ──────────────

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
    wp_enqueue_style(  'bdn-liveblog-admin', BDN_LIVEBLOG_URL . 'admin/admin.css', [], BDN_LIVEBLOG_VERSION );
    wp_enqueue_script( 'bdn-liveblog-admin', BDN_LIVEBLOG_URL . 'admin/admin.js',  [], BDN_LIVEBLOG_VERSION, true );
} );

// ── Auto-inject live blog below post content ──────────────────────────────────

add_filter( 'the_content', function ( $content ) {
    if ( ! is_singular() || ! in_the_loop() ) return $content;
    $post_id = get_the_ID();
    if ( ! get_post_meta( $post_id, '_bdn_liveblog_enabled', true ) ) return $content;
    return $content . do_shortcode( '[bdn_liveblog post_id="' . $post_id . '"]' );
} );

// ── Generate slug on entry publish ────────────────────────────────────────────

add_action( 'save_post_' . BDN_Liveblog_Post_Type::CPT, function ( $post_id, WP_Post $post ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
    if ( get_post_meta( $post_id, '_bdn_lb_seo_slug', true ) ) return;
    BDN_Liveblog_Slug::get_entry_url( $post_id, $post->post_content, get_the_title( $post ) );
}, 10, 2 );

// ── NOTA SEO enrichment on entry publish ─────────────────────────────────────

add_action( 'save_post_' . BDN_Liveblog_Post_Type::CPT, function ( $post_id, WP_Post $post ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
    if ( ! BDN_Liveblog_Nota::is_available() ) return;
    if ( get_post_meta( $post_id, '_bdn_lb_meta_description', true ) ) return;

    $text = $post->post_content;
    $title = get_the_title( $post );
    $full_text = $title ? $title . '. ' . $text : $text;

    // Meta description
    $desc_response = BDN_Liveblog_Nota::call( 'meta-description', $full_text );
    if ( $desc_response ) {
        $desc = BDN_Liveblog_Nota::extract_first( $desc_response, 'metaDescription' )
             ?: BDN_Liveblog_Nota::extract_first( $desc_response, 'meta_description' )
             ?: BDN_Liveblog_Nota::extract_first( $desc_response, 'description' );
        if ( $desc ) {
            update_post_meta( $post_id, '_bdn_lb_meta_description', sanitize_text_field( $desc ) );
        }
    }

    // Keywords
    $kw_response = BDN_Liveblog_Nota::call( 'keywords', $full_text );
    if ( $kw_response ) {
        $keywords = BDN_Liveblog_Nota::extract_all( $kw_response, 'keywords' );
        if ( $keywords ) {
            update_post_meta( $post_id, '_bdn_lb_keywords', implode( ', ', array_slice( $keywords, 0, 10 ) ) );
        }
    }

    // Entities
    $ent_response = BDN_Liveblog_Nota::call( 'entities', $full_text );
    if ( $ent_response ) {
        $entities = BDN_Liveblog_Nota::extract_all( $ent_response, 'entities' );
        if ( $entities ) {
            update_post_meta( $post_id, '_bdn_lb_entities', wp_json_encode( array_slice( $entities, 0, 20 ) ) );
        }
    }

    // Suggested headline (if entry has no title)
    if ( ! $title ) {
        $hl_response = BDN_Liveblog_Nota::call( 'headlines', $text, [ 'count' => 1 ] );
        if ( $hl_response ) {
            $headline = BDN_Liveblog_Nota::extract_first( $hl_response, 'headlines' );
            if ( $headline ) {
                update_post_meta( $post_id, '_bdn_lb_suggested_headline', sanitize_text_field( $headline ) );
            }
        }
    }

    // Social-ready summary
    $social_response = BDN_Liveblog_Nota::call( 'summary', $full_text );
    if ( $social_response ) {
        $social = BDN_Liveblog_Nota::extract_first( $social_response, 'summary' )
               ?: BDN_Liveblog_Nota::extract_first( $social_response, 'summaries' );
        if ( $social ) {
            $social = mb_substr( $social, 0, 280 );
            update_post_meta( $post_id, '_bdn_lb_social_summary', sanitize_text_field( $social ) );
        }
    }
}, 20, 2 );
