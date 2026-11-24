<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Exceptions\InvalidRedirectException;
use Pollora\Headless\Domain\Redirect\Redirect;
use Pollora\Headless\Domain\Redirect\RedirectRules;

it('redirects an exact path, with or without its trailing slash', function (string $path): void {
    $rule = new Redirect('/old-page/', '/new-page/', 301);

    expect($rule->resolve($path))->toBe('/new-page/');
})->with(['/old-page/', '/old-page']);

it('does not match another path', function (): void {
    expect((new Redirect('/old', '/new'))->resolve('/old/child'))->toBeNull()
        ->and((new Redirect('/old', '/new'))->resolve('/older'))->toBeNull();
});

it('matches a pattern against the whole path and fills the groups of the destination', function (): void {
    $rule = new Redirect('/blog/(\d{4})/(.*)', '/news/$1/$2', 301, true);

    expect($rule->resolve('/blog/2024/hello-world'))->toBe('/news/2024/hello-world')
        ->and($rule->resolve('/blog/24/hello'))->toBeNull()
        ->and($rule->resolve('/x/blog/2024/hello'))->toBeNull()
        ->and($rule->resolve('/blog/2024/hello'."\n"))->toBeNull();
});

it('leaves a group the pattern did not capture empty', function (): void {
    expect((new Redirect('/a/(b)?', '/c/$1/$2', 302, true))->resolve('/a/'))->toBe('/c//');
});

it('takes a destination that is a path or an absolute URL, and no destination for a 410', function (): void {
    expect((new Redirect('/a', '/b'))->to)->toBe('/b')
        ->and((new Redirect('/a', 'https://www.example.org/b?x=1'))->to)->toBe('https://www.example.org/b?x=1')
        ->and((new Redirect('/gone', '', 410))->status)->toBe(410);
});

it('refuses what cannot be a rule', function (callable $make, string $message): void {
    expect($make)->toThrow(InvalidRedirectException::class, $message);
})->with([
    'no leading slash' => [fn () => new Redirect('old', '/new'), 'is not a path to redirect'],
    'a space in the source' => [fn () => new Redirect('/old page', '/new'), 'is not a path to redirect'],
    'a source that is too long' => [fn () => new Redirect('/'.str_repeat('a', 2000), '/new'), 'is not a path to redirect'],
    'a relative destination' => [fn () => new Redirect('/a', 'new'), 'is not a destination'],
    'a protocol-relative destination' => [fn () => new Redirect('/a', '//evil.example.org'), 'is not a destination'],
    'a javascript destination' => [fn () => new Redirect('/a', 'javascript:alert(1)'), 'is not a destination'],
    'a destination with a space' => [fn () => new Redirect('/a', '/b c'), 'is not a destination'],
    'an empty destination' => [fn () => new Redirect('/a', ''), 'is not a destination'],
    'a status that is not a redirect' => [fn () => new Redirect('/a', '/b', 200), 'is not a redirect status'],
    'a pattern that does not compile' => [fn () => new Redirect('/a/(', '/b', 301, true), 'is not a valid pattern'],
    'a rule that loops' => [fn () => new Redirect('/a', '/a'), 'redirects to itself'],
]);

it('goes to an array and back, and does not read a rule that is not valid', function (): void {
    $rule = new Redirect('/a/(.*)', '/b/$1', 308, true, 'seo');

    expect(Redirect::tryFromArray($rule->toArray()))->toEqual($rule)
        ->and(Redirect::tryFromArray(['from' => 'bad', 'to' => '/x']))->toBeNull()
        ->and(Redirect::tryFromArray(['from' => '/a']))->toBeNull()
        ->and(Redirect::tryFromArray([]))->toBeNull()
        ->and(Redirect::tryFromArray(['from' => '/a', 'to' => '/b'])?->status)->toBe(301);
});

it('prefers an exact rule to a pattern, and the first of equals', function (): void {
    $rules = new RedirectRules([
        new Redirect('/blog/(.*)', '/news/$1', 301, true),
        new Redirect('/blog/special', '/special', 302),
        new Redirect('/dup', '/first'),
        new Redirect('/dup', '/second'),
    ]);

    expect($rules->match('/blog/special')['to'])->toBe('/special')
        ->and($rules->match('/blog/other')['to'])->toBe('/news/other')
        ->and($rules->match('/dup')['to'])->toBe('/first')
        ->and($rules->match('/dup?utm=1')['to'])->toBe('/first')
        ->and($rules->match('/nothing'))->toBeNull()
        ->and($rules->all())->toHaveCount(4);
});

it('tells the rule that matched', function (): void {
    $match = (new RedirectRules([new Redirect('/a', '/b', 308, false, 'manual')]))->match('/a');

    expect($match['rule']->status)->toBe(308)->and($match['rule']->source)->toBe('manual');
});

it('does not send a path to itself with a pattern', function (): void {
    $rules = new RedirectRules([new Redirect('/loop/(.*)', '/loop/$1', 301, true), new Redirect('/loop/x', '/y')]);

    expect($rules->match('/loop/x')['to'])->toBe('/y')
        ->and($rules->match('/loop/z'))->toBeNull();
});
