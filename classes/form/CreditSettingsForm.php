<?php

/**
 * @file classes/form/CreditSettingsForm.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class CreditSettingsForm
 * @brief Form for journal managers to setup the CRediT plugin.
 */

namespace APP\plugins\generic\credit\classes\form;

use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;

class CreditSettingsForm extends Form
{
    //
    // Private properties
    //
    /** @var int */
    public $_contextId;

    /**
     * Get the context ID.
     *
     * @return int
     */
    public function _getContextId()
    {
        return $this->_contextId;
    }

    /** @var DataciteExportPlugin */
    public $_plugin;

    /**
     * Get the plugin.
     *
     * @return DataciteExportPlugin
     */
    public function _getPlugin()
    {
        return $this->_plugin;
    }

    //
    // Constructor
    //
    /**
     * Constructor
     *
     * @param DataciteExportPlugin $plugin
     * @param int $contextId
     */
    public function __construct($plugin, $contextId)
    {
        $this->_contextId = $contextId;
        $this->_plugin = $plugin;

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
    }


    //
    // Implement template methods from Form
    //
    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $contextId = $this->_getContextId();
        $plugin = $this->_getPlugin();
        foreach (['showCreditRoles'] as $fieldName) {
            $this->setData($fieldName, $plugin->getSetting($contextId, $fieldName));
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(['showCreditRoles']);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $plugin = $this->_getPlugin();
        $contextId = $this->_getContextId();
        parent::execute(...$functionArgs);
        foreach (['showCreditRoles'] as $fieldName) {
            $plugin->updateSetting($contextId, $fieldName, $this->getData($fieldName));
        }
    }
}
