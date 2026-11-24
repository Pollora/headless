<?php

declare(strict_types=1);

use Pollora\Headless\Domain\Front\AllowList;
use Pollora\Headless\Domain\Front\FrontGatePolicy;
use Pollora\Headless\Domain\Front\FrontMode;
use Pollora\Headless\Domain\Front\Origin;
use Pollora\Headless\Domain\Front\RedirectStatus;
use Pollora\Headless\Domain\Front\RequestTarget;

function gate(FrontMode $mode, ?string $front = 'https://www.example.com', RedirectStatus $status = RedirectStatus::Found, bool $query = true): FrontGatePolicy
{
    return new FrontGatePolicy($mode, AllowList::defaults(), Origin::fromUrl('https://cms.example.com'), $front === null ? null : Origin::fromUrl($front), $status, $query, 410);
}

it('redirects a public page to the same path on the front end', function (): void {
    $decision = gate(FrontMode::Redirect)->decide(RequestTarget::fromUri('/blog/hello?x=1'));

    expect($decision->action)->toBe('redirect')
        ->and($decision->location)->toBe('https://www.example.com/blog/hello?x=1')
        ->and($decision->status)->toBe(302);
});

it('can drop the query string and use another status', function (): void {
    $decision = gate(FrontMode::Redirect, status: RedirectStatus::MovedPermanently, query: false)->decide(RequestTarget::fromUri('/a?b=c'));

    expect($decision->location)->toBe('https://www.example.com/a')->and($decision->status)->toBe(301);
});

it('never touches the allow-listed paths', function (string $uri): void {
    expect(gate(FrontMode::Redirect)->decide(RequestTarget::fromUri($uri))->isAllowed())->toBeTrue();
})->with(['/wp-admin/post.php', '/wp-login.php', '/wp-json/wp/v2/posts?per_page=1', '/graphql', '/wp-cron.php', '/wp-content/uploads/2026/11/a.jpg', '/robots.txt']);

it('matches an allow-list entry on a segment boundary only', function (): void {
    expect(gate(FrontMode::Redirect)->decide(RequestTarget::fromUri('/wp-jsonx'))->isAllowed())->toBeFalse();
});

it('does nothing when the mode is off, and says it is inactive', function (): void {
    expect(gate(FrontMode::Off)->decide(RequestTarget::fromUri('/blog'))->isAllowed())->toBeTrue()
        ->and(gate(FrontMode::Off)->isActive())->toBeFalse()
        ->and(gate(FrontMode::Redirect)->isActive())->toBeTrue();
});

it('does not redirect without a front-end URL, nor to the host of WordPress itself (a loop)', function (?string $front): void {
    expect(gate(FrontMode::Redirect, $front)->decide(RequestTarget::fromUri('/blog'))->isAllowed())->toBeTrue();
})->with([[null], ['https://cms.example.com'], ['https://cms.example.com:443']]);

it('answers with a holding page or JSON and the configured status', function (): void {
    expect(gate(FrontMode::Page)->decide(RequestTarget::fromUri('/'))->action)->toBe('page')
        ->and(gate(FrontMode::Page)->decide(RequestTarget::fromUri('/'))->status)->toBe(410)
        ->and(gate(FrontMode::Json)->decide(RequestTarget::fromUri('/'))->action)->toBe('json');
});

it('extends the allow-list with extra paths', function (): void {
    $list = AllowList::defaults()->with('/wc-api/', 'webhooks');

    expect($list->allows(new RequestTarget('/wc-api/paypal')))->toBeTrue()
        ->and($list->allows(new RequestTarget('/webhooks')))->toBeTrue()
        ->and($list->allows(new RequestTarget('/blog')))->toBeFalse();
});

it('builds a request target from a URI and a method', function (): void {
    $target = RequestTarget::fromUri('blog/hello?x=1', 'post');

    expect($target->path)->toBe('/blog/hello')->and($target->query)->toBe('x=1')->and($target->method)->toBe('POST')
        ->and($target->pathAndQuery(false))->toBe('/blog/hello');
});
