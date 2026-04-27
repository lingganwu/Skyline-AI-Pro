<?php
/**
 * Skyline AI Pro - API Gateway Module
 * 
 * This module provides a custom, high-privilege API endpoint for automated 
 * content publishing, bypassing standard REST API authentication hurdles.
 * 
 * @package SkylineAIPro
 */

if (!defined('ABSPATH')) exit;

class Skyline_API_Gateway {
    
    /**
     * The secret token used for authentication.
     * In a production environment, this should be stored in the database via plugin settings.
     */
    private $secret_token = 'Skyline_Ultra_Secret_Token_2026_Industrial_Edition';
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register custom REST API routes.
     */
    public function register_routes() {
        register_rest_route('skyline/v1', '/post', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_post_creation'),
            'permission_callback' => '__return_true', // Handled manually inside callback
        ));
    }

    /**
     * Handle the post creation request.
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_post_creation($request) {
        // 1. Token Authentication
        $auth_header = $request->get_header('X-Skyline-Token');
        
        if (!$auth_header || $auth_header !== $this->secret_token) {
            return new WP_Error(
                'rest_forbidden', 
                'Skyline Gateway: Authentication failed. Invalid or missing X-Skyline-Token.', 
                array('status' => 401)
            );
        }

        // 2. Parameter Extraction
        $params = $request->get_json_params();
        
        if (empty($params['title']) || empty($params['content'])) {
            return new WP_Error(
                'rest_invalid_param', 
                'Skyline Gateway: Missing required parameters (title or content).', 
                array('status' => 400)
            );
        }

        // 3. Post Data Construction
        $post_data = array(
            'post_title'   => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($params['content']),
            'post_status'  => isset($params['status']) ? sanitize_text_field($params['status']) : 'publish',
            'post_author'  => isset($params['author']) ? intval($params['author']) : 1,
            'post_category' => isset($params['category']) ? array(intval($params['category'])) : array(),
            'tags'          => isset($params['tags']) ? $params['tags'] : array(),
        );

        // 4. Post Insertion
        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            return new WP_Error(
                'rest_post_insert_failed', 
                'Skyline Gateway: Failed to insert post into database: ' . $post_id->get_error_message(), 
                array('status' => 500)
            );
        }

        // 5. Handle Metadata (e.g., AI Source, etc.)
        if (isset($params['meta'])) {
            foreach ($params['meta'] as $key => $value) {
                update_post_meta($post_id, '_' . $key, sanitize_text_field($value));
            }
        }

        return new WP_REST_Response(array(
            'status'  => 'success',
            'message' => 'Article published successfully via Skyline Gateway.',
            'id'      => $post_id,
            'link'    => get_permalink($post_id)
        ), 200);
    }
}

// Initialize the gateway if not handled by a core loader
new Skyline_API_Gateway();
