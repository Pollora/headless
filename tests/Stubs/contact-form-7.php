<?php

declare(strict_types=1);

/*
 * A stand-in for the parts of Contact Form 7 the adapter reads. The integration suite runs the real plugin.
 */

class WPCF7_ContactForm
{
    /** @var array<int, WPCF7_ContactForm> the forms of the site */
    public static $forms = [];

    /** @var list<mixed> anything else find() gives back */
    public static $noise = [];

    public function __construct(private $id = 0, private $title = '', private $locale = 'en_US', private $tags = []) {}

    public static function find($args = '')
    {
        return [...array_values(self::$forms), ...self::$noise];
    }

    public static function get_instance($post)
    {
        return self::$forms[$post] ?? null;
    }

    public function scan_form_tags($cond = null)
    {
        return $this->tags;
    }

    public function id()
    {
        return $this->id;
    }

    public function title()
    {
        return $this->title;
    }

    public function locale()
    {
        return $this->locale;
    }
}

class WPCF7_FormTag
{
    public function __construct(
        public $basetype = 'text',
        public $name = '',
        public $values = [],
        public $labels = [],
        private $options = [],
        private $required = false,
    ) {}

    public function has_option($option_name)
    {
        return in_array($option_name, $this->options, true);
    }

    public function is_required()
    {
        return $this->required;
    }
}
