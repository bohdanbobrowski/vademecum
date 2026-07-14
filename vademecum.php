<?php
/**
 * @package VadeMecum
 */
/*
Plugin Name: VadeMecum
Plugin URI: https://github.com/bohdanbobrowski/vademecum
Description: Save post latitude and longitude, draw post position on map and show nearest posts
Version: 0.2.1
Requires at least: 6.0
Requires PHP: 8.2
Author: Bohdan Bobrowski
Author URI: https://bohdan.bobrowski.com.pl
License: MIT
Text Domain: vademecum
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Define plugin constants
define( 'VADEMECUM_VERSION', '0.2' );
define( 'VADEMECUM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VADEMECUM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, 'vademecum_activate');
register_deactivation_hook( __FILE__, 'vademecum_deactivate');
register_rest_field( 'post', 'metadata', array(
    'get_callback' => function ( $data ) {
        return get_post_meta( $data['id'], '', '' );
},)); # this line adds post metadata in API response

add_action('admin_init','vademecum_admin');
add_action('admin_head', 'vademecum_admin_css' );
add_action('save_post','save_metabox_vademecum');
add_action('wp_head', 'vademecum_meta', 1);

add_shortcode('vademecum-map','vademecum_add_map');
add_shortcode('vademecum-nearest','vademecum_show_nearest');

add_action( 'admin_menu', 'vademecum_settings' );

# On activate
function vademecum_activate(){
    global $wpdb, $table_prefix;
    if ( ! is_plugin_active( 'leaflet-map/leaflet-map.php' ) and current_user_can( 'activate_plugins' ) ) {        
        wp_die('Sorry, but this plugin requires the leaflet-map installed and active. <br><a href="' . admin_url( 'plugins.php' ) . '">&laquo; Return to Plugins</a>');
    }
    // Can't create views on many shared hostings so decided to create table
    // But first rename old table if exists
    $wpdb->get_results("RENAME TABLE {$table_prefix}postlatlong TO {$table_prefix}_vademecum;");
    // Create vademecum main table and fill with data
    $create_table_query = "CREATE TABLE IF NOT EXISTS {$table_prefix}vademecum (post_id INT NOT NULL PRIMARY KEY, latlong POINT NOT NULL) ";
    $create_table_query .= "SELECT post_id, POINT(SUM(CASE WHEN meta_key=\"post_lat\" THEN meta_value ELSE 0 END), SUM(CASE WHEN meta_key=\"post_long\" THEN meta_value ELSE 0 END)) as latlong FROM {$table_prefix}postmeta WHERE (abs(meta_value) > 0 AND meta_value IS NOT NULL) AND (meta_key=\"post_long\" OR meta_key=\"post_lat\") ";
    $create_table_query .= "GROUP BY post_id;";
    $wpdb->get_results($create_table_query);
}

# On activate
function vademecum_deactivate(){
    global $wpdb, $table_prefix;
    $wpdb->get_results("DROP TABLE IF EXISTS {$table_prefix}vademecum;");
}

# Admin post edit form 
function vademecum_admin()
{
    add_meta_box("Post coordinates", "Post coordinates", "vademecum_admin_form", "post", "side", "high");
    // Add settings
    register_setting( 'vademecum_options', 'vademecum_options', 'vademecum_options_validate' );
    add_settings_section( 'display_settings', 'Titles and labels', 'vademecum_section_text', 'vademecum' );
    add_settings_field( 'vademecum_map_title', 'Map title', 'vademecum_map_title', 'vademecum', 'display_settings' );
    add_settings_field( 'vademecum_nearest_title', 'Nearest list title', 'vademecum_nearest_title', 'vademecum', 'display_settings' );
}
function vademecum_admin_form(){
    global $post;
    $post_meta = get_post_meta($post->ID);
    $post_lat = isset($post_meta['post_lat']) ? $post_meta['post_lat'][0] : "";
    $post_long = isset($post_meta['post_long']) ? $post_meta['post_long'][0] : "";
    $post_address = isset($post_meta['post_address']) ? $post_meta['post_address'][0] : "";
    ?>
    <table class="vademecum_block">
        <tr>
            <th><label for="post_sidebar">Latitude:</label></th>
            <td><input type="text" name="post_lat" id="post_lat" value="<?php echo $post_lat; ?>" /></td>
        </tr>
        <tr>
            <th><label for="post_sidebar">Longitude:</label></th>
            <td><input type="text" name="post_long" id="post_long" value="<?php echo $post_long; ?>" /></td>
        </tr>
        <tr>
            <th><label for="post_sidebar">Address:</label></th>
            <td><input type="text" name="post_address" id="post_address" value="<?php echo $post_address; ?>" /></td>
        </tr>
    </table>
    <?php
}

# Admin post edit form css styles
function vademecum_admin_css() {
	echo "
	<style type='text/css'>
    .vademecum_block {
        display: table;
    }
	.vademecum_row {
        padding: 0 0 0.5em 0;
        display: table-row;
    }
    .vademecum_row label {
        width: 40%
        display: table-cell;
    }
    .vademecum_row input {
        display: table-cell;
    }
	</style>
	";
}

# Save lat/long and adresss
function save_metabox_vademecum($post_id)
{
    global $wpdb, $table_prefix;
    $post_lat = $post_long = $post_address = "";
    if(isset($_POST['post_lat'])) {
        $post_lat = $_POST['post_lat'];
    }
    if(isset($_POST['post_long'])) {
        $post_long = $_POST['post_long'];
    }
    if(isset($_POST['post_address'])) {
        $post_address = $_POST['post_address'];
    }
    update_post_meta( $post_id, 'post_lat', $post_lat );
    update_post_meta( $post_id, 'post_long', $post_long );
    // I know this is maybe not the most efficient way to store same data in two separate columns, but here is the only resonable thing that comes to my mind 
    if($post_lat && $post_long) {
        $wpdb->get_results("INSERT INTO `{$table_prefix}vademecum` (`post_id`, `latlong`) VALUES ({$post_id}, ST_GeomFromText('POINT({$post_lat} {$post_long})'));");
    } else {
        $wpdb->get_results("DELETE FROM `{$table_prefix}vademecum` WHERE `{$table_prefix}vademecum`.`post_id` = {$post_id};");
    }
    update_post_meta( $post_id, 'post_address', $post_address );
}

# Print metadata
function vademecum_meta() {    
    global $post;
    if($post) {
        $post_meta = get_post_meta($post->ID);
        if (isset($post_meta['post_long']) && isset($post_meta['post_lat'])) {
            echo "<meta name=\"geo.position\" content=\"".$post_meta['post_lat'][0].";".$post_meta['post_long'][0]."\">\n";
            echo "<meta name=\"geo.placename\" content=\"".$post->post_title."\">\n";
        }
    }
}

# Print map
function vademecum_add_map() {
    global $post;
    $output = "";
    $post_meta = get_post_meta($post->ID);
    if (isset($post_meta['post_long']) && isset($post_meta['post_lat']) && $post_meta['post_long'][0] && $post_meta['post_lat'][0]) {
        $options = get_option('vademecum_options');
        if (isset($options['vademecum_map_title']) && $options['vademecum_map_title']) {
            $output = "<h3 class=\"wp-block-heading\">{$options['vademecum_map_title']}</h3>\n";
        }
        $icon_url = "/wp-content/themes/synagogu.es/assets/img/star_of_david_full_blue.png";
        $shortcode = "[leaflet-map][leaflet-marker lat=".$post_meta['post_lat'][0]." lng=".$post_meta['post_long'][0]." iconurl=\"".$icon_url."\"]";
        $post_address = isset($post_meta['post_address']) && $post_meta['post_address'][0] ? $post_meta['post_address'][0] : $post->post_title;
        $shortcode = $shortcode . "<a href=\"https://www.google.com/maps?q=".$post_meta['post_lat'][0].",".$post_meta['post_long'][0]."\" target=\"_blank\" rel=\"noopener\">{$post_address}</a>[/leaflet-marker]";
        $output .= "\n\n" . do_shortcode($shortcode);
    }    
    return $output;
}

# Print list of nearest
function vademecum_show_nearest($atts) {
    global $geotag_table, $wpdb, $post, $table_prefix;
    $output = "";
    $limit = isset($atts['limit']) ? $atts['limit'] : 5;
    $post_meta = get_post_meta($post->ID);
    if (isset($post_meta['post_long']) && isset($post_meta['post_lat']) && $post_meta['post_long'][0] && $post_meta['post_lat'][0]) {
        $options = get_option('vademecum_options');
        if (isset($options['vademecum_nearest_title']) && $options['vademecum_nearest_title']) {
            $output = "<h3 class=\"wp-block-heading\">{$options['vademecum_nearest_title']}</h3>\n";
        }
        $query = "SELECT post_id, ST_AsText(latlong) as latlong, ST_DISTANCE(ST_GeomFromText('POINT(".$post_meta['post_lat'][0]." ".$post_meta['post_long'][0].")'),latlong) AS dist FROM {$table_prefix}vademecum WHERE post_id <> {$post->ID} ORDER BY dist LIMIT {$limit};";
        $ids = array_map(function($a) {return $a->post_id;}, $wpdb->get_results($query));
        // $output .= "<code>".$query."</code>";
        // $output .= "<pre>".print_r($ids, TRUE)."</pre>";        
        if($ids) {
            $output .= "<ul>\n";
            foreach ($ids as $id) {   
                $p = get_post($id);
                $output .= "<li><a href=\"".get_permalink($id)."\">".$p->post_title."</a></li>\n";
            }
            $output .= "</ul>\n";
        }
    }
    return $output;
}


function vademecum_settings() {
    add_options_page( 'VadeMecum Settings', 'VadeMecum', 'manage_options', 'vademecum', 'vademecum_settings_page' );
}

function vademecum_settings_page() {
    ?>
    <h2>VadeMecum Settings</h2>
    <form action="options.php" method="post">
        <?php 
        settings_fields('vademecum_options');
        do_settings_sections('vademecum');
        ?>
        <input name="submit" class="button button-primary" type="submit" value="<?php esc_attr_e( 'Save' ); ?>" />
    </form>
    <?php
}

function vademecum_section_text() {
    echo '<p>Here you can set how map and list headers should be displayed.</p>';
}

function vademecum_map_title() {
    $options = get_option('vademecum_options');
    echo "<input id=\"vademecum_map_title\" name=\"vademecum_options[vademecum_map_title]\" type=\"text\" value=\"".esc_attr($options['vademecum_map_title'])."\" />";
}

function vademecum_nearest_title() {
    $options = get_option('vademecum_options');
    echo "<input id=\"vademecum_nearest_title\" name=\"vademecum_options[vademecum_nearest_title]\" type=\"text\" value=\"".esc_attr($options['vademecum_nearest_title'])."\" />";
}

function vademecum_options_validate( $input ) {
    // Do nothung for now
    return $input;
}