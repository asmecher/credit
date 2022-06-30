{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2022 Simon Fraser University
 * Copyright (c) 2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Datacite plugin settings
 *
 *}
<script type="text/javascript">
	$(function() {ldelim}
		// Attach the form handler.
		$('#creditSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="creditSettingsForm" method="post" action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin="creditplugin" category="generic" verb="save"}">
	{csrf}
	{fbvFormArea id="creditSettingsFormArea"}
		<p class="pkp_help">{translate key="plugins.generic.credit.settings.description"}</p>
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" id="showCreditRoles" label="plugins.generic.credit.showCreditRoles" checked=$showCreditRoles|compare:true}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
</form>
