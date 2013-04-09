<?php
/*
Plugin Name: Pixelpost Importer
Plugin URI: http://wordpress.org/extend/plugins/pixelpost-importer/
Description: Import posts, comments, and categories from a Pixelpost database.
Author: Pierre Bodilis
Author URI: http://rataki.eu/
Version: 0.1
Text Domain: pixelpost-importer
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

require_once( plugin_dir_path( __FILE__ ) . 'pixelpost-importer.php');
require_once( plugin_dir_path( __FILE__ ) . 'pixelpost_ajaxratings-importer.php');

function pixelpost_importer_init() {
    //load_plugin_textdomain( 'pixelpost-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    /**
     * WordPress Importer object for registering the import callback
     * @global WP_Import $wp_import
     */
    $GLOBALS['PP_Importer'] = new PP_Importer();
    register_importer(
        $GLOBALS['PP_Importer']->get_slug(),
        'PixelPost',
        __('Import <strong>posts, comments, and categories</strong> from a pixelpost installation.', 'pixelpost-importer'),
        array($GLOBALS['PP_Importer'], 'dispatch')
    );
}
add_action('admin_init', 'pixelpost_importer_init');

register_activation_hook( __FILE__, array( 'PP_Importer', 'create_pp2wp_table' ) );

function pixelpost_ratings_importer_init() {
    //load_plugin_textdomain( 'pixelpost-ajaxRatings-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    /**
     * WordPress Importer object for registering the import callback
     * @global WP_Import $wp_import
     */
    $GLOBALS['PP_AjaxRatings_Importer'] = new PP_AjaxRatings_Importer();
    register_importer(
        $GLOBALS['PP_AjaxRatings_Importer']->get_slug(),
        'PixelPost Ajax Ratings',
        __('Import <strong>ajaxRatings</strong> from a pixelpost installation.', 'pixelpost-ajaxRatings-importer'),
        array($GLOBALS['PP_AjaxRatings_Importer'], 'dispatch')
    );
}
add_action('admin_init', 'pixelpost_ratings_importer_init');
