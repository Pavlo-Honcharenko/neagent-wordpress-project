<?php
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use HivePress\Models\Listing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ASPO_Importer {

    /**
     * Receives the euro to hryvnia exchange rate from the NBU with caching in the database.
     * * @return float Поточний курс євро.
     */
    private function get_eur_rate() {
        // 1. Settings
        $fallback_rate = 54; // Fallback course if the API doesn't respond
        $ttl           = 12 * HOUR_IN_SECONDS; // Cache validity period (12 hours)
        $now           = time();
        
        // We get the saved data from the table wp_options
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

        // 4.Processing the result
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
            // $this->log( 'USD rate loaded from DB (fresh): ' . $stored_rate );
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

    public function run( $source = 'MANUAL' ) {
        if ( get_transient( 'aspo_import_lock' ) ) {
            $this->log( "[$source] Import already running. Skipped." );
            return 'Import already running';
        }

        // We set the lock for 25 minutes (less than 30 min crown interval)
        set_transient( 'aspo_import_lock', 1, 25 * MINUTE_IN_SECONDS );

        $this->log( "--- START IMPORT [$source] ---" );

        $feed = new ASPO_Feed();
        $xml  = $feed->fetch();

        if ( ! is_object( $xml ) || ! isset( $xml->realty ) ) {
            $this->log( "[$source] Feed error or empty: " . ( is_string( $xml ) ? $xml : 'No realty tags' ) );
            delete_transient( 'aspo_import_lock' );
            return;
        }

        $eur_rate         = $this->get_eur_rate();
        $usd_rate         = $this->get_usd_rate();
        $limit            = 10; //Limit of SUCCESSFUL ads per run
        $imported_count   = 0;
        
        // --- LOGIC OF DISPLACEMENT(OFFSET) ---
        $current_offset   = (int) get_option( 'aspo_import_offset', 0 );
        $total_realty     = count( $xml->realty );
        $processed_this_run = 0; // How many feed elements have we viewed in total in this run
        $max_to_process   = 100; // The maximum number of feed elements to check (so that there is no timeout)
        
        if ( $current_offset >= $total_realty ) {
            $current_offset = 0;
        }
        
        $this->log( "[$source] Start from index $current_offset. Total in feed: $total_realty" );

        $user_id          = 34; // 28, hosting - 34
        $user_listing_id  = 17485; // 17450, hosting - 17485

        $category_map = [
            'long-term-lease_homes'      => 'house-rental',
            'rent_homes'      => 'house-rental',
            'long-term-lease_apartments' => 'apartment-rental',
            'rent_apartments' => 'apartment-rental',
            'sell_homes'                 => 'house-for-sale',
            'sell_apartments'            => 'apartment-for-sale',

            'sell_land'            => 'land-for-sale',
            'long-term-lease_land'       => 'land-rental',
            'rent_land'            => 'land-rental',

            'sell_office'                 => 'commercial-property-for-sale',
            'sell_industry'               => 'commercial-property-for-sale',
            'sell_garages-parking'        => 'commercial-property-for-sale',

            'rent_office'                 => 'commercial-property-rental',
            'long-term-lease_office'      => 'commercial-property-rental',
            'long-term-lease_industry'      => 'commercial-property-rental',
            'long-term-lease_garages-parking' => 'commercial-property-rental',
            
        ];

        $region_map = [
            'kievskaya'      => 'kyiv-region',
            'odesskaya'      => 'odesa-region',
            'dnepropetrovskaya' => 'dnipropetrovsk-region',
            'doneckaya' => 'donetsk-region',
            'zhytomyrskaya' => 'zhytomyr-region',
            'lvovskaya'      => 'lviv-region',
            'volynskaya'      => 'volyn-region',
            'zaporozhskaya'      => 'zaporizhzhia-region',
            'kirovogradskaya'      => 'kirovohrad-region',
            'xarkovskaya'      => 'kharkiv-region',
            'vinnickaya'      => 'vinnytsia-region',
            'ivano-frankovskaya'      => 'ivano-frankivsk-region',
            'rovenskaya'      => 'rivne-region',
            'chernigovskaya'      => 'chernihiv-region',
            'nikolaevskaya'      => 'mykolaiv-region',
            'poltavskaya'      => 'poltava-region',
            'zakarpatskaya'      => 'zakarpattia-region',
            'sumskaya'      => 'sumy-region',
            'ternopolskaya'      => 'ternopil-region',
            'cherkasskaya'      => 'cherkasy-region',
            'chernovickaya'      => 'chernivtsi-region',
            'luganskaya'      => 'lugansk-region',
            'xersonskaya'      => 'kherson-region',
            'xmelnickaya'      => 'khmelnytskyi-region',
            'zhitomirskaya'      => 'zhytomyr-region',
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

        // Replace foreach with for to work with indexes
        for ( $i = $current_offset; $i < $total_realty; $i++ ) {
            
            // If we have already added 10 ads (limit) 
            // OR if too many raw records were checked (eg 100) to avoid a timeout
            if ( $imported_count >= $limit || $processed_this_run >= $max_to_process ) {
                break;
            }

            $item = $xml->realty[$i];
            $processed_this_run++; 

            $external_id = (string) $item->id;
            $feed_hash_img = trim( (string) $item->hash_ads_img );
            $feed_hash_txt = trim( (string) $item->hash_ads_txt );
            
            $is_update_needed = false;
            $update_images    = false;
            $post_id          = 0;

            // 1. Duplicate check
            $existing = get_posts( [
                'post_type'    => 'hp_listing',
                'meta_key'     => 'aspo_external_id',
                'meta_value'   => $external_id,
                'fields'       => 'ids',
                'numberposts' => 1,
            ] );

            if ( ! empty( $existing ) ) {
                $post_id = $existing[0];
                $db_hash_img = get_post_meta( $post_id, 'hash_ads_img', true );
                $db_hash_txt = get_post_meta( $post_id, 'hash_ads_txt', true );

                if ( empty( $db_hash_img ) && empty( $db_hash_txt ) ) {
                    update_post_meta( $post_id, 'hash_ads_img', $feed_hash_img );
                    update_post_meta( $post_id, 'hash_ads_txt', $feed_hash_txt );
                    continue; 
                }

                if ( $db_hash_txt !== $feed_hash_txt ) {
                    $is_update_needed = true;
                }
                if ( $db_hash_img !== $feed_hash_img ) {
                    $is_update_needed = true;
                    $update_images    = true;
                }

                if ( ! $is_update_needed ) {
                    continue; 
                }
                
                // $this->log( "[$source] Update detected for ID $external_id (Post ID: $post_id). Hash mismatch." );
            }

            // 2. Валідація телефону
            $phone = '';
            if ( ! empty( $item->fixed_phone ) ) {
                $phone = trim( (string) $item->fixed_phone );
            } elseif ( ! empty( $item->phone1 ) ) {
                $phone = trim( (string) $item->phone1 );
            }

            if ( empty( $phone ) ) {
                $this->log( "[$source] Skip ID $external_id: No phone provided." );
                continue;
            }

            // 3. Photo validation
            if ( ( ! $post_id || $update_images ) && ( ! isset( $item->photos->photo ) || empty( $item->photos->photo ) ) ) {
                $this->log( "[$source] Skip ID $external_id: No photos found." );
                continue;
            }

            // 4. Category validation
            $type_offer   = strtolower( trim( (string) $item->property ) );
            $type_realty  = strtolower( trim( (string) $item->typeofrealty ) );
            $map_key      = $type_offer . '_' . $type_realty;
            $category_slug = isset( $category_map[ $map_key ] ) ? $category_map[ $map_key ] : '';

            $term = $category_slug ? get_term_by( 'slug', $category_slug, 'hp_listing_category' ) : false;
            if ( ! $term ) {
                $this->log( "[$source] Skip ID $external_id: Category not found ($map_key)." );
                continue;
            }

            // 5. City validation
            $raw_city  = strtolower( trim( (string) $item->settle ) );
            $city_slug = isset( $city_map[ $raw_city ] ) ? $city_map[ $raw_city ] : '';
            $city_term = $city_slug ? get_term_by( 'slug', $city_slug, 'hp_listing_city' ) : false;

            if ( ! $city_term ) {
                $raw_region = strtolower( trim( (string) ( $item->region ?? '' ) ) );
                if ( isset( $region_map[ $raw_region ] ) ) {
                    $region_slug = $region_map[ $raw_region ];
                    // Consistent data type: always try to get the term object
                    $city_term = get_term_by( 'slug', $region_slug, 'hp_listing_city' );
                }
            }

            // Final check: if still no term found, log and skip
            if ( ! $city_term ) {
                $this->log( "[$source] Skip ID $external_id: City not found ($raw_city , $raw_region)." );
                continue;
            }

            // 6. Create or update an ad
            $post_data = [
                'post_type'      => 'hp_listing',
                'post_status'    => 'publish',
                'post_title'     => (string) $item->title,
                'post_content'   => (string) $item->description,
                'post_author'    => $user_id,
                'post_parent'    => $user_listing_id,
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
                'post_excerpt'   => 'Продавець: ASPO.',
            ];

            if ( $post_id ) {
                $post_data['ID'] = $post_id;
                wp_update_post( $post_data );
            } else {
                $post_id = wp_insert_post( $post_data, true );
            }

            if ( is_wp_error( $post_id ) ) {
                $this->log( "[$source] Skip ID $external_id: DB error: " . $post_id->get_error_message() );
                continue;
            }

            wp_set_object_terms( $post_id, [ (int) $city_term->term_id ], 'hp_listing_city' );
            wp_set_object_terms( $post_id, [ (int) $term->term_id ], 'hp_listing_category' );

            update_post_meta( $post_id, '_hp_listing_author', $user_id );
            update_post_meta( $post_id, 'price', (string) $item->cost );
            update_post_meta( $post_id, 'currency', (string) $item->currency );
            update_post_meta( $post_id, 'aspo_external_id', $external_id );
            update_post_meta( $post_id, 'phone', $phone );
            update_post_meta( $post_id, 'hash_ads_img', $feed_hash_img );
            update_post_meta( $post_id, 'hash_ads_txt', $feed_hash_txt );

            if ( ! empty( $item->email ) ) {
                update_post_meta( $post_id, 'email', sanitize_email( (string) $item->email ) );
            }
            if ( ! empty( $item->name ) ) {
                update_post_meta( $post_id, 'name', trim( (string) $item->name ) );
            }

            $typeofoffer   = strtolower( trim( (string) $item->typeofoffer ) );
            $allowed_types = [ 'delegate-owner', 'agent', 'owner' ];
            if ( in_array( $typeofoffer, $allowed_types, true ) ) {
                update_post_meta( $post_id, 'typeofoffer', $typeofoffer );
            }

            $lat      = trim( (string) $item->coords_lat );
            $lng      = trim( (string) $item->coords_lng );
            $street   = trim( (string) $item->street );
            $house    = trim( (string) $item->house_number );
            $location = implode( ', ', array_filter( [ $street, $house, $city_term->name, 'Україна' ] ) );

            if ( $lat !== '' && $lng !== '' ) {
                update_post_meta( $post_id, 'hp_latitude', $lat );
                update_post_meta( $post_id, 'hp_longitude', $lng );
            }
            update_post_meta( $post_id, 'hp_location', $location );

            update_post_meta( $post_id, 'hp_rooms', (int) $item->rooms );
            update_post_meta( $post_id, 'hp_level', (int) $item->floor );
            update_post_meta( $post_id, 'hp_building_levels', (int) $item->numberoffloors );
            update_post_meta( $post_id, 'hp_sq_footage_live', (float) $item->squarelive );
            update_post_meta( $post_id, 'hp_sq_footage_total', (float) $item->squarefull );

            $price = (float) $item->cost;
            if ( strtolower( trim( (string) $item->costtype ) ) === 'day' ) { $price *= 30; }
            if ( strtoupper( trim( (string) $item->currency ) ) === 'USD' ) { $price *= $usd_rate; }
            if ( strtoupper( trim( (string) $item->currency ) ) === 'EUR' ) { $price *= $eur_rate; }
            update_post_meta( $post_id, 'hp_price', round( $price ) );

            if ( ! empty( $item->photos->photo ) && ( ! isset( $existing[0] ) || $update_images ) ) {
                if ( $update_images ) {
                    $old_images = get_post_meta( $post_id, 'images' );
                    if ( ! empty( $old_images ) ) {
                        foreach ( $old_images as $old_id ) {
                            wp_delete_attachment( $old_id, true );
                        }
                        delete_post_meta( $post_id, 'images' );
                    }
                }

                $attachment_ids = [];
                $max_images     = 5;
                $order          = 0;

                foreach ( $item->photos->photo as $photo_url ) {
                    if ( count( $attachment_ids ) >= $max_images ) break;

                    $photo_url = esc_url_raw( (string) $photo_url );
                    if ( strpos( $photo_url, 'images.weserv.nl' ) !== false ) {
                        parse_str( parse_url( $photo_url, PHP_URL_QUERY ), $qs );
                        if ( ! empty( $qs['url'] ) ) { $photo_url = 'https://' . ltrim( $qs['url'], '/' ); }
                    }

                    $tmp = download_url( $photo_url, 15 );
                    if ( is_wp_error( $tmp ) ) continue;

                    $file_array = [
                        'name'     => basename( parse_url( $photo_url, PHP_URL_PATH ) ),
                        'tmp_name' => $tmp,
                    ];

                    $attachment_id = media_handle_sideload( $file_array, $post_id, null, [
                        'post_author' => $user_id,
                        'post_status' => 'inherit',
                        'post_parent' => $post_id,
                        'menu_order'  => $order,
                    ] );

                    if ( ! is_wp_error( $attachment_id ) ) {
                        if ( in_array( get_post_mime_type( $attachment_id ), [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ], true ) ) {
                            $attachment_ids[] = (int) $attachment_id;
                            update_post_meta( $attachment_id, 'hp_parent_model', 'listing' );
                            update_post_meta( $attachment_id, 'hp_parent_field', 'images' );
                            $order++;
                        } else {
                            wp_delete_attachment( $attachment_id, true );
                        }
                    }
                }

                if ( ! empty( $attachment_ids ) ) {
                    set_post_thumbnail( $post_id, $attachment_ids[0] );
                    foreach ( $attachment_ids as $aid ) {
                        add_post_meta( $post_id, 'images', $aid );
                    }
                }
            }

            try {
                $listing = \HivePress\Models\Listing::query()->get_by_id( (int) $post_id );
                if ( $listing ) { $listing->save(); }
            } catch ( \Exception $e ) {
                global $wpdb;
                $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s", '_transient_hp_models/%' ) );
            }

            $imported_count++;
            $status_msg = $is_update_needed ? "Updated" : "Imported";
            // $this->log( "[$source] Success: $status_msg ID $post_id ($external_id). Total: $imported_count" );
        }

        // --- UPDATE OFFSET AFTER CYCLE ---
        $new_offset = $current_offset + $processed_this_run;
        if ( $new_offset >= $total_realty ) {
            update_option( 'aspo_import_offset', 0 );
            $this->log( "[$source] End of feed reached. Resetting offset to 0." );
        } else {
            update_option( 'aspo_import_offset', $new_offset );
            $this->log( "[$source] Session finished. Offset moved to $new_offset." );
        }

        delete_transient( 'aspo_import_lock' );
        $this->log( "[$source] Import session finished. Added/Updated: $imported_count. Checked in feed: $processed_this_run" );
    }

    private function log( $message ) {
        $dir = ASPO_PATH . 'logs/';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $file = $dir . 'aspo.log';
        $time = date( 'Y-m-d H:i:s' );
        file_put_contents( $file, '[' . $time . '] ' . $message . PHP_EOL, FILE_APPEND );
    }
}