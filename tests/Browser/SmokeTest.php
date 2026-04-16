<?php

declare(strict_types=1);

it('loads the landing page without JS errors', function () {
    $page = visit('/');

    $page->assertSee('riservo')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
