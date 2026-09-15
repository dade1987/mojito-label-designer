<?php

declare(strict_types=1);

namespace Mojito\Label;

/**
 * I valori di esempio che il layout dichiara sulle proprie sorgenti dati.
 *
 * Il designer li scrive sulla SORGENTE DATI, non sull'elemento. Chi stampa
 * dal server (pannello, postazione) passa solo i campi che conosce: senza
 * questo ripiego tutti gli altri uscirebbero vuoti e l'etichetta arriverebbe
 * muta, senza nessuna scritta.
 */
final class TemplateDefaults
{
    /**
     * @param  array<string, mixed>  $template
     * @return array<string, string>
     */
    public static function forTemplate(array $template): array
    {
        $sources = $template['dataSources'] ?? [];

        if (! is_array($sources)) {
            return [];
        }

        $defaults = [];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $name = TypeCaster::string($source['name'] ?? '');

            if ($name === '' || ! array_key_exists('defaultValue', $source)) {
                continue;
            }

            $defaults[$name] = TypeCaster::string($source['defaultValue']);
        }

        return $defaults;
    }
}
