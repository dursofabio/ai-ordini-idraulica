<?php

namespace App\Console\Commands;

use App\Jobs\ImportXlsxJob;
use App\Models\Brand;
use App\Models\Family;
use App\Models\StagingArticolo;
use App\Models\Subfamily;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Seeds the closed brand/family/subfamily taxonomy from the distinct values
 * already sitting in `staging_articoli.raw_row` (the `Marca`, `Fam`, `S.Fam`
 * and their `Descrizione *` columns from the source XLSX).
 *
 * Without this, `BrandResolver` and the AI classification job have no
 * taxonomy to match against, since both only assign values that already
 * exist as Brand/Family/Subfamily records — nothing else creates them.
 *
 * Distinct combinations are collected in PHP while chunking through
 * `staging_articoli` (like {@see ImportXlsxJob}), rather than via
 * database-specific JSON path operators, so the command runs the same way
 * against Postgres and the SQLite connection used in tests.
 *
 * Safe to re-run after further imports: matches existing rows by `slug` and
 * merges (never replaces) `aliases`, so admin-added aliases survive.
 */
class CatalogSeedTaxonomyCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'catalog:seed-taxonomy';

    /**
     * @var string
     */
    protected $description = 'Genera brands, families e subfamilies a partire dai dati grezzi di staging_articoli.';

    public const CHUNK_SIZE = 2000;

    public function handle(): int
    {
        /** @var array<string, array{code: string, label: string}> $brandRows */
        $brandRows = [];

        /** @var array<string, array{code: string, label: string}> $familyRows */
        $familyRows = [];

        /** @var array<string, array{fam_code: string, fam_label: string, code: string, label: string}> $subfamilyRows */
        $subfamilyRows = [];

        StagingArticolo::query()
            ->select(['raw_row'])
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, function ($chunk) use (&$brandRows, &$familyRows, &$subfamilyRows): void {
                /** @var StagingArticolo $row */
                foreach ($chunk as $row) {
                    $raw = $row->raw_row ?? [];

                    $marcaCode = trim((string) ($raw['marca'] ?? ''));
                    $marcaLabel = trim((string) ($raw['descrizione_marca'] ?? ''));
                    $famCode = trim((string) ($raw['fam'] ?? ''));
                    $famLabel = trim((string) ($raw['descrizione_fam'] ?? ''));
                    $subfamCode = trim((string) ($raw['s_fam'] ?? ''));
                    $subfamLabel = trim((string) ($raw['descrizione_s_fam'] ?? ''));

                    if ($marcaCode !== '') {
                        $brandRows["{$marcaCode}\0{$marcaLabel}"] = ['code' => $marcaCode, 'label' => $marcaLabel];
                    }

                    if ($famCode !== '') {
                        $familyRows["{$famCode}\0{$famLabel}"] = ['code' => $famCode, 'label' => $famLabel];
                    }

                    if ($famCode !== '' && $subfamCode !== '') {
                        $key = "{$famCode}\0{$famLabel}\0{$subfamCode}\0{$subfamLabel}";
                        $subfamilyRows[$key] = [
                            'fam_code' => $famCode,
                            'fam_label' => $famLabel,
                            'code' => $subfamCode,
                            'label' => $subfamLabel,
                        ];
                    }
                }
            });

        $brands = $this->seedBrands($brandRows);
        $families = $this->seedFamilies($familyRows);
        $subfamilies = $this->seedSubfamilies($subfamilyRows);

        $this->info("Brand: {$brands} | Famiglie: {$families} | Sottofamiglie: {$subfamilies}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{code: string, label: string}>  $rows
     */
    private function seedBrands(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $name = $row['label'] !== '' ? $row['label'] : $row['code'];

            if ($name === '') {
                continue;
            }

            $this->upsertTaxonomy(Brand::class, $name, $this->buildAliases($name, [$row['code']]));
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, array{code: string, label: string}>  $rows
     */
    private function seedFamilies(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $name = $row['label'] !== '' ? $row['label'] : $row['code'];

            if ($name === '') {
                continue;
            }

            $this->upsertTaxonomy(Family::class, $name, $this->buildAliases($name, [$row['code']]));
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, array{fam_code: string, fam_label: string, code: string, label: string}>  $rows
     */
    private function seedSubfamilies(array $rows): int
    {
        $families = Family::all(['id', 'slug'])->keyBy('slug');
        $count = 0;

        foreach ($rows as $row) {
            $familyName = $row['fam_label'] !== '' ? $row['fam_label'] : $row['fam_code'];
            $family = $families->get(Str::slug($familyName));

            if ($family === null) {
                continue;
            }

            $name = $row['label'] !== '' ? $row['label'] : $row['code'];

            if ($name === '') {
                continue;
            }

            $aliases = $this->buildAliases($name, [$row['code']]);

            // Subfamily short codes/names repeat across unrelated families
            // (e.g. "ACCESSORI" under 25 different families), so the slug is
            // scoped by family to stay unique while `name` stays plain.
            $slug = Str::slug($familyName.'-'.$name);

            $existing = Subfamily::query()->where('slug', $slug)->first();

            if ($existing !== null) {
                $existing->update([
                    'name' => $name,
                    'family_id' => $family->id,
                    'aliases' => $this->mergeAliases($existing->aliases, $aliases),
                ]);
            } else {
                Subfamily::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                    'family_id' => $family->id,
                    'aliases' => $aliases === [] ? null : $aliases,
                ]);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Upserts a Brand/Family record by slug, merging new aliases into any
     * existing ones instead of replacing them.
     *
     * @param  class-string<Brand|Family>  $model
     * @param  array<int, string>  $aliases
     */
    private function upsertTaxonomy(string $model, string $name, array $aliases): void
    {
        $slug = Str::slug($name);
        $existing = $model::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            $existing->update([
                'name' => $name,
                'aliases' => $this->mergeAliases($existing->aliases, $aliases),
            ]);

            return;
        }

        $model::query()->create([
            'name' => $name,
            'slug' => $slug,
            'aliases' => $aliases === [] ? null : $aliases,
        ]);
    }

    /**
     * Builds the alias list for a taxonomy entry: the raw ERP code, plus the
     * first word of a multi-word name (e.g. "WAVIN ITALIA SPA" => "WAVIN"),
     * since product descriptions in this catalog reference the short form,
     * not the full legal name. Excludes anything equal to the name itself.
     *
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    private function buildAliases(string $name, array $codes): array
    {
        $candidates = $codes;

        $words = preg_split('/\s+/', $name) ?: [];

        if (count($words) > 1) {
            $candidates[] = $words[0];
        }

        $normalizedName = Str::lower($name);

        return collect($candidates)
            ->map(fn (string $candidate): string => trim($candidate))
            ->filter(fn (string $candidate): bool => $candidate !== '' && Str::lower($candidate) !== $normalizedName)
            ->unique(fn (string $candidate): string => Str::lower($candidate))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>|null  $existing
     * @param  array<int, string>  $incoming
     * @return array<int, string>|null
     */
    private function mergeAliases(?array $existing, array $incoming): ?array
    {
        $merged = collect($existing ?? [])
            ->merge($incoming)
            ->map(fn (string $alias): string => trim($alias))
            ->filter(fn (string $alias): bool => $alias !== '')
            ->unique(fn (string $alias): string => Str::lower($alias))
            ->values()
            ->all();

        return $merged === [] ? null : $merged;
    }
}
