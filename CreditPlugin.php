<?php

/**
 * @file CreditPlugin.php
 *
 * Copyright (c) 2022 Simon Fraser University
 * Copyright (c) 2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CreditPlugin
 * @brief Support for the NISO CRediT contributor credit vocabulary.
 */

namespace APP\plugins\generic\credit;

use \DOMDocument;

use APP\core\Application;
use PKP\config\Config;
use PKP\components\forms\publication\ContributorForm;
use PKP\core\Registry;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\core\JSONMessage;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\author\maps\Schema;
use APP\author\Author;
use PKP\oai\OAIRecord;

use APP\plugins\generic\credit\classes\form\CreditSettingsForm;

class CreditPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            $contextId = ($mainContextId === null) ? $this->getCurrentContextId() : $mainContextId;
            if ($this->getEnabled($mainContextId)) {
                // Extend the contributor map to include CRediT roles.
                app('maps')->extend(Schema::class, function($output, Author $item, Schema $map) {
                    // Ensure that an empty list is passed from the API as [] rather than null.
                    $output['creditRoles'] = $output['creditRoles'] ?? [];
                    return $output;
                });

                Hook::add('Form::config::before', [$this, 'addCreditRoles']);
                Hook::add('Schema::get::author', function ($hookName, $args) {
                    $schema = $args[0];
                    $schema->properties->creditRoles = json_decode('{
                        "type": "array",
                        "validation": [
                                "nullable"
                        ],
                        "items": {
                                "type": "string"
                        }
                    }');

                });
                if ($this->getSetting($contextId, 'showCreditRoles')) {
                    Hook::add('TemplateManager::display', [$this, 'handleTemplateDisplay']);
                }
                Hook::add('JatsTemplatePlugin::jats', $this->augmentJats(...));
            }
            return true;
        }
        return false;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.credit.name');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.credit.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $context = $request->getContext();

                $form = new CreditSettingsForm($this, $context->getId());
                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
            case 'save':
                $context = $request->getContext();

                $form = new CreditSettingsForm($this, $context->getId());
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
        }
        return parent::manage($args, $request);
    }

    /**
     * Hook callback: register output filter for article display.
     *
     * @param string $hookName
     * @param array $args
     *
     * @return bool
     *
     * @see TemplateManager::display()
     *
     */
    public function handleTemplateDisplay($hookName, $args)
    {
        $templateMgr = & $args[0];
        $template = & $args[1];
        $request = Application::get()->getRequest();

        // Assign our private stylesheet, for front and back ends.
        $templateMgr->addStyleSheet(
            'creditPlugin',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/styles.css',
            [
                'contexts' => ['frontend']
            ]
        );

        switch ($template) {
            case 'frontend/pages/article.tpl':
                $templateMgr->registerFilter('output', [$this, 'articleDisplayFilter']);
                break;
        }
        return false;
    }

    /**
     * Output filter adds CRediT information to article view.
     *
     * @param string $output
     * @param TemplateManager $templateMgr
     *
     * @return string
     */
    public function articleDisplayFilter($output, $templateMgr)
    {
        $authorIndex = 0;
        $publication = $templateMgr->getTemplateVars('publication');
        $creditRoles = $this->getCreditRoles(Locale::getLocale());
        $authors = array_values(iterator_to_array($publication->getData('authors')));
        // Identify the ul.authors list and traverse li/ul/ol elements from there.
        // For any </li> elements in 1st-level depth, append CRediT information before </li>.
        $startMarkup = '<ul class="authors">';
        $startOffset = strpos($output, $startMarkup);
        if ($startOffset === false) return $output;
        $startOffset += strlen($startMarkup);
        $depth = 1; // Depth of potentially nested ul/ol list elements
        return substr($output, 0, $startOffset) . preg_replace_callback(
            '/(<\/li>)|(<[uo]l[^>]*>)|(<\/[uo]l>)/i',
            function($matches) use (&$depth, &$authorIndex, $authors, $creditRoles) {
                switch (true) {
                    case $depth == 1 && $matches[1] !== '': // </li> in first level depth
                        $newOutput = '<ul class="userGroup">';
                        foreach ((array) $authors[$authorIndex++]->getData('creditRoles') as $roleUri) {
                            $roleUri = str_replace('http://', 'https://', $roleUri); // Initial release of CRediT used http:// URIs
                            $newOutput .= '<li class="creditRole" data-role="' . $roleUri . '">' . htmlspecialchars($creditRoles[$roleUri]['name'] ?? $roleUri) . "</li>\n";
                        }
                        $newOutput .= '</ul>';
                        return $newOutput . $matches[0];
                    case !empty($matches[2]) && $depth >= 1: $depth++; break; // <ul>; do not re-enter once we leave
                    case !empty($matches[3]): $depth--; break; // </ul>
                }
                return $matches[0];
            },
            substr($output, $startOffset)
        );
    }

    /**
     * Add roles to the contributor form
     *
     * @param string $hookName
     * @param FormComponent $form
     */
    public function addCreditRoles($hookName, $form)
    {

        if (!$form instanceof ContributorForm) return Hook::CONTINUE;

        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return;
        }

        // Build a list of roles for selection in the UI.
        $roleList = [];
        foreach ($this->getCreditRoles(Locale::getLocale()) as $uri => $data) {
            $roleList[] = ['value' => $uri, 'label' => $data['name']];
        }

        $author = $form->_author ?? null;

        $form->addField(new \PKP\components\forms\FieldOptions('creditRoles', [
            'type' => 'checkbox',
            'label' => __('plugins.generic.credit.contributorRoles'),
            'description' => __('plugins.generic.credit.contributorRoles.description'),
            'options' => $roleList,
            'value' => $author?->getData('creditRoles') ?? [],
        ]));

        return Hook::CONTINUE;
    }

    /**
     * Get the credit roles in an associative URI => Term array.
     * @param $locale The locale for which to fetch the data (en_US if not available)
     */
    public function getCreditRoles($locale): array {
        $doc = new DOMDocument();
        if (!Locale::isLocaleValid($locale)) $locale = 'en';
        foreach ([$locale, 'en'] as $locale) {
            $path = dirname(__FILE__) . "/credit-translation/translations/{$locale}.json";
            if (!file_exists($path)) continue;

            $json = json_decode(file_get_contents($path), true);
            return $json['translations'];
        }
        throw new \Exception('Unable to load JSON CRediT role list!');
    }

    /**
     * Add the CRediT role information to the JATS contributor list
     */
    public function augmentJats($hookName, OAIRecord $record, \DOMDocument $doc) {
        $submission = $record->getData('article');
        $publication = $submission->getCurrentPublication();
        $roleVocabulary = $this->getCreditRoles($submission->getData('locale'));
        $xpath = new \DOMXPath($doc);
	$authorsArray = array_values($publication->getData('authors')->toArray());
        foreach ($xpath->query('//article/front/article-meta/contrib-group/contrib') as $matchIndex => $contribNode) {
            $match = $xpath->query('//email', $contribNode);
            if (!$match->length) continue;
	    $author = $authorsArray[$matchIndex];
            $creditRoles = $author->getData('creditRoles');
            if (!$creditRoles) continue;

            foreach ($creditRoles as $role) {
                $roleNode = $contribNode->insertBefore($doc->createElement('role'), $contribNode->firstChild);
                $roleNode->setAttribute('vocab-identifier', 'https://credit.niso.org/');
                $roleNode->setAttribute('vocab-term', $roleVocabulary[$role]['name']);
                $roleNode->setAttribute('vocab-term-identifier', $role);
                $roleNode->nodeValue = $roleVocabulary[$role]['name'];
            }
        }
        return Hook::CONTINUE;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\credit\CreditPlugin', '\CreditPlugin');
}
