<?php

declare(strict_types=1);

/*
 * What the package reads of Contact Form 7, for PHPStan only: the plugin publishes no stubs.
 * What a filter of the plugin can change is declared `mixed`, so that the code has to check it.
 */

class WPCF7_ContactForm
{
    /**
     * @param  array<string, mixed>|string  $args
     * @return array<mixed>
     */
    public static function find($args = '') {}

    /**
     * @param  mixed  $post
     * @return self|false|null
     */
    public static function get_instance($post) {}

    /**
     * @param  mixed  $cond
     * @return array<mixed>
     */
    public function scan_form_tags($cond = null) {}

    /** @return mixed */
    public function id() {}

    /** @return mixed */
    public function title() {}

    /** @return mixed */
    public function locale() {}
}

class WPCF7_FormTag
{
    /** @var mixed */
    public $basetype;

    /** @var mixed */
    public $name;

    /** @var mixed */
    public $values;

    /** @var mixed */
    public $labels;

    /**
     * @param  string  $option_name
     * @return bool
     */
    public function has_option($option_name) {}

    /** @return bool */
    public function is_required() {}
}
