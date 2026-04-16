<?php

declare(strict_types=1);

use Tests\Browser\Support\BusinessSetup;

// Covers: public/embed.js popup script — overlay / iframe / per-button service (E2E-6).
//
// The popup is tested by injecting an embed script tag and a trigger button
// into an already-loaded page (the landing page works as a neutral host).
// This avoids the need to register a test-only route just to serve a fixture.

function injectPopupFixture($page, string $slug, string $buttonLabel = 'Book appointment', ?string $perButtonService = null): mixed
{
    $extraAttr = $perButtonService !== null
        ? "btn.setAttribute('data-riservo-service', '".$perButtonService."');"
        : '';

    $page->script(<<<JS
        (function () {
            var btn = document.createElement('button');
            btn.setAttribute('data-riservo-open', '');
            btn.setAttribute('type', 'button');
            btn.setAttribute('id', 'popup-trigger');
            {$extraAttr}
            btn.textContent = '{$buttonLabel}';
            btn.style.cssText = 'position:fixed;top:20px;left:20px;z-index:100;';
            document.body.appendChild(btn);

            var s = document.createElement('script');
            s.src = '/embed.js';
            s.setAttribute('data-slug', '{$slug}');
            document.body.appendChild(s);
        })();
    JS);

    return $page;
}

it('opens an iframe overlay when clicking a data-riservo-open trigger', function () {
    ['business' => $business] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/');

    injectPopupFixture($page, $business->slug);

    // Give the embed script a moment to attach its delegated click handler.
    $page->wait(0.5);

    $page->click('#popup-trigger');

    $page->wait(0.5);

    // The overlay container is created by embed.js with a `[data-riservo-overlay]` attribute.
    $page->assertPresent('[data-riservo-overlay]')
        ->assertPresent('[data-riservo-overlay] iframe');
});

it('loads the business booking page inside the popup iframe', function () {
    ['business' => $business] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/');
    injectPopupFixture($page, $business->slug);
    $page->wait(0.5)->click('#popup-trigger')->wait(0.5);

    $iframeSrc = $page->script(<<<'JS'
        (() => {
            var f = document.querySelector('[data-riservo-overlay] iframe');
            return f ? f.getAttribute('src') : null;
        })()
    JS);

    expect($iframeSrc)->toContain('/'.$business->slug)
        ->and($iframeSrc)->toContain('embed=1');
});

it('includes the service slug in the iframe URL when data-riservo-service is set per-button (D-070)', function () {
    ['business' => $business, 'service' => $service] = BusinessSetup::createLaunchedBusiness();

    $page = visit('/');
    injectPopupFixture($page, $business->slug, perButtonService: $service->slug);
    $page->wait(0.5)->click('#popup-trigger')->wait(0.5);

    $iframeSrc = $page->script(<<<'JS'
        (() => {
            var f = document.querySelector('[data-riservo-overlay] iframe');
            return f ? f.getAttribute('src') : null;
        })()
    JS);

    expect($iframeSrc)->toContain('/'.$business->slug.'/'.$service->slug)
        ->and($iframeSrc)->toContain('embed=1');
});
