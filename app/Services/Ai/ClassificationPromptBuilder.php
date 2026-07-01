<?php

namespace App\Services\Ai;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Builds the Anthropic Messages API payload used to classify a batch of
 * products against the closed catalog taxonomy. The prompt instructs the
 * model to return strict JSON matching the `{results: [...]}` schema
 * expected by {@see ClassificationResponseValidator}, and embeds the full
 * list of assignable brands/families/subfamilies so the model cannot invent
 * values outside the existing taxonomy.
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
    public function build(Collection $products, TaxonomyCatalog $taxonomy, string $model): array
    {
        return [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->prompt($products, $taxonomy),
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function prompt(Collection $products, TaxonomyCatalog $taxonomy): string
    {
        $items = $products
            ->map(fn (Product $product): string => sprintf(
                '- codice_articolo: %s | descrizione: %s',
                $product->codice_articolo,
                trim((string) ($product->description_clean ?? $product->description_raw)),
            ))
            ->implode("\n");

        $taxonomyText = $taxonomy->toPromptText();

        return <<<PROMPT
        Sei un classificatore di catalogo per una ditta di forniture idrauliche e termoidrauliche.
        Per ciascun prodotto elencato sotto, assegna marca, famiglia, sottofamiglia e tipo prodotto
        usando ESCLUSIVAMENTE i valori della tassonomia chiusa riportata più sotto. Non inventare
        marche, famiglie o sottofamiglie che non compaiono nell'elenco: se non sei sicuro, lascia il
        campo a null. Includi anche una breve descrizione arricchita e un livello di confidenza
        (intero 0-100) per ciascun prodotto.

        Tassonomia chiusa:
        {$taxonomyText}

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
              "confidence": 0
            }
          ]
        }

        Includi un elemento in "results" per ogni codice_articolo elencato sopra, nello stesso ordine.
        PROMPT;
    }
}
