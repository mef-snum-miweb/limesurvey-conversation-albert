<?php

/**
 * ConversationIA Plugin for LimeSurvey
 *
 * Transforme une question "Texte long" en interface de conversation IA.
 * Utilise l'API Albert (ou autre LLM) pour le dialogue.
 * Génère un résumé automatique stocké dans le champ de réponse.
 */
class ConversationIA extends PluginBase
{
    /** @inheritdoc */
    protected $storage = 'DbStorage';

    /**
     * Configuration globale du plugin
     * @inheritdoc
     */
    protected $settings = [
        'api_endpoint' => [
            'type' => 'string',
            'label' => 'URL de l\'API Albert',
            'default' => 'https://albert.api.etalab.gouv.fr/v1/chat/completions',
            'help' => 'Endpoint de l\'API compatible OpenAI'
        ],
        'api_key' => [
            'type' => 'password',
            'label' => 'Clé API Albert',
            'default' => '',
            'help' => 'Votre clé API Albert (ne sera jamais exposée côté client)'
        ],
        'model' => [
            'type' => 'string',
            'label' => 'Modèle à utiliser',
            'default' => 'albert-large',
            'help' => 'Nom du modèle LLM (ex: albert-large, albert-light)'
        ],
        'max_tokens' => [
            'type' => 'int',
            'label' => 'Tokens maximum par réponse',
            'default' => 1024,
            'help' => 'Limite de tokens pour chaque réponse du LLM'
        ],
        'max_exchanges' => [
            'type' => 'int',
            'label' => 'Nombre maximum d\'échanges',
            'default' => 10,
            'help' => 'Nombre maximum de messages dans la conversation'
        ],
        'question_attribute' => [
            'type' => 'string',
            'label' => 'Attribut déclencheur',
            'default' => 'ia_prompt',
            'help' => 'Nom de l\'attribut personnalisé qui contient le prompt système'
        ]
    ];

    /**
     * Initialisation du plugin - abonnement aux événements
     * @inheritdoc
     */
    public function init()
    {
        // Injection du chat dans les questions marquées
        $this->subscribe('beforeQuestionRender', 'onBeforeQuestionRender');

        // Enregistrement des assets
        $this->subscribe('beforeSurveyPage', 'onBeforeSurveyPage');

        // Configuration par sondage
        $this->subscribe('beforeSurveySettings', 'onBeforeSurveySettings');
        $this->subscribe('newSurveySettings', 'onNewSurveySettings');

        // Enregistrement de l'attribut personnalisé pour les questions
        $this->subscribe('newQuestionAttributes', 'onNewQuestionAttributes');

        // Gestion des requêtes AJAX directes (route plugins/unsecure - sans CSRF)
        $this->subscribe('newUnsecureRequest');
    }

    /**
     * Gestionnaire des requêtes AJAX directes
     * Appelé via la route plugins/unsecure (sans vérification CSRF)
     */
    public function newUnsecureRequest()
    {
        $oEvent = $this->getEvent();

        // Vérifier que cette requête est destinée à ce plugin
        if ($oEvent->get('target') !== get_class($this)) {
            return;
        }

        // Empêcher tout output HTML - terminer immédiatement avec JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        try {
            $function = $oEvent->get('function');
            $request = Yii::app()->request;

            // Debug: test simple
            if ($function === 'test') {
                echo json_encode(['success' => true, 'message' => 'Plugin OK']);
                exit;
            }

            // Debug: test sendMessage simplifié (sans appel API)
            if ($function === 'testSend') {
                $msg = $request->getPost('message', 'pas de message');
                $prompt = $request->getPost('prompt', 'pas de prompt');
                echo json_encode([
                    'success' => true,
                    'message' => 'Bonjour ! Je suis l\'assistant IA. Comment puis-je vous aider ?',
                    'received_message' => $msg,
                    'received_prompt' => $prompt,
                    'shouldEnd' => false
                ]);
                exit;
            }

            // Debug: test sendMessage avec mock (simule une réponse sans appeler l'API)
            if ($function === 'sendMessageMock') {
                $msg = $request->getPost('message', '');
                echo json_encode([
                    'success' => true,
                    'message' => 'Réponse simulée de l\'IA pour le message: ' . $msg,
                    'shouldEnd' => false
                ]);
                exit;
            }

            $result = '';

            switch ($function) {
                case 'sendMessage':
                    $result = $this->handleSendMessage($request);
                    break;
                case 'endConversation':
                    $result = $this->handleEndConversation($request);
                    break;
                default:
                    $result = json_encode([
                        'success' => false,
                        'error' => 'Fonction inconnue: ' . $function
                    ]);
            }

            echo $result;
            exit;
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            exit;
        }
    }

    /**
     * Déclare l'attribut personnalisé ia_prompt pour les questions de type texte long
     */
    public function onNewQuestionAttributes()
    {
        $event = $this->event;
        $questionAttributes = (array) $event->get('questionAttributes');

        // Ajouter l'attribut ia_prompt pour les questions de type "T" (texte long)
        $questionAttributes['ia_prompt'] = [
            'types' => 'T',  // T = Long free text (Texte long)
            'category' => gT('ConversationIA'),
            'sortorder' => 1,
            'inputtype' => 'textarea',
            'caption' => gT('Prompt IA (système)'),
            'help' => gT('Si renseigné, cette question sera transformée en interface de conversation IA. Décrivez ici le contexte et les instructions pour l\'assistant IA.'),
            'default' => '',
            'i18n' => true  // Permet la traduction par langue du sondage
        ];

        $event->set('questionAttributes', $questionAttributes);
    }

    /**
     * Paramètres spécifiques au sondage
     */
    public function onBeforeSurveySettings()
    {
        $event = $this->event;
        $surveyId = $event->get('survey');

        $event->set("surveysettings.{$this->id}", [
            'name' => get_class($this),
            'settings' => [
                'enabled' => [
                    'type' => 'boolean',
                    'label' => 'Activer ConversationIA pour ce sondage',
                    'current' => $this->get('enabled', 'Survey', $surveyId, false)
                ],
                'custom_prompt_prefix' => [
                    'type' => 'text',
                    'label' => 'Préfixe de prompt personnalisé (optionnel)',
                    'current' => $this->get('custom_prompt_prefix', 'Survey', $surveyId, ''),
                    'help' => 'Texte ajouté avant chaque prompt de question'
                ]
            ]
        ]);
    }

    /**
     * Sauvegarde des paramètres du sondage
     */
    public function onNewSurveySettings()
    {
        $event = $this->event;
        $surveyId = $event->get('survey');

        foreach ($event->get('settings') as $name => $value) {
            $this->set($name, $value, 'Survey', $surveyId);
        }
    }

    /**
     * Enregistrement des assets CSS/JS avant le rendu de la page
     */
    public function onBeforeSurveyPage()
    {
        $event = $this->event;
        $surveyId = $event->get('surveyId');

        // Vérifier si le plugin est activé pour ce sondage
        if (!$this->get('enabled', 'Survey', $surveyId, false)) {
            return;
        }

        // Publier et enregistrer les assets — via AssetManager avec chemin absolu
        // pour rester indépendant du répertoire d'installation (plugins/, upload/plugins/, core/plugins/).
        // PluginBase::publish() résout en dur sur plugins/<Classe>/ (alias webroot.plugins.<Classe>)
        // et échoue depuis upload/plugins/.
        $assetUrl = App()->getAssetManager()->publish(__DIR__ . '/assets');

        // Enregistrer le CSS
        App()->clientScript->registerCssFile($assetUrl . '/css/conversation-ia.css');

        // Enregistrer le JS avec les paramètres nécessaires
        App()->clientScript->registerScriptFile($assetUrl . '/js/conversation-ia.js', CClientScript::POS_END);

        // Passer la configuration au JavaScript (sans la clé API !)
        $jsConfig = [
            'sendMessageUrl' => $this->getAjaxUrl('sendMessage'),
            'endConversationUrl' => $this->getAjaxUrl('endConversation'),
            'maxExchanges' => (int) $this->get('max_exchanges', null, null, 10),
            'labels' => [
                'send' => gT('Envoyer'),
                'end' => gT('Terminer la conversation'),
                'placeholder' => gT('Écrivez votre message...'),
                'thinking' => gT('L\'assistant réfléchit...'),
                'ended' => gT('Conversation terminée. Merci pour vos réponses.'),
                'error' => gT('Une erreur est survenue. Veuillez réessayer.')
            ]
        ];

        $jsConfigJson = json_encode($jsConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        App()->clientScript->registerScript(
            'conversationIA-config',
            "window.ConversationIAConfig = {$jsConfigJson};",
            CClientScript::POS_HEAD
        );
    }

    /**
     * Transformation de la question en interface chat
     */
    public function onBeforeQuestionRender()
    {
        $event = $this->event;
        $surveyId = $event->get('surveyId');

        // Vérifier si le plugin est activé
        if (!$this->get('enabled', 'Survey', $surveyId, false)) {
            return;
        }

        $questionId = $event->get('qid');
        $groupId = $event->get('gid');

        // Construire le SGQA (SurveyId X GroupId X QuestionId) - format standard LimeSurvey
        $sgqa = $surveyId . 'X' . $groupId . 'X' . $questionId;

        // Récupérer les attributs de la question via QuestionAttribute
        $triggerAttribute = $this->get('question_attribute', null, null, 'ia_prompt');
        $questionAttributes = QuestionAttribute::model()->getQuestionAttributes($questionId);

        // Vérifier si cette question a l'attribut déclencheur
        if (empty($questionAttributes[$triggerAttribute])) {
            return;
        }

        // L'attribut peut être un tableau (i18n) ou une chaîne
        $promptData = $questionAttributes[$triggerAttribute];
        if (is_array($promptData)) {
            // Récupérer la langue courante du sondage
            $language = Yii::app()->getLanguage();
            // Utiliser la valeur de la langue courante, ou la première valeur disponible
            $prompt = isset($promptData[$language]) ? $promptData[$language] : reset($promptData);
        } else {
            $prompt = $promptData;
        }

        // Vérifier que le prompt n'est pas vide après extraction
        if (empty($prompt)) {
            return;
        }

        // Préfixe personnalisé du sondage
        $promptPrefix = $this->get('custom_prompt_prefix', 'Survey', $surveyId, '');
        if (!empty($promptPrefix)) {
            $prompt = $promptPrefix . "\n\n" . $prompt;
        }

        // Générer le HTML du chat
        $chatHtml = $this->renderChatInterface($questionId, $sgqa, $prompt);

        // Remplacer le contenu de la question
        $event->set('answers', $chatHtml);
    }

    /**
     * Génère l'interface HTML du chat
     */
    protected function renderChatInterface($questionId, $sgqa, $prompt)
    {
        $promptEncoded = htmlspecialchars($prompt, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div class="conversation-ia-container"
     data-question-id="{$questionId}"
     data-sgqa="{$sgqa}"
     data-prompt="{$promptEncoded}">

    <!-- Zone de conversation -->
    <div class="fr-card">
        <div class="fr-card__body">
            <div class="conversation-ia-messages" id="cia-messages-{$questionId}" role="log" aria-live="polite">
                <!-- Les messages seront injectés ici -->
            </div>
        </div>
    </div>

    <!-- Zone de saisie -->
    <div class="conversation-ia-input-container fr-mt-2w">
        <div class="fr-input-group">
            <label class="fr-label sr-only" for="cia-input-{$questionId}">Votre message</label>
            <textarea
                class="fr-input conversation-ia-input"
                id="cia-input-{$questionId}"
                rows="2"
                placeholder="Écrivez votre message..."
                aria-describedby="cia-hint-{$questionId}"></textarea>
            <p class="fr-hint-text" id="cia-hint-{$questionId}">Appuyez sur Entrée pour envoyer, Maj+Entrée pour un saut de ligne</p>
        </div>

        <div class="fr-btns-group fr-btns-group--inline fr-mt-1w">
            <button type="button"
                    class="fr-btn conversation-ia-send"
                    data-question-id="{$questionId}"
                    aria-label="Envoyer le message">
                Envoyer
            </button>
            <button type="button"
                    class="fr-btn fr-btn--secondary conversation-ia-end"
                    data-question-id="{$questionId}"
                    aria-label="Terminer la conversation">
                Terminer
            </button>
        </div>
    </div>

    <!-- Indicateur de chargement -->
    <div class="conversation-ia-loading fr-hidden" id="cia-loading-{$questionId}">
        <span class="fr-icon-refresh-line" aria-hidden="true"></span>
        <span>L'assistant réfléchit...</span>
    </div>

    <!-- Champ caché pour stocker le résumé (réponse LimeSurvey) -->
    <input type="hidden"
           name="{$sgqa}"
           id="answer{$sgqa}"
           class="conversation-ia-answer"
           value="">

    <!-- Stockage de l'historique complet (optionnel) -->
    <input type="hidden"
           name="{$sgqa}_history"
           id="answer{$sgqa}_history"
           class="conversation-ia-history"
           value="">
</div>
HTML;
    }

    /**
     * Retourne l'URL AJAX du plugin (accessible publiquement)
     * @param string $function Nom de la fonction à appeler
     */
    protected function getAjaxUrl($function = 'sendMessage')
    {
        // Utiliser la route unsecure pour les plugins (sans vérification CSRF)
        return Yii::app()->createUrl('plugins/unsecure', [
            'plugin' => get_class($this),
            'function' => $function
        ]);
    }

    /**
     * Gestionnaire AJAX pour envoyer un message au LLM
     * @param CHttpRequest $request
     * @return string JSON response
     */
    protected function handleSendMessage($request)
    {
        try {
            $userMessage = $request->getPost('message');
            $history = $request->getPost('history', '[]');
            $systemPrompt = $request->getPost('prompt', '');

            // Décoder l'historique JSON s'il est sous forme de chaîne
            if (is_string($history)) {
                $history = json_decode($history, true) ?: [];
            }

            if (empty($userMessage)) {
                return json_encode([
                    'success' => false,
                    'error' => 'Message vide'
                ]);
            }

            // Construire les messages pour l'API
            $messages = $this->buildMessages($systemPrompt, $history, $userMessage);

            // Appeler l'API Albert
            $response = $this->callLLMApi($messages);

            if ($response['success']) {
                return json_encode([
                    'success' => true,
                    'message' => $response['content'],
                    'shouldEnd' => $this->shouldEndConversation($response['content'], count($history))
                ]);
            } else {
                return json_encode([
                    'success' => false,
                    'error' => $response['error']
                ]);
            }
        } catch (Exception $e) {
            return json_encode([
                'success' => false,
                'error' => 'Erreur handleSendMessage: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Gestionnaire AJAX pour terminer la conversation et générer le résumé
     * @param CHttpRequest $request
     * @return string JSON response
     */
    protected function handleEndConversation($request)
    {
        $history = $request->getPost('history', []);
        $systemPrompt = $request->getPost('prompt', '');

        if (empty($history)) {
            return json_encode([
                'success' => false,
                'error' => 'Historique vide'
            ]);
        }

        // Générer le résumé
        $summary = $this->generateSummary($systemPrompt, $history);

        return json_encode([
            'success' => true,
            'summary' => $summary
        ]);
    }

    /**
     * Construire les messages pour l'API LLM
     */
    protected function buildMessages($systemPrompt, $history, $newMessage)
    {
        $messages = [];

        // Prompt système enrichi
        $fullSystemPrompt = $this->buildSystemPrompt($systemPrompt);
        $messages[] = [
            'role' => 'system',
            'content' => $fullSystemPrompt
        ];

        // Historique de la conversation
        if (is_string($history)) {
            $history = json_decode($history, true) ?: [];
        }

        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        // Nouveau message utilisateur
        $messages[] = [
            'role' => 'user',
            'content' => $newMessage
        ];

        return $messages;
    }

    /**
     * Construire le prompt système complet
     */
    protected function buildSystemPrompt($basePrompt)
    {
        $instructions = <<<PROMPT
Tu es un assistant conversationnel dans le cadre d'un questionnaire. Ton rôle est de guider l'utilisateur pour obtenir des réponses complètes et pertinentes.

Instructions importantes:
1. Pose des questions de suivi si la réponse est incomplète ou peu claire
2. Sois bienveillant et encourage l'utilisateur à développer ses réponses
3. Quand tu estimes avoir suffisamment d'informations, remercie l'utilisateur
4. Réponds toujours en français
5. Garde un ton professionnel mais accessible

Contexte de la question:
{$basePrompt}

Commence par accueillir l'utilisateur et lui poser la première question basée sur le contexte ci-dessus.
PROMPT;

        return $instructions;
    }

    /**
     * Appeler l'API LLM (Albert ou compatible OpenAI)
     */
    protected function callLLMApi($messages)
    {
        $apiEndpoint = $this->get('api_endpoint', null, null, 'https://albert.api.etalab.gouv.fr/v1/chat/completions');
        $apiKey = $this->get('api_key');
        $model = $this->get('model', null, null, 'albert-large');
        $maxTokens = (int) $this->get('max_tokens', null, null, 1024);

        if (empty($apiKey)) {
            return [
                'success' => false,
                'error' => 'Clé API non configurée'
            ];
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => 0.7
        ];

        $ch = curl_init($apiEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log('Erreur cURL: ' . $error, CLogger::LEVEL_ERROR);
            return [
                'success' => false,
                'error' => 'Erreur de connexion à l\'API'
            ];
        }

        if ($httpCode !== 200) {
            $this->log('Erreur API HTTP ' . $httpCode . ': ' . $response, CLogger::LEVEL_ERROR);
            return [
                'success' => false,
                'error' => 'Erreur API (code ' . $httpCode . ')'
            ];
        }

        $data = json_decode($response, true);

        if (isset($data['choices'][0]['message']['content'])) {
            return [
                'success' => true,
                'content' => $data['choices'][0]['message']['content']
            ];
        }

        return [
            'success' => false,
            'error' => 'Réponse API invalide'
        ];
    }

    /**
     * Déterminer si la conversation devrait se terminer
     */
    protected function shouldEndConversation($lastResponse, $exchangeCount)
    {
        $maxExchanges = (int) $this->get('max_exchanges', null, null, 10);

        // Limite d'échanges atteinte
        if ($exchangeCount >= $maxExchanges) {
            return true;
        }

        // Détection de phrases de conclusion dans la réponse de l'IA
        $endIndicators = [
            'merci pour vos réponses',
            'merci pour votre participation',
            'merci d\'avoir répondu',
            'j\'ai toutes les informations',
            'nous avons fait le tour',
            'cela conclut notre échange'
        ];

        $lowerResponse = mb_strtolower($lastResponse);
        foreach ($endIndicators as $indicator) {
            if (mb_strpos($lowerResponse, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Générer un résumé de la conversation
     */
    protected function generateSummary($systemPrompt, $history)
    {
        if (is_string($history)) {
            $history = json_decode($history, true) ?: [];
        }

        // Construire le texte de la conversation
        $conversationText = "";
        foreach ($history as $msg) {
            $role = $msg['role'] === 'user' ? 'Utilisateur' : 'Assistant';
            $conversationText .= "{$role}: {$msg['content']}\n\n";
        }

        // Prompt pour le résumé
        $summaryPrompt = <<<PROMPT
Voici une conversation entre un utilisateur et un assistant dans le cadre d'un questionnaire.

Contexte de la question posée:
{$systemPrompt}

Conversation:
{$conversationText}

Génère un résumé structuré des informations importantes fournies par l'utilisateur.
Le résumé doit:
- Être factuel et concis
- Contenir uniquement les informations pertinentes données par l'utilisateur
- Être rédigé à la troisième personne
- Ne pas inclure les questions de l'assistant, seulement les réponses de l'utilisateur

Résumé:
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => 'Tu es un assistant qui résume des conversations de manière concise et factuelle.'],
            ['role' => 'user', 'content' => $summaryPrompt]
        ];

        $response = $this->callLLMApi($messages);

        if ($response['success']) {
            return $response['content'];
        }

        // Fallback: retourner l'historique brut si le résumé échoue
        return "Résumé automatique non disponible.\n\nHistorique:\n" . $conversationText;
    }
}
