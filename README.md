# MA2E — Plateforme de gestion de mutuelle

Application web PHP destinée à la **Mutuelle des Agents de l’Eau et de l’Électricité (MA2E)**. Elle centralise l’adhésion des membres, la gestion de l’épargne, des crédits, des logements, des opérations comptables et des utilisateurs internes.

## Fonctionnalités

- Portail public : présentation de la mutuelle, adhésion et informations sur les produits.
- Espace membre : comptes d’épargne, dépôts, retraits, demandes de crédit ou de logement, historique et notifications.
- Administration : validation des candidatures, gestion des membres et consultation des documents déposés.
- Gestion des crédits : étude des demandes, octroi, échéanciers et suivi des remboursements.
- Comptabilité : saisie des versements, prélèvements sur salaire, validation des retraits, remboursements et rapports de mouvements.
- Super-administration : gestion des sociétés, des comptes utilisateurs et des paramètres applicatifs.
- Gestion immobilière : gestion des logements et de leurs attributions.
- Tâches planifiées : intérêts d’épargne, pénalités de retard et relances/notifications.

## Technologies

- PHP avec PDO MySQL
- MySQL / MariaDB
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) pour les emails
- Bootstrap et Bootstrap Icons (chargés depuis CDN)
- MAMP pour l’environnement local initial

## Rôles utilisateurs

| Rôle | Accès principal |
| --- | --- |
| `super_admin` | Paramètres, sociétés et utilisateurs |
| `administrateur` | Candidatures et membres |
| `comptable` | Versements, retraits, prélèvements et rapports |
| `gestionnaire_credit` | Demandes, crédits et échéanciers |
| `gestionnaire_immobilier` | Logements et attributions |
| `membre` | Espace personnel, épargne, crédits et notifications |

## Prérequis

- PHP avec les extensions `pdo` et `pdo_mysql`
- MySQL ou MariaDB
- Composer
- Un serveur web PHP (MAMP, Apache ou Nginx)

## Installation locale

1. Placer le projet dans le répertoire web de votre serveur local. Avec MAMP, il est déjà situé dans :

   ```text
   /Applications/MAMP/htdocs/SOUTENANCE_pro
   ```

2. Installer les dépendances PHP si le dossier `vendor/` est absent :

   ```bash
   composer install
   ```

3. Créer une base de données MySQL, par exemple `ma2e`, puis importer le schéma et les données de référence de l’application. Le catalogue du schéma actuellement utilisé est documenté dans la section [Schéma de la base de données](#schéma-de-la-base-de-données).

4. Créer le fichier local `.env` à partir du modèle, puis renseigner les paramètres MySQL. Ce fichier est ignoré par Git :

   ```bash
   cp .env.example .env
   ```

   Modifier ensuite `.env` pour indiquer le nom de base, l’utilisateur, le mot de passe et le socket ou l’hôte MySQL. Ne pas publier ce fichier.

5. Renseigner dans `.env` les paramètres `MA2E_SMTP_*` avec un compte SMTP propre à votre environnement. Ne pas placer les identifiants dans les fichiers PHP.

6. Démarrer Apache et MySQL, puis ouvrir l’application :

   ```text
   http://localhost:8888/SOUTENANCE_pro/interface_utilisateur/page_home.php
   ```

   L’écran de connexion est accessible à l’adresse :

   ```text
   http://localhost:8888/SOUTENANCE_pro/connexion/pageconnexion.php
   ```

   Le port `8888` est celui généralement utilisé par MAMP ; adaptez-le à votre configuration.

## Migration disponible

Le script [`sql/2026-04-15_comptes_techniques_immobilier.sql`](sql/2026-04-15_comptes_techniques_immobilier.sql) ajoute le compte technique utilisé par le module immobilier. Il doit être exécuté **après** l’import du schéma principal :

```bash
mysql -u <utilisateur> -p ma2e < sql/2026-04-15_comptes_techniques_immobilier.sql
```

## Schéma de la base de données

Schéma exporté depuis la base locale `ma2e` le 28 août 2026, **sans les données**. La base comporte 26 tables.

### Tables métier

| Domaine | Tables | Description |
| --- | --- | --- |
| Utilisateurs | `utilisateurs`, `password_reset_tokens`, `logs_systeme` | Comptes, réinitialisation des mots de passe et journalisation des actions. |
| Adhésion | `candidats`, `membres`, `societes` | Candidatures, profils des membres et sociétés de rattachement. |
| Épargne | `types_compte_epargne`, `comptes_epargne`, `plans_epargne_expresse`, `mouvements` | Produits d’épargne, comptes, plans et opérations financières. |
| Crédits | `types_credit`, `taux_credit_express`, `taux_credit_ordinaire`, `demandes_credit`, `credits`, `echeanciers` | Paramétrage, demandes, contrats et échéances de crédit. |
| Retraits et alertes | `demandes_retrait`, `notifications`, `parametres_systeme`, `logs_cron` | Demandes de retrait, communication aux membres et paramètres métier. |
| Immobilier | `sites_immobiliers`, `types_logement`, `logements`, `demandes_logement`, `attributions`, `comptes_techniques` | Parc immobilier, financement et attributions de logements. |

### Relations principales

```text
societes ──< candidats ──> membres ──< utilisateurs
     │                       │
     └───────────────────────┘

membres ──< comptes_epargne ──< mouvements
   │               │
   │               └──< plans_epargne_expresse
   │
   ├──< demandes_credit ──< credits ──< echeanciers
   │                              │
   │                              └── compte de garantie (comptes_epargne)
   │
   ├──< demandes_retrait ──> comptes_epargne
   ├──< notifications
   └──< demandes_logement ──> types_logement ──> sites_immobiliers
                                  │
                                  └──< attributions ──> logements
```

### Détail des tables

| Table | Clé et liens principaux | Objet |
| --- | --- | --- |
| `attributions` | `logement_id`, `membre_id`, `demande_logement_id`, `credit_id` | Attribution et financement d’un logement. |
| `candidats` | `societe_id` | Demandes d’adhésion et documents associés. |
| `comptes_epargne` | `membre_id`, `type_compte_id` | Soldes, dates, taux et statut des comptes d’épargne. |
| `comptes_techniques` | `code` unique | Comptes internes, notamment pour l’immobilier. |
| `credits` | `demande_id`, `membre_id`, `type_credit_id`, `compte_garantie_id` | Crédits accordés, capital restant et garanties. |
| `demandes_credit` | `membre_id`, `type_credit_id`, `instructeur_id` | Demandes de crédit à instruire. |
| `demandes_logement` | `membre_id`, `type_logement_id`, `compte_logement_id`, `credit_immobilier_id` | Demandes et décisions de logement. |
| `demandes_retrait` | `membre_id`, `compte_id`, `traite_par` | Retraits soumis par les membres. |
| `echeanciers` | `credit_id`, `mouvement_id` | Échéances, règlements et pénalités. |
| `logements` | `reference` unique, `site_id` | Unités immobilières commercialisées. |
| `logs_cron` | `script`, `date_execution` | Résultats des tâches planifiées. |
| `logs_systeme` | `utilisateur_id` | Traçabilité des actions utilisateur. |
| `membres` | `candidat_id`, `societe_id` | Informations et statut des membres actifs. |
| `mouvements` | `compte_id`, `compte_technique_id`, `utilisateur_id` | Journal comptable des mouvements d’épargne et de crédit. |
| `notifications` | `membre_id` | Notifications adressées aux membres. |
| `parametres_systeme` | `cle` unique | Paramètres fonctionnels de l’application. |
| `password_reset_tokens` | `email`, `token` unique | Jetons de réinitialisation de mot de passe. |
| `plans_epargne_expresse` | `compte_id`, `membre_id` | Plans d’épargne à versement mensuel. |
| `sites_immobiliers` | `id` | Sites ou programmes immobiliers. |
| `societes` | `code` unique | Entreprises auxquelles les membres sont rattachés. |
| `taux_credit_express` | bornes d’ancienneté | Grille des taux des crédits express. |
| `taux_credit_ordinaire` | bornes de ratio | Grille des taux des crédits ordinaires. |
| `types_compte_epargne` | `code` unique | Configuration des produits d’épargne. |
| `types_credit` | `code` unique | Configuration des produits de crédit. |
| `types_logement` | `site_id` | Catégories, prix et disponibilité des logements. |
| `utilisateurs` | `membre_id`, `matricule` et `email` uniques | Utilisateurs authentifiés et leurs rôles. |

> Certaines relations logiques du module immobilier (`attributions.logement_id`, `attributions.membre_id` et `logements.site_id`) ne sont pas actuellement définies comme clés étrangères dans la base exportée. Elles doivent être préservées par les contrôles applicatifs ou ajoutées lors d’une future migration.

## Tâches planifiées

Les scripts ci-dessous sont conçus pour être lancés par `cron` :

| Script | Rôle | Fréquence suggérée |
| --- | --- | --- |
| `cron/interets_mensuels.php` | Crédit des intérêts et gestion des comptes arrivés à échéance | Tous les jours, ou le 1er de chaque mois selon les règles métier |
| `cron/penalites_retard.php` | Calcul des pénalités et mise à jour des retards | Chaque jour |
| `cron/alertes_relances.php` | Relances d’échéances, épargne et crédits | Chaque jour |

Exemple de configuration quotidienne :

```cron
0 7 * * * /chemin/vers/php /Applications/MAMP/htdocs/SOUTENANCE_pro/cron/penalites_retard.php
10 7 * * * /chemin/vers/php /Applications/MAMP/htdocs/SOUTENANCE_pro/cron/alertes_relances.php
```

Vérifier que l’utilisateur exécutant les tâches planifiées peut écrire dans `logs/` et que la configuration de la base est accessible depuis la ligne de commande.

## Structure du projet

```text
config/                           Connexion à la base et configuration email
connexion/                        Authentification et réinitialisation de mot de passe
cron/                             Traitements planifiés
interface_utilisateur/            Site public et parcours d’adhésion
interface_membre/                 Espace personnel des membres
interface_administrateur/         Gestion des candidatures et membres
interface_gestionCredit/          Gestion des demandes et crédits
interface_gestionComptable/       Opérations comptables et rapports
interface_gestionnaire_immobilier/ Gestion immobilière
interface_superAdmin/             Paramètres et utilisateurs
sql/                              Migrations SQL versionnées
uploads/                          Documents déposés par les candidats et membres
photo/                            Images de l’interface
```

## Sécurité et données sensibles

- Ne pas versionner ni partager les mots de passe de base de données ou les identifiants SMTP.
- Remplacer immédiatement toute clé ou tout mot de passe qui aurait été exposé dans un dépôt ou une capture d’écran.
- Restreindre les droits d’écriture de `uploads/` et `logs/` au strict nécessaire.
- En production, utiliser HTTPS, des variables d’environnement ou un fichier de configuration non versionné, et désactiver l’affichage détaillé des erreurs PHP.

## Tests

Le projet ne contient pas de suite de tests automatisés à ce stade. Avant toute mise en production, tester au minimum l’authentification par rôle, les dépôts/retraits, le cycle complet d’un crédit, l’envoi de notifications et l’exécution des scripts planifiés.
