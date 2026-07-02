<?php

namespace App\Filament\Resources\Brands\Schemas;

use App\Models\Brand;
use Closure;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required(),
                TextInput::make('slug')
                    ->label('Slug')
                    ->required(),
                TagsInput::make('aliases')
                    ->label('Alias')
                    ->rules([static::uniqueAliasRule()]),
            ]);
    }

    /**
     * Ensures no alias submitted for this brand is already used as an alias
     * or as the name of another brand (AC4), and that the submitted list
     * itself has no case-insensitive duplicates. Applies on both create and
     * edit: on edit, the record's own row is excluded from the collision
     * check so re-saving an alias it already owns does not falsely collide
     * with itself.
     *
     * The comparison is case-insensitive throughout (both against other
     * brands' `aliases`/`name` and within the submitted batch) because
     * `BrandResolver` matches alias tokens case-insensitively; a
     * case-sensitive check here (e.g. `whereJsonContains`, which is an
     * exact-value match) would let through an alias that only differs by
     * case from one already in use, silently reintroducing the ambiguity
     * AC4 is meant to prevent.
     */
    protected static function uniqueAliasRule(): Closure
    {
        return function (?Brand $record): Closure {
            return function (string $attribute, $value, Closure $fail) use ($record): void {
                /** @var array<int, string> $aliases */
                $aliases = array_filter(is_array($value) ? $value : [], fn ($alias) => $alias !== '');

                $seen = [];

                foreach ($aliases as $alias) {
                    $normalized = Str::lower($alias);

                    if (isset($seen[$normalized])) {
                        $fail("L'alias \"{$alias}\" è duplicato nella lista.");

                        return;
                    }

                    $seen[$normalized] = true;
                }

                $otherBrands = Brand::query()
                    ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                    ->get(['name', 'aliases']);

                foreach ($aliases as $alias) {
                    $normalized = Str::lower($alias);

                    $collision = $otherBrands->contains(
                        fn (Brand $other): bool => Str::lower($other->name) === $normalized
                            || collect($other->aliases ?? [])->contains(fn (string $otherAlias): bool => Str::lower($otherAlias) === $normalized)
                    );

                    if ($collision) {
                        $fail("L'alias \"{$alias}\" è già associato a un'altra marca.");

                        return;
                    }
                }
            };
        };
    }
}
