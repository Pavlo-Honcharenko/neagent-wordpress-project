<?php
/**
 * Script for checking and DELETING missing listings between DB and XML Feed.
 * Variables:
 * $feed_url
 * $target_user_id
 * 
 * Block:
 *  if (is_object($xml) && isset($xml->offer)) {
 *      foreach ($xml->offer as $item) {
 *         $feed_ids[] = (string) $item['internal-id'];
 *     }
 * }
 */

define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';

class FEED {

    private $feed_url = 'https://crm-an-flatprime-ukr.realtsoft.net/feed/xml?id=4';

    public function fetch() {

        $response = wp_remote_get( $this->feed_url, [
            'timeout' => 30,
        ]);

        if ( is_wp_error( $response ) ) {
            return $response->get_error_message();
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return 'Empty feed';
        }

        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );

        if ( ! $xml ) {
            return 'Invalid XML';
        }

        return $xml;
    }
}


// Function for correct output (browser vs console)
function my_log($message) {
    $line_break = (php_sapi_name() === 'cli') ? "\n" : "<br>";
    echo $message . $line_break;
}

// Permission Check (admin or CLI only)
if (php_sapi_name() !== 'cli' && !current_user_can('manage_options')) {
    die('Restricted access');
}

my_log("--- START ADS SYNC & CLEANUP ---");

$target_user_id = 46; 

// --- STEP 1: We get the list from the database ---
my_log("Step 1: Fetching listings from DB for user $target_user_id...");

global $wpdb;

$results = $wpdb->get_results($wpdb->prepare("
    SELECT p.ID, pm.meta_value as external_id
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
    WHERE p.post_author = %d 
    AND p.post_type = 'hp_listing'
    AND pm.meta_key = 'external_id'
", $target_user_id), ARRAY_A);

$db_listings = [];
if ($results) {
    foreach ($results as $row) {
        $db_listings[$row['external_id']] = $row['ID'];
    }
}

my_log("Found " . count($db_listings) . " listings in DB.");

// --- STEP 2: We get a list of IDs from the feed ---
my_log("Step 2: Fetching listings from XML Feed...");

$feed = new FEED();
$xml  = $feed->fetch();

$feed_ids = [];
if (is_object($xml) && isset($xml->offer)) {
    foreach ($xml->offer as $item) {
        $feed_ids[] = (string) $item['internal-id'];
    }
}

my_log("Found " . count($feed_ids) . " listings in the Feed.");
my_log("-------------------------------");

// --- STEP 3: Find and REMOVE the missing ones ---
my_log("Step 3: Processing deletions...");

$deleted_count = 0;

foreach ($db_listings as $external_id => $post_id) {
    if (!in_array($external_id, $feed_ids)) {
        my_log("ACTION: Deleting Listing $post_id (External ID: $external_id) - Not found in Feed.");

        // 1. We get all the IDs of the images
        $attachment_ids = [];
        
        // the 'images' field (your plugin stores the ID array there)
        $meta_images = get_post_meta($post_id, 'images', false);
        if (!empty($meta_images)) {
            $attachment_ids = array_merge($attachment_ids, (array) $meta_images);
        }

        // thumbnail fields
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $attachment_ids[] = $thumbnail_id;
        }

        // We remove duplicate image IDs
        $attachment_ids = array_unique(array_map('intval', $attachment_ids));

        // 2. We delete each image physically and from the database
        if (!empty($attachment_ids)) {
            foreach ($attachment_ids as $attachment_id) {
                // The second parameter true means "delete forever" instead of trash
                if (wp_delete_attachment($attachment_id, true)) {
                    my_log("--- Deleted attachment ID: $attachment_id");
                }
            }
        }

        if (wp_delete_post($post_id, true)) {
            my_log("SUCCESS: Post $post_id deleted permanently.");
            $deleted_count++;
        } else {
            my_log("ERROR: Failed to delete post $post_id.");
        }
        
        my_log("---");
    }
}

if ($deleted_count > 0) {
    my_log("Total listings deleted: $deleted_count");
} else {
    my_log("No missing listings found. Database is clean.");
}

my_log("--- END OF SCRIPT ---");