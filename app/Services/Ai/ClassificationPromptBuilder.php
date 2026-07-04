<?php

namespace App\Services\Ai;

use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Support\Collection;

/**
 * Builds the Anthropic Messages API payload used to classify a batch of
 * products against the closed catalog taxonomy and the closed attribute
 * registry. The prompt instructs the model to return strict JSON matching
 * the `{results: [...]}` schema expected by {@see ClassificationResponseValidator},
 * and embeds the full list of assignable brands/families/subfamilies (so the
 * model cannot invent values outside the existing taxonomy) plus the full
 * attribute registry (US-043: only canonical keys from
 * {@see AttributeVocabulary} may be used — the AI never invents a free-form
 * key, and never converts a value's unit itself).
 *
 * Product descriptions are internal catalog data (not third-party user
 * input) and are embedded verbatim without sanitization. A description
 * crafted to look like an instruction could in theory nudge the model off
 * the JSON contract, but {@see ClassificationResponseValidator} strictly
 * re-validates shape and taxonomy membership before anything is trusted, and
 * this job never writes brand_id/family_id back onto the product — so the
 * worst case is a wasted retry or a `needs_review` product, not a bad write.
 */
class ClassificationPromptBuilder
{
    /**
     * Maximum tokens requested for a batch classification response. Sized
     * generously for batches up to 50 products, each producing a compact
     * JSON object.
     */
    private const MAX_TOKENS = 8192;

    /**
     * Build the Messages API payload for classifying the given batch of
     * products with the given model.
     *
     * @param  Collection<int, Product>  $products
     * @return array<string, mixed>
     */
    public function build(Collection $products, TaxonomyCatalog $taxonomy, AttributeVocabulary $vocabulary, string $model): array
    {
        return [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->prompt($products, $taxonomy, $vocabulary),
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function prompt(Collection $products, TaxonomyCatalog $taxonomy, AttributeVocabulary $vocabulary): string
    {
        $items = $products
            ->map(fn (Product $product): string => sprintf(
                '- codice_articolo: %s | descrizione: %s%s',
                $product->codice_articolo,
                trim((string) ($product->description_clean ?? $product->description_raw)),
                $this->knownAttributesText($product),
            ))
            ->implode("\n");

        $taxonomyText = $taxonomy->toPromptText();
        $vocabularyText = $vocabulary->toPromptText();

        return <<<PROMPT
        Sei un classificatore di catalogo per una ditta di forniture idrauliche e termoidrauliche.
        Per ciascun prodotto elencato sotto, assegna marca, famiglia, sottofamiglia e tipo prodotto
        usando ESCLUSIVAMENTE i valori della tassonomia chiusa riportata più sotto. Non inventare
        marche, famiglie o sottofamiglie che non compaiono nell'elenco: se non sei sicuro, lascia il
        campo a null. Il campo "product_type" deve contenere ESCLUSIVAMENTE il nome/tipo del
        prodotto (es. "Caldaia a condensazione"): MAI la marca, la famiglia, la sottofamiglia o
        valori di attributi tecnici. Includi anche una breve descrizione arricchita e un livello di
        confidenza (intero 0-100) per ciascun prodotto.

        Per ciascun prodotto sono elencati anche gli attributi tecnici già noti (chiave, valore,
        unità di misura e origine). Valida quegli attributi correggendoli se il valore non è
        coerente con la descrizione, e proponi eventuali altri attributi tecnici rilevanti. Usa
        ESCLUSIVAMENTE le chiavi del registro attributi riportato più sotto (chiave, tipo, unità
        canonica, descrizione): NON inventare chiavi libere. Se nessuna chiave del registro è
        pertinente per un dato attributo, ometti semplicemente quell'attributo. Per ciascun
        attributo proposto riporta il valore ("value_num" o "value_text") e l'unità di misura
        ("unit") ESATTAMENTE come letti nel testo del prodotto: NON convertire mai il valore
        nell'unità canonica del registro (es. per "3500 W" riporta value_num: 3500, unit: "W", MAI
        3.5 e "kW" — la conversione all'unità canonica è responsabilità dell'applicazione, non tua).
        Includi anche un livello di confidenza (intero 0-100) specifico per quell'attributo. Se non
        sei sicuro di un attributo, non includerlo.

        Tassonomia chiusa:
        {$taxonomyText}

        Registro attributi (chiave | tipo | unità canonica | descrizione):
        {$vocabularyText}

        Prodotti da classificare:
        {$items}

        Rispondi ESCLUSIVAMENTE con un oggetto JSON valido, senza testo aggiuntivo, nel seguente formato:
        {
          "results": [
            {
              "codice_articolo": "string",
              "brand": "string|null",
              "family": "string|null",
              "subfamily": "string|null",
              "product_type": "string|null",
              "enriched_description": "string",
              "confidence": 0,
              "attributes": [
                {
                  "key": "string",
                  "value_num": 0,
                  "value_text": "string|null",
                  "unit": "string|null",
                  "confidence": 0
                }
              ]
            }
          ]
        }

        Includi un elemento in "results" per ogni codice_articolo elencato sopra, nello stesso ordine.
        Il campo "attributes" può essere un array vuoto se non ci sono attributi da validare o proporre.
        PROMPT;
    }

    /**
     * Renders the product's already-known technical attributes (legacy
     * `source = 'regex'` rows still being phased out, or previously proposed
     * by AI) as inline prompt context, so the model can validate/correct them
     * instead of guessing blind. Returns an empty string when the product has
     * no known attributes.
     */
    private function knownAttributesText(Product $product): string
    {
        $attributes = $product->relationLoaded('attributes') ? $product->attributes : collect();

        if ($attributes->isEmpty()) {
            return '';
        }

        $parts = $attributes
            ->map(function (ProductAttribute $attribute): string {
                $value = $attribute->value_num !== null
                    ? (string) $attribute->value_num
                    : (string) $attribute->value_text;

                $unit = $attribute->unit !== null ? " {$attribute->unit}" : '';
                $source = $attribute->source ?? 'sconosciuta';

                return "{$attribute->key}={$value}{$unit} (origine: {$source})";
            })
            ->implode(', ');

        return " | attributi noti: {$parts}";
    }
}
