# Guide de démarrage et de recette — Synerivia

**Version :** 1.0 — 28 juillet 2026
**Pour qui :** la personne chargée de tester le système avant la mise en service du premier client.
**Durée estimée :** 1 à 2 journées.

---

## 1. À quoi sert ce document

Vous allez utiliser le système comme le ferait un vrai commerçant : créer une société, saisir des produits, recevoir une commande fournisseur, vendre en caisse, clôturer la journée, puis vérifier que les chiffres se retrouvent correctement dans le back-office.

Le but n'est **pas** de valider que tout est parfait. Le but est de **trouver ce qui ne va pas** avant qu'un vrai client ne le découvre. Un problème que vous notez aujourd'hui coûte une heure ; le même problème découvert par un client en pharmacie coûte une journée et de la confiance.

Deux documents vont ensemble :

- **ce guide**, qui explique le système et vous accompagne pas à pas ;
- **le fichier `smoke-test-fr.csv`**, votre feuille de test, à remplir au fur et à mesure.

Ne lisez pas tout le guide d'un bloc. Lisez la section 2 (elle est courte et vous fera gagner du temps), puis avancez section par section en remplissant le CSV en parallèle.

---

## 2. Comprendre le système en 5 minutes

### 2.1 Le vocabulaire

| Terme | Ce que ça veut dire concrètement |
|---|---|
| **Société** | L'entreprise du client. Elle a un nom, une devise, un identifiant fiscal. |
| **Emplacement** | Un lieu physique où il y a du stock : un dépôt, une boutique. Une société peut en avoir plusieurs. Le stock est toujours rattaché à un emplacement précis. |
| **Utilisateur** | Une personne qui se connecte. Chaque utilisateur a un **rôle**. |
| **Rôle** | Ce que la personne a le droit de faire. Un caissier ne voit pas les mêmes écrans qu'un gérant. |
| **Terminal** | Une caisse. Chaque caisse est rattachée à un emplacement et doit être activée une fois. |
| **Poste (shift)** | Une session de caisse : on l'ouvre le matin avec un fonds de caisse, on la ferme le soir en comptant l'argent. |

### 2.2 Les deux applications

Le système se compose de **deux applications distinctes** — c'est le point le plus important à comprendre :

**Le back-office (dans le navigateur web).** C'est le bureau du gérant : produits, stock, achats, fournisseurs, rapports, paramètres. On y prépare et on y contrôle.

**La caisse (application installée sur l'ordinateur de la caisse).** C'est le comptoir : on encaisse, on rend la monnaie, on imprime le ticket, on clôture la journée. On y vend.

Les deux se parlent, mais **pas instantanément**. La caisse continue de fonctionner même sans internet : elle enregistre les ventes chez elle et les envoie au serveur dès que la connexion revient. C'est voulu — une pharmacie ne doit jamais s'arrêter de vendre parce que la connexion est tombée.

**Conséquence pratique pour vos tests :** si vous faites une vente en caisse et qu'elle n'apparaît pas immédiatement dans le back-office, **ce n'est pas forcément un bug**. Attendez une minute, rafraîchissez la page. Si après quelques minutes elle n'est toujours pas là, là c'est un problème : notez-le.

### 2.3 Ce qui rend ce système particulier

Chaque ticket de caisse est **signé électroniquement** et chaîné au précédent, comme les maillons d'une chaîne. C'est une exigence légale : cela prouve qu'aucune vente n'a été supprimée après coup. Vous n'avez rien à faire pour cela, mais cela explique pourquoi certaines opérations (annuler un ticket, par exemple) ne suppriment jamais rien : elles créent une opération inverse. **Si vous voyez qu'un ticket annulé reste visible quelque part, c'est normal.**

---

## 3. Avant de commencer

Vérifiez que vous avez :

- [ ] L'adresse du site de test et un compte administrateur (identifiant + mot de passe).
- [ ] L'application caisse installée sur un poste, avec son code d'activation.
- [ ] Le fichier `smoke-test-fr.csv` ouvert dans Google Sheets (voir section 5).
- [ ] De quoi faire des captures d'écran.
- [ ] Une imprimante à tickets, si le client en aura une.

Si l'un de ces éléments manque, demandez-le avant de commencer — ne contournez pas.

**Une règle absolue :** vous travaillez sur un environnement de test. Vous ne pouvez rien casser d'irréparable. N'ayez donc **aucune hésitation à essayer des choses bizarres** : saisir une quantité négative, cliquer deux fois sur « Valider », fermer l'application au milieu d'une vente. C'est exactement là que se cachent les bugs.

---

## 4. Partie 1 — La mise en route

Suivez cet ordre. Chaque étape dépend de la précédente : on ne peut pas vendre un produit qui n'existe pas, ni le vendre depuis un emplacement sans stock.

### Étape 1 — Se connecter et découvrir

Connectez-vous au back-office. Vous arrivez sur **Tableau de bord**.

Prenez deux minutes pour parcourir le menu de gauche sans rien modifier. Vous y trouverez notamment : **Catalogue**, **Inventaire**, **Achats**, **Point de vente**, **Banque & Paiements**, **Paramètres**.

> **À noter dans le CSV :** le menu est-il compréhensible ? Y a-t-il des intitulés que vous ne comprenez pas ? Un mot mal traduit ou un mélange français/anglais est un vrai problème à signaler — le client le verra aussi.

### Étape 2 — La société et les emplacements

Allez dans **Paramètres**, puis la société. Vérifiez le nom, la devise, l'identifiant fiscal.

Créez ensuite les **Emplacements** : pour ce test, créez-en **trois** — un dépôt et deux boutiques. C'est la configuration typique d'un client avec plusieurs points de vente.

> **Points de vigilance :** les champs obligatoires sont-ils signalés clairement ? Que se passe-t-il si vous laissez un champ vide et validez ? Le message d'erreur est-il en français et compréhensible ?

### Étape 3 — Les utilisateurs et les rôles

Créez au moins **deux utilisateurs** en plus du vôtre :

- un **gérant** (accès large) ;
- un **caissier** (accès restreint).

Puis faites un test essentiel : **déconnectez-vous et reconnectez-vous en caissier.** Le caissier doit voir **moins de choses** que le gérant. S'il peut accéder aux paramètres de la société, aux achats ou aux rapports financiers, **c'est un problème grave** — notez-le en gravité « Bloquant ».

### Étape 4 — Taxes et modes de paiement

Toujours dans **Paramètres** : vérifiez les taux de TVA et les **Modes de paiement** (espèces, carte, etc.).

Ces réglages conditionnent tout le reste. Si un taux de TVA est faux ici, toutes les ventes seront fausses.

### Étape 5 — Les produits

Allez dans **Catalogue → Produits**. Créez **une dizaine de produits à la main** — ne les importez pas, la saisie manuelle est justement ce qu'on veut tester.

Variez volontairement :

- des produits vendus à l'unité (une boîte, un tube) ;
- au moins un produit vendu **au poids ou au volume** (kilogramme, litre) ;
- au moins un produit avec une **date de péremption / un lot**, typique en parapharmacie.

> **Le point le plus important de cette étape :** chaque produit doit avoir une **unité de mesure** correcte. Un produit vendu à l'unité ne doit pas accepter « 2,5 » en quantité. Un produit au poids doit accepter les décimales. Testez les deux cas et notez le résultat.

### Étape 6 — Le stock initial

Deux façons d'avoir du stock, testez-les toutes les deux :

**a) La réception fournisseur (le cas normal).** Allez dans **Achats** : créez un **fournisseur**, puis une **commande fournisseur**, puis un **bon de réception**. Réceptionnez la marchandise.

Vérifiez ensuite dans **Inventaire → Niveaux de stock** que les quantités sont bien arrivées, **au bon emplacement**.

**b) Le stock de départ.** Pour un client qui démarre avec du stock déjà en rayon, on saisit un stock initial sans commande. Testez cette voie sur deux ou trois produits.

> **Point de vigilance :** réceptionnez volontairement **une quantité différente** de celle commandée (par exemple 8 reçus sur 10 commandés). Le système doit l'accepter et le signaler clairement, pas le refuser en silence ni prétendre que 10 sont arrivés.

### Étape 7 — La caisse

Allez dans **Point de vente → Terminaux** et créez un terminal rattaché à l'une des boutiques.

Ouvrez ensuite l'application caisse et activez-la avec son code. Une fois activée, elle doit afficher la liste des caissiers.

Fermez complètement l'application, puis rouvrez-la : **elle ne doit pas redemander le code d'activation.**

---

## 5. Partie 2 — Le test de recette

Vous avez maintenant un système utilisable. Passez au fichier **`smoke-test-fr.csv`**.

### 5.1 Préparer votre feuille

1. Ouvrez [Google Sheets](https://sheets.google.com) → **Fichier** → **Importer** → **Importer** le fichier `smoke-test-fr.csv`.
2. Choisissez « Remplacer la feuille de calcul » et, pour le séparateur, « Détecter automatiquement ».
3. Figez la première ligne (**Affichage → Figer → 1 ligne**) pour garder les en-têtes visibles.

Les colonnes **Domaine**, **Étape**, **Action** et **Résultat attendu** sont déjà remplies : c'est votre programme de test. Vous remplissez uniquement :

| Colonne | Ce que vous écrivez |
|---|---|
| **Résultat obtenu** | Ce qui s'est réellement passé. Soyez factuel : « le total affiche 12,500 au lieu de 12,000 ». |
| **Statut** | `OK`, `KO` ou `Bloqué` (voir 5.2). |
| **Gravité** | Uniquement si `KO` ou `Bloqué` (voir 5.3). |
| **Commentaire** | Tout ce qui aide à reproduire : le produit utilisé, le montant saisi, l'heure. |
| **Capture** | Le nom de votre fichier de capture d'écran, ou un lien Drive. |

### 5.2 Les trois statuts

- **OK** — le résultat obtenu correspond au résultat attendu.
- **KO** — ça ne correspond pas. **Vous continuez quand même** le test suivant.
- **Bloqué** — vous ne pouvez pas faire l'étape du tout (bouton absent, page en erreur, application figée). Le test s'arrête pour cette branche.

Dans le doute entre OK et KO, mettez **KO** et expliquez. Un faux KO nous coûte cinq minutes de vérification ; un vrai problème classé OK nous coûte un client.

### 5.3 Les quatre niveaux de gravité

| Gravité | Définition | Exemple |
|---|---|---|
| **Bloquant** | Le commerce ne peut pas fonctionner. On ne met pas en service tant que ce n'est pas corrigé. | Impossible d'encaisser. Le caissier accède aux réglages de la société. |
| **Majeur** | Ça fonctionne mais un chiffre est faux, ou une opération courante est très pénible. | Le stock ne diminue pas après une vente. Le total TVA est faux de quelques millimes. |
| **Mineur** | Gênant mais contournable. | Une colonne mal alignée. Un tri qui ne marche pas. |
| **Cosmétique** | Purement visuel ou rédactionnel. | Un texte en anglais. Une faute d'orthographe. |

**Tout ce qui touche à un montant, une quantité ou un stock est au minimum « Majeur ».** Jamais « Mineur ». Les chiffres, c'est le métier.

### 5.4 À la fin

**Fichier → Télécharger → Valeurs séparées par des virgules (.csv)**, et renvoyez le fichier. Ne renvoyez pas de lien Sheets : nous réimportons le CSV directement.

---

## 6. Problèmes déjà connus — ne les signalez pas

Ces points sont **déjà identifiés et en cours de correction**. Les retrouver ne nous apprend rien ; ne perdez pas de temps dessus.

1. **La saisie des quantités d'inventaire physique se fait sur l'application mobile**, pas dans le navigateur. Le back-office sert à préparer, contrôler et valider l'inventaire — pas à saisir les quantités rayon par rayon. Si vous n'avez pas l'application mobile, sautez les lignes marquées « Mobile requis » dans le CSV.

2. **Le blocage des ventes pendant un inventaire ne prend effet qu'après redémarrage de la caisse.** Si vous lancez un inventaire « bloquant » et que la caisse continue d'accepter des ventes, c'est le défaut connu. Correction en cours.

3. **Les boutons d'export PDF/Excel du rapport d'écarts d'inventaire sont masqués.** C'est volontaire, la fonction n'est pas terminée.

4. **Les textes en arabe sont incomplets** sur l'inventaire physique. Testez en français.

En revanche, **tout autre écran en anglais est à signaler** (gravité Cosmétique).

---

## 7. Conseils pour un test utile

**Testez comme un utilisateur pressé, pas comme un informaticien.** Cliquez vite, revenez en arrière, changez d'avis en cours de vente. Les clients font ça toute la journée.

**Essayez systématiquement de mal faire :** vendre plus que le stock disponible, saisir une quantité à virgule sur un produit à l'unité, encaisser moins que le total, fermer la caisse sans compter l'argent. Le système doit vous en empêcher **avec un message clair**, ou l'accepter volontairement — mais jamais planter ni accepter en silence.

**Coupez le réseau.** Débranchez le Wi-Fi de la caisse, faites deux ou trois ventes, rebranchez. Les ventes doivent remonter dans le back-office, **sans doublon**. C'est un des tests les plus importants du lot.

**Notez l'heure** de chaque anomalie. Cela nous permet de retrouver la trace technique correspondante.

**Une ligne = un problème.** Si un écran a trois soucis, faites trois lignes. Cela nous permet d'en corriger deux et de laisser le troisième si besoin.

---

## 8. En cas de blocage

Si vous êtes bloqué plus de quinze minutes sur une étape : notez-la en `Bloqué`, passez à la suivante, et signalez-le sans attendre la fin du test. Il est inutile de perdre une demi-journée sur un point que nous pouvons débloquer en cinq minutes.

Merci — ce travail est ce qui fait la différence entre une mise en service sereine et une mise en service dans l'urgence.
