<?php
if (!defined('ABSPATH')) exit;

// 1️⃣ We get the page slug
$page_id   = get_the_ID();
$page_post = get_post($page_id);

if (!$page_post) {
    return;
}

$page_slug = $page_post->post_name;
$is_home   = is_front_page();

// 2️⃣ If it is NOT the main thing, we are looking for a city
$city_term_id = null;

if (!$is_home) {

    $city_term = get_term_by('slug', $page_slug, 'hp_listing_city');

    if (!$city_term) {
        return;
    }

    $city_term_id = $city_term->term_id;
}

// 3️⃣ We receive all categories
$categories = get_terms([
    'taxonomy'   => 'hp_listing_category',
    'hide_empty' => false,
]);

// 4️⃣ Sort by hp_sort_order
usort($categories, function($a, $b) {
    $a_order = (int) get_term_meta($a->term_id, 'hp_sort_order', true);
    $b_order = (int) get_term_meta($b->term_id, 'hp_sort_order', true);
    return $a_order <=> $b_order;
});

$output  = '<div class="hp-listing-categories hp-grid hp-block">';
$output .= '<div class="hp-row">';

// 5️⃣ List of categories
foreach ($categories as $cat) {

    // We form the arguments of the request
    $tax_query = [
        [
            'taxonomy' => 'hp_listing_category',
            'field'    => 'term_id',
            'terms'    => $cat->term_id,
        ]
    ];

    // If this is a city page, add a city filter
    if ($city_term_id) {
        $tax_query[] = [
            'taxonomy' => 'hp_listing_city',
            'field'    => 'term_id',
            'terms'    => $city_term_id,
        ];
    }

    $query = new WP_Query([
        'post_type'      => 'hp_listing',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'tax_query'      => $tax_query,
    ]);

    $count = $query->found_posts;

    if ($count === 0) continue;

    // 6️⃣ Form the URL
    if ($city_term_id) {
        $url = add_query_arg([
            's'         => '',
            'post_type' => 'hp_listing',
            '_category' => $cat->term_id,
            'city'      => $city_term_id,
        ], home_url('/'));
    } else {
        // The main page is without a city
        $url = add_query_arg([
            's'         => '',
            'post_type' => 'hp_listing',
            '_category' => $cat->term_id,
        ], home_url('/'));
    }

    // 7️⃣ We get the image
    $image_id  = get_term_meta($cat->term_id, 'hp_image', true);
    $image_url = $image_id 
        ? wp_get_attachment_url($image_id) 
        : 'https://neagent.org.ua/wp-content/uploads/default-category.webp';

    switch ($page_slug) {
        case 'kyiv':
            $location = ' в&nbsp;Києві';
            break;

        case 'kyiv-region':
            $location = ' в&nbsp;Київській області';
            break;

        case 'odesa':
            $location = ' в&nbsp;Одесі';
            break;

        default:
            $location = '';
            break;
    }

    // 8️⃣ Conclusion
    $output .= '<div class="hp-grid__item hp-col-sm-3 hp-col-xs-12">';
    $output .= '<article class="hp-listing-category hp-listing-category--view-block">';
    $output .= '<header class="hp-listing-category__header">';
    $output .= '<div class="hp-listing-category__image">';
    $output .= '<a href="'.esc_url($url).'">';
    $output .= '<img decoding="async" src="'.esc_url($image_url).'" alt="'.esc_attr($cat->name).'" loading="lazy">';
    $output .= '</a>';
    $output .= '</div>';
    $output .= '<div class="hp-listing-category__item-count hp-listing-category__count">'.$count.' оголошень</div>';
    $output .= '</header>';
    $output .= '<div class="hp-listing-category__content">';
    $output .= '<h3 class="hp-listing-category__name">';
    $output .= '<a href="'.esc_url($url).'">'.esc_html($cat->name) . $location .'</a>';
    $output .= '</h3>';
    $output .= '<div class="hp-listing-category__details hp-listing-category__details--primary"></div>';
    $output .= '</div>';
    $output .= '</article>';
    $output .= '</div>';
}

$output .= '</div></div>';

echo $output;