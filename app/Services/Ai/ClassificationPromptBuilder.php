<?php

namespace App\Services\Ai;

use App\Models\Product;
use App\Models\ProductAttribute;
use Illuminate\Support\Collection;

/**
 * Builds the Anthropic Messages API payload used to classify a batch of
 * products against the closed catalog taxonomy. The prompt instructs the
 * model to return strict JSON matching the `{results: [...]}` schema
 * expected by {@see ClassificationResponseValidator}, and embeds the full
 * list of assignable brands/families/subfamilies (so the model cannot invent
 * values outside the existing taxonomy). Technical attributes are free-form:
 * the AI is not shown the {@see AttributeVocabulary} registry and is not
 * constrained to it — a key simply names the type of characteristic (e.g.
 * `potenza`) without the unit embedded in it, since the unit is already its
 * own `unit` field.
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
     * Output token budget requested per product in the batch. Measured on a
     * real run: a verbose result (rich enriched_description plus several
     * attributes) costs ~210 output tokens, so 400 leaves ample headroom. A
     * fixed batch-wide budget (previously 8192) truncated the JSON mid-array
     * on 40-product batches with verbose descriptions (`finish_reason:
     * "length"`), failing the whole batch as invalid JSON.
     */
    private const MAX_TOKENS_PER_PRODUCT = 400;

    /**
     * Floor for the requested output budget, so a single-product escalation
     * call still has room for an unusually long result.
     */
    private const MIN_MAX_TOKENS = 1024;

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
            'max_tokens' => max(self::MIN_MAX_TOKENS, $products->count() * self::MAX_TOKENS_PER_PRODUCT),
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
                '- codice_articolo: %s | descrizione: %s%s',
                $product->codice_articolo,
                trim((string) ($product->description_clean ?? $product->description_raw)),
                $this->knownAttributesText($product),
            ))
            ->implode("\n");

        $taxonomyText = $taxonomy->toPromptText();

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
        coerente con la descrizione, e proponi eventuali altri attributi tecnici rilevanti. La
        chiave ("key") di ogni attributo deve indicare SOLO il tipo di caratteristica, in italiano
        e in snake_case, SENZA includere l'unità di misura nel nome (es. "potenza", MAI "potenza_kw"
        o "potenza_watt": l'unità va sempre e solo nel campo "unit" a parte). NON includere MAI
        tra gli attributi il codice articolo o la descrizione del prodotto: sono già gestiti come
        campi propri del prodotto, non sono caratteristiche tecniche (il tipo di prodotto va invece
        nel campo dedicato "product_type", mai tra gli attributi).

        Il valore ("value") è sempre una stringa, riportata ESATTAMENTE come letta nel testo del prodotto:
        NON convertire mai il valore in un'altra unità di misura (es. per "3500 W"
        riporta value: "3500", unit: "W", MAI "3.5" e "kW" — la conversione è responsabilità
        dell'applicazione, non tua). Se il valore è un numero, scrivilo SEMPRE con il punto (.)
        come separatore decimale e MAI un separatore delle migliaia (es. "1200.5", MAI "1.200,5"
        né "1,200.5"). Se il valore non è un numero semplice (una frazione come "1/2", una sigla,
        un intervallo), scrivilo così come appare nel testo. Includi anche un livello di
        confidenza (intero 0-100) specifico per quell'attributo. Se non sei sicuro di un
        attributo, non includerlo.

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
              "confidence": 0,
              "attributes": [
                {
                  "key": "string",
                  "value": "string",
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
                $value = (string) $attribute->value;

                $unit = $attribute->unit !== null ? " {$attribute->unit}" : '';
                $source = $attribute->source ?? 'sconosciuta';

                return "{$attribute->key}={$value}{$unit} (origine: {$source})";
            })
            ->implode(', ');

        return " | attributi noti: {$parts}";
    }
}
