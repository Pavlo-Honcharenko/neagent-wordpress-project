<?php

/*
 * Performance optimization, custom rewrites, and asset management.
 */

/**
 * 1. CRITICAL ASSETS PRELOADING (LCP FIX)
 * Preloads the background image used in the Cover block to reduce LCP.
 */
add_action('wp_head', function() {
    // Preloading the LCP image found in your screenshot.
    // Replace the URL if it changes for different pages.
    echo '<link rel="preload" as="image" href="https://neagent.org.ua/wp-content/uploads/2024/09/main-bg.webp" fetchpriority="high">';
    
    // Critical CSS for layout stability.
    $critical_file = get_stylesheet_directory() . '/critical.css';
    if (file_exists($critical_file)) {
        echo '<style id="critical-path-css">' . file_get_contents($critical_file) . '</style>';
    }
}, -1000);

/**
 * 2. GOOGLE FONTS & STABILITY
 */
add_action('wp_head', function(){ ?>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css?family=Poppins:500|Open+Sans:400,600&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Poppins:500|Open+Sans:400,600&display=swap" media="print" onload="this.media='all'">
    <style>
        /* Fixing CLS by reserving space for category blocks and setting font-display */
        .home .hp-listing-category--view-block { min-height: 336px; aspect-ratio: 1/1; }
        body { font-display: swap !important; }
    </style>
<?php }, 1);


// We add font-display: swap to local WOFF2 fonts Font Awesome
add_filter('style_loader_tag', function($html, $handle){
    if (strpos($html, 'fa-solid-900.woff2') !== false) {
        $html = str_replace('src: url(', 'src: url(', $html); 
        $html .= '<style>@font-face{font-display:swap;}</style>';
    }
    return $html;
}, 10, 2);

/**
 * 3. ASSET DEFERRING & OPTIMIZATION
 */
add_action('wp_enqueue_scripts', function () {
    // Moving jQuery to footer to remove render-blocking.
    if (!is_admin()) {
        wp_deregister_script('jquery');
        wp_register_script('jquery', includes_url('/js/jquery/jquery.min.js'), false, null, true);
        wp_enqueue_script('jquery');
    }

    wp_enqueue_style('listinghive-child-style', get_stylesheet_uri(), [], filemtime(get_stylesheet_directory() . '/style.css'));
    wp_enqueue_style('listinghive-child-custom', get_stylesheet_directory_uri() . '/assets/css/custom.css', ['listinghive-child-style'], filemtime(get_stylesheet_directory() . '/assets/css/custom.css'));

    wp_dequeue_style('google-fonts-css');
    wp_dequeue_style('googlesitekit-fonts'); 
}, 99);

/**
 * 4. SELECTIVE ASYNCHRONOUS CSS
 */
add_filter('style_loader_tag', function($html, $handle) {
    $critical_handles = [
        'listinghive-child-style',
        'listinghive-child-custom',
        'hivetheme-parent-frontend',
        'hivepress-grid',
        'admin-bar'
    ];

    if (is_admin() || in_array($handle, $critical_handles)) {
        return $html;
    }

    return str_replace("rel='stylesheet'", "rel='stylesheet' media='print' onload=\"this.media='all'\"", $html);
}, 10, 2);

/**
 * 5. LCP IMAGE PRIORITY (FOR IMG TAGS)
 */
add_filter('wp_get_attachment_image_attributes', function($attrs) {
    if (is_front_page()) {
        $attrs['fetchpriority'] = 'high';
        $attrs['loading'] = 'eager';
    }
    return $attrs;
}, 10);


/**
 * 6. FIX LCP for wp-block-cover (convert background to real IMG)
 */
add_filter('render_block', function ($block_content, $block) {

    if ($block['blockName'] !== 'core/cover') {
        return $block_content;
    }

    // We find the URL of the picture with inline style
    if (preg_match('/background-image:\s*url\((.*?)\)/', $block_content, $matches)) {
        $img_url = str_replace(['"', "'"], '', $matches[1]);

        // We create a real img for the browser
        $img_tag = '<img src="' . esc_url($img_url) . '" 
                        class="lcp-cover-img" 
                        fetchpriority="high" 
                        loading="eager" 
                        decoding="async" 
                        alt="оголошення нерухомості в Україні">';

        // Insert the img BEFORE the cover div
        $block_content = preg_replace(
            '/(<div class="wp-block-cover__inner-container")/',
            $img_tag . '$1',
            $block_content
        );
    }

    return $block_content;

}, 9, 2);

/**
 * 7. CUSTOM URL LOGIC (STATTI)
 */
add_filter('post_link', 'custom_post_link_for_statti', 10, 3);
function custom_post_link_for_statti($permalink, $post, $leavename) {
    if ($post->post_type != 'post') return $permalink;
    $categories = get_the_category($post->ID);
    if (!$categories) return $permalink;
    foreach ($categories as $cat) {
        $parent = $cat->parent;
        while ($parent) {
            $parent_cat = get_category($parent);
            if ($parent_cat && $parent_cat->slug === 'statti') {
                return home_url('/statti/' . $post->post_name . '/');
            }
            $parent = $parent_cat->parent ?? 0;
        }
    }
    return $permalink;
}

add_action('init', function() {
    add_rewrite_rule('^statti/([^/]+)/?$', 'index.php?name=$matches[1]', 'top');
});

add_action('template_redirect', function() {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/stati/(.+)\.php$#i', $request_uri, $matches)) {
        wp_redirect(home_url('/statti/' . $matches[1] . '/'), 301);
        exit;
    }
});

/**
 * 7. GOOGLE TAG MANAGER
 */
add_action('wp_head', function() { ?>
    <script async src="https://www.googletagmanager.com/gtm.js?id=GTM-NRFM5ZMV"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', 'GTM-NRFM5ZMV');
    </script>
<?php }, 10);




/**
 * Add version query string (?v=timestamp) to all WordPress images
 * This ensures browser loads new image when file changes
 */
add_filter('wp_get_attachment_image_src', function($image, $attachment_id, $size, $icon) {
    if (!empty($image[0])) {
        $file = get_attached_file($attachment_id); // повний шлях на сервері
        if (file_exists($file)) {
            $time = filemtime($file); // отримуємо час останньої зміни
            // Додаємо ?v=timestamp до URL
            $image[0] .= (strpos($image[0], '?') === false ? '?' : '&') . 'v=' . $time;
        }
    }
    return $image;
}, 10, 4);



// PROHIBITION of creating additional images:
add_filter('intermediate_image_sizes_advanced', function($sizes) {
    // We remove the standard WP sizes (if not reset in the admin)
    unset($sizes['medium']);
    unset($sizes['medium_large']);
    unset($sizes['large']);
    unset($sizes['1536x1536']);
    unset($sizes['2048x2048']);
    // Remove specific HivePress sizes that duplicate each other
    unset($sizes['hp_thumbnail']);  
    unset($sizes['hp_portrait_small']);
    unset($sizes['hp_square_small']);
    unset($sizes['hp_landscape_large']);
    unset($sizes['hp_listing_small']);
    unset($sizes['hp_listing_thumbnail']);
	unset($sizes['ht_portrait_small']);
	unset($sizes['ht_landscape_large']);
    return $sizes;
}, 999);

// We turn off the creation of a "scaled" copy (2560px)
add_filter('big_image_size_threshold', '__return_false');





// I add the price in dollars in the ads.
add_action('wp_enqueue_scripts', function () {

    $script_handle = 'price-usd-script';

    wp_enqueue_script( $script_handle, get_stylesheet_directory_uri() . '/js/price-usd.js', [], '1.0', true );

    wp_localize_script(
    $script_handle,
        'ASPO_DATA',
        ['usdRate' => (float) get_option('aspo_usd_rate'),]);
});



/**
 * Displaying additional information in ads
 */

add_action('wp_footer', 'pavl_ajax_check_offer_details_script');
function pavl_ajax_check_offer_details_script() {
    ?>
    <script>
    (function() {
        /**
         * Formatting the phone number to standard +38 and 10 digits
         */
        const formatUkrainianPhone = (phone) => {
            if (!phone || phone === 'empty') return phone;
            
            let digits = phone.replace(/\D/g, '');
            
            if (digits.length === 12 && digits.startsWith('38')) {
                return '+' + digits;
            } else if (digits.length === 10) {
                return '+38' + digits;
            } else if (digits.length > 10) {
                return '+38' + digits.slice(-10);
            }
            
            return phone;
        };

        /**
         * A function to force text updates and track changes (MutationObserver)
         */
        const forceUpdateText = (element, newText, isPhone = false) => {
            if (!element) return;
            
            const finalContent = isPhone ? formatUkrainianPhone(newText) : newText;
            if (element.textContent === finalContent) return;
            
            element.textContent = finalContent;
            
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (element.textContent !== finalContent) {
                        observer.disconnect();
                        element.textContent = finalContent;
                        observer.observe(element, { childList: true, characterData: true, subtree: true });
                    }
                });
            });
            
            observer.observe(element, { childList: true, characterData: true, subtree: true });
        };

        // Checking the availability of items before use
        const phoneSpan = document.querySelector('span[data-component="phone"]');
        const vendorAttribute = document.querySelector('.hp-vendor__attribute.hp-vendor__attribute--user-status');
        const listingAttribute = document.querySelector('.hp-listing__attribute.hp-listing__attribute--user-status');
        const vendorNameContainer = document.querySelector('.hp-vendor__name');

        
        let aspoLink = null;
        if (vendorNameContainer) {
            const links = vendorNameContainer.querySelectorAll('a');
			
			const vendorNames = ['aspo', 'АН Flatprime', 'kubrealtyestate'];
			aspoLink = Array.from(links).find(link =>
				vendorNames.includes(link.textContent.trim())
			);
        }

        if (vendorAttribute || listingAttribute || phoneSpan || aspoLink) {
            const bodyElement = document.querySelector('body');
            if (bodyElement) {
                const bodyClasses = Array.from(bodyElement.classList);
                const postIdClass = bodyClasses.find(className => className.startsWith('postid-'));

                if (postIdClass) {
                    const postId = postIdClass.split('-')[1];

                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'get_offer_details',
                            post_id: postId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data && data.success) {
                            const { typeofoffer, phone, name } = data.data;

                            // 1. Update statuses (typeofoffer)
                            if (typeofoffer && typeofoffer !== 'empty') {
                                let labelVendor = '';
                                let labelListing = '';
                                
                                switch (typeofoffer) {
                                    case 'owner':
                                        labelVendor = 'Власник';
                                        labelListing = 'власника';
                                        break;
                                    case 'agent':
                                        labelVendor = 'Посередник';
                                        labelListing = 'посередника';
                                        break;
                                    case 'delegate-owner':
                                        labelVendor = 'Представник власника';
                                        labelListing = 'представника власника';
                                        break;
                                }

                                if (vendorAttribute && labelVendor !== '') {
                                    forceUpdateText(vendorAttribute, labelVendor);
                                }

                                if (listingAttribute && labelListing !== '') {
                                    const strongTag = listingAttribute.querySelector('strong');
                                    if (strongTag) {
                                        forceUpdateText(strongTag, labelListing);
                                    }
                                }
                            }

                            // 2. Update phone with prefix +38 and MutationObserver
                            if (phoneSpan && phone && phone !== 'empty') {
                                const formattedPhone = formatUkrainianPhone(phone);
                                forceUpdateText(phoneSpan, formattedPhone, true);
                                
                                const parentLink = phoneSpan.closest('a');
                                if (parentLink) {
                                    parentLink.href = 'tel:' + formattedPhone;
                                }
                            }

                            // 3. Replacing the ASPO name with a name from the database
                            if (aspoLink && name && name !== 'empty') {
                                forceUpdateText(aspoLink, name);
                            }
                        }
                    })
                    .catch(error => console.error('AJAX Error:', error));
                }
            }
        }
    })();
    </script>
    <?php
}

add_action('wp_ajax_get_offer_details', 'pavl_get_offer_details_callback');
add_action('wp_ajax_nopriv_get_offer_details', 'pavl_get_offer_details_callback');

function pavl_get_offer_details_callback() {
    global $wpdb;

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if ($post_id > 0) {
        $table_name = $wpdb->prefix . 'postmeta';
        $meta_keys = ['typeofoffer', 'phone', 'name'];
        $results = [];

        foreach ($meta_keys as $key) {
            // Using prepare and mandatory escape for WordPress
            $val = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $table_name WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $post_id,
                $key
            ));
            
            // Shield the output for safety
            $results[$key] = !empty($val) ? esc_html($val) : 'empty';
        }

        wp_send_json_success($results);
    }

    wp_send_json_error(['typeofoffer' => 'empty', 'phone' => 'empty', 'name' => 'empty']);
}



// Custom Blocks Guttenberg

add_action('init', function () {

    add_filter('block_categories_all', function ($categories) {
        $new = [
            [
                'slug'  => 'neagent-blocks',
                'title' => 'Neagent Blocks',
            ],
        ];
        array_splice($categories, 3, 0, $new);
        return $categories;
    });

    // We register a dynamic block
    register_block_type(
        get_stylesheet_directory() . '/blocks/city-categories',
        [
            'render_callback' => 'neagent_city_categories_render'
        ]
    );
});

function neagent_city_categories_render($attributes, $content) {
    ob_start();
    include get_stylesheet_directory() . '/blocks/city-categories/render.php';
    return ob_get_clean();
}