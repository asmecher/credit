<?php

/**
 * @file CreditPlugin.inc.php
 *
 * Copyright (c) 2022 Simon Fraser University
 * Copyright (c) 2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CreditPlugin
 * @brief Support for the NISO CRediT contributor credit vocabulary.
 */

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
                HookRegistry::register('authorform::initdata', [$this, 'initAuthorForm']);
                HookRegistry::register('authorform::display', [$this, 'displayAuthorForm']);
                HookRegistry::register('authorform::validate', [$this, 'validateAuthorForm']);
                HookRegistry::register('authorform::execute', [$this, 'executeAuthorForm']);
                HookRegistry::register('Schema::get::author', [$this, 'extendAuthorSchema']);

                if ($this->getSetting($contextId, 'showCreditRoles')) {
                    HookRegistry::register('TemplateManager::display', [$this, 'handleTemplateDisplay']);
                }
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
        $this->import('classes.form.CreditSettingsForm');
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
     * Initialize the author edit form.
     */
    function initAuthorForm($hookName, $params) {
        $form =& $params[0];
        if ($author = $form->getAuthor()) {
            $form->setData('creditRoles', (array) $author->getData('creditRoles'));
        } else {
            $form->setData('creditRoles', []);
        }
        return false;
    }

    /**
     * Display the author edit form.
     */
    function displayAuthorForm($hookName, $params) {
        HookRegistry::register('Common::UserDetails::AdditionalItems', [$this, 'addCreditRoles']);
        $form =& $params[0];

        // Build a list of roles for selection in the UI.
        $roleList = [];
        foreach ($this->getCreditRoles(AppLocale::getLocale()) as $uri => $name) {
            $roleList[] = ['value' => $uri, 'label' => $name];
        }

        $templateMgr = TemplateManager::getManager(Application::get()->getRequest());
        $templateMgr->assign('roleList', $roleList);

        return false;
    }

    /**
     * Validate the author edit form.
     */
    function validateAuthorForm($hookName, $params) {
        $form =& $params[0];
        $form->readUserVars(['creditRoles']);
        $creditRoles = (array) $form->getData('creditRoles');
        $roleUris = array_keys($this->getCreditRoles(AppLocale::getLocale()));
        foreach ($creditRoles as $roleUri) {
            if (!in_array($roleUri, $roleUris)) $form->addError('creditRoles', __('plugins.generic.credit.invalidCreditRole'));
        }
        return false;
    }

    /**
     * Execute the author edit form.
     */
    function executeAuthorForm($hookName, $params) {
        $form =& $params[0];
        $form->getAuthor()->setData('creditRoles', (array) $form->getData('creditRoles'));
        return false;
    }

    /**
     * Add additional CRediT specific field to the Author object
     *
     * @param $hookName string
     * @param $args array
     *
     * @return bool
     */
    function extendAuthorSchema($hookName, $args) {
        $schema = $args[0];
        $schema->properties->creditRoles = (object)[
            'type' => 'array',
            'validation' => ['nullable'],
            'items' => (object) [
                'type' => 'string',
            ],
        ];
        return false;
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
        $creditRoles = $this->getCreditRoles(AppLocale::getLocale());
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
                            $newOutput .= '<li class="creditRole">' . htmlspecialchars($creditRoles[$roleUri]) . "</li>\n";
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
     * @param array $params
     */
    public function addCreditRoles($hookName, $params)
    {
        $smarty = $params[1];
        $output =& $params[2];
        $output .= $smarty->fetch($this->getTemplateResource('creditField.tpl'));

        return false;
    }

    /**
     * Get the credit roles in an associative URI => Term array.
     * @param $locale The locale for which to fetch the data (en_US if not available)
     */
    public function getCreditRoles($locale) {
        $roleList = [];
        $doc = new DOMDocument();
        if (!AppLocale::isLocaleValid($locale)) $locale = 'en_US';
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
