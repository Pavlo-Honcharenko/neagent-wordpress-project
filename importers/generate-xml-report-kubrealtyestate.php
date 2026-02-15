<?php
/**
 * Script for generating reports.
 * Variables:
 * $author_id
 * $filename
 */

define('WP_USE_THEMES', false);
require_once('wp-load.php');

global $wpdb;

$author_id = 7;
$filename = 'reportNeagentKubrealtyestate.xml';

$results = $wpdb->get_results($wpdb->prepare(
    "SELECT 
        p.ID, 
        p.guid, 
        pm.meta_value as import_id, 
        IFNULL(pv.count, 0) as views_count
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = 'external_id')
    LEFT JOIN {$wpdb->prefix}post_views pv ON (p.ID = pv.id AND pv.type = 4 AND pv.period = 'total')
    WHERE p.post_author = %d 
    AND p.post_status = 'publish' 
    AND p.post_type = 'hp_listing'", 
    $author_id
));

// We create XML
$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><objects></objects>');
$xml->addAttribute('date', date('Y-m-d H:i:s'));

if ($results) {
    foreach ($results as $row) {
        $object = $xml->addChild('object');
        $object->addChild('import_id', htmlspecialchars($row->import_id ? $row->import_id : ''));
        $object->addChild('link', htmlspecialchars($row->guid));
        $object->addChild('local_id', $row->ID);
        $object->addChild('views', (int)$row->views_count);
    }
}

$xml_content = $xml->asXML();
if (file_put_contents(ABSPATH . $filename, $xml_content)) {
    echo "File updated successfully (optimized). Processed objects: " . count($results);
} else {
    echo "Write error.";
}