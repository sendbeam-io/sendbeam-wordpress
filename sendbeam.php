<?php
/**
 * Plugin Name:       SendBeam
 * Plugin URI:        https://sendbeam.io/integrations/wordpress
 * Description:       Newsletter signup forms, a pop-up and contact forms from your SendBeam account: a block, a shortcode and one settings page.
 * Version:           1.6.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            SendBeam
 * Author URI:        https://sendbeam.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sendbeam
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

define( 'SENDBEAM_VERSION', '1.6.0' );
define( 'SENDBEAM_FILE', __FILE__ );
define( 'SENDBEAM_DIR', plugin_dir_path( __FILE__ ) );

require SENDBEAM_DIR . 'includes/helpers.php';
require SENDBEAM_DIR . 'includes/api.php';
require SENDBEAM_DIR . 'includes/popups.php';
require SENDBEAM_DIR . 'includes/sync.php';
require SENDBEAM_DIR . 'includes/admin-ui.php';
require SENDBEAM_DIR . 'includes/screens.php';
require SENDBEAM_DIR . 'includes/settings.php';
require SENDBEAM_DIR . 'includes/render.php';
require SENDBEAM_DIR . 'includes/mail.php';
require SENDBEAM_DIR . 'includes/onboarding.php';
