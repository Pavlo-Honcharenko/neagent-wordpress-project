<?php
/* Template Name: Import Articles */
get_header();
?>

<h1>Import articles from JSON</h1>

<?php

/* =====================================================
 * SETTINGS
 * ===================================================== */
$limit = 20; // number of articles per run (0 = all)

/* =====================================================
 * ШЛЯХИ
 * ===================================================== */
$file_path   = get_stylesheet_directory() . '/assets/articlesUTFexample.json';
$images_path = get_stylesheet_directory() . '/assets/images-articles/';
$images_url  = get_stylesheet_directory_uri() . '/assets/images-articles/';

/* =====================================================
 * OPTION
 * ===================================================== */
$option_key = 'lh_import_last_index';

/* =====================================================
 * MAPPING OF CATEGORIES
 * ===================================================== */
$category_map = [
    'stroitelstvo-remont'                    => 'budivnytstvo',
    'nedvizhimost-ukraine'                   => 'neruhomist',
    'stroitelnye-materialy'                  => 'budmaterialy',
    'oborudovanie-dlya-stroitelstva-remonta' => 'budivelni-instrumenty',
    'spectehnika'                            => 'spectehnika',
    'gruzoperevozki-pereezd'                 => 'perevezennia',
    'torgovoe-vystavochnoe-oborudovanie'     => 'obladnannia',
    'ohrannye-sistemy'                       => 'bezpeka',
    'mebel'                                  => 'mebli',
    'design'                                 => 'dyzain',
    'landscape-design'                       => 'landshaftnyi-dyzain',
    'sadovaya-tehnika'                       => 'sadova-tehnika',
    'santehnika'                             => 'santehnika',
    'elektromontazh'                         => 'elektromontazh',
    'service-offer'                          => 'poslugy',
];

/* =====================================================
 * FUNCTION: find the actual image file
 * ===================================================== */
function lh_find_real_image( $images_path, $filename ) {
    if ( ! $filename ) return false;

    $filename = basename( $filename );
    $normalized = str_replace([',','%2C',' ','--'], ['-','-','-','-'], $filename);

    if ( file_exists( $images_path . $filename ) ) return $filename;
    if ( file_exists( $images_path . $normalized ) ) return $normalized;

    $files = glob( $images_path . '*' );
    foreach ( $files as $file ) {
        if ( stripos( basename($file), pathinfo($filename, PATHINFO_FILENAME) ) !== false ) {
            return basename($file);
        }
    }

    return false;
}

/* =====================================================
 * FUNCTION: Replace HTML entity with characters
 * ===================================================== */
function lh_fix_html_entities( $text ) {
    if ( ! $text ) return $text;

    $replace = [
        '&ndash;' => '–',
        '&mdash;' => '—',
        '&laquo;' => '«',
        '&raquo;' => '»',
        '&apos;'  => "'",
        '&quot;'  => '"',
    ];

    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    return strtr( $text, $replace );
}

/* =====================================================
 * DOWNLOAD JSON
 * ===================================================== */
if ( ! file_exists( $file_path ) ) {
    echo '<p>JSON file not found.</p>';
    get_footer();
    return;
}

$data = json_decode( file_get_contents( $file_path ), true );

if ( json_last_error() !== JSON_ERROR_NONE ) {
    echo '<p>JSON error: ' . esc_html( json_last_error_msg() ) . '</p>';
    get_footer();
    return;
}

$articles = $data[2]['data'] ?? [];
$total    = count( $articles );

if ( ! $total ) {
    echo '<p>No articles.</p>';
    get_footer();
    return;
}

/* =====================================================
 * PROGRESS
 * ===================================================== */
$start_index = (int) get_option( $option_key, 0 );
$end_index   = ( $limit > 0 ) ? min( $start_index + $limit, $total ) : $total;

echo "<p>Importing <strong>$start_index</strong> → <strong>" . ($end_index - 1) . "</strong> of <strong>$total</strong></p>";

/* =====================================================
 * IMPORTS
 * ===================================================== */
for ( $i = $start_index; $i < $end_index; $i++ ) {

    $a = $articles[ $i ];

    $post_title   = lh_fix_html_entities( $a['zagolovok'] ?: 'Без заголовку' );
    $post_content = lh_fix_html_entities( $a['kontent'] ?? '' );
    $post_date    = $a['datastati'] ?? current_time( 'mysql' );
    $post_slug    = sanitize_title( $a['url_stati'] ?? '' );

    /* --- duplicate protection --- */
    if ( $post_slug && get_page_by_path( $post_slug, OBJECT, 'post' ) ) {
        echo "<p>⏭ Skip duplicate: {$post_slug}</p>";
        continue;
    }

    /* --- empty cleaning <p> --- */
    $post_content = preg_replace('/<p>(\s|&nbsp;)*<\/p>/i', '', $post_content);

    /* --- fix IMG in content --- */
    preg_match_all( '/<img[^>]*>/i', $post_content, $imgs );
    foreach ( $imgs[0] ?? [] as $img ) {
        if ( preg_match( '/src=["\']([^"\']+)["\']/i', $img, $m ) ) {
            $real_image = lh_find_real_image( $images_path, $m[1] );
            if ( $real_image ) {
                $post_content = str_replace( $m[1], $images_url . $real_image, $post_content );
            } else {
                $post_content = str_replace( $img, '', $post_content );
            }
        }
    }

    /* --- featured image --- */
    $featured_real_image = lh_find_real_image( $images_path, $a['kartynka'] ?? '' );
    $attachment_id = 0;

    if ( $featured_real_image ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_sideload([
            'name'     => $featured_real_image,
            'tmp_name' => $images_path . $featured_real_image,
        ], 0 );

        if ( is_wp_error( $attachment_id ) ) {
            $attachment_id = 0;
        }
    }

    /* --- the first image in the content from the Media Library --- */
    $has_image_before_first_p = preg_match('/^\s*(<img|<p>\s*<img)/i', $post_content);
    if ( ! $has_image_before_first_p && $attachment_id ) {
        $post_content = '<p class="first-paragraph-image"><img src="' . wp_get_attachment_url( $attachment_id ) . '" alt="' . esc_attr($post_title) . '" class="img-responsive img_left imgshadow img_big articles-first-image"></p>' . $post_content;
    }

    /* --- category --- */
    $category_id = 0;
    $old_slug = $a['rubrika'] ?? '';
    if ( isset( $category_map[ $old_slug ] ) ) {
        $cat = get_category_by_slug( $category_map[ $old_slug ] );
        if ( $cat ) $category_id = $cat->term_id;
    }

    /* --- creating a post --- */
    $post_id = wp_insert_post([
        'post_title'    => $post_title,
        'post_content'  => $post_content,
        'post_status'   => 'publish',
        'post_date'     => $post_date,
        'post_name'     => $post_slug,
        'post_category' => $category_id ? [ $category_id ] : [],
    ]);

    if ( is_wp_error( $post_id ) ) {
        echo "<p>❌ Error on #$i</p>";
        continue;
    }

    /* --- appoint featured image --- */
    if ( $attachment_id ) {
        set_post_thumbnail( $post_id, $attachment_id );
    }
}

/* =====================================================
 * SAVING PROGRESS
 * ===================================================== */
update_option( $option_key, $end_index );

/* =====================================================
 * ▶ CONTINUE
 * ===================================================== */
if ( $end_index < $total ) {
    echo '<form method="post">
        <button class="button button-primary">▶ Continue import</button>
    </form>';
} else {
    echo '<p><strong>🎉 Import finished. All articles imported.</strong></p>';
    delete_option( $option_key );
}

get_footer();
