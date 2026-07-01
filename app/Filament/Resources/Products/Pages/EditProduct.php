<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Services\Enrichment\EnrichmentApplier;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Mark brand/family/subfamily as manually-set whenever the admin changes
     * them from this backoffice form, so {@see EnrichmentApplier}
     * never silently overwrites a human correction on the next AI reimport
     * batch: `EnrichmentApplier` explicitly skips any field whose
     * `*_source` is already `'manual'`, but it has no way of knowing a
     * value was hand-picked unless something sets that flag here, at the
     * point where we can still compare the submitted value against the
     * record's current (pre-save) value.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $fieldsToSources = [
            'brand_id' => 'brand_source',
            'family_id' => 'family_source',
            'subfamily_id' => 'subfamily_source',
        ];

        $anyChanged = false;

        foreach ($fieldsToSources as $field => $sourceField) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            // Compare against getOriginal(), not getAttribute(): by the time
            // this hook runs, Filament's Select::relationship() has already
            // called BelongsTo::associate() during form-state resolution
            // (Schema::getState() -> saveRelationships()), which writes the
            // new foreign key straight onto $this->record's attributes. That
            // makes getAttribute() reflect the *new* value already, so a
            // getAttribute()-vs-getAttribute() comparison would always see
            // "unchanged". getOriginal() is untouched by associate() (it's
            // only refreshed by syncOriginal(), which runs on an actual save
            // or on the initial DB hydration), so it still holds the true
            // pre-edit value at this point.
            //
            // Loose comparison is intentional: form state for a Select bound
            // to a foreign key arrives as an int or numeric string, while the
            // record's cast attribute may be an int or null. `!=` treats
            // `5` and `"5"` as equal while still detecting `null` vs `5` as
            // a real change.
            if ($data[$field] != $this->record->getOriginal($field)) {
                $data[$sourceField] = 'manual';
                $anyChanged = true;
            }
        }

        if ($anyChanged) {
            $data['source'] = 'manual';
            $data['confidence'] = 100;
        }

        return $data;
    }
}
