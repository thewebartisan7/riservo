<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

#[Signature('docs:check {--json : Emit findings as JSON instead of human-readable text} {--base= : Override the project root (used by tests)}')]
#[Description('Verify the frontmatter-vs-index contract across docs/roadmaps, docs/plans, docs/reviews, and HANDOFF.md.')]
class DocsCheckCommand extends Command
{
    /** @var array<int, array{severity: string, file: string, message: string}> */
    private array $findings = [];

    private const array STATUSES = ['draft', 'planning', 'active', 'review', 'shipped', 'superseded', 'abandoned'];

    private const array REQUIRED_FIELDS = ['name', 'description', 'type', 'status', 'created', 'updated'];

    private const array TYPE_DIR = [
        'plan' => 'docs/plans',
        'roadmap' => 'docs/roadmaps',
        'review' => 'docs/reviews',
        'handoff' => 'docs',
    ];

    private const array BUCKETS = [
        'In flight' => ['draft', 'planning', 'active', 'review'],
        'Shipped' => ['shipped'],
        'Superseded / Abandoned' => ['superseded', 'abandoned'],
    ];

    private string $base = '';

    public function handle(): int
    {
        $this->base = rtrim($this->option('base') ?: base_path(), '/');
        $base = $this->base;

        $processFiles = $this->collectProcessFiles($base);
        $indexedPlans = $this->parseIndex($base.'/docs/PLANS.md');
        $indexedRoadmaps = $this->parseIndex($base.'/docs/ROADMAP.md');

        foreach ($processFiles as $relPath => $result) {
            $this->checkFrontmatter($relPath, $result);
        }

        // Only files with a successfully-parsed frontmatter participate in index / bucket checks.
        $parsed = array_map(fn ($r) => $r['data'], array_filter($processFiles, fn ($r) => $r['state'] === 'ok'));

        $this->checkIndexCoverage($parsed, $indexedPlans, 'plan', 'docs/PLANS.md');
        $this->checkIndexCoverage($parsed, $indexedRoadmaps, 'roadmap', 'docs/ROADMAP.md');

        $this->checkIndexBackref($base, $indexedPlans, 'docs/PLANS.md');
        $this->checkIndexBackref($base, $indexedRoadmaps, 'docs/ROADMAP.md');

        $this->checkBucketPolicy($parsed, $indexedPlans, 'plan', 'docs/PLANS.md');
        $this->checkBucketPolicy($parsed, $indexedRoadmaps, 'roadmap', 'docs/ROADMAP.md');

        return $this->emitFindings();
    }

    /**
     * @return array<string, array{state: 'ok'|'missing'|'parse_error', data: array<string, mixed>, error?: string}> indexed by repo-relative path
     */
    private function collectProcessFiles(string $base): array
    {
        $files = [];

        $paths = [
            ...glob("{$base}/docs/plans/*.md"),
            ...glob("{$base}/docs/roadmaps/*.md"),
            ...glob("{$base}/docs/reviews/*.md"),
            "{$base}/docs/HANDOFF.md",
        ];

        foreach ($paths as $absPath) {
            if (! is_file($absPath)) {
                continue;
            }

            $basename = basename($absPath);

            if (in_array($basename, ['CLAUDE.md', 'AGENTS.md', 'README.md'], true)) {
                continue;
            }

            $relPath = ltrim(str_replace($base, '', $absPath), '/');
            $files[$relPath] = $this->extractFrontmatter($absPath);
        }

        return $files;
    }

    /**
     * @return array{state: 'ok'|'missing'|'parse_error', data: array<string, mixed>, error?: string}
     */
    private function extractFrontmatter(string $absPath): array
    {
        $contents = file_get_contents($absPath);

        if ($contents === false || ! preg_match('/\A---\r?\n(.*?)\r?\n---(?:\r?\n|$)/s', $contents, $matches)) {
            return ['state' => 'missing', 'data' => []];
        }

        try {
            $parsed = Yaml::parse($matches[1]);
        } catch (ParseException $e) {
            return ['state' => 'parse_error', 'data' => [], 'error' => $e->getMessage()];
        }

        if (! is_array($parsed)) {
            return ['state' => 'parse_error', 'data' => [], 'error' => 'frontmatter did not parse to a mapping'];
        }

        return ['state' => 'ok', 'data' => $parsed];
    }

    /**
     * @param  array{state: 'ok'|'missing'|'parse_error', data: array<string, mixed>, error?: string}  $result
     */
    private function checkFrontmatter(string $relPath, array $result): void
    {
        if ($result['state'] === 'missing') {
            $this->addFinding('error', $relPath, 'no YAML frontmatter found (every process doc must start with a `---` block)');

            return;
        }

        if ($result['state'] === 'parse_error') {
            $this->addFinding('error', $relPath, 'YAML frontmatter is present but failed to parse: '.($result['error'] ?? 'unknown error').' (check that values containing `:`, `#`, or other YAML-reserved characters are quoted)');

            return;
        }

        $fm = $result['data'];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $fm) || $fm[$field] === '' || $fm[$field] === null) {
                $this->addFinding('error', $relPath, "frontmatter is missing required field `{$field}`");
            }
        }

        if (isset($fm['status']) && ! in_array($fm['status'], self::STATUSES, true)) {
            $this->addFinding('error', $relPath, "status `{$fm['status']}` is not in the taxonomy (allowed: ".implode('|', self::STATUSES).')');
        }

        if (isset($fm['type']) && ! array_key_exists($fm['type'], self::TYPE_DIR)) {
            $this->addFinding('error', $relPath, "type `{$fm['type']}` is not recognised (allowed: ".implode('|', array_keys(self::TYPE_DIR)).')');

            return;
        }

        if (isset($fm['type'], $fm['status']) && ! $this->isStatusValidForType($fm['type'], $fm['status'])) {
            $this->addFinding('warn', $relPath, "status `{$fm['status']}` is unusual for type `{$fm['type']}` — verify this is intentional");
        }

        if (isset($fm['type']) && ! str_starts_with($relPath, self::TYPE_DIR[$fm['type']].'/') && ! ($fm['type'] === 'handoff' && $relPath === 'docs/HANDOFF.md')) {
            $this->addFinding('error', $relPath, "type `{$fm['type']}` expects the file to live under `".self::TYPE_DIR[$fm['type']].'/`');
        }

        if (($fm['status'] ?? null) === 'superseded' && empty($fm['supersededBy'])) {
            $this->addFinding('warn', $relPath, 'status is `superseded` but `supersededBy:` is not set');
        }

        if (isset($fm['name']) && $fm['name'] !== pathinfo($relPath, PATHINFO_FILENAME)) {
            $this->addFinding('warn', $relPath, "frontmatter `name: {$fm['name']}` does not match filename `".pathinfo($relPath, PATHINFO_FILENAME).'`');
        }
    }

    private function isStatusValidForType(string $type, string $status): bool
    {
        return match ($type) {
            'handoff' => in_array($status, ['active', 'shipped'], true),
            'review' => in_array($status, ['planning', 'active', 'shipped'], true),
            'roadmap' => in_array($status, ['draft', 'planning', 'active', 'shipped', 'superseded'], true),
            'plan' => in_array($status, self::STATUSES, true),
            default => false,
        };
    }

    /**
     * @return array<string, array{bucket: string, status: string, path: string}> keyed by the repo-relative path referenced by the index row
     */
    private function parseIndex(string $indexPath): array
    {
        if (! is_file($indexPath)) {
            $this->addFinding('error', ltrim(str_replace($this->base, '', $indexPath), '/'), 'index file is missing');

            return [];
        }

        $contents = file_get_contents($indexPath);
        $rows = [];
        $currentBucket = null;

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (preg_match('/^## +(.+?)\s*$/', $line, $m)) {
                $heading = trim($m[1]);
                $currentBucket = $this->normaliseBucket($heading);

                continue;
            }

            if ($currentBucket === null) {
                continue;
            }

            // Row format: | [Title](path/file.md) | status | ... | date |
            if (preg_match('/^\|\s*\[[^\]]+\]\(([^)]+)\)\s*\|\s*([a-z]+)\s*\|/', $line, $m)) {
                $rowPath = 'docs/'.ltrim($m[1], '/');
                $rows[$rowPath] = [
                    'bucket' => $currentBucket,
                    'status' => $m[2],
                    'path' => $rowPath,
                ];
            }
        }

        return $rows;
    }

    private function normaliseBucket(string $heading): ?string
    {
        foreach (array_keys(self::BUCKETS) as $bucket) {
            if (stripos($heading, $bucket) !== false) {
                return $bucket;
            }
        }

        // Alternative bucket names used in docs/ROADMAP.md
        return match (strtolower($heading)) {
            'active' => 'In flight',
            'planning' => 'In flight',
            'shipped' => 'Shipped',
            'superseded' => 'Superseded / Abandoned',
            default => null,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $processFiles
     * @param  array<string, array{bucket: string, status: string, path: string}>  $index
     */
    private function checkIndexCoverage(array $processFiles, array $index, string $type, string $indexPath): void
    {
        foreach ($processFiles as $relPath => $fm) {
            if (($fm['type'] ?? null) !== $type) {
                continue;
            }

            if (! array_key_exists($relPath, $index)) {
                $this->addFinding('error', $relPath, "{$type} file is not indexed in `{$indexPath}`");
            }
        }
    }

    /**
     * @param  array<string, array{bucket: string, status: string, path: string}>  $index
     */
    private function checkIndexBackref(string $base, array $index, string $indexPath): void
    {
        foreach ($index as $rowPath => $_) {
            if (! is_file("{$base}/{$rowPath}")) {
                $this->addFinding('error', $indexPath, "index row references `{$rowPath}` which does not exist on disk");
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $processFiles
     * @param  array<string, array{bucket: string, status: string, path: string}>  $index
     */
    private function checkBucketPolicy(array $processFiles, array $index, string $type, string $indexPath): void
    {
        foreach ($processFiles as $relPath => $fm) {
            if (($fm['type'] ?? null) !== $type) {
                continue;
            }

            if (! array_key_exists($relPath, $index)) {
                continue;
            }

            $fileStatus = $fm['status'] ?? null;
            $rowStatus = $index[$relPath]['status'];
            $rowBucket = $index[$relPath]['bucket'];

            if ($fileStatus !== $rowStatus) {
                $this->addFinding('error', $relPath, "file status `{$fileStatus}` disagrees with `{$indexPath}` row status `{$rowStatus}`");
            }

            $expectedBucket = $this->bucketForStatus($fileStatus);

            if ($expectedBucket !== null && $expectedBucket !== $rowBucket) {
                $this->addFinding('error', $relPath, "file status `{$fileStatus}` should live under `## {$expectedBucket}` in `{$indexPath}`, found under `## {$rowBucket}`");
            }
        }
    }

    private function bucketForStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        foreach (self::BUCKETS as $bucket => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $bucket;
            }
        }

        return null;
    }

    private function addFinding(string $severity, string $file, string $message): void
    {
        $this->findings[] = compact('severity', 'file', 'message');
    }

    private function emitFindings(): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($this->findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCodeFromFindings();
        }

        $errors = array_filter($this->findings, fn ($f) => $f['severity'] === 'error');
        $warns = array_filter($this->findings, fn ($f) => $f['severity'] === 'warn');

        if ($errors === [] && $warns === []) {
            $this->info('docs:check — clean. Frontmatter, indices, and bucket policy all agree.');

            return self::SUCCESS;
        }

        if ($errors !== []) {
            $this->error(count($errors).' error(s):');
            foreach ($errors as $f) {
                $this->line("  [ERROR] {$f['file']} — {$f['message']}");
            }
        }

        if ($warns !== []) {
            $this->warn(count($warns).' warning(s):');
            foreach ($warns as $f) {
                $this->line("  [WARN]  {$f['file']} — {$f['message']}");
            }
        }

        return $this->exitCodeFromFindings();
    }

    private function exitCodeFromFindings(): int
    {
        return array_any($this->findings, fn ($f) => $f['severity'] === 'error')
            ? self::FAILURE
            : self::SUCCESS;
    }
}
