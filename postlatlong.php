<?php
/**
 * @package PostLatLong
 */
/*
Plugin Name: PostLatLong
Plugin URI: https://bobrowski.com.pl/
Description: Save post latitude and longitude, draw post position on map and show nearest posts
Version: 0.1
Requires at least: 6.0
Requires PHP: 8.2
Author: Bohdan Bobrowski
Author URI: https://bohdan.bobrowski.com.pl
License:
Text Domain: postlatlong
*/

register_activation_hook( __FILE__, 'postlatlong_activate');
register_deactivation_hook( __FILE__, 'postlatlong_deactivate');
add_action('admin_init','postlatlong_admin');
add_action('admin_head', 'postlatlong_admin_css' );
add_action('save_post','save_metabox_postlatlong');
add_action('wp_head', 'postlatlong_meta', 1);
add_filter('the_content', 'postlatlong_add_map');
add_shortcode('postlatlong-nearest','postlatlong_show_nearest');

# On activate
function postlatlong_activate(){
    global $wpdb;
    if ( ! is_plugin_active( 'leaflet-map/leaflet-map.php' ) and current_user_can( 'activate_plugins' ) ) {        
        wp_die('Sorry, but this plugin requires the leaflet-map installed and active. <br><a href="' . admin_url( 'plugins.php' ) . '">&laquo; Return to Plugins</a>');
    }
    $wpdb->get_results("CREATE VIEW IF NOT EXISTS synagogues_postlatlong AS SELECT post_id, POINT(SUM(CASE WHEN meta_key=\"post_lat\" THEN meta_value ELSE 0 END), SUM(CASE WHEN meta_key=\"post_long\" THEN meta_value ELSE 0 END)) as latlong FROM synagogues_postmeta WHERE (abs(meta_value) > 0 AND meta_value IS NOT NULL) AND (meta_key=\"post_long\" OR meta_key=\"post_lat\") GROUP BY post_id;");
}

# On activate
function postlatlong_deactivate(){
    global $wpdb;
    $wpdb->get_results("DROP VIEW IF EXISTS synagogues_postlatlong;");
}

# Admin post edit form 
function postlatlong_admin()
{
    add_meta_box("Post coordinates", "Post coordinates", "postlatlong_admin_form", "post", "side", "high");
}
function postlatlong_admin_form(){
    global $post;
    $post_meta = get_post_meta($post->ID);
    $post_lat = isset($post_meta['post_lat']) ? $post_meta['post_lat'][0] : "";
    $post_long = isset($post_meta['post_long']) ? $post_meta['post_long'][0] : "";
    $post_address = isset($post_meta['post_address']) ? $post_meta['post_address'][0] : "";
    ?>
    <table class="postlatlong_block">
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
function postlatlong_admin_css() {
	echo "
	<style type='text/css'>
    .postlatlong_block {
        display: table;
    }
	.postlatlong_row {
        padding: 0 0 0.5em 0;
        display: table-row;
    }
    .postlatlong_row label {
        width: 40%
        display: table-cell;
    }
    .postlatlong_row input {
        display: table-cell;
    }
	</style>
	";
}

# Save lat/long and adresss
function save_metabox_postlatlong($post_id)
{
    $post_lat = $_POST['post_lat'];
    $post_long = $_POST['post_long'];
    $post_address = $_POST['post_address'];
    update_post_meta( $post_id, 'post_lat', $post_lat );
    update_post_meta( $post_id, 'post_long', $post_long );
    update_post_meta( $post_id, 'post_address', $post_address );
}

# Print metadata
function postlatlong_meta() {    
    global $post;
    $post_meta = get_post_meta($post->ID);
    if (isset($post_meta['post_long']) && isset($post_meta['post_lat'])) {
        echo "<meta name=\"geo.position\" content=\"".$post_meta['post_lat'][0].";".$post_meta['post_long'][0]."\">\n";
        echo "<meta name=\"geo.placename\" content=\"".$post->post_title."\">\n";
    }
}

# Print map
function postlatlong_add_map($content) {
    global $post;
    $post_meta = get_post_meta($post->ID);
    if (isset($post_meta['post_long']) && isset($post_meta['post_lat']) && isset($post_meta['post_address']) && $post_meta['post_long'][0] && $post_meta['post_lat'][0]) {
        $icon_url = "/wp-content/themes/synagogu.es/assets/img/star_of_david_full_blue.png";
        $shortcode = "[leaflet-map][leaflet-marker lat=".$post_meta['post_lat'][0]." lng=".$post_meta['post_long'][0]." iconurl=\"".$icon_url."\"]";
        $shortcode = $shortcode . "<a href=\"https://www.google.com/maps?q=".$post_meta['post_lat'][0].",".$post_meta['post_long'][0]."\" target=\"_blank\" rel=\"noopener\">".$post_meta['post_address'][0]."</a>[/leaflet-marker]";
        $content = $content . "\n\n" . do_shortcode($shortcode);
    }    
    return $content;
}

# Print list of nearest
function postlatlong_show_nearest() {
    global $geotag_table, $wpdb, $post;
    $post_meta = get_post_meta($post->ID);
    $output = "";
    $output .= "<h3 class=\"wp-block-heading\">"._("Najbliżej:")."</h3>";
    if (isset($post_meta['post_long']) && isset($post_meta['post_lat']) && $post_meta['post_long'][0] && $post_meta['post_lat'][0]) {
        $query = "SELECT post_id, ST_AsText(latlong) as latlong, ST_DISTANCE(ST_GeomFromText('POINT(".$post_meta['post_lat'][0]." ".$post_meta['post_long'][0].")'),latlong) AS dist FROM synagogues_postlatlong WHERE post_id <> ".$post->ID." ORDER BY dist LIMIT 5;";
        // $output .= "<code>".$query."</code>";
        // $output .= "<pre>".print_r($ids, TRUE)."</pre>";
        $ids = array_map(function($a) {return $a->post_id;}, $wpdb->get_results($query));
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
