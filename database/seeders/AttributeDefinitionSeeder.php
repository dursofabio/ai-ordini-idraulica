<?php

namespace Database\Seeders;

use App\Models\AttributeDefinition;
use Illuminate\Database\Seeder;

/**
 * Populates the central attribute registry ({@see AttributeDefinition}) with
 * the canonical keys currently in use across the catalog (US-042): 9
 * canonical definitions derived from the regex extraction pass that used to
 * populate `product_attributes` before it was retired in favor of AI-only
 * extraction (US-043) — `potenza_watt` is retired, folded into `potenza_kw`
 * via its accepted `W` unit — plus `portata_lmin`, the one AI-recurring key
 * identifiable statically at the time this registry was introduced.
 *
 * Each definition carries an Italian `description` meant to ground the
 * AI-only extraction prompt introduced in US-043.
 *
 * Idempotent: uses `updateOrCreate` on `key`, safe to re-run.
 */
class AttributeDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            AttributeDefinition::query()->updateOrCreate(
                ['key' => $definition['key']],
                $definition,
            );
        }
    }

    /**
     * @return array<int, array{key: string, data_type: string, canonical_unit: ?string, accepted_units: ?array<string, float|int>, description: string}>
     */
    private function definitions(): array
    {
        return [
            [
                'key' => 'potenza_kw',
                'data_type' => 'numeric',
                'canonical_unit' => 'kW',
                'accepted_units' => ['kW' => 1, 'W' => 0.001],
                'description' => 'Potenza nominale dell\'apparecchio, espressa in kilowatt (kW).',
            ],
            [
                'key' => 'capacita_litri',
                'data_type' => 'numeric',
                'canonical_unit' => 'L',
                'accepted_units' => ['L' => 1, 'LT' => 1, 'ML' => 0.001],
                'description' => 'Capacità del serbatoio o bollitore, espressa in litri (L).',
            ],
            [
                'key' => 'attacco_pollici',
                'data_type' => 'numeric',
                'canonical_unit' => '"',
                'accepted_units' => ['"' => 1, 'POLLICI' => 1, 'IN' => 1],
                'description' => 'Dimensione dell\'attacco filettato, espressa in pollici (").',
            ],
            [
                'key' => 'diametro_nominale',
                'data_type' => 'numeric',
                'canonical_unit' => 'DN',
                'accepted_units' => ['DN' => 1],
                'description' => 'Diametro nominale (DN) di tubazioni, raccordi e valvole.',
            ],
            [
                'key' => 'pressione_nominale',
                'data_type' => 'numeric',
                'canonical_unit' => 'PN',
                'accepted_units' => ['PN' => 1],
                'description' => 'Pressione nominale (PN) di esercizio di raccordi e giunzioni flangiate.',
            ],
            [
                'key' => 'pressione_bar',
                'data_type' => 'numeric',
                'canonical_unit' => 'bar',
                'accepted_units' => ['bar' => 1, 'mbar' => 0.001, 'mb' => 0.001],
                'description' => 'Pressione di esercizio dell\'apparecchio, espressa in bar.',
            ],
            [
                'key' => 'tensione_volt',
                'data_type' => 'numeric',
                'canonical_unit' => 'V',
                'accepted_units' => ['V' => 1, 'VDC' => 1, 'VAC' => 1],
                'description' => 'Tensione elettrica di alimentazione dell\'apparecchio, espressa in volt (V).',
            ],
            [
                'key' => 'colore_ral',
                'data_type' => 'text',
                'canonical_unit' => null,
                'accepted_units' => null,
                'description' => 'Codice colore RAL dell\'apparecchio o del componente (es. RAL9010).',
            ],
            [
                'key' => 'materiale',
                'data_type' => 'text',
                'canonical_unit' => null,
                'accepted_units' => null,
                'description' => 'Materiale di costruzione del componente (es. ottone, inox, PVC).',
            ],
            [
                'key' => 'portata_lmin',
                'data_type' => 'numeric',
                'canonical_unit' => 'l/min',
                'accepted_units' => ['l/min' => 1, 'lt/min' => 1, 'lmin' => 1],
                'description' => 'Portata dell\'apparecchio o del componente, espressa in litri al minuto (l/min).',
            ],
        ];
    }
}
