# Note à l'attention du conseil fiscal — Architecture POS, conformité fiscale et protection des données

**Destinataire :** Conseil fiscal et conseil en protection des données, **[Pays]**
**Émetteur :** Équipe technique Syneriva / Otospex
**Date :** 19 avril 2026
**Objet :** Demande d'avis juridique écrit sur la conformité de notre architecture POS actuelle au regard de la législation de **[Pays]** en matière de certification fiscale et de protection des données à caractère personnel
**Classification :** Confidentiel — couvert par le secret professionnel applicable aux conseils en [Pays]

> **Note d'usage.** Ce document est un modèle. Avant envoi à un conseil d'une juridiction donnée, remplacer chaque occurrence de **[Pays]** par le nom du pays concerné et compléter les champs **[entre crochets]** en fin de document (point de contact, localisation du serveur, délai). Une version anglaise équivalente est maintenue en parallèle sous `tax-counsel-brief.en.md`.

---

## 1. Synthèse

Nous exploitons un système de caisse (POS) déployé en France, au Royaume-Uni, en Italie, en Tunisie, au Maroc et en Côte d'Ivoire, sous deux marques : **IziPOS** pour le commerce de détail et **Otospex** pour le secteur automobile. L'application fonctionne en mode *offline-first* sur le terminal du commerçant, avec une synchronisation asynchrone vers un serveur central.

**L'intégrité fiscale** est assurée par une chaîne de hachage SHA-256 qui relie cryptographiquement chaque ticket au ticket précédent ; toute altération d'un ticket antérieur invalide l'ensemble de la chaîne suivante et est automatiquement détectée à la synchronisation suivante.

**La confidentialité** de la base de données locale est actuellement garantie par une politique de chiffrement intégral du disque (*full-disk encryption*, FDE) obligatoirement activée sur chaque terminal déployé (FileVault sous macOS, BitLocker sous Windows, LUKS sous Linux), associée à une procédure documentée d'intégration des terminaux en production.

Nous sollicitons votre **avis écrit** sur la conformité de cette architecture aux obligations de certification fiscale et de protection des données à caractère personnel applicables en **[Pays]** à la date ci-dessus.

---

## 2. Questions soumises à votre avis

Nous vous remercions de bien vouloir répondre à chacune des questions ci-dessous par *oui / non / avec réserves*, en citant, le cas échéant, le fondement légal ou réglementaire pertinent.

1. **Certification fiscale.** Le régime fiscal de [Pays] exige-t-il que la base de données locale du terminal POS soit *chiffrée au repos au niveau applicatif*, ou bien le chiffrement intégral du disque de la machine hôte, combiné à une chaîne de hachage à épreuve de manipulation appliquée à chaque enregistrement fiscal, est-il suffisant pour satisfaire à l'exigence locale d'*inaltérabilité* (ou équivalente) ?

2. **Protection des données personnelles.** En vertu de la législation de [Pays] en matière de protection des données à caractère personnel (équivalent local de l'article 32 du RGPD), le chiffrement de la base locale est-il une mesure technique *obligatoire*, ou le chiffrement intégral du disque constitue-t-il un équivalent acceptable lorsqu'il est déployé avec les mesures de contrôle d'accès décrites en section 6 ?

3. **Durée de conservation.** Quelle est la durée de conservation obligatoire des enregistrements fiscaux POS en [Pays] ? Une durée de conservation distincte, et potentiellement plus courte, s'applique-t-elle aux *données personnelles* rattachées à ces enregistrements (identifiants de fidélité, noms des clients) — et le cas échéant, quelle étape de pseudonymisation ou de suppression est attendue ?

4. **Notification de violation.** Si un terminal POS est perdu ou volé alors que le chiffrement intégral du disque est activé et correctement configuré (voir section 6.1), s'agit-il d'une violation de données à notifier en [Pays] ? Quels en sont le fait générateur et le délai de notification applicables ?

5. **Obligations récurrentes.** Existe-t-il des certifications annuelles, des audits ou des déclarations obligatoires auprès de l'administration fiscale en [Pays] que nous devons maintenir pour rester conformes au régime POS local ? Une déclaration à l'autorité de protection des données est-elle requise ?

6. **Résidence des données.** La législation de [Pays] impose-t-elle que les enregistrements fiscaux ou les données personnelles soient *physiquement stockés* sur son territoire national ? Notre serveur central est actuellement hébergé en **[Data center européen — à confirmer]**.

---

## 3. Contexte commercial

- **Otospex** sert des ateliers B2B du secteur automobile ; **IziPOS** s'adresse au commerce de détail classique (cafés, petites épiceries, commerces spécialisés).
- Objectif de déploiement à horizon un an : 50 à 200 terminaux répartis sur les six pays précités.
- Chaque terminal fonctionne sur du matériel dédié (Mac mini, mini-PC Windows ou NUC Linux) physiquement installé au comptoir du commerçant — il ne s'agit jamais d'un ordinateur portable personnel partagé.
- Chaque vente, remboursement et clôture journalière (ticket Z) constitue un enregistrement fiscal.
- Les données clients capturées par vente se limitent à : identifiant de membre fidélité (facultatif, sur *opt-in*), nom du client imprimé sur le ticket (facultatif) et métadonnées de moyen de paiement (voir section 7).

---

## 4. Architecture du système

### 4.1 Conception en deux niveaux

Chaque terminal exécute une application de bureau (*framework* Tauri, Rust + web) qui maintient sa propre base SQLite locale contenant les tickets, le stock et les sessions opérateur. Un serveur API central basé sur Laravel (PHP) conserve la copie faisant foi de l'ensemble des enregistrements après synchronisation.

**Au niveau du terminal (*offline-first*) :**
- L'opérateur s'authentifie par code PIN numérique.
- Le caissier saisit le panier et encaisse le paiement.
- Le ticket est écrit immédiatement dans la base SQLite locale (latence médiane 5 à 50 millisecondes).
- Un planificateur en arrière-plan envoie les nouveaux tickets au serveur central dès qu'une connexion réseau est disponible.

**Au niveau du serveur central :**
- Déduplication par clé d'idempotence (un ticket donné généré côté client n'est accepté qu'une seule fois, même en cas de retransmission).
- Vérification côté serveur de la chaîne de hachage.
- Tout ticket rejeté déclenche une alerte rouge persistante « chaîne rompue » sur l'interface du terminal.

### 4.2 Tolérance au mode hors ligne

Nous considérons que le réseau du commerçant peut être instable. Le système est conçu pour fonctionner pendant des heures — voire des jours, théoriquement — sans connectivité. Les tickets s'accumulent localement ; la file de synchronisation se vide au rétablissement de la connexion ; tout conflit ou rupture de chaîne est immédiatement porté à l'attention de l'opérateur à l'écran.

---

## 5. Mesures d'intégrité — la chaîne de hachage

### 5.1 Construction

Chaque ticket comporte un champ `fiscal_hash` calculé comme suit :

```
fiscal_hash = SHA-256(
    hash_du_ticket_précédent
  || numéro_du_ticket
  || date_et_heure
  || total
  || devise
  || ventilation_TVA
  || ventilation_des_paiements
)
```

Le premier ticket de chaque terminal est initialisé par le serveur à partir d'un *hash* d'origine propre à l'événement d'activation du terminal. Chaque ticket suivant dépend du *hash* du ticket précédent, formant une chaîne en écriture seule (*append-only*).

### 5.2 Propriétés

- **Détection de manipulation.** Toute altération du contenu d'un ticket antérieur — qu'elle soit accidentelle ou frauduleuse — produit un *hash* différent, qui rompt la chaîne. Le ticket *suivant*, dont le *hash* dépend du ticket altéré, échoue à la vérification côté serveur de manière automatique.
- **Non-répudiation.** Un ticket, une fois écrit, ne peut être modifié ni supprimé silencieusement. Les annulations et remboursements sont enregistrés comme *nouveaux* enregistrements fiscaux, jamais comme modifications d'enregistrements existants.
- **Séquence unidirectionnelle.** Chaque terminal maintient un entier monotone `hash_sequence` ; aucun ticket ne peut être inséré rétroactivement et toute discontinuité est détectée à la synchronisation.

### 5.3 Conséquences opérationnelles d'une rupture de chaîne

Si le serveur détecte une rupture de chaîne, le terminal :
- Affiche une **bannière rouge d'alerte persistante** (« Chaîne de tickets fiscale rompue — ne plus encaisser. Contacter le support. »).
- Conserve l'enregistrement litigieux à des fins d'audit (il n'est jamais supprimé, même après que l'alerte a été acquittée).
- Journalise l'événement avec horodatage et numéro du dernier ticket validé.

Ce dispositif correspond, selon notre analyse, à l'exigence d'inaltérabilité de la norme française NF525, que la DGFiP considère satisfaite par un mécanisme de *hachage chaîné*. **La question 1 ci-dessus porte sur la question de savoir si un raisonnement équivalent s'applique en [Pays].**

---

## 6. Mesures de confidentialité — chiffrement du disque aujourd'hui, SQLCipher envisagé

### 6.1 État actuel (au 19 avril 2026)

- La base SQLite locale **n'est pas** chiffrée au niveau applicatif.
- Chaque terminal déployé dispose du **chiffrement intégral du disque (FDE)** activé : FileVault (macOS), BitLocker (Windows) ou LUKS (Linux).
- La procédure d'intégration en production comprend une étape de vérification matérielle : aucun terminal n'est autorisé à entrer en production tant que le FDE n'a pas été confirmé actif et que la clé de récupération n'a pas été déposée dans un système interne de gestion des clés.
- Le verrouillage automatique de l'écran est configuré à un délai maximal de 5 minutes ; la connexion automatique est désactivée.
- Un audit trimestriel, mené par l'équipe de déploiement, vérifie sur un échantillon aléatoire de 10 % du parc que le FDE demeure activé.

### 6.2 État projeté (P1)

Nous prévoyons d'ajouter **SQLCipher** (chiffrement AES-256 de la base SQLite au niveau applicatif) comme seconde ligne de défense. Cette évolution est estimée à 3 à 5 jours-homme d'ingénierie et sera planifiée en fonction de votre avis :
- Si le FDE est jugé suffisant en [Pays], la mise en œuvre de SQLCipher est reportée.
- Si le FDE n'est **pas** jugé suffisant, SQLCipher devient un prérequis bloquant pour le déploiement en [Pays].

### 6.3 Modèle de menaces

| Scénario | Couvert par le FDE aujourd'hui | Apport de SQLCipher |
|---|---|---|
| Terminal volé éteint | Oui | Aucun gain marginal |
| Terminal volé en session fermée | Oui | Aucun gain marginal |
| Terminal volé en session ouverte | Non | Oui (le fichier de base reste chiffré au repos) |
| Accès physique d'un initié pendant les heures d'ouverture | Partiellement (contrôles d'accès) | Oui, tant que l'application POS n'est pas en cours d'exécution |
| Logiciel malveillant sur un terminal en fonctionnement | Non | Non — les clés de chiffrement sont disponibles au processus actif |

Le FDE couvre la grande majorité des scénarios réels de perte ou de vol de terminal. La lacune résiduelle concerne un terminal volé en cours d'utilisation active — scénario que la plupart des régulateurs traitent comme une compromission de session plutôt que comme un défaut de protection des données au repos.

---

## 7. Inventaire des données

Données stockées dans la base locale du terminal :

| Catégorie | Contenu | Sensibilité réglementaire |
|---|---|---|
| Enregistrements fiscaux | Numéro de ticket, lignes, ventilation TVA, totaux, *hash* fiscal, ventilation des paiements | Droit fiscal (conservation + inaltérabilité) |
| Identifiants opérateur | Code PIN, stocké uniquement sous forme de *hash* bcrypt — le code en clair n'est jamais persistant | Protection des données (identifiant) ; PCI si requalifié en *credential* |
| Métadonnées de paiement | Identifiant de moyen de paiement, quatre derniers chiffres de la carte, référence d'autorisation | **Hors champ** PCI DSS : nous ne stockons pas de PAN, de piste magnétique, de CVV ni de blocs PIN. Les données cartes complètes sont traitées par un terminal de paiement séparé, certifié indépendamment. |
| Données clients | Nom du client imprimé sur le ticket (facultatif) ; identifiant de membre fidélité le cas échéant | Protection des données |
| Données bancaires | Métadonnées de caisse et de comptes bancaires (références internes, IBAN) | Secret bancaire (lois nationales) |
| Catalogue produits | Noms, prix, catégories | Sensibilité commerciale |

Aucune donnée biométrique, de santé ou autre donnée à caractère personnel de catégorie particulière n'est collectée.

---

## 8. Textes potentiellement applicables que nous avons identifiés

Merci de confirmer, corriger ou compléter la liste suivante pour **[Pays]** :

- **France :** certification NF525 des systèmes de caisse ; articles 32, 33 et 34 du RGPD (sécurité et notification de violation).
- **Royaume-Uni :** règles HMRC *Making Tax Digital* en matière de conservation ; UK GDPR.
- **Italie :** certification *Registratore Telematico* (RT) ; RGPD ; règles de transmission télématique de l'Agenzia delle Entrate.
- **Tunisie :** régime de certification fiscale des systèmes de caisse (le cas échéant) ; cadre de protection des données personnelles INPDP.
- **Maroc :** loi 09-08 (protection des données), autorité CNDP ; modernisation fiscale en cours (SIMPL et initiatives connexes).
- **Côte d'Ivoire :** cadre de protection des données ; mise en place de la facturation électronique FNE.

---

## 9. Points particuliers sur lesquels nous sollicitons votre éclairage

1. **Séquestre des clés de récupération.** Nous déposons les clés de récupération du chiffrement intégral du disque dans un système interne de gestion des clés. Cette pratique crée-t-elle une obligation supplémentaire en [Pays] — déclaration à l'autorité de protection des données, obligation de localisation nationale du séquestre, contraintes de conservation portant sur le séquestre lui-même ?

2. **Flux transfrontaliers.** Lorsque le terminal fonctionne en [Pays] mais que le serveur central est dans l'Union européenne, la législation de [Pays] impose-t-elle que les enregistrements fiscaux ou les données personnelles demeurent physiquement sur le territoire national (résidence des données) ?

3. **Rotation du personnel.** Lorsqu'un opérateur quitte l'entreprise, son PIN est révoqué mais les tickets historiques lui restent attribués via un `operator_id` stable. Cette pratique est-elle conforme aux exigences de *minimisation des données* en [Pays], ou convient-il de pseudonymiser le nom de l'opérateur à l'issue d'un délai défini ?

4. **Réponse à incident.** Merci de nous confirmer, pour chacun des cas suivants, le délai de notification et l'autorité compétente en [Pays] :
   - Rupture de chaîne avérée suggérant une manipulation ;
   - Perte ou vol d'un terminal dont le FDE est activé ;
   - Perte ou vol d'un terminal dont le FDE est désactivé (manquement du commerçant à la politique).

---

## 10. Annexes disponibles sur demande

- **Annexe A.** Audit technique complet de la décision relative au chiffrement au repos, comprenant l'inventaire des colonnes sensibles et l'analyse du modèle de menaces. Référence interne : `apps/pos/docs/encryption-at-rest-audit.md`.
- **Annexe B.** Protocole de recette manuelle illustrant l'alerte de rupture de chaîne et le démarrage à froid d'un terminal. Référence interne : `apps/pos/docs/manual-qa-offline-first.md`.
- **Annexe C.** Code source du calcul de la chaîne de hachage et de la logique de vérification côté serveur. Peut être communiqué sous accord de confidentialité (*NDA*) si cela s'avère pertinent pour votre analyse.

---

## 11. Délai souhaité

Nous vous serions reconnaissants de bien vouloir nous adresser votre avis écrit dans un délai de **[14 jours calendaires]** à compter de la réception de la présente note. Si ce délai n'est pas réalisable, merci de nous indiquer la date la plus proche à laquelle vous pourriez nous faire parvenir une réponse écrite et, le cas échéant, la possibilité d'une opinion verbale préliminaire portant sur la question 1 (certification fiscale) dans l'intervalle.

Si des éléments techniques complémentaires vous sont utiles — échantillon de base de données de travail, démonstration du logiciel terminal ou accès à un serveur de test — nous pouvons les mettre à disposition dans un délai de **[deux jours ouvrés]** à compter de votre demande.

---

**Point de contact :**

- Nom : **[Nom]**
- Fonction : **[Fonction]**
- Courriel : **[courriel]**
- Téléphone : **[téléphone]**

**Classification du document :** Confidentiel. Couvert par le secret professionnel applicable au conseil juridique en [Pays].
