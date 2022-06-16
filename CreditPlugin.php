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
use PKP\plugins\GenericPlugin;
use PKP\plugins\HookRegistry;

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
                HookRegistry::register('Form::config::before', [$this, 'addCounterRoles']);
                HookRegistry::register('Schema::get::author', function ($hookName, $args) {
                    $schema = $args[0];

                    $schema->properties->creditRoles = (object)[
                        'type' => 'object',
                        'apiSummary' => true,
                        'validation' => ['nullable']
                    ];
               });
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
     * Add settings to the payments form
     *
     * @param string $hookName
     * @param FormComponent $form
     */
    public function addCounterRoles($hookName, $form)
    {
        import('lib.pkp.classes.components.forms.publication.PKPContributorForm');
        if ($form->id !== FORM_CONTRIBUTOR) {
            return;
        }

        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return;
        }

        $roleList = [];
        $doc = new DOMDocument();
        $doc->load(dirname(__FILE__) . '/jats-schematrons/schematrons/1.0/credit-roles.xml');
        foreach ($doc->getElementsByTagName('credit-roles') as $roles) {
            foreach ($roles->getElementsByTagName('item') as $item) {
                $roleList[] = ['value' => $item->getAttribute('uri'), 'label' => $item->getAttribute('term')];
            }
        }

        $form->addField(new \PKP\components\forms\FieldSelect('contributorRoles', [
                'label' => __('plugins.generic.credit.contributorRoles'),
                'description' => __('plugins.generic.credit.contributorRoles.description'),
                'options' => $roleList,
                //'value' => $this->getSetting($context->getId(), ''),
        ]));

        return;
    }
}
