<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Evals\EvalCase;
use App\Evals\EvalFile;
use App\Evals\EvalRunner;
use App\Models\User;
use Database\Seeders\SampleDocumentSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DocChatEval extends Command
{
    protected $signature = 'docchat:eval
        {file : Path to a JSON eval file, for example evals/sample.json}
        {--user= : Email or id of the user whose documents are searched (default: the demo user)}
        {--documents=* : Limit the search to these document ids (default: all ready documents)}
        {--delay= : Seconds to wait between chat calls (default: docchat.evals.delay_seconds)}
        {--sweep= : Comma-separated thresholds; runs retrieval only, with no chat calls}';

    protected $description = 'Measure retrieval hit rate, keyword match, refusals and latency on an eval file';

    public function handle(EvalRunner $runner): int
    {
        try {
            $cases = EvalFile::load($this->resolvePath((string) $this->argument('file')));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if ($user === null) {
            return self::FAILURE;
        }

        $documentIds = $this->documentIds($user);

        if ($documentIds === []) {
            $this->components->error('None of the --documents ids belong to '.$user->email.'.');

            return self::FAILURE;
        }

        if (! $user->documents()->where('status', DocumentStatus::Ready)->exists()) {
            $this->components->error('User '.$user->email.' has no ready documents. Upload one, or run: php artisan db:seed --class=SampleDocumentSeeder');

            return self::FAILURE;
        }

        if (is_string($this->option('sweep')) && $this->option('sweep') !== '') {
            return $this->sweep($runner, $user, $cases, $documentIds);
        }

        $delay = $this->option('delay') !== null
            ? max(0.0, (float) $this->option('delay'))
            : (float) config('docchat.evals.delay_seconds');

        $this->components->info(sprintf('Running %d cases for %s (delay %ss between chat calls)', count($cases), $user->email, $delay));

        $report = $runner->run($user, $cases, $documentIds, $delay, function (int $index, array $result) use ($cases): void {
            $status = match (true) {
                $result['error'] !== null => '<fg=red>error</>',
                $result['should_refuse'] => $result['refused'] ? '<fg=green>refused</>' : '<fg=red>answered</>',
                $result['refused'] => '<fg=red>refused</>',
                default => $result['hit'] ? '<fg=green>hit</>' : '<fg=yellow>miss</>',
            };

            $this->line(sprintf('  [%d/%d] %s  %s', $index + 1, count($cases), $status, Str::limit($result['question'], 70)));
        });

        $this->newLine();
        $this->table(
            ['#', 'Question', 'Expect', 'Hit', 'Keywords', 'Refused', 'Top sim', 'ms', 'Error'],
            array_map(fn (array $r, int $i): array => [
                $i + 1,
                Str::limit($r['question'], 45),
                $r['should_refuse'] ? 'refuse' : 'p.'.$r['expected_page'],
                $r['hit'] === null ? '-' : ($r['hit'] ? 'yes' : 'no'),
                $r['keyword_match'] === null ? '-' : self::percent($r['keyword_match']),
                $r['refused'] ? 'yes' : 'no',
                $r['retrieved'] === [] ? '-' : number_format($r['retrieved'][0]['similarity'], 3),
                $r['latency_ms'],
                $r['error'] === null ? '' : Str::limit((string) $r['error'], 30),
            ], $report['cases'], array_keys($report['cases'])),
        );

        $summary = $report['summary'];
        $this->table(['Metric', 'Value'], [
            ['Retrieval hit rate (expected page in top '.$report['config']['top_k'].')', self::percent($summary['retrieval_hit_rate'])],
            ['Keyword match rate', self::percent($summary['keyword_match_rate'])],
            ['Correct refusal rate', self::percent($summary['correct_refusal_rate'])],
            ['Wrong refusals on answerable questions', self::percent($summary['false_refusal_rate'])],
            ['Average latency', $summary['avg_latency_ms'] === null ? '-' : $summary['avg_latency_ms'].' ms'],
            ['Total tokens', number_format($summary['total_tokens'])],
            ['Errors', $summary['errors']],
        ]);

        $path = $this->writeReport($report);
        $this->components->info('Report written to '.$path);

        return self::SUCCESS;
    }

    /**
     * @param  list<EvalCase>  $cases
     * @param  list<int>|null  $documentIds
     */
    private function sweep(EvalRunner $runner, User $user, array $cases, ?array $documentIds): int
    {
        $thresholds = array_values(array_filter(array_map(
            fn (string $value): ?float => is_numeric(trim($value)) ? (float) trim($value) : null,
            explode(',', (string) $this->option('sweep')),
        ), fn (?float $value): bool => $value !== null && $value >= 0 && $value <= 1));

        if ($thresholds === []) {
            $this->components->error('--sweep needs thresholds between 0 and 1, for example --sweep=0.3,0.4,0.5');

            return self::FAILURE;
        }

        $rows = $runner->sweep($user, $cases, $thresholds, $documentIds);

        $this->table(
            ['Threshold', 'Hit rate', 'Correct refusals', 'Wrong refusals'],
            array_map(fn (array $row): array => [
                $row['threshold'],
                self::percent($row['hit_rate']),
                self::percent($row['correct_refusal_rate']),
                self::percent($row['false_refusal_rate']),
            ], $rows),
        );

        $path = $this->writeReport(['type' => 'sweep', 'config' => EvalRunner::config(), 'sweep' => $rows]);
        $this->components->info('Sweep written to '.$path);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(array $report): string
    {
        $directory = storage_path('app/evals');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/'.now()->format('Y-m-d_His').'.json';
        $report = ['file' => (string) $this->argument('file'), 'ran_at' => now()->toIso8601String()] + $report;

        File::put($path, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return $path;
    }

    private function resolvePath(string $file): string
    {
        return is_file($file) ? $file : base_path($file);
    }

    private function resolveUser(): ?User
    {
        $option = $this->option('user');
        $key = is_string($option) && $option !== '' ? $option : SampleDocumentSeeder::EMAIL;

        $user = ctype_digit($key)
            ? User::query()->find((int) $key)
            : User::query()->where('email', $key)->first();

        if ($user === null) {
            $this->components->error("User [{$key}] not found. Pass --user, or run: php artisan db:seed --class=SampleDocumentSeeder");
        }

        return $user;
    }

    /**
     * @return list<int>|null
     */
    private function documentIds(User $user): ?array
    {
        $ids = array_map('intval', (array) $this->option('documents'));

        if ($ids === []) {
            return null;
        }

        // Only the user's own documents, whatever ids are passed.
        return array_values($user->documents()->whereIn('id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    private static function percent(?float $value): string
    {
        return $value === null ? '-' : number_format($value * 100, 1).'%';
    }
}
