<?php

declare(strict_types=1);

/*
 * The few classes of WordPress the unit suite needs as real classes (loaded by tests/Pest.php): the code builds
 * them with `new`, or reads their properties. Mockery can still mock any of them.
 */

#[AllowDynamicProperties]
class WP_Post
{
    public $ID = 0;

    public $post_type = 'post';

    public $post_status = 'publish';

    public $post_title = '';

    public $post_content = '';

    public $post_excerpt = '';

    public $post_name = '';

    public $post_parent = 0;

    public $post_modified_gmt = '2026-01-01 00:00:00';

    public $post_date_gmt = '2026-01-01 00:00:00';

    public function __construct(array $fields = [])
    {
        foreach ($fields as $name => $value) {
            $this->{$name} = $value;
        }
    }
}

class WP_HTTP_Response
{
    public function __construct(public $data = null, public $status = 200, public $headers = []) {}

    public function get_data()
    {
        return $this->data;
    }

    public function get_status()
    {
        return $this->status;
    }

    public function get_headers()
    {
        return $this->headers;
    }

    public function header($key, $value)
    {
        $this->headers[$key] = $value;
    }
}

class WP_REST_Response extends WP_HTTP_Response
{
    public function is_error()
    {
        return $this->status >= 400;
    }
}

class WP_REST_Request
{
    private $params = [];

    private $headers = [];

    public function __construct(private $method = 'GET', private $route = '') {}

    public function get_method()
    {
        return $this->method;
    }

    public function get_route()
    {
        return $this->route;
    }

    public function get_param($key)
    {
        return $this->params[$key] ?? null;
    }

    public function set_param($key, $value)
    {
        $this->params[$key] = $value;
    }

    public function get_header($key)
    {
        return $this->headers[$key] ?? null;
    }

    public function set_header($key, $value)
    {
        $this->headers[$key] = $value;
    }
}

/** Records what it was asked, and answers with what a test put in `$answer`. */
class WP_Query
{
    /** @var list<mixed> */
    public static $queries = [];

    /** @var array{posts?: mixed, found_posts?: int, max_num_pages?: int} */
    public static $answer = [];

    public $posts;

    public $found_posts;

    public $max_num_pages;

    public function __construct($query = '')
    {
        self::$queries[] = $query;
        $this->posts = array_key_exists('posts', self::$answer) ? self::$answer['posts'] : [];
        $this->found_posts = self::$answer['found_posts'] ?? 0;
        $this->max_num_pages = self::$answer['max_num_pages'] ?? 0;
    }
}

class WP_Block_Type
{
    public function __construct(public $attributes = null, private $dynamic = false) {}

    public function is_dynamic()
    {
        return $this->dynamic;
    }
}

class WP_Block_Type_Registry
{
    /** @var array<string, WP_Block_Type> */
    public static $types = [];

    private static $instance;

    public static function get_instance()
    {
        return self::$instance ??= new self;
    }

    public function get_registered($name)
    {
        return self::$types[$name] ?? null;
    }
}
