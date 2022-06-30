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

    public function getCreditRoles($locale) {
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
