{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2022 Simon Fraser University
 * Copyright (c) 2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * CRediT input field for the author form
 *
 *}
{fbvFormArea id="creditFormArea"}
	<p class="pkp_help">{translate key="plugins.generic.credit.contributorRoles.description"}</p>
	{fbvFormSection list="true"}
		{foreach from=$roleList item=roleListEntry key=roleListEntryKey}
			{fbvElement type="checkbox" name="creditRoles[]" value=$roleListEntry.value id="roleListEntry"|concat:$key checked=in_array($roleListEntry.value,$creditRoles) label=$roleListEntry.label translate=false}
		{/foreach}
	{/fbvFormSection}
{/fbvFormArea}
