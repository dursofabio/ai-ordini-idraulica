@php
    use App\Services\Search\MatchOutcome;
@endphp
<x-filament-panels::page>
    {{ $this->form }}

    @if ($this->hasSearched && filled($this->data['query'] ?? null) && $this->recognizedText !== null)
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                <span class="font-medium">Tipo riconosciuto:</span>
                {{ $this->recognizedText }}
            </p>

            @if (count($this->appliedFilterLabels) > 0)
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($this->appliedFilterLabels as $label)
                        <span class="inline-flex items-center rounded-full bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700 dark:bg-primary-400/10 dark:text-primary-400">
                            {{ $label }}
                        </span>
                    @endforeach
                </div>
            @endif

            @if ($this->matchOutcome === MatchOutcome::AutoMatch)
                <div class="mt-2">
                    <span class="inline-flex items-center rounded-full bg-success-50 px-2 py-1 text-xs font-medium text-success-700 dark:bg-success-400/10 dark:text-success-400">
                        Corrispondenza automatica
                    </span>
                </div>
            @elseif ($this->matchOutcome === MatchOutcome::Disambiguation)
                <div class="mt-2">
                    <span class="inline-flex items-center rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                        Prodotti candidati da verificare
                    </span>
                </div>
            @endif
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
