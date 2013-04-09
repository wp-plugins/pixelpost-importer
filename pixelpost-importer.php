<?php

// first, the ajax calls
function pp2wp_migrate_callback() {

    echo json_encode($GLOBALS['PP_Importer']->pp_post2wp_post($_GET['pp_post_id']));
    die();
}
add_action('wp_ajax_pp2wp_migrate', 'pp2wp_migrate_callback');


// let's comment this, as the ajax callbacks needs it (wp_ajax_pp2wp_post_migration_start & wp_ajax_pp2wp_post_migration_resume)
// if ( ! defined('WP_LOAD_IMPORTERS'))
//     return;

/** Display verbose errors */
// define('IMPORT_DEBUG', true);

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
class PP_Importer extends WP_Importer {
    const PPIMPORTER_PIXELPOST_TO_WORDPRESS_TABLE = 'odyssey_pp2wp';

    const PPIMPORTER_PIXELPOST_SUBMIT  = 'pp2wp_pixelpost_importer_submit';
    const PPIMPORTER_PIXELPOST_RESET   = 'pp2wp_pixelpost_importer_reset';
   
    protected $ppdbh;
    protected $prefix;
    protected $ppurl;
    protected $tmp_dir;
    protected $img_size;
    
    function get_title() {
        return __('Import Pixelpost');
    }

    function get_slug() {
        return 'pixelpost';
    }

    function header() {
        echo '<div class="wrap">';
        echo '<div id="icon-tools" class="icon32"><br></div>' . PHP_EOL;
        echo '<h2>' . $this->get_title() . '</h2>';
    }

    function footer() {
        echo '</div>';
    }

    function get_pixelpost_default_settings() {
        $uploads = wp_upload_dir();
        $tmp_dir = $uploads['basedir'] . '/pp2wp/';
        @mkdir($tmp_dir);
        return array(
            'dbuser'        => '',
            'dbpass'        => '',
            'dbname'        => '',
            'dbhost'        => 'localhost',
            'dbpre'         => 'pixelpost_',
            'ppurl'         => 'http://127.0.0.1/pixelpost/',
            'tmp_directory' => $tmp_dir,
            'image_size'    => 'full',
        );
    }

    function get_option_name() {
        return 'pp2wp_pixelpost_importer_settings';
    }

    function get_pixelpost_settings() {
        return get_option($this->get_option_name(), $this->get_pixelpost_default_settings());
    }

    function setting2Type($s) {
        return ($s == 'dbpass') ? 'password' : 'text';
    }
    function setting2Label($s) {
         $s2l = array(
            'dbuser'        => __('Pixelpost Database User:'),
            'dbpass'        => __('Pixelpost Database Password:'),
            'dbname'        => __('Pixelpost Database Name:'),
            'dbhost'        => __('Pixelpost Database Host:'),
            'dbpre'         => __('Pixelpost Table Prefix:'),
            'ppurl'         => __('Pixelpost original url:'),
            'tmp_directory' => __('Directory for temp files:'),
            'image_size'    => __('Imported image size in post (thumbnail, medium, large or full):'),
        );
        return $s2l[$s];
    }


    function description() {
        echo '<p>' . __( 'This importer allows you to extract posts from a Pixelpost install into wordpress.' ) . '</p>';
        echo '<p>' . __( 'Please note that this improter has been developped for pixelpost 1.7.1 and wordpress 3.5.1. It may not work very well with other versions.' ) . '</p>';
    }

    function greet() {
        if ( isset( $_POST[self::PPIMPORTER_PIXELPOST_RESET] ) ) {
            delete_option($this->get_option_name());
        }
        $settings = $this->get_pixelpost_settings();
        if ( isset( $_POST[ self::PPIMPORTER_PIXELPOST_SUBMIT ] ) ) {
            unset( $_POST[ self::PPIMPORTER_PIXELPOST_SUBMIT ] );
            foreach ( $_POST as $name => $setting ) {
                $settings[$name] = $setting;
            }
            update_option($this->get_option_name(), $settings);
        }

        $this->description();
        echo '<p>' . __( 'Your Pixelpost configuration settings are as follows:' ) . '</p>';

        echo '<form action="admin.php?import=' . $this->get_slug() . '&amp;step=0" method="post">';
        echo '  <table class="form-table">';
        echo '    <tbody>';
        foreach ($settings as $name => &$setting) {
            echo '      <tr valign="top">';
            echo '        <th scope="row">';
            echo '          <label for="' . $name . '" name="' . $name . '" style="width: 300px; display: inline-block;">';
            echo             $this->setting2Label($name);
            echo '          </label>';
            echo '        </th>';
            echo '        <td>';
            echo '          <input id="' . $name . '" name="' . $name . '" type="' . $this->setting2Type($name) . '" value="' . $setting . '"  size="60" />';
            echo '        </td>';
            echo '      </tr>';
        }
        echo '    </tbody>';
        echo '  </table>';
        echo '  <p>';
        echo '    <input type="submit" name="' . self::PPIMPORTER_PIXELPOST_SUBMIT . '"  class="button button-primary" value="' . __( 'update settings' ) . '" />';
        echo '    <input type="submit" name="' . self::PPIMPORTER_PIXELPOST_RESET  . '"  class="button button-primary" value="' . __( 'reset settings' )  . '" />';
        echo '  </p>';
        echo '</form>';
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
        $this->ppurl    = $settings['ppurl'];
        $this->tmp_dir  = $settings['tmp_directory'];
        $this->img_size = $settings['image_size'];
    }

    function get_pp_dbh() {
        if (!isset($this->ppdbh)) $this->init();
        return $this->ppdbh;
    }
    
    /**
     * Create the correspondance table from pixelpost IDs to wordpress IDs
     * used by the redirection application.
     */
    static function create_pp2wp_table() {
        global $wpdb;
        $wpdb->pp2wp = $wpdb->prefix . self::PPIMPORTER_PIXELPOST_TO_WORDPRESS_TABLE;

        $charset_collate = '';
        if($wpdb->has_cap( 'collation' )) {
            if(!empty($wpdb->charset)) {
                $charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
            }
            if(!empty($wpdb->collate)) {
                $charset_collate .= " COLLATE $wpdb->collate";
            }
        }
        // Create Post Ratings Table
        $create_pp2wp_sql = "CREATE TABLE $wpdb->pp2wp (".
                        "pp_post_id INT(11) NOT NULL ,".
                        "wp_post_id INT(11) NOT NULL ,".
                        "PRIMARY KEY (pp_post_id)) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $create_pp2wp_sql );
    }

    /**
     * Insert an entry into the pp2wp table
     */
    function insert_pp2wp($pp_post_id, $wp_post_id) {
        global $wpdb;
        $wpdb->pp2wp = $wpdb->prefix . self::PPIMPORTER_PIXELPOST_TO_WORDPRESS_TABLE;

        $wpdb->insert(
            $wpdb->pp2wp,
            array(
                'pp_post_id' => $pp_post_id,
                'wp_post_id' => $wp_post_id,
            ),
            array(
                '%d',
                '%d',
            )
        );
    }

    /**
     * gets the wp post id bound to a pp post id.
     */
    function get_pp2wp_wp_post_id($pp_post_id) {
        global $wpdb;
        $wpdb->pp2wp = $wpdb->prefix . self::PPIMPORTER_PIXELPOST_TO_WORDPRESS_TABLE;

        $row = $wpdb->get_row("SELECT * FROM $wpdb->pp2wp WHERE pp_post_id = $pp_post_id", ARRAY_A);
        return is_null($row) ? false : $row['wp_post_id'];
    }

    function get_pp_cats() {
        return $this->get_pp_dbh()->query("SELECT id, name FROM {$this->prefix}categories");
    }
    
    function get_pp_postcats( $post_id ) {
        $res = $this->get_pp_dbh()->query("SELECT ca.cat_id
                            FROM {$this->prefix}catassoc ca
                                INNER JOIN {$this->prefix}categories c ON c.id = ca.cat_id
                            WHERE ca.image_id = $post_id");
        $ret = array();
        foreach ($res as $r) {
             $ret[] = intval($r['cat_id']);
        }
        return $ret;
    }
    
    function get_pp_post_ids() {
        $pp_ids = $this->get_pp_dbh()->query("SELECT id FROM {$this->prefix}pixelpost");
        $ret = array();
        foreach($pp_ids as $pp_id) {
            $ret[] = $pp_id['id'];
        }
        return $ret;
    }

    function get_pp_post_count() {
        $res_pdo = $this->get_pp_dbh()->query("SELECT count(id) as 'post_count' FROM {$this->prefix}pixelpost");
        $ret = $res_pdo->fetchAll();
        if (is_array($ret)) {
            return $ret[0]['post_count'];
        } else {
            return 0;
        }
    }

    function get_pp_post_by_post_id($post_id) {
        $res_pdo = $this->get_pp_dbh()->query("SELECT id, datetime, headline, body, image
                            FROM {$this->prefix}pixelpost
                            WHERE id = '$post_id'");
        $ret = $res_pdo->fetchAll();
        return $ret[0];
    }

    function get_pp_posts() {
        $dbh = $this->get_pp_dbh();
        $query = "SELECT id, datetime, headline, body, image
                 FROM {$this->prefix}pixelpost";
        return $dbh->query($query);
    }
    
    function get_pp_post_comment_count( $post_id ) {
        $ret = $this->get_pp_dbh()->query("SELECT count(id) as 'comments_count' FROM {$this->prefix}comments WHERE parent_id = '$post_id'");
        if (is_array($ret)) {
            return $ret[0]['comments_count'];
        } else {
            return 0;
        }
    }
    
    function get_pp_comments_by_post_id( $post_id ) {
        return $this->get_pp_dbh()->query("SELECT id, parent_id, datetime, ip, message, name, url, email
                            FROM {$this->prefix}comments
                            WHERE parent_id = '$post_id'
                                AND publish = 'yes'
                            ORDER BY datetime ASC");
    }

    function build_category_tree( $categories ) {
        global $wpdb;
        $tree = array();
        foreach( $categories as $category ) {
            $path = explode('/', $category['name']);
            $last = end($path);
            reset($path);
            $t = &$tree;
            foreach ( $path as $p ) {
                if ( ! isset( $t[ $p ] )) {
                    $name = htmlspecialchars_decode($p);
                    $leaf = ($p == $last);
                    $t[$p] = array(
                        'id'       => $leaf ? $category['id'] : null,
                        'nicename' => str_replace(' ', '-', strtolower($name)),
                        'leaf'     => $leaf,
                        'sub'      => array(),
                    );
                } else {
                    $t[ $p ]['leaf'] &= ($p == $last);
                }
                $t = &$t[ $p ]['sub'];
            }
        }
        return $tree;
    }

    function insert_category_tree($pp_cat_tree, $parent_wp_cat_id = null) {
        $pp_cat2wpcat = array();
        foreach ($pp_cat_tree as $name => $pp_cat) {
            $params = array(
                'category_nicename' => $pp_cat['nicename'],
                'cat_name'          => $name
            );
            if ($cinfo = category_exists($name)) {
                $params['cat_ID'] = $cinfo;
            }
            if ( ! is_null($parent_wp_cat_id)) {
                $params['category_parent'] = $parent_wp_cat_id;
            }

            $wp_cat_id = wp_insert_category($params);

            if ( ! is_null($pp_cat['id'])) {
                $pp_cat2wpcat[ $pp_cat['id'] ] = $wp_cat_id;
            }
            if ( ! $pp_cat['leaf']) {
                $pp_cat2wpcat = $this->insert_category_tree($pp_cat['sub'], $wp_cat_id) + $pp_cat2wpcat;
            }
        }
        return $pp_cat2wpcat;
    }
    
    function pp_cats2wp_cats() {
        $pp_cats = $this->get_pp_cats();
        $pp_cat_tree = $this->build_category_tree($pp_cats);
        $pp_cats2wp_cats = $this->insert_category_tree($pp_cat_tree);

        // $tmpPpCats = $this->get_pp_cats();
        // $pp_cat = array();
        // foreach ($tmpPpCats as $c) {
        // 	$pp_cat[$c['id']] = $c['name'];
        // }
        // foreach ($pp_cat2wpcat as $pp_cat_id => $wp_cat_id) {
        //     echo '"' . $pp_cat[$pp_cat_id] . '" => "' . get_cat_name($wp_cat_id) . '"' . PHP_EOL;
        // }
        
        // Store category translation for future use
        update_option('pp_cats2wp_cats', $pp_cats2wp_cats);
        return count($pp_cats2wp_cats);
    }
    
    function pp_post2wp_post($pp_post_id) {
        global $wpdb;
        
        // Let's assume the logged in user in the author of all imported posts
        $authorid = get_current_user_id();

        set_time_limit(0);
        $pp_post = $this->get_pp_post_by_post_id($pp_post_id);

        if (false !== $this->get_pp2wp_wp_post_id($pp_post['id'])) { // already in the correspondance table, skip this entry
            return false;
        }

        // retrieve this post categories ID
        $pp_cats2wp_cats = get_option('pp_cats2wp_cats');
        $pp_categories = $this->get_pp_postcats($pp_post['id']);
        $wp_categories = array();
        foreach ($pp_categories as $pp_category) {
            if ( ! isset($pp_cats2wp_cats[ $pp_category ])) {
                continue;
            }
            $wp_categories[] = $pp_cats2wp_cats[ $pp_category ];
        }

        // let's insert the new post
        $wp_post_params = array(
            'comment_status' => 'open',
            'ping_status'    => 'open',
            'post_author'    => $authorid,
            'post_date'      => $pp_post['datetime'],
            'post_modified'  => $pp_post['datetime'],
            'post_content'   => '',
            'post_status'    => 'publish',
            'post_title'     => htmlspecialchars_decode($pp_post['headline']),
            'post_category'  => $wp_categories,
        );
        $wp_post_id = wp_insert_post($wp_post_params, true);

        // download the post image ( !  may be troublesome on certain platforms!)
        $pp_image_url      = str_replace(' ', '%20', $this->ppurl . '/images/' . $pp_post['image']);
        $pp_image_tmp_file = $this->tmp_dir . '/' . $pp_post['image'];

        $response = wp_remote_get($pp_image_url, array('timeout' => 300, 'stream' => true, 'filename' => $pp_image_tmp_file));
        if (is_wp_error($response)) {
            var_dump($response);
            unlink($pp_image_tmp_file);
            return $response;
        }

        // Set variables for storage & fix file filename for query strings
        $file_array = array(
            'name'     => basename($pp_image_tmp_file),
            'tmp_name' => $pp_image_tmp_file,
        );

        // do the validation and storage stuff, note that the tmp file is moved, no need for unlink
        $wp_post_img_id = media_handle_sideload($file_array, $wp_post_id, $wp_post_params['post_title']);

        // update the newly inserted post with a link and a post to the image
        $img = wp_get_attachment_image($wp_post_img_id, $this->img_size);
        $url = '<a href ="' . wp_get_attachment_url($wp_post_img_id) . '">' . $img . '</a>';
        // Update the post into the database
        $wp_post_params['ID'] = $wp_post_id;
        $wp_post_params['post_content'] = $url . PHP_EOL . PHP_EOL . '<!--more-->' . PHP_EOL . PHP_EOL . htmlspecialchars_decode($pp_post['body']);
        wp_update_post($wp_post_params);

        // mark the attached image as this post thumbnail
        update_post_meta( $wp_post_id, '_thumbnail_id', $wp_post_img_id );

        // set post format to image
        set_post_format($wp_post_id, 'image');

        // get the comments bound to this post
        $pp_comments = $this->get_pp_comments_by_post_id($pp_post['id']);
        foreach ($pp_comments as $pp_comment) {
            $wp_comment_params = array(
                'comment_post_ID'      => $wp_post_id,
                'comment_author'       => htmlspecialchars_decode($pp_comment['name']),
                'comment_author_email' => $pp_comment['email'],
                'comment_author_url'   => $pp_comment['url'],
                'comment_content'      => htmlspecialchars_decode($pp_comment['message']),
                'user_id'              => $authorid,
                'comment_author_IP'    => $pp_comment['ip'],
                'comment_agent'        => 'PP Importer',
                'comment_date'         => $pp_comment['datetime'],
                'comment_approved'     => true,
            );
            wp_insert_comment($wp_comment_params);
        }

        // Store ID translation for later use
        $this->insert_pp2wp($pp_post['id'], $wp_post_id);

        set_time_limit(30);

        return $wp_post_id;
    }
        
    function import_categories() {
        $n_cats = $this->pp_cats2wp_cats();
        echo '<p>'.sprintf(__('Done! <strong>%1$s</strong> categories imported.'), $n_cats).'<br /><br /></p>';
    }
    
    function import_posts() {
        wp_enqueue_script( 'pixelpost-importer', plugins_url('/pixelpost-importer.js', __FILE__) );
        wp_localize_script( 'pixelpost-importer', 'pp_post_ids', $this->get_pp_post_ids() );
        echo '<p>' . sprintf(__('Retrieved %d posts from Pixelpost, importing...'), $this->get_pp_post_count()) . '</p>';
        echo '<p id="pp2wp_post_migration_log">'. __('Starting...') . '</p>';
        echo '<p>';
        echo '  <input id="pp2wp_post_migration_stop"   type="submit" name="stop migration"   value="stop migration"   class="button button-primary"/>';
        echo '  <input id="pp2wp_post_migration_resume" type="submit" name="resume migration" value="resume migration" class="button button-primary"/>';
        echo '</p>';
    }
    
    function cleanup_ppimport() {
        delete_option('pp_cats2wp_cats');
    }
    
    function dispatch() {
        $this->header();

        $step = isset( $_GET['step'] ) ? intval( $_GET['step'] ) : 0;

        switch ( $step ) {
            default:
            case 0 : $this->greet();             break;
            case 1 : $this->import_categories(); break;
            case 2 : $this->import_posts();      break;
            case 3 : $this->cleanup_ppimport();  break;
        }
        
        $step2Str = array(
            0 => __('Import Categories'),
            1 => __('Import Posts'),
            2 => __('Finish'),
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

