<?php

declare(strict_types=1);

namespace Mojito\Label;

use RuntimeException;

/**
 * Il salvataggio avrebbe scritto sopra un layout gia' presente sul server
 * senza che il chiamante avesse chiesto di sovrascriverlo.
 *
 * Da API diventa un 409: il designer lo distingue dagli altri errori e
 * propone "Salva con nome...".
 */
final class TemplateExistsException extends RuntimeException
{
    public function __construct(
        public readonly string $templateId,
        public readonly string $existingName,
    ) {
        parent::__construct(
            'Sul server esiste già il layout «'.$existingName.'» con questo identificativo: '
            .'ricarica l\'elenco e salva di nuovo per sovrascriverlo, oppure usa «Salva con nome…».'
        );
    }
}
