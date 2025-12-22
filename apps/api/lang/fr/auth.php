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
    'unauthenticated' => 'Veuillez vous connecter pour continuer.',
    'token_expired' => 'Votre session a expiré. Veuillez vous reconnecter.',
    'token_invalid' => 'Jeton d\'authentification invalide.',
    'account_disabled' => 'Votre compte a été désactivé.',
    'email_not_verified' => 'Veuillez vérifier votre adresse e-mail.',
    'logout_success' => 'Vous avez été déconnecté avec succès.',
    'login_success' => 'Connexion réussie.',

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
