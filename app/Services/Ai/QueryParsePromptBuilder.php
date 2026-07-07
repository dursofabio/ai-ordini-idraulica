<?php

namespace App\Services\Ai;

/**
 * Builds the Anthropic Messages API payload used to parse a free-text search
 * query into a residual descriptive text plus explicit technical attributes
 * (US-048), anchored to the closed attribute registry
 * ({@see AttributeDefinitionCatalog}) the same way {@see ClassificationPromptBuilder}
 * anchors bulk classification to it (US-043): the model must use ONLY
 * registry keys and report the value exactly as read in the query, never
 * pre-converted. Any technical mention the model cannot confidently map to
 * a registry key must stay in the residual text instead of becoming an
 * invented filter (AC3). Numeric attribute filtering isn't supported for
 * now (search is being redesigned): the model is instructed to only report
 * textual attributes and leave numeric mentions in the residual text.
 */
class QueryParsePromptBuilder
{
    /**
     * Small, deterministic response (one recognized_text string + a short
     * attributes array): a single search query never needs anywhere near the
     * token budget of a batch classification call.
     */
    private const MAX_TOKENS = 1024;

    /**
     * Build the Messages API payload for parsing the given query with the
     * given model.
     *
     * @return array<string, mixed>
     */
    public function build(string $query, AttributeDefinitionCatalog $catalog, string $model): array
    {
        return [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->prompt($query, $catalog),
                ],
            ],
        ];
    }

    private function prompt(string $query, AttributeDefinitionCatalog $catalog): string
    {
        $registryText = $catalog->toPromptText();

        return <<<PROMPT
        Sei un motore di interpretazione per la ricerca in un catalogo di forniture idrauliche e
        termoidrauliche. Ricevi una query di ricerca in linguaggio naturale scritta da un utente e
        devi separare il testo descrittivo dagli eventuali attributi tecnici espliciti (dimensioni,
        unità di misura, materiali, ecc.).

        Usa ESCLUSIVAMENTE le chiavi del registro attributi riportato più sotto (chiave, tipo, unità
        canonica, descrizione): NON inventare mai chiavi libere. Se una menzione tecnica della query
        non è riconducibile con certezza a una chiave del registro, NON creare un filtro per quella
        menzione: lasciala semplicemente nel testo riconosciuto ("recognized_text"). Nel dubbio, non
        includere l'attributo.

        Per ogni attributo riconosciuto riporta il valore ESATTAMENTE come letto nella query. Riporta
        SOLO attributi testuali (es. "materiale": "inox"): se la menzione tecnica riguarda un attributo
        numerico del registro (es. una dimensione o un'unità di misura), NON creare un filtro per
        quell'attributo — lasciala nel testo riconosciuto ("recognized_text").

        "recognized_text" deve contenere il testo descrittivo residuo della query, cioè la query
        originale privata delle sole menzioni diventate un attributo esplicito riconosciuto — non
        riscrivere né tradurre il resto del testo.

        Registro attributi (chiave | tipo | unità canonica | descrizione):
        {$registryText}

        Query da interpretare:
        "{$query}"

        Rispondi ESCLUSIVAMENTE con un oggetto JSON valido, senza testo aggiuntivo, nel seguente formato:
        {
          "recognized_text": "string",
          "attributes": [
            {
              "key": "string",
              "value": "string"
            }
          ]
        }

        Il campo "attributes" può essere un array vuoto se non ci sono attributi tecnici espliciti
        nella query.
        PROMPT;
    }
}
