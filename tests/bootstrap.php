<?php

declare(strict_types=1);

/**
 * Bootstrap pour les tests unitaires du plugin Diagnostic.
 *
 * @package Company\Diagnostic\Tests
 */

$root = dirname(__DIR__, 4);

// Charger l'autoloader Composer
$autoload = $root . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Charger l'autoloader du plugin Diagnostic
require_once dirname(__DIR__) . '/autoload.php';

// Définir ABSPATH si nécessaire
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

// ─── Mocks des fonctions WordPress de base ───

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type): string
    {
        if ($type === 'mysql') {
            return date('Y-m-d H:i:s');
        }
        return (string) time();
    }
}

// ─── Options API ───

if (!function_exists('get_option')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        global $_wp_options;
        return $_wp_options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    /**
     * @param mixed $value
     */
    function update_option(string $option, $value): bool
    {
        global $_wp_options;
        $_wp_options[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        global $_wp_options;
        unset($_wp_options[$option]);
        return true;
    }
}

// ─── Transients API ───

if (!function_exists('get_transient')) {
    /**
     * @return mixed
     */
    function get_transient(string $key)
    {
        global $_wp_transients;
        return $_wp_transients[$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    /**
     * @param mixed $value
     */
    function set_transient(string $key, $value, int $expiration = 0): bool
    {
        global $_wp_transients;
        $_wp_transients[$key] = $value;
        return true;
    }
}

// ─── Gutenberg / Block API ───

if (!function_exists('parse_blocks')) {
    function parse_blocks(string $content): array
    {
        global $_diagnostic_parse_blocks_return;
        if (isset($_diagnostic_parse_blocks_return)) {
            return $_diagnostic_parse_blocks_return;
        }
        return [];
    }
}

if (!function_exists('render_block')) {
    function render_block(array $block): string
    {
        global $_diagnostic_render_block_return;
        if (isset($_diagnostic_render_block_return)) {
            return $_diagnostic_render_block_return;
        }
        return '';
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return dirname($file) . '/';
    }
}

if (!function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names'): array
    {
        global $_diagnostic_post_types;
        return $_diagnostic_post_types ?? ['post' => 'post', 'page' => 'page'];
    }
}

if (!function_exists('apply_filters')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function apply_filters(string $hook, $value, ...$args)
    {
        global $_diagnostic_filters;
        if (isset($_diagnostic_filters[$hook]) && is_callable($_diagnostic_filters[$hook])) {
            return call_user_func($_diagnostic_filters[$hook], $value, ...$args);
        }
        return $value;
    }
}

if (!function_exists('get_post')) {
    /**
     * @param int|WP_Post $post_id
     * @return WP_Post|null
     */
    function get_post($post_id)
    {
        global $_diagnostic_posts;
        $id = is_object($post_id) ? $post_id->ID : (int) $post_id;
        return $_diagnostic_posts[$id] ?? null;
    }
}

if (!function_exists('get_the_title')) {
    /**
     * @param int|WP_Post $post_id
     */
    function get_the_title($post_id): string
    {
        $post = get_post($post_id);
        return $post ? ($post->post_title ?? '') : '';
    }
}

if (!function_exists('get_edit_post_link')) {
    /**
     * @param int $post_id
     */
    function get_edit_post_link($post_id, string $context = 'display'): string
    {
        return "https://example.com/wp-admin/post.php?post={$post_id}&action=edit";
    }
}

// ─── Mock WP_Post ───

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_content = '';
        public string $post_title = '';
        public string $post_status = 'publish';
        public string $post_type = 'post';

        /**
         * @param array<string, mixed> $data
         */
        public function __construct(array $data = [])
        {
            foreach ($data as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
    }
}

// ─── Mock WP_Block_Type ───

if (!class_exists('WP_Block_Type')) {
    class WP_Block_Type
    {
        public string $name = '';
        /** @var callable|null */
        public $render_callback = null;

        /**
         * @param array<string, mixed> $args
         */
        public function __construct(string $name = '', array $args = [])
        {
            $this->name = $name;
            foreach ($args as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->$key = $value;
                }
            }
        }
    }
}

// ─── Mock WP_Block_Type_Registry (singleton) ───

if (!class_exists('WP_Block_Type_Registry')) {
    class WP_Block_Type_Registry
    {
        private static ?self $instance = null;

        /** @var array<string, WP_Block_Type> */
        private array $registered_blocks = [];

        public static function get_instance(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Reset the singleton for test isolation.
         */
        public static function reset(): void
        {
            self::$instance = null;
        }

        public function register(string $name, WP_Block_Type $block_type): void
        {
            $this->registered_blocks[$name] = $block_type;
        }

        public function is_registered(string $name): bool
        {
            return isset($this->registered_blocks[$name]);
        }

        public function get_registered(string $name): ?WP_Block_Type
        {
            return $this->registered_blocks[$name] ?? null;
        }

        /**
         * @return array<string, WP_Block_Type>
         */
        public function get_all_registered(): array
        {
            return $this->registered_blocks;
        }
    }
}

// ─── Mock wpdb minimal ───

if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $posts = 'wp_posts';
        public string $prefix = 'wp_';

        /** @var array<int, mixed> */
        private array $col_results = [];

        /**
         * Configure les résultats retournés par get_col().
         * @param array<int, mixed> $results
         */
        public function set_col_results(array $results): void
        {
            $this->col_results = $results;
        }

        public function prepare(string $query, ...$args): string
        {
            // Remplacement simplifié pour les tests
            $result = $query;
            foreach ($args as $arg) {
                $pos = strpos($result, '%d');
                if ($pos === false) {
                    $pos = strpos($result, '%s');
                }
                if ($pos !== false) {
                    $result = substr_replace($result, (string) $arg, $pos, 2);
                }
            }
            return $result;
        }

        /**
         * @return array<int, mixed>
         */
        public function get_col(string $query): array
        {
            return $this->col_results;
        }
    }
}

// ─── Mock REST API ───

if (!function_exists('register_rest_route')) {
    /**
     * @var array<string, array<string, mixed>>
     */
    global $_diagnostic_rest_routes;
    $_diagnostic_rest_routes = [];

    function register_rest_route(string $namespace, string $route, array $args = []): void
    {
        global $_diagnostic_rest_routes;
        $_diagnostic_rest_routes[$namespace . $route] = $args;
    }
}

if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'https://example.com/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        global $_diagnostic_user_caps;
        return isset($_diagnostic_user_caps[$capability]) && $_diagnostic_user_caps[$capability];
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string
    {
        return 'test_nonce_' . $action;
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        private array $params = [];

        /**
         * @param mixed $value
         */
        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        /**
         * @return mixed
         */
        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }
    }
}
