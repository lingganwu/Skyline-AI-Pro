<?php
if (!defined('ABSPATH')) exit;

class Skyline_Content {
    public function __construct() {
        add_action('save_post', [$this, 'auto_spider'], 20, 2);
        add_filter('the_content', [$this, 'auto_internal_links']);
    }

    public function auto_spider($pid, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($pid) || $post->post_status != 'publish') return;
        
        $core = Skyline_Core::instance();
        if (!$core->get_opt('spider_enable') || !$core->get_opt('spider_auto')) return;
        
        if (get_post_meta($pid, '_sky_spider_processing', true)) return;
        update_post_meta($pid, '_sky_spider_processing', 1);
        
        $content = $post->post_content;
        preg_match_all('/(src|data-src)=[\'"]([^\'"]+)[\'"]/i', $content, $m);
        if (empty($m[2])) {
            delete_post_meta($pid, '_sky_spider_processing');
            return;
        }
        
        foreach ($m[2] as $url) {
            $res = $this->download_and_cos($url, $pid);
            if (isset($res['url'])) {
                $content = str_replace($url, $res['url'], $content);
            }
        }
        
        if ($content !== $post->post_content) {
            remove_action('save_post', [$this, 'auto_spider'], 20);
            global $wpdb;
            $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $pid]);
            clean_post_cache($pid);
            add_action('save_post', [$this, 'auto_spider'], 20, 2);
        }
        delete_post_meta($pid, '_sky_spider_processing');
    }

    private function download_and_cos($url, $pid) {
        $core = Skyline_Core::instance();
        $temp_file = download_url($url);
        if (is_wp_error($temp_file)) return ['error' => $temp_file->get_error_message()];
        
        $aid = wp_insert_attachment([ 'post_mime_type' => mime_content_type($temp_file), 'post_title' => 'Spider' ], $temp_file, $pid);
        if (is_wp_error($aid)) return ['error' => 'DB Fail'];
        
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $temp_file));
        
        $cos_mod = new Skyline_COS_Mod();
        $cos_mod->sync_all_sizes(wp_generate_attachment_metadata($aid, $temp_file), $aid);
        
        return ['url' => wp_get_attachment_url($aid)];
    }

    public function auto_internal_links($content) {
        $core = Skyline_Core::instance();
        if (!$core->get_opt('link_enable') || is_admin()) return $content;
        $links_str = $core->get_opt('link_pairs');
        if (!$links_str) return $content;
        $pairs = explode("\n", $links_str);
        foreach ($pairs as $pair) {
            $p = explode('|', trim($pair));
            if (count($p) < 2) continue;
            $kw = trim($p[0]); $url = trim($p[1]);
            if (substr_count($content, $url) < 1) {
                $content = preg_replace('/(?!(?:[^<]+>|[^>]+<\/a>))'.preg_quote($kw, '/').'/u', '<a href="'.$url.'" target="_blank">$0</a>', $content, 1);
            }
        }
        return $content;
    }
}
