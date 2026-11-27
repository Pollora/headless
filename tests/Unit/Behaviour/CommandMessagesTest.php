<?php

declare(strict_types=1);

use Pollora\Headless\Application\Token\TokenService;
use Pollora\Headless\Domain\Token\TokenScope;
use Pollora\Headless\Infrastructure\Exceptions\ThemeInstallException;
use Pollora\Headless\Infrastructure\Status\Check;
use Pollora\Headless\Infrastructure\Status\CheckLevel;
use Pollora\Headless\Infrastructure\WordPress\StubTheme;
use Pollora\Headless\Infrastructure\WpCli\HeadlessCommand;
use Pollora\Headless\Infrastructure\WpCli\Output;
use Pollora\Headless\Infrastructure\WpCli\SchemaCommand;
use Pollora\Headless\Infrastructure\WpCli\TokenCommand;
use Pollora\Headless\Tests\Fixtures\InMemoryTokenStore;

/*
 * What the WP-CLI commands say.
 */

function saidOutput(): Output
{
    return new class implements Output
    {
        /** @var list<string> */
        public array $messages = [];

        public function line(string $message): void
        {
            $this->messages[] = 'line: '.$message;
        }

        public function success(string $message): void
        {
            $this->messages[] = 'success: '.$message;
        }

        public function warning(string $message): void
        {
            $this->messages[] = 'warning: '.$message;
        }

        public function error(string $message): void
        {
            $this->messages[] = 'error: '.$message;
        }
    };
}

function saidTokens(Output $output, InMemoryTokenStore $store): TokenCommand
{
    return new TokenCommand(new TokenService($store, static fn (): int => 1_000_000), static fn (string $who): ?int => $who === 'editor' ? 7 : null, static fn (): int => 1_000_000, $output);
}

it('announces a new token, then its secret', function (): void {
    $output = saidOutput();
    $store = new InMemoryTokenStore;

    saidTokens($output, $store)->create(['editor']);

    $token = array_values($store->tokens)[0];

    expect($output->messages[0])->toBe('success: Token '.$token->id.' created. Copy the secret now: it cannot be shown again.')
        ->and($output->messages[1])->toStartWith('line: hl.7.')
        ->and($token->scopes)->toBe([TokenScope::Read])
        ->and($token->expiresAt)->toBeNull()
        ->and($token->routes)->toBe([]);
});

it('says which token a rotation replaces', function (): void {
    $output = saidOutput();
    $store = new InMemoryTokenStore;
    saidTokens(saidOutput(), $store)->create(['editor']);
    $old = array_values($store->tokens)[0]->id;

    saidTokens($output, $store)->rotate(['editor', $old]);

    $new = array_values($store->tokens)[1]->id;

    expect($output->messages[0])->toBe('success: Token '.$new.' replaces '.$old.', which works for the grace period. Copy the secret now: it cannot be shown again.');
});

it('reads a list of routes with blanks and empty entries as a list of routes', function (): void {
    $store = new InMemoryTokenStore;

    saidTokens(saidOutput(), $store)->create(['editor'], ['routes' => ' ,/wp/v2/posts , /acme ', 'scopes' => ',read,']);

    $token = array_values($store->tokens)[0];

    expect($token->routes)->toBe(['/wp/v2/posts', '/acme'])->and($token->scopes)->toBe([TokenScope::Read]);
});

it('takes a name given as a number', function (): void {
    $store = new InMemoryTokenStore;

    saidTokens(saidOutput(), $store)->create(['editor'], ['name' => 2026]);

    expect(array_values($store->tokens)[0]->name)->toBe('2026');
});

it('says when a listed token expires', function (): void {
    $output = saidOutput();
    $store = new InMemoryTokenStore;
    saidTokens(saidOutput(), $store)->create(['editor'], ['expires' => '1d', 'name' => 'n']);
    $id = array_values($store->tokens)[0]->id;

    saidTokens($output, $store)->list_(['editor']);

    expect($output->messages)->toBe(['line: '.$id.'  n  [read]  all routes  expires '.gmdate('Y-m-d H:i', 1_086_400).' UTC']);
});

it('says it has no user to a command given none', function (string $command): void {
    $output = saidOutput();

    saidTokens($output, new InMemoryTokenStore)->{$command}();

    expect($output->messages)->toBe(['error: No user "".']);
})->with(['create', 'list_', 'revoke', 'rotate']);

it('says which token it did not find when given none', function (string $command): void {
    $output = saidOutput();

    saidTokens($output, new InMemoryTokenStore)->{$command}(['editor']);

    expect($output->messages)->toBe(['error: No token "" for this user.']);
})->with(['revoke', 'rotate']);

function saidDoctor(Output $output, array $checks): HeadlessCommand
{
    return new HeadlessCommand(static fn () => null, static fn () => null, static fn () => null, $output, static fn (): array => $checks);
}

it('prints what to do after a check that is not ok, and only then', function (): void {
    $output = saidOutput();

    saidDoctor($output, [new Check('a', CheckLevel::Ok, 'fine', 'nothing'), new Check('b', CheckLevel::Warning, 'hm', 'do it'), new Check('c', CheckLevel::Warning, 'bare')])->doctor();

    expect($output->messages)->toBe(['line: [ok] fine', 'warning: [warning] hm → do it', 'warning: [warning] bare', 'success: No failing check.']);
});

it('prints the checks as readable JSON when asked', function (): void {
    $output = saidOutput();

    saidDoctor($output, [new Check('a', CheckLevel::Ok, 'see /wp-json/')])->doctor([], ['format' => 'json']);

    expect($output->messages[0])->toBe("line: [\n    {\n        \"id\": \"a\",\n        \"level\": \"ok\",\n        \"message\": \"see /wp-json/\",\n        \"fix\": \"\"\n    }\n]");
});

it('explains the theme command to who gives no action', function (): void {
    $output = saidOutput();

    saidDoctor($output, [])->theme();

    expect($output->messages)->toBe(['error: Usage: wp headless theme install [--activate]']);
});

it('writes an OpenAPI document of the namespaces asked for, readable as it is', function (): void {
    $output = saidOutput();
    $written = [];
    $command = new SchemaCommand(
        static fn (): array => ['/a/v1' => [['methods' => 'GET']], '/b/v1' => [['methods' => 'GET']], '/c/v1' => [['methods' => 'GET']]],
        static fn (): array => ['title' => 'Été', 'version' => '1', 'server' => 'https://x.test/wp-json'],
        static fn () => null,
        $output,
        static function (string $file, string $content) use (&$written): bool {
            $written = [$file, $content];

            return true;
        },
    );

    $command->openapi([], ['namespace' => ' , a/v1 , b/v1', 'file' => 'api.json']);

    expect($written[1])->toContain("\n    \"openapi\": \"3.0.3\"", '"title": "Été"', '"url": "https://x.test/wp-json"')
        ->and($written[1])->toEndWith("}\n")
        ->and(array_keys(json_decode($written[1], true)['paths']))->toBe(['/a/v1', '/b/v1'])
        ->and($output->messages)->toBe(['success: 2 paths written to api.json.']);
});

it('prints the document when the file name is empty', function (): void {
    $output = saidOutput();
    $command = new SchemaCommand(static fn (): array => [], static fn (): array => ['title' => 't', 'version' => '1', 'server' => 's'], static fn () => 'type Query', $output, static fn (): bool => false);

    $command->graphql([], ['file' => '']);

    expect($output->messages)->toBe(['line: type Query']);
});

it('names the folder or the file of the theme it could not write', function (string $case): void {
    $root = sys_get_temp_dir().'/headless-said-'.bin2hex(random_bytes(6));
    $source = $root.'-source';
    mkdir($root);
    mkdir($source);
    file_put_contents($source.'/style.css', 'css');
    $expected = '"'.$root.'/headless/index.php"';

    if ($case === 'folder') {
        file_put_contents($root.'/headless', 'a file in the way');
        $expected = '"'.$root.'/headless"';
    }

    // mkdir() and copy() warn about what they cannot do: the exception is what is read here
    set_error_handler(static fn (): bool => true);

    try {
        (new StubTheme($root.'/', $source))->install();
    } catch (ThemeInstallException $exception) {
        expect($exception->getMessage())->toContain($expected);

        return;
    } finally {
        restore_error_handler();
    }

    throw new RuntimeException('Expected an exception');
})->with(['folder', 'file']);

it('refuses a themes directory that does not exist', function (): void {
    (new StubTheme(sys_get_temp_dir().'/headless-missing-'.bin2hex(random_bytes(6)), '/nowhere'))->install();
})->throws(ThemeInstallException::class, 'Cannot write');
