<?php
/**
 * Plugin Name:       برداشت‌گر فروشگاه پرو
 * Plugin URI:        https://mahsen81.ir/plugins/shopharvester-pro
 * Description:        اتوماسیون استخراج داده فروشگاه‌های اینترنتی، پارتیشن‌بندی چند-فروشگاهی روی زیردامنه، غنی‌سازی هوشمند محتوا، اتصال به Yoast SEO و سامانه گزارش‌گیری حرفه‌ای.
 * Version:           3.4.7
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mohsen
 * Author URI:        https://mahsen81.ir
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shopharvester-pro
 * Domain Path:       /languages
 *
 * @package ShopHarvesterPro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SHP_VERSION', '3.4.7' );
define( 'SHP_PLUGIN_FILE', __FILE__ );
define( 'SHP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SHP_DB_VERSION', '1.2.0' );
define( 'SHP_TEXT_DOMAIN', 'shopharvester-pro' );

/**
 * PSR-4 style autoloader for the plugin namespace.
 */
spl_autoload_register(
    static function ( $class ) {
        $prefix = 'ShopHarvesterPro\\';
        if ( strpos( $class, $prefix ) !== 0 ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        $relative = str_replace( '\\', '/', $relative );

        $segments = explode( '/', $relative );
        $class_file = array_pop( $segments );
        $class_file = 'class-' . strtolower( str_replace( '_', '-', $class_file ) ) . '.php';

        $sub_path = '';
        foreach ( $segments as $segment ) {
            $sub_path .= strtolower( $segment ) . '/';
        }

        $path = SHP_PLUGIN_DIR . $sub_path . $class_file;

        if ( is_readable( $path ) ) {
            require_once $path;
        }
    }
);

register_activation_hook( __FILE__, array( 'ShopHarvesterPro\\Includes\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ShopHarvesterPro\\Includes\\Deactivator', 'deactivate' ) );

/**
 * Bootstrap the plugin once all plugins are loaded.
 */
function shp_bootstrap() {
    $core = \ShopHarvesterPro\Includes\Core::instance();
    $core->run();
}
add_action( 'plugins_loaded', 'shp_bootstrap', 5 );
