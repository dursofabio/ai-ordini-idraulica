<?php

namespace App\Services\Ai;

use App\Models\EnrichmentProposal;
use App\Models\Product;
use App\Models\ProductAttribute;

/**
 * Builds the Anthropic Messages API payload used to deeply enrich a single
 * product (US-051): a markdown extended description, a proposed product
 * type, plus a full technical fact sheet with free-form attribute keys — the
 * AI is not shown the {@see AttributeVocabulary} registry and is not
 * constrained to it, so a key
 * simply names the type of characteristic (e.g. `potenza`) without the unit
 * embedded in it, since the unit is already its own `unit` field. Always
 * targets `model_smart`, since this is a single, on-demand, higher-value
 * call rather than a batch.
 *
 * Product descriptions are internal catalog data (not third-party user
 * input) and are embedded verbatim, same reasoning as
 * {@see ClassificationPromptBuilder}. The free-form markdown description this
 * prompt produces is a larger surface than classification's constrained enum
 * fields, so a reviewer skimming rich prose is more likely to rubber-stamp
 * injected content than one checking a taxonomy value — but
 * {@see DeepEnrichmentResponseValidator} still only re-validates shape and
 * confidence ranges, not content, and the result is never written to the
 * product directly: it always lands as a `pending`
 * {@see EnrichmentProposal} that a human must confirm, so the
 * worst case is a misleading proposal in the review queue, not a bad write.
 */
class DeepEnrichmentPromptBuilder
{
    /**
     * Output token budget for a single deep-enrichment call: a rich markdown
     * description plus a full attribute list costs noticeably more than the
     * short `enriched_description` produced by bulk classification.
     */
    private const MAX_TOKENS = 3072;

    /**
     * Build the Messages API payload for deeply enriching the given product
     * with the given model.
     *
     * @return array<string, mixed>
     */
    public function build(Product $product, string $model): array
    {
        return [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->prompt($product),
                ],
            ],
        ];
    }

    private function prompt(Product $product): string
    {
        $context = sprintf(
            'codice_articolo: %s | descrizione: %s%s%s',
            $product->codice_articolo,
            trim((string) ($product->description_clean ?? $product->description_raw)),
            $product->product_type !== null ? " | tipo prodotto: {$product->product_type}" : '',
            $this->knownAttributesText($product),
        );

        return <<<PROMPT
        Sei un redattore tecnico di catalogo per una ditta di forniture idrauliche e termoidrauliche.
        Per il prodotto sotto elencato, scrivi una descrizione estesa in formato markdown (titoli,
        elenchi puntati dove utile) che ne illustri caratteristiche, funzionamento e utilizzo tipico,
        proponi il tipo di prodotto e l'elenco più completo possibile delle sue caratteristiche
        tecniche.

        Prodotto:
        {$context}

        Il campo "tipo_prodotto" deve contenere ESCLUSIVAMENTE il nome/tipo del prodotto (es.
        "Caldaia a condensazione"): MAI la marca, la famiglia, la sottofamiglia o un valore di
        attributo tecnico. Se il prodotto ha già un tipo prodotto noto (riportato sopra), confermalo
        se corretto oppure proponine uno più preciso; se non hai basi sufficienti, restituisci null.

        Per ogni caratteristica tecnica, la chiave ("key") deve indicare SOLO il tipo di
        caratteristica, in italiano e in snake_case, SENZA includere l'unità di misura nel nome
        (es. "potenza", MAI "potenza_kw" o "potenza_watt": l'unità va sempre e solo nel campo "unit"
        a parte). NON includere MAI tra gli attributi il codice articolo, la descrizione del
        prodotto o il tipo di prodotto: sono già gestiti come campi propri del prodotto, non sono
        caratteristiche tecniche.

        Il valore ("value") è sempre una stringa, riportata ESATTAMENTE come letta nel testo del prodotto:
        NON convertire mai il valore nell'unità di un altro sistema di misura (la
        conversione è responsabilità dell'applicazione, non tua). Se il valore è un numero, scrivilo
        SEMPRE con il punto (.) come separatore decimale e MAI un separatore delle migliaia (es.
        "1200.5", MAI "1.200,5" né "1,200.5"). Se il valore non è un numero semplice (una frazione
        come "1/2", una sigla, un intervallo), scrivilo così come appare nel testo. Includi un
        livello di confidenza (intero 0-100) specifico per quell'attributo.

        IMPORTANTE: se i dati di partenza sono minimi (ad esempio solo codice articolo e una breve
        descrizione di listino), NON inventare caratteristiche tecniche o dettagli che non puoi
        dedurre con ragionevole certezza dal testo. In quel caso dichiara una confidenza complessiva
        bassa (inferiore a 60) invece di produrre contenuto inventato, e limita "attributes" a un
        array vuoto se non hai basi sufficienti.

        Rispondi ESCLUSIVAMENTE con un oggetto JSON valido, senza testo aggiuntivo, nel seguente formato:
        {
          "descrizione_estesa": "string|null",
          "tipo_prodotto": "string|null",
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

        Il campo "attributes" può essere un array vuoto se non ci sono attributi da proporre.
        PROMPT;
    }

    /**
     * Renders the product's already-known technical attributes as inline
     * prompt context, mirroring {@see ClassificationPromptBuilder::knownAttributesText()}.
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
