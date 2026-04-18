<?php

use Illuminate\Support\Str;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/docs-check-'.Str::random(8);
    mkdir($this->base.'/docs/plans', 0777, true);
    mkdir($this->base.'/docs/roadmaps', 0777, true);
    mkdir($this->base.'/docs/reviews', 0777, true);
});

afterEach(function () {
    $rrmdir = function (string $path) use (&$rrmdir): void {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.'/'.$entry;
            is_dir($full) ? $rrmdir($full) : @unlink($full);
        }
        @rmdir($path);
    };
    $rrmdir($this->base);
});

function write_plan(string $base, string $filename, string $status, ?string $description = null): void
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $desc = $description ?? "Test plan {$name}";
    file_put_contents("{$base}/docs/plans/{$filename}", <<<MD
        ---
        name: {$name}
        description: "{$desc}"
        type: plan
        status: {$status}
        created: 2026-04-18
        updated: 2026-04-18
        ---

        # {$name}
        MD);
}

function write_index(string $base, string $indexBasename, array $sections): void
{
    $out = "# Index\n\n";
    foreach ($sections as $heading => $rows) {
        $out .= "## {$heading}\n\n";
        $out .= "| File | Status | Scope | Updated |\n";
        $out .= "|---|---|---|---|\n";
        foreach ($rows as [$path, $status]) {
            $out .= "| [X]({$path}) | {$status} | test | 2026-04-18 |\n";
        }
        $out .= "\n";
    }
    file_put_contents("{$base}/docs/{$indexBasename}", $out);
}

test('clean fixtures produce no findings', function () {
    write_plan($this->base, 'PLAN-A.md', 'shipped');
    write_plan($this->base, 'PLAN-B.md', 'active');
    write_index($this->base, 'PLANS.md', [
        'In flight' => [['plans/PLAN-B.md', 'active']],
        'Shipped' => [['plans/PLAN-A.md', 'shipped']],
    ]);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base, '--json' => true])
        ->assertSuccessful();
});

test('missing frontmatter is flagged as error', function () {
    file_put_contents($this->base.'/docs/plans/PLAN-NOFM.md', "# plan without frontmatter\n");
    write_index($this->base, 'PLANS.md', []);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertFailed()
        ->expectsOutputToContain('no YAML frontmatter');
});

test('status outside taxonomy is flagged as error', function () {
    $name = 'PLAN-BAD-STATUS';
    file_put_contents($this->base."/docs/plans/{$name}.md", <<<MD
        ---
        name: {$name}
        description: "bad status"
        type: plan
        status: wip
        created: 2026-04-18
        updated: 2026-04-18
        ---
        MD);
    write_index($this->base, 'PLANS.md', []);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertFailed()
        ->expectsOutputToContain('not in the taxonomy');
});

test('plan not present in PLANS.md is flagged', function () {
    write_plan($this->base, 'PLAN-ORPHAN.md', 'active');
    write_index($this->base, 'PLANS.md', []);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertFailed()
        ->expectsOutputToContain('not indexed');
});

test('index row pointing at nonexistent file is flagged', function () {
    write_index($this->base, 'PLANS.md', [
        'Shipped' => [['plans/PLAN-GHOST.md', 'shipped']],
    ]);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertFailed()
        ->expectsOutputToContain('does not exist');
});

test('status-vs-bucket mismatch is flagged', function () {
    write_plan($this->base, 'PLAN-WRONGBUCKET.md', 'active');
    // Place an `active` plan under the Shipped bucket: that is a bucket drift.
    write_index($this->base, 'PLANS.md', [
        'Shipped' => [['plans/PLAN-WRONGBUCKET.md', 'active']],
    ]);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertFailed()
        ->expectsOutputToContain('should live under');
});

test('status-vs-row-status mismatch is flagged', function () {
    write_plan($this->base, 'PLAN-DRIFT.md', 'shipped');
    write_index($this->base, 'PLANS.md', [
        'In flight' => [['plans/PLAN-DRIFT.md', 'active']],
    ]);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertFailed()
        ->expectsOutputToContain('disagrees');
});

test('human-readable output includes error summary', function () {
    write_plan($this->base, 'PLAN-ORPHAN.md', 'active');
    write_index($this->base, 'PLANS.md', []);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertFailed()
        ->expectsOutputToContain('error(s):');
});

test('malformed YAML is distinguished from missing frontmatter', function () {
    // Valid `---` delimiters but YAML body that is not a mapping.
    file_put_contents($this->base.'/docs/plans/PLAN-BADYAML.md', "---\nname: PLAN-BADYAML\ndescription: oops: this is not quoted\n---\n\n# body\n");
    write_index($this->base, 'PLANS.md', []);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertFailed()
        ->expectsOutputToContain('failed to parse');
});

test('warn-only output exits 0', function () {
    // Superseded plan with no supersededBy → warn only (one warn, no errors).
    $name = 'PLAN-SUPERSEDED';
    file_put_contents("{$this->base}/docs/plans/{$name}.md", <<<MD
        ---
        name: {$name}
        description: "test"
        type: plan
        status: superseded
        created: 2026-04-18
        updated: 2026-04-18
        ---
        MD);
    write_index($this->base, 'PLANS.md', [
        'Superseded / Abandoned' => [["plans/{$name}.md", 'superseded']],
    ]);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertSuccessful()
        ->expectsOutputToContain('warning(s):');
});

test('handoff with status other than active or shipped produces a warn', function () {
    $name = 'HANDOFF';
    file_put_contents("{$this->base}/docs/{$name}.md", <<<MD
        ---
        name: {$name}
        description: "test handoff"
        type: handoff
        status: draft
        created: 2026-04-18
        updated: 2026-04-18
        ---
        MD);
    write_index($this->base, 'PLANS.md', []);
    write_index($this->base, 'ROADMAP.md', []);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertSuccessful()
        ->expectsOutputToContain('unusual for type');
});

test('review with active status is accepted', function () {
    $name = 'REVIEW-X';
    file_put_contents("{$this->base}/docs/reviews/{$name}.md", <<<MD
        ---
        name: {$name}
        description: "cross-cutting audit in progress"
        type: review
        status: active
        created: 2026-04-18
        updated: 2026-04-18
        ---
        MD);
    write_index($this->base, 'PLANS.md', []);
    write_index($this->base, 'ROADMAP.md', []);

    // Reviews are directory-only, not indexed — no coverage error expected.
    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertSuccessful();
});

test('roadmap in draft status is accepted', function () {
    $name = 'ROADMAP-NEW';
    file_put_contents("{$this->base}/docs/roadmaps/{$name}.md", <<<MD
        ---
        name: {$name}
        description: "roadmap under revision"
        type: roadmap
        status: draft
        created: 2026-04-18
        updated: 2026-04-18
        ---
        MD);
    write_index($this->base, 'PLANS.md', []);
    write_index($this->base, 'ROADMAP.md', [
        'In flight' => [["roadmaps/{$name}.md", 'draft']],
    ]);

    $this->artisan('docs:check', ['--base' => $this->base])
        ->assertSuccessful();
});
