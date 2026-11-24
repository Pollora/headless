<?php

declare(strict_types=1);

namespace Pollora\Headless\Domain\Revalidation;

/**
 * The changes of a few seconds, merged into one notification.
 *
 * Saving a post touches its tags, its terms and its menu entries at once, and an import saves hundreds of
 * posts: a front end that is called once per change would be flooded. The batch keeps each tag and URL once.
 * Past the limits it says it is incomplete (`truncated`): the front end should then revalidate everything.
 */
final readonly class Batch
{
    public const int MAX_TAGS = 200;

    public const int MAX_URLS = 200;

    public const int MAX_EVENTS = 100;

    /**
     * @param  list<ChangeEvent>  $events
     * @param  list<string>  $tags
     * @param  list<string>  $urls
     */
    private function __construct(
        public array $events,
        public array $tags,
        public array $urls,
        public bool $truncated,
    ) {}

    /**
     * @param  list<ChangeEvent>  $events
     */
    public static function of(array $events): self
    {
        $tags = [];
        $urls = [];

        foreach ($events as $event) {
            foreach ($event->tags as $tag) {
                $tags[$tag] = $tag;
            }

            foreach ($event->urls as $url) {
                $urls[$url] = $url;
            }
        }

        $truncated = count($tags) > self::MAX_TAGS || count($urls) > self::MAX_URLS || count($events) > self::MAX_EVENTS;

        return new self(
            array_slice($events, 0, self::MAX_EVENTS),
            array_slice(array_values($tags), 0, self::MAX_TAGS),
            array_slice(array_values($urls), 0, self::MAX_URLS),
            $truncated,
        );
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    /**
     * The JSON body a target receives.
     *
     * @param  string  $id  identifies the delivery: a retry carries the same one, so a receiver can ignore a repeat
     */
    public function toJson(string $id, int $sentAt): string
    {
        $paths = [];

        foreach ($this->urls as $url) {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = parse_url($url, PHP_URL_QUERY);
            $paths[$path.(is_string($query) ? '?'.$query : '')] = true;
        }

        return json_encode([
            'id' => $id,
            'sent_at' => $sentAt,
            'truncated' => $this->truncated,
            'events' => array_map(static fn (ChangeEvent $event): array => $event->toArray(), $this->events),
            'tags' => $this->tags,
            'urls' => $this->urls,
            'paths' => array_map(strval(...), array_keys($paths)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
