<?php
/**
 * BDN_Liveblog_Profiles
 *
 * Lets reporters set their byline name and upload a profile photo directly
 * inside WordPress, with no dependency on Gravatar or any third-party service.
 *
 * Stored in user meta:
 *   _bdn_byline_name   — display name override for live blog bylines
 *   _bdn_byline_photo  — attachment ID of the uploaded profile photo
 *
 * Exposed in the REST API so the admin composer can display live previews.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BDN_Liveblog_Profiles {

    const META_NAME  = '_bdn_byline_name';
    const META_PHOTO = '_bdn_byline_photo';

    public static function register() {
        // Extend the WP user profile screen with BDN byline fields.
        add_action( 'show_user_profile',     [ __CLASS__, 'render_profile_fields' ] );
        add_action( 'edit_user_profile',      [ __CLASS__, 'render_profile_fields' ] );
        add_action( 'personal_options_update', [ __CLASS__, 'save_profile_fields' ] );
        add_action( 'edit_user_profile_update', [ __CLASS__, 'save_profile_fields' ] );

        // Register user meta for REST so the admin JS can read it.
        register_meta( 'user', self::META_NAME, [
            'type'          => 'string',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function( $allowed, $meta_key, $object_id ) {
                return current_user_can( 'edit_user', $object_id );
            },
        ] );
        register_meta( 'user', self::META_PHOTO, [
            'type'          => 'integer',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function( $allowed, $meta_key, $object_id ) {
                return current_user_can( 'edit_user', $object_id );
            },
        ] );

        // REST endpoint: GET /bdn-liveblog/v1/me — returns current user's byline data.
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );

        // Filter avatar URL to prefer the BDN-uploaded photo.
        add_filter( 'get_avatar_url', [ __CLASS__, 'filter_avatar_url' ], 10, 3 );
    }

    // ── REST ────────────────────────────────────────────────────────────────

    public static function register_rest_routes() {
        register_rest_route( 'bdn-liveblog/v1', '/me', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'rest_get_me' ],
            'permission_callback' => function() { return is_user_logged_in(); },
        ] );
    }

    public static function rest_get_me( WP_REST_Request $req ) {
        $user_id = get_current_user_id();
        return new WP_REST_Response( self::get_byline_data( $user_id ), 200 );
    }

    // ── Public helper ────────────────────────────────────────────────────────

    /**
     * Return the resolved byline name and photo URL for any WP user.
     * Called by BDN_Liveblog_API::format_entry().
     *
     * Priority for name:  BDN byline meta → display_name → user_login
     * Priority for photo: BDN uploaded photo → Gravatar
     */
    public static function get_byline_data( int $user_id ): array {
        $user = get_userdata( $user_id );
        if ( ! $user ) return [ 'name' => '', 'photo_url' => '' ];

        // Name
        $name = get_user_meta( $user_id, self::META_NAME, true );
        if ( ! $name ) $name = $user->display_name ?: $user->user_login;

        // Photo
        $photo_id  = (int) get_user_meta( $user_id, self::META_PHOTO, true );
        $photo_url = $photo_id ? wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '';
        if ( ! $photo_url ) {
            // Fall back to Gravatar (80px) only if no BDN photo is set.
            $photo_url = get_avatar_url( $user_id, [ 'size' => 80, 'default' => 'mystery' ] );
        }

        return [
            'name'      => $name,
            'photo_url' => $photo_url,
            'user_id'   => $user_id,
        ];
    }

    // ── Avatar filter ────────────────────────────────────────────────────────

    /**
     * Intercept get_avatar_url() calls site-wide so any theme or plugin
     * that renders a WP user avatar automatically gets the BDN photo.
     */
    public static function filter_avatar_url( string $url, $id_or_email, array $args ): string {
        $user_id = self::resolve_user_id( $id_or_email );
        if ( ! $user_id ) return $url;

        $photo_id = (int) get_user_meta( $user_id, self::META_PHOTO, true );
        if ( ! $photo_id ) return $url; // no BDN photo — keep Gravatar

        $size      = isset( $args['size'] ) ? (int) $args['size'] : 96;
        $image_url = wp_get_attachment_image_url( $photo_id, [ $size, $size ] );
        return $image_url ?: $url;
    }

    private static function resolve_user_id( $id_or_email ): int {
        if ( is_numeric( $id_or_email ) ) return (int) $id_or_email;
        if ( $id_or_email instanceof WP_User ) return $id_or_email->ID;
        if ( $id_or_email instanceof WP_Comment ) {
            return $id_or_email->user_id ? (int) $id_or_email->user_id : 0;
        }
        if ( is_string( $id_or_email ) && strpos( $id_or_email, '@' ) !== false ) {
            $user = get_user_by( 'email', $id_or_email );
            return $user ? $user->ID : 0;
        }
        return 0;
    }

    // ── Profile fields on Users > Edit screen ────────────────────────────────

    public static function render_profile_fields( WP_User $user ) {
        $current_name  = esc_attr( get_user_meta( $user->ID, self::META_NAME, true ) );
        $photo_id      = (int) get_user_meta( $user->ID, self::META_PHOTO, true );
        $photo_url     = $photo_id ? wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '';
        wp_nonce_field( 'bdn_byline_save_' . $user->ID, 'bdn_byline_nonce' );
        ?>
        <h2 style="border-top:1px solid #dcdcde;margin-top:2rem;padding-top:1.5rem;">
            BDN Live Blog Byline
        </h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="bdn_byline_name">Byline name</label></th>
                <td>
                    <input type="text"
                           name="bdn_byline_name"
                           id="bdn_byline_name"
                           value="<?php echo $current_name; ?>"
                           class="regular-text"
                           placeholder="e.g. Jessica Piper" />
                    <p class="description">
                        Overrides your display name on live blog entries.
                        Leave blank to use your WordPress display name
                        (<strong><?php echo esc_html( $user->display_name ); ?></strong>).
                    </p>
                </td>
            </tr>
            <tr>
                <th><label>Byline photo</label></th>
                <td>
                    <div id="bdn-photo-preview" style="margin-bottom:.75rem;">
                        <?php if ( $photo_url ) : ?>
                            <img src="<?php echo esc_url( $photo_url ); ?>"
                                 id="bdn-photo-img"
                                 style="width:80px;height:80px;border-radius:50%;object-fit:cover;display:block;" />
                        <?php else : ?>
                            <img src=""
                                 id="bdn-photo-img"
                                 style="width:80px;height:80px;border-radius:50%;object-fit:cover;display:<?php echo $photo_url ? 'block' : 'none'; ?>;" />
                        <?php endif; ?>
                    </div>

                    <input type="hidden"
                           name="bdn_byline_photo_id"
                           id="bdn-photo-id"
                           value="<?php echo $photo_id ?: ''; ?>" />

                    <button type="button"
                            class="button"
                            id="bdn-upload-photo">
                        <?php echo $photo_id ? 'Change photo' : 'Upload photo'; ?>
                    </button>

                    <?php if ( $photo_id ) : ?>
                        <button type="button"
                                class="button"
                                id="bdn-remove-photo"
                                style="margin-left:.4rem;color:#b32d2e;">
                            Remove
                        </button>
                    <?php endif; ?>

                    <p class="description" style="margin-top:.5rem;">
                        Used on live blog entries and entry pages.
                        Recommended: square image, at least 200×200 px.
                        No Gravatar account needed.
                    </p>
                </td>
            </tr>
        </table>

        <script>
        (function() {
            var frame;
            document.getElementById('bdn-upload-photo')?.addEventListener('click', function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({
                    title: 'Select Byline Photo',
                    button: { text: 'Use this photo' },
                    multiple: false,
                    library: { type: 'image' },
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    document.getElementById('bdn-photo-id').value = attachment.id;
                    var img = document.getElementById('bdn-photo-img');
                    img.src = attachment.url;
                    img.style.display = 'block';
                    document.getElementById('bdn-upload-photo').textContent = 'Change photo';
                });
                frame.open();
            });

            document.getElementById('bdn-remove-photo')?.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('bdn-photo-id').value = '';
                var img = document.getElementById('bdn-photo-img');
                img.src = '';
                img.style.display = 'none';
                document.getElementById('bdn-upload-photo').textContent = 'Upload photo';
                this.style.display = 'none';
            });
        })();
        </script>
        <?php
    }

    public static function save_profile_fields( int $user_id ) {
        if ( ! isset( $_POST['bdn_byline_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['bdn_byline_nonce'], 'bdn_byline_save_' . $user_id ) ) return;
        if ( ! current_user_can( 'edit_user', $user_id ) ) return;

        // Byline name
        $name = sanitize_text_field( $_POST['bdn_byline_name'] ?? '' );
        update_user_meta( $user_id, self::META_NAME, $name );

        // Photo attachment ID (empty string = cleared)
        $photo_id = absint( $_POST['bdn_byline_photo_id'] ?? 0 );
        if ( $photo_id ) {
            update_user_meta( $user_id, self::META_PHOTO, $photo_id );
        } else {
            delete_user_meta( $user_id, self::META_PHOTO );
        }
    }

    // ── Enqueue WP Media on user profile pages ───────────────────────────────

    public static function enqueue_media( $hook ) {
        if ( ! in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) return;
        wp_enqueue_media();
    }
}
