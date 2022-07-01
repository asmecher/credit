<?php

/**
 * @file CreditPlugin.php
 *
 * Copyright (c) 2022 Simon Fraser University
 * Copyright (c) 2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CreditPlugin
 * @brief Support for the CASRAI CRediT contributor credit vocabulary.
 */

namespace APP\plugins\generic\credit;

use \DOMDocument;

use APP\core\Application;
use PKP\config\Config;
use PKP\core\Registry;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\core\JSONMessage;
use PKP\plugins\GenericPlugin;
use PKP\plugins\HookRegistry;
use PKP\author\maps\Schema;
use APP\author\Author;

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
            if ($this->getEnabled($mainContextId)) {
                // Extend the contributor map to include CRediT roles.
                app('maps')->extend(Schema::class, function($output, Author $item, Schema $map) {
                    // Ensure that an empty list is passed from the API as [] rather than null.
                    $output['creditRoles'] = $output['creditRoles'] ?? [];
                    return $output;
                });

                HookRegistry::register('Form::config::before', [$this, 'addCreditRoles']);
                HookRegistry::register('Schema::get::author', function ($hookName, $args) {
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
                HookRegistry::register('TemplateManager::display', [$this, 'handleTemplateDisplay']);
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

                $this->import('classes.form.CreditSettingsForm');
                $form = new \CreditSettingsForm($this, $context->getId());
                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
            case 'save':
                $context = $request->getContext();

                $this->import('classes.form.CreditSettingsForm');
                $form = new \CreditSettingsForm($this, $context->getId());
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
     * Output filter adds ORCiD interaction to registration form.
     *
     * @param string $output
     * @param TemplateManager $templateMgr
     *
     * @return string
     */
    public function articleDisplayFilter($output, $templateMgr)
    {
        $offset = 0;
        $authorIndex = 0;
        $publication = $templateMgr->getTemplateVars('publication');
        $creditRoles = $this->getCreditRoles(Locale::getLocale());
        $authors = iterator_to_array($publication->getData('authors'));
        while (preg_match('/<span class="userGroup">[^<]*<\/span>/', $output, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $author = $authors[$authorIndex];
            $match = $matches[0][0];
            $offset = $matches[0][1];

            $newOutput = substr($output, 0, $offset);
            $newOutput .= '<ul class="userGroup">';
            foreach ($author->getData('creditRoles') ?? [] as $creditRole) {
                $newOutput .= '<li class="creditRole">' . htmlspecialchars($creditRoles[$creditRole]) . "</li>\n";
            }
            $newOutput .= '</ul>';
            $newOutput .= substr($output, $offset + strlen($match));
            $output = $newOutput;
            $templateMgr->unregisterFilter('output', [$this, 'articleDisplayFilter']);
            $offset++; // Don't match the same string again
            $authorIndex++;
        }
        return $output;
    }

    /**
     * Add roles to the contributor form
     *
     * @param string $hookName
     * @param FormComponent $form
     */
    public function addCreditRoles($hookName, $form)
    {
        import('lib.pkp.classes.components.forms.publication.PKPContributorForm');
        if ($form->id !== FORM_CONTRIBUTOR) {
            return;
        }

        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return;
        }

        // Build a list of roles for selection in the UI.
        $roleList = [];
        foreach ($this->getCreditRoles(Locale::getLocale()) as $uri => $name) {
            $roleList[] = ['value' => $uri, 'label' => $name];
        }

        $author = $form->_author ?? null;

        $form->addField(new \PKP\components\forms\FieldOptions('creditRoles', [
            'type' => 'checkbox',
            'label' => __('plugins.generic.credit.contributorRoles'),
            'description' => __('plugins.generic.credit.contributorRoles.description'),
            'options' => $roleList,
            'value' => $author?->getData('creditRoles') ?? [],
        ]));

        return;
    }

    /**
     * Get the credit roles in an associative URI => Term array.
     * @param $locale The locale for which to fetch the data (en_US if not available)
     */
    public function getCreditRoles($locale): array {
        $roleList = [];
        $doc = new DOMDocument();
        if (!Locale::isLocaleValid($locale)) $locale = 'en_US';
        if (file_exists($filename = dirname(__FILE__) . '/translations/credit-roles-' . $locale . '.xml')) {
            $doc->load($filename);
        } else {
            $doc->load(dirname(__FILE__) . '/jats-schematrons/schematrons/1.0/credit-roles.xml');
        }
        foreach ($doc->getElementsByTagName('credit-roles') as $roles) {
            foreach ($roles->getElementsByTagName('item') as $item) {
                $roleList[$item->getAttribute('uri')] = $item->getAttribute('term');
            }
        }
        return $roleList;
    }
}
