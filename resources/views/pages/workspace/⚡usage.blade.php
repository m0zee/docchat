<?php

use App\Enums\ConversationChannel;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Usage')] class extends Component {
    #[Url]
    public string $month = '';

    public function mount(): void
    {
        Gate::authorize('manage', $this->workspace);

        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->require();
    }

    #[Computed]
    public function start(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $this->month.'-01 00:00:00') ?: CarbonImmutable::now()->startOfMonth();
    }

    /**
     * Totals per channel for the month.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        $rows = DB::table('usage_events')
            ->where('workspace_id', $this->workspace->id)
            ->where('created_at', '>=', $this->start)
            ->where('created_at', '<', $this->start->addMonth())
            ->groupBy('channel')
            ->orderBy('channel')
            ->selectRaw("channel,
                count(*) filter (where type = 'question') as questions,
                count(*) filter (where type = 'ingest') as ingests,
                coalesce(sum(prompt_tokens), 0) as prompt_tokens,
                coalesce(sum(completion_tokens), 0) as completion_tokens,
                coalesce(sum(embedding_tokens), 0) as embedding_tokens,
                count(*) filter (where cache_hit is not null) as cache_hits,
                count(*) filter (where type = 'question' and ai_source = 'workspace') as own_key,
                count(*) filter (where type = 'question' and ai_source = 'device') as on_device,
                coalesce(sum(tokens_saved), 0) as tokens_saved,
                avg(latency_ms) filter (where type = 'question') as avg_latency_ms")
            ->get();

        return $rows->map(fn (object $row): array => [
            'channel' => ConversationChannel::tryFrom((string) $row->channel)?->label() ?? (string) $row->channel,
            'questions' => (int) $row->questions,
            'ingests' => (int) $row->ingests,
            'prompt_tokens' => (int) $row->prompt_tokens,
            'completion_tokens' => (int) $row->completion_tokens,
            'embedding_tokens' => (int) $row->embedding_tokens,
            'cache_hits' => (int) $row->cache_hits,
            'own_key' => (int) $row->own_key,
            'on_device' => (int) $row->on_device,
            'tokens_saved' => (int) $row->tokens_saved,
            'avg_latency_ms' => $row->avg_latency_ms === null ? null : (int) round((float) $row->avg_latency_ms),
        ])->all();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function totals(): array
    {
        $keys = ['questions', 'ingests', 'prompt_tokens', 'completion_tokens', 'embedding_tokens', 'cache_hits', 'tokens_saved', 'own_key', 'on_device'];

        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => array_sum(array_column($this->rows, $key))])->all();
    }
}; ?>

<x-pages::workspace.layout :heading="__('Usage')" :subheading="__('Questions, documents and tokens per channel. Question and document text is never stored here.')">
    <div class="mb-4 flex items-center gap-2">
        <flux:button size="sm" icon="chevron-left" wire:click="$set('month', '{{ $this->start->subMonth()->format('Y-m') }}')" :aria-label="__('Previous month')" />
        <flux:heading class="w-40 text-center" data-test="usage-month">{{ $this->start->format('F Y') }}</flux:heading>
        <flux:button size="sm" icon="chevron-right" wire:click="$set('month', '{{ $this->start->addMonth()->format('Y-m') }}')" :aria-label="__('Next month')" />
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-sm text-zinc-500">{{ __('Questions') }}</div>
            <div class="text-2xl font-semibold" data-test="usage-questions">{{ number_format($this->totals['questions']) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-sm text-zinc-500">{{ __('Chat tokens') }}</div>
            <div class="text-2xl font-semibold">{{ number_format($this->totals['prompt_tokens'] + $this->totals['completion_tokens']) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-sm text-zinc-500">{{ __('Answered from cache') }}</div>
            <div class="text-2xl font-semibold" data-test="usage-cache">
                {{ $this->totals['questions'] > 0 ? number_format($this->totals['cache_hits'] / $this->totals['questions'] * 100, 0) : 0 }}%
            </div>
            <div class="text-xs text-zinc-500">{{ __('about :n tokens saved', ['n' => number_format($this->totals['tokens_saved'])]) }}</div>
        </div>
    </div>

    @if ($this->totals['own_key'] > 0 || $this->totals['on_device'] > 0)
        <flux:text class="mb-6" data-test="usage-sources">
            {{ __(':own answered on your own AI key, :device written on the asker\'s device.', ['own' => number_format($this->totals['own_key']), 'device' => number_format($this->totals['on_device'])]) }}
        </flux:text>
    @endif

    @if ($this->rows === [])
        <flux:text>{{ __('No usage this month.') }}</flux:text>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Channel') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Questions') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Cache hits') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Prompt') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Completion') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Documents') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Embedding') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Avg latency') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->rows as $row)
                        <flux:table.row data-test="usage-row">
                            <flux:table.cell>{{ $row['channel'] }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($row['questions']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($row['cache_hits']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($row['prompt_tokens']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($row['completion_tokens']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($row['ingests']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($row['embedding_tokens']) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $row['avg_latency_ms'] === null ? '—' : number_format($row['avg_latency_ms']).' ms' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</x-pages::workspace.layout>
