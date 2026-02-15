<?php
/**
 *  FLATPRIME Importer Script (INSERT ONLY)
 */

$start_script_time = microtime(true);

// 1. CONNECTING THE WORDPRESS CORE
require_once( dirname( __FILE__ ) . '/wp-load.php' );

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use HivePress\Models\Listing;

// 2. ACCESS CHECK (Cron or Admin only)
$is_cron = ( php_sapi_name() === 'cli' || (defined('DOING_CRON') && DOING_CRON) );
$is_admin = current_user_can( 'manage_options' );

if ( ! $is_cron && ! $is_admin ) {
    wp_die( 'Access is denied.' );
}

if (!$is_cron) {
    set_time_limit(0);
    // Force output buffering to off
    if (ob_get_level()) ob_end_clean();
    echo "Start import Flatprime...<br>";
    flush();
}

/**
 * FEED
 */
class Flatprime_Feed {
    private $feed_url = 'https://crm-an-flatprime-ukr.realtsoft.net/feed/xml?id=4'; 
    public function fetch() {
        $response = wp_remote_get( $this->feed_url, ['timeout' => 30] );
        if ( is_wp_error( $response ) ) { return $response->get_error_message(); }
        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) { return 'Empty feed'; }
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );
        if ( ! $xml ) { return 'Invalid XML'; }
        return $xml;
    }
}

/**
 * IMPORTER
 */
class Flatprime_Importer {

    private function log($msg) {
        $formatted_msg = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
        
        // Log to file
        file_put_contents(
            __DIR__ . '/import_flatprime.log',
            $formatted_msg . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        // Display in browser if not CLI/Cron
        if ( php_sapi_name() !== 'cli' && (!defined('DOING_CRON') || !DOING_CRON) ) {
            // Escaping output for WordPress security
            echo esc_html($formatted_msg) . "<br>";
            flush(); // Send the output to the browser immediately
        }
    }

    /**
     * Receives the euro to hryvnia exchange rate from the NBU with caching in the database.
     * * @return float Поточний курс євро.
     */
    private function get_eur_rate() {
        // 1. Settings
        $fallback_rate = 54; // Fallback course if the API doesn't respond
        $ttl           = 12 * HOUR_IN_SECONDS; // Cache validity period (12 hours)
        $now           = time();
        
        $stored_rate   = get_option( 'aspo_eur_rate' );
        $stored_time   = get_option( 'aspo_eur_rate_time' );

        // 2. Checking the validity of the cache
        if ( $stored_rate && $stored_time && ( $now - $stored_time ) < $ttl ) {
            // If the course is in the database and it is "fresh", we return it
            return (float) $stored_rate;
        }

        // 3. Request to NBU API
        // The parameter has been changed valcode=EUR
        $response = wp_remote_get( 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?valcode=EUR&json', [ 
            'timeout' => 10 
        ] );

        // Check for request error (eg timeout)
        if ( is_wp_error( $response ) ) {
            $this->log( 'NBU EUR request error: ' . $response->get_error_message() );
            return $stored_rate ? (float) $stored_rate : $fallback_rate;
        }

        // 4. Processing the result
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // Checking whether valid data came from the NBU
        if ( empty( $body[0]['rate'] ) ) {
            $this->log( 'NBU EUR response invalid' );
            return $stored_rate ? (float) $stored_rate : $fallback_rate;
        }

        $rate = round( (float) $body[0]['rate'], 2 );

        // 5. Updating data in the database
        update_option( 'aspo_eur_rate', $rate, false );
        update_option( 'aspo_eur_rate_time', $now, false );

        $this->log( 'EUR rate updated from NBU: ' . $rate );

        return $rate;
    }

    private function get_usd_rate() {
        $fallback_rate = 45.67;
        $ttl           = 12 * HOUR_IN_SECONDS;
        $now           = time();
        $stored_rate   = get_option( 'aspo_usd_rate' );
        $stored_time   = get_option( 'aspo_usd_rate_time' );

        if ( $stored_rate && $stored_time && ( $now - $stored_time ) < $ttl ) {
            $this->log( 'USD rate loaded from DB (fresh): ' . $stored_rate );
            return (float) $stored_rate;
        }

        $this->log( 'USD rate is missing or expired, trying NBU...' );

        $response = wp_remote_get( 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?valcode=USD&json', [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) ) {
            $this->log( 'NBU request error: ' . $response->get_error_message() );
            return $stored_rate ? (float) $stored_rate : $fallback_rate;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body[0]['rate'] ) ) {
            $this->log( 'NBU response invalid' );
            return $stored_rate ? (float) $stored_rate : $fallback_rate;
        }

        $rate = round( (float) $body[0]['rate'], 2 );

        update_option( 'aspo_usd_rate', $rate, false );
        update_option( 'aspo_usd_rate_time', $now, false );

        $this->log( 'USD rate updated from NBU: ' . $rate );

        return $rate;
    }

    private function convert_image_to_webp($file_path, $quality = 82) {

       //$this->log("WEBP: Start convert for $file_path");

        // Якщо вже webp
        if (strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) === 'webp') {
            //$this->log("WEBP: Already webp, skip");
            return $file_path;
        }

        // === IMAGICK
        if (class_exists('Imagick')) {
            try {
                //$this->log("WEBP: Try Imagick");

                $image = new Imagick($file_path);

                $formats = $image->queryFormats('WEBP');
                if (empty($formats)) {
                    //$this->log("WEBP: Imagick has NO WEBP support!");
                } else {
                    $image->setImageFormat('webp');
                    $image->setImageCompressionQuality($quality);

                    $webp_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $file_path);
                    $image->writeImage($webp_path);

                    if (file_exists($webp_path)) {
                        //$this->log("WEBP: Imagick success -> $webp_path");
                        $image->clear();
                        $image->destroy();
                        unlink($file_path);
                        return $webp_path;
                    } else {
                        //$this->log("WEBP: Imagick failed to write file");
                    }
                }

            } catch (Exception $e) {
                //$this->log("WEBP: Imagick exception: " . $e->getMessage());
            }
        } else {
            //$this->log("WEBP: Imagick not exists");
        }

        // === GD fallback
        if (function_exists('imagewebp')) {
            //$this->log("WEBP: Try GD");

            $info = getimagesize($file_path);
            if ($info) {

                switch ($info['mime']) {
                    case 'image/jpeg':
                        $image = imagecreatefromjpeg($file_path);
                        break;
                    case 'image/png':
                        $image = imagecreatefrompng($file_path);
                        imagepalettetotruecolor($image);
                        imagealphablending($image, true);
                        imagesavealpha($image, true);
                        break;
                    case 'image/gif':
                        $image = imagecreatefromgif($file_path);
                        break;
                    default:
                        //$this->log("WEBP: GD unsupported mime " . $info['mime']);
                        return $file_path;
                }

                $webp_path = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $file_path);
                imagewebp($image, $webp_path, $quality);
                imagedestroy($image);

                if (file_exists($webp_path)) {
                    //$this->log("WEBP: GD success -> $webp_path");
                    unlink($file_path);
                    return $webp_path;
                } else {
                    //$this->log("WEBP: GD failed to write file");
                }
            }
        } else {
            //$this->log("WEBP: GD imagewebp not exists");
        }

        // fallback
        //$this->log("WEBP: Fallback to original $file_path");
        return $file_path;
    }

    public function run( $source = '' ) {

        $feed = new Flatprime_Feed();
        $xml  = $feed->fetch();

        if (!is_object($xml) || !isset($xml->offer)) {
            $this->log("Feed error");
            return;
        }

        $eur_rate         = $this->get_eur_rate();
        $usd_rate         = $this->get_usd_rate();
        
        $limit            = 20;  // Limit of SUCCESSFUL new ads
        $max_to_process   = 100; 
        
        $imported           = 0;
        $processed_this_run = 0; // Counter of viewed items

        // --- ЛОГІКА ЗМІЩЕННЯ (OFFSET) ---
        $current_offset = (int)get_option('flatprime_import_offset', 0);
        $total = count($xml->offer);

        if ($current_offset >= $total) {
            $current_offset = 0;
        }

        $this->log("Start from offset $current_offset / $total");

        $user_id = 46;
        $parent_listing = 27850;

        $category_map = [
            'long-term-lease_homes'      => 'house-rental',
            'rent_homes'      => 'house-rental',
            'аренда_дом'      => 'house-rental',
            'long-term-lease_apartments' => 'apartment-rental',
            'rent_apartments' => 'apartment-rental',
            'аренда_квартира' => 'apartment-rental',
            'sell_homes'                 => 'house-for-sale',
            'продажа_дом'                 => 'house-for-sale',
            'sell_apartments'            => 'apartment-for-sale',
            'продажа_квартира'            => 'apartment-for-sale',

            'sell_land'            => 'land-for-sale',
            'продажа_участок'            => 'land-for-sale',
            'long-term-lease_land'       => 'land-rental',
            'rent_land'            => 'land-rental',
            'аренда_участок'            => 'land-rental',

            'sell_office'                 => 'commercial-property-for-sale',
            'продажа_офис'                 => 'commercial-property-for-sale',
            'sell_industry'               => 'commercial-property-for-sale',
            'sell_garages-parking'        => 'commercial-property-for-sale',

            'rent_office'                 => 'commercial-property-rental',
            'аренда_офис'                 => 'commercial-property-rental',
            'аренда_коммерческая'                 => 'commercial-property-rental',
            'long-term-lease_office'      => 'commercial-property-rental',
            'long-term-lease_industry'      => 'commercial-property-rental',
            'long-term-lease_garages-parking' => 'commercial-property-rental',         
        ];

        $region_map = [
            'kievskaya'      => 'kyiv-region',
            'Киевская'      => 'kyiv-region',
            'Киевская область'      => 'kyiv-region',
            'Київська область'      => 'kyiv-region',
            'odesskaya'      => 'odesa-region',
                        'Одесская'      => 'odesa-region',
            'Одесская область'      => 'odesa-region',
            'dnepropetrovskaya' => 'dnipropetrovsk-region',
            'Днепропетровская' => 'dnipropetrovsk-region',
            'Днепропетровская область' => 'dnipropetrovsk-region',
            'doneckaya' => 'donetsk-region',
            'Донецкая' => 'donetsk-region',
            'Донецкая область' => 'donetsk-region',
            'zhytomyrskaya' => 'zhytomyr-region',
            'Житомирская' => 'zhytomyr-region',
            'Житомирская область' => 'zhytomyr-region',
            'lvovskaya'      => 'lviv-region',
            'Львовская'      => 'lviv-region',
            'Львовская область'      => 'lviv-region',
            'volynskaya'      => 'volyn-region',
            'Волынская'      => 'volyn-region',
            'Волынская область'      => 'volyn-region',
            'zaporozhskaya'      => 'zaporizhzhia-region',
            'Запорожская'      => 'zaporizhzhia-region',
            'Запорожская область'      => 'zaporizhzhia-region',
            'kirovogradskaya'      => 'kirovohrad-region',
            'Кировоградская'      => 'kirovohrad-region',
            'Кировоградская область'      => 'kirovohrad-region',
            'xarkovskaya'      => 'kharkiv-region',
            'Харьковская'      => 'kharkiv-region',
            'Харьковская область'      => 'kharkiv-region',
            'vinnickaya'      => 'vinnytsia-region',
            'Винницкая'      => 'vinnytsia-region',
            'Винницкая область'      => 'vinnytsia-region',
            'ivano-frankovskaya'      => 'ivano-frankivsk-region',
            'Ивано-Франковская'      => 'ivano-frankivsk-region',
            'Ивано-Франковская область'      => 'ivano-frankivsk-region',
            'rovenskaya'      => 'rivne-region',
            'Ровенская'      => 'rivne-region',
            'Ровенская область'      => 'rivne-region',
            'chernigovskaya'      => 'chernihiv-region',
            'Черниговская'      => 'chernihiv-region',
            'Черниговская область'      => 'chernihiv-region',
            'nikolaevskaya'      => 'mykolaiv-region',
            'Николаевская'      => 'mykolaiv-region',
            'Николаевская область'      => 'mykolaiv-region',
            'poltavskaya'      => 'poltava-region',
            'Полтавская'      => 'poltava-region',
            'Полтавская область'      => 'poltava-region',
            'zakarpatskaya'      => 'zakarpattia-region',
            'Закарпатская'      => 'zakarpattia-region',
            'Закарпатская область'      => 'zakarpattia-region',
            'sumskaya'      => 'sumy-region',
            'Сумская'      => 'sumy-region',
            'Сумская область'      => 'sumy-region',
            'ternopolskaya'      => 'ternopil-region',
            'Тернопольская'      => 'ternopil-region',
            'Тернопольская область'      => 'ternopil-region',
            'cherkasskaya'      => 'cherkasy-region',
            'Черкасская'      => 'cherkasy-region',
            'Черкасская область'      => 'cherkasy-region',
            'chernovickaya'      => 'chernivtsi-region',
            'Черновицкая'      => 'chernivtsi-region',
            'Черновицкая область'      => 'chernivtsi-region',
            'luganskaya'      => 'lugansk-region',
            'Луганская'      => 'lugansk-region',
            'Луганская область'      => 'lugansk-region',
            'xersonskaya'      => 'kherson-region',
            'Херсонская'      => 'kherson-region',
            'Херсонская область'      => 'kherson-region',
            'xmelnickaya'      => 'khmelnytskyi-region',
            'Хмельницкая'      => 'khmelnytskyi-region',
            'Хмельницкая область'      => 'khmelnytskyi-region',
            'zhitomirskaya'      => 'zhytomyr-region',
            'Житомирская'      => 'zhytomyr-region',
            'Житомирская область'      => 'zhytomyr-region',
            ];

        $city_map = [
            'vinnica'         => 'vinnytsia',
            'dnepropetrovsk'  => 'dnipro',
            'zhitomir'        => 'zhytomyr',
            'zaporozhe'       => 'zaporizhzhia',
            'ivano-frankivsk' => 'ivano-frankivsk',
            'ivano-frankovsk' => 'ivano-frankivsk',
            'frankivsk'       => 'ivano-frankivsk',
            'frankovsk'       => 'ivano-frankivsk',
            'Київ'            => 'kyiv',
            'Киев'            => 'kyiv',
            'kiev'            => 'kyiv',
            'sofievskaya-borshhagovka' => 'kyiv',
            'petropavlovskaya-borshhagovka' => 'kyiv',
            'kirovograd'      => 'kropyvnytskyi',
            'luck'            => 'lutsk',
            'lvov'            => 'lviv',
            'nikolaev'        => 'mykolaiv',
            'mykolaiv'        => 'mykolaiv',
            'odessa'          => 'odesa',
            'poltava'         => 'poltava',
            'rovno'           => 'rivne',
            'rivne'           => 'rivne',
            'sumy'            => 'sumy',
            'sumi'            => 'sumy',
            'ternopil'        => 'ternopil',
            'ternopol'        => 'ternopil',
            'uzhhorod'        => 'uzhhorod',
            'ujhorod'         => 'uzhhorod',
            'ujgorod'         => 'uzhhorod',
            'uzhпorod'        => 'uzhhorod',
            'xarkov'          => 'kharkiv',
            'kherson'         => 'kherson',
            'xerson'          => 'kherson',
            'herson'          => 'kherson',
            'xmelnick'        => 'khmelnytskyi',
            'khmelnytskyi'    => 'khmelnytskyi',
            'cherkassy'       => 'cherkasy',
            'cherkassі'       => 'cherkasy',
            'chernovtsy'      => 'chernivtsi',
            'chernovсy'       => 'chernivtsi',
            'chernivtsi'      => 'chernivtsi',
            'chernigov'       => 'chernihiv',
        ];


        for ($i = $current_offset; $i < $total; $i++) {

            // 1. CHECKING LIMITS
            if ($imported >= $limit) {
                $this->log("Reached import limit ($limit). Stopping.");
                break;
            }
            
            if ($processed_this_run >= $max_to_process) {
                $this->log("Reached processing limit ($max_to_process). Stopping.");
                break;
            }

            $processed_this_run++; // We increase the counter at each iteration

            $item = $xml->offer[$i];
            $external_id = (string)$item['internal-id'];

            if (!$external_id) continue;

            // CHECK FOR DUPLICATIONS
            $exists = get_posts([
                'post_type'  => 'hp_listing',
                'meta_key'   => 'external_id',
                'meta_value' => $external_id,
                'fields'     => 'ids',
                'numberposts'=> 1
            ]);

            if ($exists) continue; // If it's a duplicate, just move on

            // PHONE
            $phone = trim((string)$item->{'sales-agent'}->phone);
            if (!$phone) continue;

            // PHOTOS
            if (empty($item->image)) continue;

            // CATEGORY (ONLY SALE)
            $category_slug = 'apartment-for-sale';
            $cat_term = get_term_by('slug', $category_slug, 'hp_listing_category');
            if (!$cat_term) continue;

             // CITY
            $city_name = trim((string)$item->location->{'locality-name'});

            $raw_city  = strtolower( trim((string)$item->location->{'locality-name'}) );
            $city_slug = isset( $city_map[ $raw_city ] ) ? $city_map[ $raw_city ] : '';
            $city_term = $city_slug ? get_term_by( 'slug', $city_slug, 'hp_listing_city' ) : false;

            if ( ! $city_term ) { 
                $raw_region = strtolower( trim( (string) ( $item->location->{'region'} ?? '' ) ) );
                if ( isset( $region_map[ $raw_region ] ) ) {
                    $region_slug = $region_map[ $raw_region ];
                    // Consistent data type: always try to get the term object
                    $city_term = get_term_by( 'slug', $region_slug, 'hp_listing_city' );

                    $this->log("City not found for: $city_name , $raw_region");
                }
            }

            if ( ! $city_term ) {
                $this->log("ID $external_id , city not found for: $city_name , $raw_region . The object is not added...");
                continue;
            }

            // TITLE
            $rooms = (string)$item->rooms;
            $category_name = trim((string)$item->category);

            $title_parts = [];
            if ($category_name) $title_parts[] = $category_name;
            if ($rooms) $title_parts[] = $rooms . '-кімнатна';
            if ($city_name) $title_parts[] = $city_name;

            $title = implode(', ', $title_parts);
            $title = mb_strtoupper(mb_substr($title, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($title, 1, null, 'UTF-8');

            // INSERT
            $post_id = wp_insert_post([
                'post_type'      => 'hp_listing',
                'post_status'    => 'publish',
                'post_title'     => $title,
                'post_content'   => (string)$item->description,
                'post_author'    => $user_id,
                'post_parent'    => $parent_listing,
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ], true);

            if (is_wp_error($post_id)) {
                $this->log("Error inserting post: " . $post_id->get_error_message());
                continue;
            }

            wp_set_object_terms($post_id, [(int)$cat_term->term_id], 'hp_listing_category');
            wp_set_object_terms($post_id, [(int)$city_term->term_id], 'hp_listing_city');

            update_post_meta($post_id, 'external_id', $external_id);
            update_post_meta($post_id, 'phone', $phone);
            update_post_meta($post_id, '_hp_listing_author', $user_id);

            if ( ! empty( $item->{'sales-agent'}->email ) ) {
                update_post_meta( $post_id, 'email', sanitize_email( (string) $item->{'sales-agent'}->email ) );
            }

            if ( ! empty( $item->{'sales-agent'}->name ) ) {
                update_post_meta( $post_id, 'name', trim( (string) $item->{'sales-agent'}->name ) );
            }

            update_post_meta( $post_id, 'hp_level', (int) $item->floor );
            update_post_meta( $post_id, 'hp_building_levels', (int) $item->{'floors-total'} );
            update_post_meta( $post_id, 'hp_sq_footage_live', (float) $item->{'living-space'}->value );
            update_post_meta( $post_id, 'hp_sq_footage_total', (float) $item->area->value );

            update_post_meta($post_id, 'hp_rooms', (int)$item->rooms);

            $price = (float) $item->price->value;
            if ( strtolower( trim( (string) $item->costtype ) ) === 'day' ) { $price *= 30; }
            if ( strtoupper( trim( (string) $item->price->currency ) ) === 'USD' ) { $price *= $usd_rate; }
            if ( strtoupper( trim( (string) $item->price->currency ) ) === 'EUR' ) { $price *= $eur_rate; }
            update_post_meta( $post_id, 'hp_price', round( $price ) );

            update_post_meta($post_id, 'hp_latitude', (string)$item->location->latitude);
            update_post_meta($post_id, 'hp_longitude', (string)$item->location->longitude);


            // IMAGES
            $ids = [];
            $order = 0;

            foreach ($item->image as $img) {
                if (count($ids) >= 6) break;

                $url = trim((string)$img);
                if (!$url) continue;

                $tmp = download_url($url);
                if (is_wp_error($tmp)) continue;

                // Конвертація WebP
                $tmp_webp = $this->convert_image_to_webp($tmp);
                if (!$tmp_webp || !file_exists($tmp_webp)) $tmp_webp = $tmp;

                $file = [
                    'name'     => basename($tmp_webp),
                    'tmp_name' => $tmp_webp
                ];

                $aid = media_handle_sideload($file, $post_id, null, ['menu_order' => $order]);

                if (!is_wp_error($aid)) {
                    $ids[] = $aid;
                    update_post_meta($aid, 'hp_parent_model', 'listing');
                    update_post_meta($aid, 'hp_parent_field', 'images');
                    $order++;
                }

                // Видаляємо тимчасові файли
                if (file_exists($tmp)) @unlink($tmp);
                if ($tmp_webp !== $tmp && file_exists($tmp_webp)) @unlink($tmp_webp);
            }

            if ($ids) {
                // We install the preview
                set_post_thumbnail($post_id, $ids[0]);

                // We create a serialized array for HivePress
                update_post_meta($post_id, 'images', $ids);
            }

            try {
                $listing = Listing::query()->get_by_id($post_id);
                if ($listing) $listing->save();
            } catch (\Exception $e) {}

            $this->log("Successfully imported: ID $external_id -> Post $post_id");
            $imported++;
        }

        // We update the offset in the database so that the next launch starts with $i
        update_option('flatprime_import_offset', $i >= $total ? 0 : $i);

        $final_offset = get_option('flatprime_import_offset');
        $this->log("Cycle finished. Next offset: $final_offset. Processed: $processed_this_run, Imported: $imported");
    }
}

(new Flatprime_Importer())->run();

// Calculate the script execution time
$time = round(microtime(true) - $start_script_time, 2);

// Prepare the log message with an extra empty line at the end
// We use double PHP_EOL to create a blank line between entries
$log_message = '[' . date('Y-m-d H:i:s') . "] DONE in {$time}s" . PHP_EOL . PHP_EOL;

// Save the log to the file
file_put_contents(
    __DIR__ . '/import_flatprime.log',
    $log_message,
    FILE_APPEND
);

// Display final message in browser
if ( php_sapi_name() !== 'cli' && (!defined('DOING_CRON') || !DOING_CRON) ) {
    echo "<br><strong>" . esc_html($log_message) . "</strong>";
}