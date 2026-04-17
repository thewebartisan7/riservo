<?php

/*
 * REVIEW-1 issue #8 lock — pure-PHP regex guards over the calendar view
 * files. Guarantees future edits cannot re-introduce the nested-<li>
 * hydration bug (closed by D-069) or re-hide the mobile view-switcher /
 * mobile week-view agenda fallback.
 *
 * Three claims from REVIEW-1 #8, each asserted against the current
 * resources/js source. No Node.js, no AST — a regex job.
 */

// Unit tests do not bootstrap the Laravel container, so base_path() is
// unavailable. Resolve the repo root from this file's own location.
$repoRoot = dirname(__DIR__, 3);
$calendarDir = $repoRoot.'/resources/js/components/calendar';

$viewFiles = [
    'week-view' => $calendarDir.'/week-view.tsx',
    'day-view' => $calendarDir.'/day-view.tsx',
    'month-view' => $calendarDir.'/month-view.tsx',
];

$headerFile = $calendarDir.'/calendar-header.tsx';

beforeAll(function () use ($viewFiles, $headerFile) {
    foreach ($viewFiles as $name => $path) {
        expect(file_exists($path))->toBeTrue("{$name}.tsx must exist at {$path}");
    }
    expect(file_exists($headerFile))->toBeTrue('calendar-header.tsx must exist');
});

// --- Claim 1 --------------------------------------------------------------
// No view file textually wraps a <li> or <CurrentTimeIndicator/> inside
// another <li>. D-069 noted the bug was closed; this test locks it.

it('does not wrap <CurrentTimeIndicator> inside a <li>', function (string $path) {
    $source = file_get_contents($path);

    // Strip line/block comments so a commented-out example can't trip the guard.
    $stripped = preg_replace('#/\*.*?\*/#s', '', $source);
    $stripped = preg_replace('#//[^\n]*#', '', $stripped);

    // Find every <CurrentTimeIndicator occurrence; walk backward to find the
    // nearest unmatched opening element. If that element is <li, the wrap is
    // present — fail.
    $offset = 0;
    while (($pos = strpos($stripped, '<CurrentTimeIndicator', $offset)) !== false) {
        $before = substr($stripped, 0, $pos);
        $parent = findNearestUnclosedTag($before);
        expect($parent)->not->toBe(
            'li',
            'CurrentTimeIndicator must not be rendered as a direct child of <li> — this re-introduces the REVIEW-1 #8 hydration bug.',
        );
        $offset = $pos + 1;
    }
})->with([
    'week-view' => dirname(__DIR__, 3).'/resources/js/components/calendar/week-view.tsx',
    'day-view' => dirname(__DIR__, 3).'/resources/js/components/calendar/day-view.tsx',
]);

it('does not place a literal <li> inside another <li> in any calendar view', function (string $path) {
    $source = file_get_contents($path);
    $stripped = preg_replace('#/\*.*?\*/#s', '', $source);
    $stripped = preg_replace('#//[^\n]*#', '', $stripped);

    // Permit <li> descendants of <ul>/<ol>, but forbid direct <li>...<li> with
    // no intervening <ul>/<ol> opening. Walk every <li occurrence; assert the
    // nearest unclosed parent is ul or ol.
    $offset = 0;
    while (($pos = strpos($stripped, '<li', $offset)) !== false) {
        // Skip </li>.
        if (substr($stripped, $pos, 4) === '</li') {
            $offset = $pos + 1;

            continue;
        }
        $before = substr($stripped, 0, $pos);
        $parent = findNearestUnclosedTag($before);
        // Allow ul, ol, Fragment, and TSX expression braces ({}). TSX/JSX maps
        // through Fragments and expressions, so anything other than a literal
        // <li parent is fine — the only failure mode is <li directly inside
        // another <li.
        expect($parent)->not->toBe(
            'li',
            '<li> must not be a direct descendant of <li> — invalid HTML, causes a hydration warning.',
        );
        $offset = $pos + 1;
    }
})->with([
    'week-view' => dirname(__DIR__, 3).'/resources/js/components/calendar/week-view.tsx',
    'day-view' => dirname(__DIR__, 3).'/resources/js/components/calendar/day-view.tsx',
    'month-view' => dirname(__DIR__, 3).'/resources/js/components/calendar/month-view.tsx',
]);

// --- Claim 2 --------------------------------------------------------------
// Mobile week view must keep its agenda-list fallback below `sm` (D-069).
// The guard is structural: week-view.tsx must contain an <ol> with class
// `sm:hidden` wrapping booking content.

it('week-view keeps the mobile agenda-list fallback below sm (D-069)', function () use ($viewFiles) {
    $source = file_get_contents($viewFiles['week-view']);

    // D-069: mobile week view must render an agenda list; the time grid
    // must be desktop-gated. Removing sm:hidden from the agenda list, or
    // dropping the `hidden sm:flex` / `hidden sm:grid` gate from the time
    // grid, re-hides every booking on phones (REVIEW-1 #8).
    expect($source)->toContain('sm:hidden');
    expect($source)->toContain('hidden sm:');
});

// --- Claim 3 --------------------------------------------------------------
// The view switcher must be reachable below `md` (D-069 dropped the
// `hidden md:block` wrapper). Guard: calendar-header.tsx must NOT contain
// the exact hidden-md-Select wrapping pattern that REVIEW-1 flagged.

it('calendar-header keeps the view switcher visible on mobile (D-069)', function () use ($headerFile) {
    $source = file_get_contents($headerFile);

    // D-069: the view <Select> must stay visible below md. The REVIEW-1 #8
    // regression was a <div className="hidden md:block"><Select …/></div>
    // wrapper — guard against it literally.
    expect($source)->not->toMatch('/className="hidden md:block"\s*>\s*<Select/');
    expect($source)->toContain('<Select value={view}');
});

/**
 * Return the tag name of the nearest opening element in $before that does
 * not have a matching closing tag yet, or null if there is no such element.
 *
 * Tolerates JSX self-closing tags (<Component ... />) and ignores expressions
 * in braces.
 */
function findNearestUnclosedTag(string $before): ?string
{
    $len = strlen($before);
    $stack = [];
    $i = 0;
    while ($i < $len) {
        $lt = strpos($before, '<', $i);
        if ($lt === false) {
            break;
        }

        // Skip JSX comment {/* */}, attribute braces, and < inside a string.
        if ($lt + 1 >= $len) {
            break;
        }
        $next = $before[$lt + 1];

        if ($next === '!' || $next === '?') {
            // Skip <!-- --> or <!DOCTYPE>
            $gt = strpos($before, '>', $lt);
            $i = $gt === false ? $len : $gt + 1;

            continue;
        }

        if ($next === '/') {
            // Closing tag.
            $gt = strpos($before, '>', $lt);
            if ($gt === false) {
                break;
            }
            $name = strtolower(preg_replace('/\s.*$/', '', substr($before, $lt + 2, $gt - $lt - 2)));
            // Pop matching tag from stack if present.
            for ($j = count($stack) - 1; $j >= 0; $j--) {
                if ($stack[$j] === $name) {
                    array_splice($stack, $j, 1);
                    break;
                }
            }
            $i = $gt + 1;

            continue;
        }

        // Opening tag — or JSX expression.
        $gt = strpos($before, '>', $lt);
        if ($gt === false) {
            break;
        }
        $tagBody = substr($before, $lt + 1, $gt - $lt - 1);
        // Self-closing.
        $selfClosing = str_ends_with(rtrim($tagBody), '/');
        // Extract tag name (first whitespace-delimited token, lowercase).
        $name = strtolower(preg_replace('/\s.*$/', '', trim($tagBody)));
        // Heuristic: only track HTML-ish names we care about (li, ul, ol, div).
        // JSX capitalized components (<Fragment>, <CalendarEvent>) are not
        // tracked — the only failure we want to detect is `<li ... > ... <li`
        // or `<li ... > ... <CurrentTimeIndicator`.
        if (in_array($name, ['li', 'ul', 'ol'], true) && ! $selfClosing) {
            $stack[] = $name;
        }
        $i = $gt + 1;
    }

    return end($stack) ?: null;
}
