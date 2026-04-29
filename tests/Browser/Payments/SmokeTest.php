<?php

declare(strict_types=1);

use Tests\Browser\Support\Payments\Pages\ConnectedAccountPage;

it('admin sees connected account page with active state when PaymentsWorld::default()->withActiveStripeAccount() is set up', function () {
    $world = $this->paymentsWorld()
        ->withActiveStripeAccount()
        ->build();

    expect($world['connectedAccount'])->not->toBeNull();

    ConnectedAccountPage::openAs($world['admin'])
        ->shouldShowActiveState($world['connectedAccount']);
});
