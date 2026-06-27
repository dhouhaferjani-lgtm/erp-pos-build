<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'Ces identifiants ne correspondent pas à nos enregistrements.',
    'password' => 'Le mot de passe fourni est incorrect.',
    'throttle' => 'Trop de tentatives de connexion. Veuillez réessayer dans :seconds secondes.',

    // Custom authentication messages
    'unauthorized' => 'Vous n\'êtes pas autorisé à effectuer cette action.',
    'permission_denied' => "Vous n'avez pas la permission pour « :ability ». Un administrateur peut l'accorder dans Paramètres → Rôles.",
    'permission_denied_generic' => 'Vous n\'avez pas la permission d\'effectuer cette action. Un administrateur peut accorder l\'accès dans Paramètres → Rôles.',
    'unauthenticated' => 'Veuillez vous connecter pour continuer.',
    'token_expired' => 'Votre session a expiré. Veuillez vous reconnecter.',
    'token_invalid' => 'Jeton d\'authentification invalide.',
    'account_disabled' => 'Votre compte a été désactivé.',
    'email_not_verified' => 'Veuillez vérifier votre adresse e-mail.',
    'logout_success' => 'Vous avez été déconnecté avec succès.',
    'login_success' => 'Connexion réussie.',

    // Connexion par e-mail d'abord (topology §9.7 — générique)
    'no_organizations' => 'Aucune organisation trouvée pour cet e-mail. Vérifiez votre e-mail ou contactez votre administrateur.',
    'invalid_credentials' => 'Les identifiants fournis sont incorrects.',
    'account_not_active' => "Votre compte n'est pas actif. Veuillez contacter le support.",
    'organization_unavailable' => 'Cette organisation est actuellement indisponible. Veuillez contacter le support.',
    // P1-1 — la réinitialisation du mot de passe exige un lien qualifié par locataire et déchiffrable.
    'invalid_reset_link' => 'Ce lien de réinitialisation du mot de passe est invalide ou a expiré. Veuillez en demander un nouveau.',

    // Email verification
    'verify_email_subject' => 'Vérifiez votre adresse e-mail',
    'verify_email_greeting' => 'Bonjour :name,',
    'verify_email_body' => 'Veuillez cliquer sur le bouton ci-dessous pour vérifier votre adresse e-mail.',
    'verify_email_button' => 'Vérifier l\'adresse e-mail',
    'verify_email_expiry' => 'Ce lien de vérification expirera dans :hours heures.',
    'verify_email_salutation' => 'Cordialement, L\'équipe AutoERP',
    'verify_email_success' => 'Adresse e-mail vérifiée avec succès.',
    'verify_email_invalid_token' => 'Jeton de vérification invalide.',
    'verify_email_expired_token' => 'Le jeton de vérification a expiré. Veuillez en demander un nouveau.',
    'verify_email_already_verified' => 'L\'adresse e-mail est déjà vérifiée.',
    'verify_email_sent' => 'E-mail de vérification envoyé avec succès.',
];
