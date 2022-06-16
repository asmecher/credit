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
}
