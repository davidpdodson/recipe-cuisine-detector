<?php
/**
 * Plugin Name: Recipe Cuisine Detector
 * Description: Determines the cuisine type from recipe URL and stores it in ACF field 'cuisine'.
 * Version: 1.1
 * Author: Your Name
 */

// Register settings page
add_action('admin_menu', 'recipe_cuisine_settings_page');
function recipe_cuisine_settings_page() {
    add_options_page('Recipe Cuisine Settings', 'Recipe Cuisine Detector', 'manage_options', 'recipe-cuisine-settings', 'recipe_cuisine_settings_html');
}

function recipe_cuisine_settings_html() {
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['submit'])) {
        update_option('recipe_cuisine_api_key', sanitize_text_field($_POST['api_key']));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $api_key = get_option('recipe_cuisine_api_key', '');

    echo '<div class="wrap"><h1>Recipe Cuisine Detector Settings</h1>';
    echo '<form method="post">';
    echo '<table class="form-table"><tr><th scope="row">OpenAI API Key</th>';
    echo '<td><input type="text" name="api_key" value="' . esc_attr($api_key) . '" class="regular-text"></td></tr></table>';
    submit_button();
    echo '</form></div>';
}

// Enqueue JS script on recipe edit screen
add_action('admin_enqueue_scripts', 'enqueue_recipe_cuisine_script');
function enqueue_recipe_cuisine_script($hook) {
    global $post;

    if ($hook === 'post.php' && $post->post_type === 'recipe') {
        wp_enqueue_script('recipe-cuisine-js', plugins_url('/recipe-cuisine.js', __FILE__), ['jquery'], '1.0', true);
        wp_localize_script('recipe-cuisine-js', 'recipeCuisine', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'post_id'  => $post->ID,
            'nonce'    => wp_create_nonce('recipe_cuisine_nonce')
        ]);
    }
}

// Add button to the edit post screen
add_action('edit_form_after_title', 'add_detect_cuisine_button');
function add_detect_cuisine_button($post) {
    if ($post->post_type === 'recipe') {
        echo '<button type="button" class="button button-primary" id="detect-cuisine-btn">Detect Cuisine</button>';
        echo '<span id="cuisine-response" style="margin-left:10px;"></span>';
    }
}

// AJAX handler to fetch page schema and send to ChatGPT
add_action('wp_ajax_detect_recipe_cuisine', 'detect_recipe_cuisine');
function detect_recipe_cuisine() {
    check_ajax_referer('recipe_cuisine_nonce', 'nonce');

    $post_id = intval($_POST['post_id']);
    $url = get_permalink($post_id);

    // Fetch and parse HTML
    $html = wp_remote_get($url);
    if (is_wp_error($html)) wp_send_json_error('Could not fetch URL');

    $dom = new DOMDocument();
    @$dom->loadHTML($html['body']);
    $xpath = new DOMXPath($dom);

    // Extract Schema Data (Title, Ingredients, Instructions)
    $script_tags = $xpath->query("//script[@type='application/ld+json']");

    $schema = [];
    foreach ($script_tags as $tag) {
        $data = json_decode($tag->nodeValue, true);
        if (isset($data['@type']) && ($data['@type'] === 'Recipe' || (is_array($data['@type']) && in_array('Recipe', $data['@type'])))) {
            $schema = $data;
            break;
        }
    }

    if (empty($schema)) wp_send_json_error('Schema not found');

    // Prepare prompt for ChatGPT
    $prompt = "Recipe: {$schema['name']}\nIngredients: " . implode(", ", $schema['recipeIngredient']) . "\nInstructions: " . strip_tags(is_array($schema['recipeInstructions']) ? json_encode($schema['recipeInstructions']) : $schema['recipeInstructions']) . "\nIn three words or less identify the cuisine of this recipe. It is not necessary to use the word 'cuisine' in the answer. Be sure the answer is actually a type of cuisine, which generally is a region and not a restating of the dish. It can be a combination of regions.";

    // Retrieve API key from database
    $api_key = get_option('recipe_cuisine_api_key');
    if (!$api_key) wp_send_json_error('API key not configured');

    // ChatGPT API Call
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key
        ],
        'body' => json_encode([
            'model' => 'gpt-3.5-turbo',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 10,
            'temperature' => 0
        ])
    ]);

    if (is_wp_error($response)) wp_send_json_error('ChatGPT request failed');

    $chatgpt_response = json_decode($response['body'], true);
    $cuisine = trim($chatgpt_response['choices'][0]['message']['content']);

    // Save to ACF field 'cuisine'
    update_field('cuisine', $cuisine, $post_id);

    wp_send_json_success($cuisine);
}
