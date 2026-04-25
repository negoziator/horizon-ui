<?php

use Illuminate\Support\Facades\File;
use Negoziator\HorizonUi\Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->cssPath = resource_path('css/app.css');
    $this->publishedVueDir = resource_path('js/vendor/horizon-ui');
    File::ensureDirectoryExists(dirname($this->cssPath));
});

afterEach(function (): void {
    if (is_file($this->cssPath)) {
        unlink($this->cssPath);
    }
    if (is_dir($this->publishedVueDir)) {
        File::deleteDirectory($this->publishedVueDir);
    }
});

it('injects the @source directive after @import tailwindcss', function (): void {
    file_put_contents(
        $this->cssPath,
        "@import \"tailwindcss\";\n\nbody { margin: 0; }\n",
    );

    $this->artisan('horizon-ui:install')
        ->expectsConfirmation(
            'Add Tailwind @source directive to resources/css/app.css so Horizon UI components are styled?',
            'yes',
        )
        ->assertSuccessful();

    $contents = file_get_contents($this->cssPath);
    expect($contents)->toContain("@source '../js/vendor/horizon-ui/**/*.vue';");
    expect($contents)->toContain('@import "tailwindcss";');
    // Directive must appear after the @import line, not before.
    expect(strpos($contents, '@source'))->toBeGreaterThan(strpos($contents, '@import'));
});

it('skips injection when the user declines the confirmation', function (): void {
    file_put_contents(
        $this->cssPath,
        "@import \"tailwindcss\";\n",
    );

    $this->artisan('horizon-ui:install')
        ->expectsConfirmation(
            'Add Tailwind @source directive to resources/css/app.css so Horizon UI components are styled?',
            'no',
        )
        ->assertSuccessful();

    $contents = file_get_contents($this->cssPath);
    expect($contents)->not->toContain('@source');
});

it('does not duplicate the @source directive on re-run', function (): void {
    $initial = "@import \"tailwindcss\";\n@source '../js/vendor/horizon-ui/**/*.vue';\n";
    file_put_contents($this->cssPath, $initial);

    $this->artisan('horizon-ui:install --no-interaction')->assertSuccessful();

    $contents = file_get_contents($this->cssPath);
    expect(substr_count($contents, 'js/vendor/horizon-ui'))->toBe(1);
});

it('recognises an existing directive written with double quotes', function (): void {
    $initial = "@import \"tailwindcss\";\n@source \"../js/vendor/horizon-ui/**/*.vue\";\n";
    file_put_contents($this->cssPath, $initial);

    $this->artisan('horizon-ui:install --no-interaction')->assertSuccessful();

    $contents = file_get_contents($this->cssPath);
    expect(substr_count($contents, 'js/vendor/horizon-ui'))->toBe(1);
});

it('shows an error when resources/css/app.css is missing', function (): void {
    // Make sure no file is present.
    if (is_file($this->cssPath)) {
        unlink($this->cssPath);
    }

    $this->artisan('horizon-ui:install --no-interaction')
        ->expectsOutputToContain('Could not find resources/css/app.css')
        ->assertSuccessful();
});

it('warns and skips injection when app.css has no @import tailwindcss', function (): void {
    file_put_contents($this->cssPath, "body { color: red; }\n");

    $this->artisan('horizon-ui:install --no-interaction')
        ->expectsOutputToContain('no @import "tailwindcss"')
        ->assertSuccessful();

    $contents = file_get_contents($this->cssPath);
    expect($contents)->not->toContain('@source');
});
