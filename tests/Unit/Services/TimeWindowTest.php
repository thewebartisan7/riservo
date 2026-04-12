<?php

use App\DTOs\TimeWindow;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Helper to create TimeWindows quickly: tw('09:00', '12:00') on a fixed date
    $this->date = CarbonImmutable::parse('2026-04-14', 'Europe/Zurich');
    $this->tw = fn (string $start, string $end) => new TimeWindow(
        $this->date->setTimeFromTimeString($start),
        $this->date->setTimeFromTimeString($end),
    );
});

test('duration returns correct minutes', function () {
    $window = ($this->tw)('09:00', '12:00');

    expect($window->durationInMinutes())->toBe(180);
});

test('overlaps detects overlapping windows', function () {
    $a = ($this->tw)('09:00', '12:00');
    $b = ($this->tw)('11:00', '14:00');

    expect($a->overlaps($b))->toBeTrue();
    expect($b->overlaps($a))->toBeTrue();
});

test('overlaps returns false for adjacent windows', function () {
    $a = ($this->tw)('09:00', '12:00');
    $b = ($this->tw)('12:00', '14:00');

    expect($a->overlaps($b))->toBeFalse();
});

test('overlaps returns false for non-overlapping windows', function () {
    $a = ($this->tw)('09:00', '11:00');
    $b = ($this->tw)('13:00', '15:00');

    expect($a->overlaps($b))->toBeFalse();
});

test('contains checks if a point is within the window', function () {
    $window = ($this->tw)('09:00', '12:00');

    expect($window->contains($this->date->setTimeFromTimeString('10:00')))->toBeTrue();
    expect($window->contains($this->date->setTimeFromTimeString('09:00')))->toBeTrue();
    expect($window->contains($this->date->setTimeFromTimeString('12:00')))->toBeFalse();
    expect($window->contains($this->date->setTimeFromTimeString('08:00')))->toBeFalse();
});

test('intersect returns overlapping portions of two window sets', function () {
    $a = [($this->tw)('09:00', '13:00'), ($this->tw)('14:00', '18:00')];
    $b = [($this->tw)('10:00', '15:00')];

    $result = TimeWindow::intersect($a, $b);

    expect($result)->toHaveCount(2);
    expect($result[0]->start->format('H:i'))->toBe('10:00');
    expect($result[0]->end->format('H:i'))->toBe('13:00');
    expect($result[1]->start->format('H:i'))->toBe('14:00');
    expect($result[1]->end->format('H:i'))->toBe('15:00');
});

test('intersect with no overlap returns empty', function () {
    $a = [($this->tw)('09:00', '12:00')];
    $b = [($this->tw)('13:00', '15:00')];

    expect(TimeWindow::intersect($a, $b))->toBeEmpty();
});

test('intersect with empty set returns empty', function () {
    $a = [($this->tw)('09:00', '12:00')];

    expect(TimeWindow::intersect($a, []))->toBeEmpty();
    expect(TimeWindow::intersect([], $a))->toBeEmpty();
});

test('subtract removes blocked ranges from windows', function () {
    $windows = [($this->tw)('09:00', '18:00')];
    $blocks = [($this->tw)('12:00', '13:00')];

    $result = TimeWindow::subtract($windows, $blocks);

    expect($result)->toHaveCount(2);
    expect($result[0]->start->format('H:i'))->toBe('09:00');
    expect($result[0]->end->format('H:i'))->toBe('12:00');
    expect($result[1]->start->format('H:i'))->toBe('13:00');
    expect($result[1]->end->format('H:i'))->toBe('18:00');
});

test('subtract with block covering entire window returns empty', function () {
    $windows = [($this->tw)('09:00', '12:00')];
    $blocks = [($this->tw)('08:00', '13:00')];

    expect(TimeWindow::subtract($windows, $blocks))->toBeEmpty();
});

test('subtract with multiple blocks', function () {
    $windows = [($this->tw)('09:00', '18:00')];
    $blocks = [($this->tw)('10:00', '11:00'), ($this->tw)('14:00', '15:00')];

    $result = TimeWindow::subtract($windows, $blocks);

    expect($result)->toHaveCount(3);
    expect($result[0]->start->format('H:i'))->toBe('09:00');
    expect($result[0]->end->format('H:i'))->toBe('10:00');
    expect($result[1]->start->format('H:i'))->toBe('11:00');
    expect($result[1]->end->format('H:i'))->toBe('14:00');
    expect($result[2]->start->format('H:i'))->toBe('15:00');
    expect($result[2]->end->format('H:i'))->toBe('18:00');
});

test('merge combines overlapping and adjacent windows', function () {
    $windows = [
        ($this->tw)('09:00', '12:00'),
        ($this->tw)('11:00', '14:00'),
        ($this->tw)('14:00', '16:00'),
    ];

    $result = TimeWindow::merge($windows);

    expect($result)->toHaveCount(1);
    expect($result[0]->start->format('H:i'))->toBe('09:00');
    expect($result[0]->end->format('H:i'))->toBe('16:00');
});

test('merge preserves non-overlapping windows', function () {
    $windows = [
        ($this->tw)('09:00', '11:00'),
        ($this->tw)('13:00', '15:00'),
    ];

    $result = TimeWindow::merge($windows);

    expect($result)->toHaveCount(2);
    expect($result[0]->start->format('H:i'))->toBe('09:00');
    expect($result[1]->start->format('H:i'))->toBe('13:00');
});

test('union adds new windows and merges', function () {
    $windows = [($this->tw)('09:00', '12:00')];
    $additions = [($this->tw)('11:00', '14:00'), ($this->tw)('16:00', '18:00')];

    $result = TimeWindow::union($windows, $additions);

    expect($result)->toHaveCount(2);
    expect($result[0]->start->format('H:i'))->toBe('09:00');
    expect($result[0]->end->format('H:i'))->toBe('14:00');
    expect($result[1]->start->format('H:i'))->toBe('16:00');
    expect($result[1]->end->format('H:i'))->toBe('18:00');
});
