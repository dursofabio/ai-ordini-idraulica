<?php

namespace App\Models;

use Database\Factories\AttributeDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Central vocabulary of attribute keys used across `product_attributes`,
 * carrying the canonical unit and the accepted-unit conversion factors for
 * each physical quantity. Anchors to `product_attributes.key` by string
 * value only: no foreign key, the EAV schema is left unchanged.
 */
#[Fillable(['key', 'data_type', 'canonical_unit', 'accepted_units', 'description'])]
class AttributeDefinition extends Model
{
    /** @use HasFactory<AttributeDefinitionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_units' => 'array',
        ];
    }
}
