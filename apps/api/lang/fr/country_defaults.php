<?php

declare(strict_types=1);

return [
    'auth' => [
        'unauthenticated' => 'Authentification requise.',
        'deactivated' => 'Ce compte administrateur a été désactivé.',
        'external_editors_disabled' => 'Les éditeurs externes des paramètres pays ne sont pas activés.',
    ],
    'errors' => [
        'template_conflict' => 'L’opération est incompatible avec l’état actuel du modèle.',
        'template_validation' => 'Le modèle n’a pas satisfait les règles de certification.',
        'template_validation_detail' => 'Une ou plusieurs règles de certification ont échoué.',
        'assignment_conflict' => 'L’affectation est incompatible avec les règles de certification du modèle.',
        'editor_conflict' => 'L’opération est incompatible avec l’état actuel du compte éditeur.',
        'provisioning_unavailable' => 'La création de l’entreprise est temporairement indisponible. Réessayez plus tard ou contactez le support.',
    ],
    'catalog' => ['generic_fallback' => 'Modèle générique'],
];
