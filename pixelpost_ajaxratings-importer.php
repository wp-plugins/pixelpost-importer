<?php

// first, the ajax calls
function pp_ajaxRating2wp_postRating_migrate_callback() {
    echo json_encode($GLOBALS['PP_AjaxRatings_Importer']->pp_ajaxRating2wp_postRating($_GET['pp_post_id']));
    die();
}
add_action('wp_ajax_pp_ajaxRating2wp_postRating_migrate', 'pp_ajaxRating2wp_postRating_migrate_callback');


// let's comment this, as the ajax callbacks needs it (wp_ajax_pp_ajaxRatings2wp_postRatings_migration_start & wp_ajax_pp_ajaxRatings2wp_postRatings_migration_resume)
// if ( ! defined('WP_LOAD_IMPORTERS'))
//     return;

/** Display verbose errors */
define('IMPORT_DEBUG', true);

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';
if ( ! class_exists( 'WP_Importer')) {
    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
    if (file_exists( $class_wp_importer))
        require $class_wp_importer;
}

/**
 * Pixelpost Importer class
 *
 * @package PixelPost
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class PP_AjaxRatings_Importer extends PP_Importer {
   
    function get_title() {
        return __('Import Ajax Ratings from Pixelpost');
    }

    function get_slug() {
        return 'pixelpost_ajaxRatings';
    }

    function get_pixelpost_default_settings() {
        $ppImporterOptions = get_option(parent::get_option_name());

        return array(
            'dbuser'      => $ppImporterOptions['dbuser'],
            'dbpass'      => $ppImporterOptions['dbpass'],
            'dbname'      => $ppImporterOptions['dbname'],
            'dbhost'      => $ppImporterOptions['dbhost'],
            'dbpre'       => $ppImporterOptions['dbpre'],
            'ppmaxrating' => 10,
            'wpmaxrating' => get_option('postratings_max'),
        );
    }

    function get_option_name() {
        return 'pp2wp_pixelpost_ajaxratings_importer_settings';
    }

    function get_pixelpost_settings() {
        return get_option($this->get_option_name(), $this->get_pixelpost_default_settings());
    }

    function setting2Label($s) {
         $s2l = array(
            'dbuser'      => __('Pixelpost Database User:'),
            'dbpass'      => __('Pixelpost Database Password:'),
            'dbname'      => __('Pixelpost Database Name:'),
            'dbhost'      => __('Pixelpost Database Host:'),
            'dbpre'       => __('Pixelpost Table Prefix:'),
            'ppmaxrating' => __('Pixelpost max rating value:'),
            'wpmaxrating' => __('Wordpress max rating value:'),
        );
        return $s2l[$s];
    }

    function description() {
        echo '<p>' . __( 'This importer allows you to extract ratings from Pixelpost\'s <a href="http://www.pixelpost.org/extend/addons/ajax-photo-ratings/">ajax ratings</a> into wordpress\' <a href="http://lesterchan.net/portfolio/programming/php/">wp-postRatings</a> by Lester Chan.' ) . '</p>';
        echo '<p>' . __( 'Make sure the latter is installed' ) . '</p>';
        echo '<p>' . __( 'This importer works as an addition to the pp2wp importer, and uses table and data created by the latter.' ) . '</p>';
        echo '<p>' . __( 'Please note that this improter has been developped for pixelpost 1.7.1 and wordpress 3.5.1. It may not work very well with other versions.' ) . '</p>';
    }
    
    function init() {
        $settings = $this->get_pixelpost_settings();

        $dsn = 'mysql:host=' . $settings['dbhost'] . ';dbname=' . $settings['dbname'];
        $username = $settings['dbuser'];
        $password = $settings['dbpass'];
//         $options = array(
//             PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES latin1',
//         );

        $this->ppdbh = new PDO($dsn, $username, $password);

        $this->prefix   = $settings['dbpre'];

        $this->ppmaxrating = $settings['ppmaxrating'];
        $this->wpmaxrating = $settings['wpmaxrating'];

        global $wpdb;
        $wpdb->pp2wp = $wpdb->prefix . parent::PPIMPORTER_PIXELPOST_TO_WORDPRESS_TABLE;
    }

    function get_pp_post_rating($pp_post_id) {
        $res_pdo = $this->get_pp_dbh()->query("SELECT * FROM {$this->prefix}ajaxRatings WHERE img_id='$pp_post_id'");
        $ret = $res_pdo->fetchAll();
        return $ret[0];
    }

    function get_pp_post_ratings() {
        return $this->get_pp_dbh()->query("SELECT * FROM {$this->prefix}ajaxRatings");
    }

    function get_hostname_by_voting_ip($voting_ip) {
        global $wpdb;
        $ret = $wpdb->get_row("SELECT distinct(rating_host) FROM {$wpdb->ratings} WHERE rating_ip='$voting_ip'", ARRAY_A);
        return is_null($ret) ? false : $ret['rating_host'];
    }

    function insert_vote($wp_post_id, $wp_post_title, $rate, $now, $voting_ip) {
        $hostname = $this->get_hostname_by_voting_ip($voting_ip);
        if ( $hostname === false) {
            $hostname = esc_attr(@gethostbyaddr($voting_ip));
        }

//         // if the hostname constains 'bot' or 'crawl', that's likely not a human
//         $blacklist = array(
//             'bot', 'crawl'
//         );
//         foreach ($blacklist as $bot) {
//             if (stristr($hostname, $bot) !== false) {
//                 return false;
//             }
//         }

        global $wpdb;
        $rate_log = $wpdb->query(
            "INSERT INTO $wpdb->ratings " .
            "VALUES (0, $wp_post_id, '$wp_post_title', $rate, '$now', '$voting_ip', '$hostname', 0, 0)");
        return true;
    }

    function pp_ajaxRating2wp_postRating($pp_post_id) {
        $pp_posts_count = $this->get_pp_post_count();

        $count = 0;

        $pp_rating = $this->get_pp_post_rating($pp_post_id);
        set_time_limit(0);

        $now = current_time('timestamp');

        if ($pp_rating['total_votes'] == 0) { // no vote, skip this entry
            return false;
        }
        $wp_post_id = $this->get_pp2wp_wp_post_id($pp_rating['img_id']);
        if ($wp_post_id === false) { // no matching wp post
            return false;
        }

        $wp_post_meta_ratings_score = get_post_meta($wp_post_id, 'ratings_score');
        if ( ! empty($wp_post_meta_ratings_score)) { // not empty => already processed (for error recovery)
            return false;
        }

        $wp_post_title = get_the_title($wp_post_id);
        // readjust rates from pp scale to wp scale
        $rate = floatval($pp_rating['total_rate']) * $this->wpmaxrating / $this->ppmaxrating;

        $current_total = $rate;
        $current_count = 1;
        $pp_ratings = unserialize($pp_rating['used_ips']);
        foreach($pp_ratings as $voting_ip) {
            $nrate = intval($rate);
            if ((floatval($current_total) / $current_count) < $rate) {
                ++$nrate;
            }
            // if the value was inserted, add it to the counts
            if ($this->insert_vote($wp_post_id, $wp_post_title, $nrate, $now, $voting_ip)) {
                $current_total += $nrate;
                ++$current_count;
            }
        }

        if ( ! update_post_meta($wp_post_id, 'ratings_users', $current_count)) {
            add_post_meta($wp_post_id, 'ratings_users', $current_count, true);
        }
        if ( ! update_post_meta($wp_post_id, 'ratings_score', $current_total)) {
            add_post_meta($wp_post_id, 'ratings_score', $current_total, true);
        }
        if ( ! update_post_meta($wp_post_id, 'ratings_average', floatval($current_total) / $current_count)) {
            add_post_meta($wp_post_id, 'ratings_average', floatval($current_total) / $current_count, true);
        }
        set_time_limit(30);
        
        return true;
    }

    function import_ratings() {
        wp_enqueue_script( 'pixelpost-importer', plugins_url('/pixelpost_ajaxratings-importer.js', __FILE__) );
        wp_localize_script( 'pixelpost-importer', 'pp_post_ids', $this->get_pp_post_ids() );
        echo '<p>' . sprintf(__('Retrieved %d posts from Pixelpost, importing...'), $this->get_pp_post_count()) . '</p>';
        echo '<p id="pp_ajaxRatings2wp_postRatings_migration_log">'. __('Starting...') . '</p>';
        echo '<p>';
        echo '  <input id="pp_ajaxRatings2wp_postRatings_migration_stop"   type="submit" name="stop migration"   value="stop migration"   class="button button-primary"/>';
        echo '  <input id="pp_ajaxRatings2wp_postRatings_migration_resume" type="submit" name="resume migration" value="resume migration" class="button button-primary"/>';
        echo '</p>';

// echo "<pre>\n";
//         $n_cats = $this->pp_ajaxRatings2wp_postRatings();
//         echo '<p>'.sprintf(__('Done! <strong>%1$s</strong> ratings imported.'), $n_cats).'<br /><br /></p>';
    }
    
    function dispatch() {
        $this->header();

        if ( ! function_exists( 'the_ratings' ) ) {
            echo '<p>' . __('Please install wp-postratings: <a href="http://lesterchan.net/portfolio/programming/php/">get it here!</a>') . '</p>';
            return;
        }


        $step = isset( $_GET['step'] ) ? intval( $_GET['step'] ) : 0;

        switch ( $step ) {
            default:
            case 0 : $this->greet();            break;
            case 1 : $this->import_ratings();   break;
        }
        
        $step2Str = array(
            0 => __('Import Ratings'),
            1 => __('Finish'),
        );

        if ( isset ( $step2Str[ $step ] ) ) {
            echo '<form action="admin.php?import=' . $this->get_slug() . '&amp;step=' . ($step + 1) . '" method="post">';
            echo '  <input type="submit" name="submit" value="' . $step2Str[$step] . '" class="button button-primary" />';
            echo '</form>';
        }

        $this->footer();
    }
    
    
}

} // class_exists( 'WP_Importer' )

