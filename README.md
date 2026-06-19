# ConversationIA - Plugin LimeSurvey

Plugin LimeSurvey permettant de transformer une question "Texte long" en interface de conversation IA avec l'API Albert (LLM gouvernemental français) ou toute API compatible OpenAI.

![DSFR](https://img.shields.io/badge/DSFR-compatible-blue)
![LimeSurvey](https://img.shields.io/badge/LimeSurvey-6.x-green)
![License](https://img.shields.io/badge/license-GPL--2.0-orange)

## Fonctionnalités

- Interface de chat intégrée aux questions LimeSurvey
- Proxy serveur pour sécuriser la clé API (jamais exposée côté client)
- Résumé automatique de la conversation généré par l'IA
- Style DSFR natif
- Compatible avec l'API Albert et toute API compatible OpenAI

## Installation

### Option 1 : Téléchargement direct

1. Télécharger le [ZIP du plugin](https://github.com/mef-snum-miweb/limesurvey-conversation-albert/archive/refs/heads/main.zip)
2. Extraire et renommer le dossier en `ConversationIA`
3. Copier dans le dossier `/plugins/` de votre installation LimeSurvey

### Option 2 : Git clone

```bash
cd /chemin/vers/limesurvey/plugins/
git clone https://github.com/mef-snum-miweb/limesurvey-conversation-albert.git ConversationIA
```

### Option 3 : Développement avec Docker

Ce plugin fait partie de la suite [limesurvey-dsfr-suite](https://github.com/mef-snum-miweb/limesurvey-dsfr-suite), qui fournit un environnement Docker complet pour le développement local et le déploiement en production.

```bash
# Cloner les repos au même niveau
cd ~/GitHub
git clone https://github.com/mef-snum-miweb/limesurvey-dsfr-suite.git
git clone https://github.com/mef-snum-miweb/limesurvey-theme-dsfr.git
git clone https://github.com/mef-snum-miweb/limesurvey-email-dsfr.git
git clone https://github.com/mef-snum-miweb/limesurvey-conversation-albert.git

# Lancer l'environnement de développement
cd limesurvey-dsfr-suite
docker compose -f docker-compose.dev.yml up -d
# → http://localhost:8080 (admin / admin)
```

Les fichiers du plugin sont montés en direct : toute modification est visible après un rafraîchissement du navigateur.

### Activation

1. Aller dans **Configuration > Plugins** dans l'admin LimeSurvey
2. Activer le plugin "ConversationIA"
3. Configurer les paramètres globaux (clé API, endpoint, modèle)

## Configuration globale

Dans les paramètres du plugin :

| Paramètre | Description | Valeur par défaut |
|-----------|-------------|-------------------|
| URL de l'API | Endpoint compatible OpenAI | `https://albert.api.etalab.gouv.fr/v1/chat/completions` |
| Clé API | Votre clé API Albert | - |
| Modèle | Nom du modèle LLM | `albert-large` |
| Tokens max | Limite par réponse | `1024` |
| Échanges max | Nombre max de messages | `10` |
| Attribut déclencheur | Nom de l'attribut personnalisé | `ia_prompt` |

## Utilisation

### 1. Activer pour un sondage

Dans les paramètres du sondage :
- Aller dans **Paramètres simples > Plugins**
- Activer "ConversationIA pour ce sondage"

### 2. Créer une question IA

1. Créer une question de type **"Texte long"**
2. Dans les **Paramètres avancés** de la question, ajouter un attribut personnalisé :
   - Nom : `ia_prompt`
   - Valeur : Le prompt système qui guide la conversation

### Exemple de prompt

```
Tu es un assistant qui aide les usagers à décrire leur projet de rénovation énergétique.

Objectifs de la conversation :
1. Comprendre le type de logement (maison/appartement, surface, année de construction)
2. Identifier les travaux envisagés (isolation, chauffage, fenêtres, etc.)
3. Connaître le budget approximatif
4. Savoir si des devis ont déjà été demandés

Pose les questions une par une, de manière naturelle et bienveillante.
Quand tu as toutes les informations, remercie l'usager.
```

## Fonctionnement

1. **Démarrage** : L'IA envoie automatiquement un premier message d'accueil
2. **Conversation** : L'usager dialogue avec l'IA qui pose des questions de suivi
3. **Fin automatique** : L'IA détecte quand elle a suffisamment d'informations
4. **Résumé** : Un résumé structuré est généré et stocké comme réponse à la question

### Stockage des données

- **Réponse principale** : Résumé généré par l'IA (visible dans les résultats)
- **Historique** : Conversation complète en JSON (champ `{SGQA}_history`)

## Personnalisation

### Préfixe de prompt par sondage

Dans les paramètres du sondage, vous pouvez définir un préfixe ajouté à tous les prompts :

```
Tu travailles pour [Nom de l'organisme].
Respecte les règles suivantes : [règles spécifiques]
```

### Indicateurs de fin de conversation

L'IA termine automatiquement quand elle détecte des phrases comme :
- "merci pour vos réponses"
- "j'ai toutes les informations"
- "nous avons fait le tour"

Vous pouvez aussi inclure dans le prompt : "Quand tu as terminé, dis 'Merci pour votre participation'."

## Dépannage

### L'interface chat n'apparaît pas

1. Vérifier que le plugin est activé globalement
2. Vérifier que le plugin est activé pour le sondage
3. Vérifier que l'attribut `ia_prompt` est bien défini sur la question

### Erreur "Clé API non configurée"

Configurer la clé API dans les paramètres globaux du plugin.

### Erreur de connexion à l'API

- Vérifier l'URL de l'API
- Vérifier que le serveur peut accéder à l'API (firewall, proxy)
- Consulter les logs LimeSurvey (`application/runtime/plugin.log`)

## Limitations

- Pas de gestion du streaming (réponses complètes uniquement)
- Historique limité en mémoire côté client
- Pas de modération du contenu

## Robustesse

- **Retry automatique** : 2 tentatives en cas d'erreur réseau ou timeout
- **Fallback** : Si le résumé IA échoue, les réponses utilisateur sont enregistrées en texte brut
- La conversation peut toujours être terminée et sauvegardée, même en cas de problème API

## Contribuer

Les contributions sont les bienvenues ! N'hésitez pas à ouvrir une issue ou une pull request.

## Licence

GNU General Public License v2 ou ultérieure

## Auteur

Développé pour le thème LimeSurvey DSFR.
