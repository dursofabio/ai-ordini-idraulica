<x-filament-panels::page>
    {{ $this->infolist }}

    <form wire:submit="save">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4">
            Salva correzione
        </x-filament::button>
    </form>
</x-filament-panels::page>
