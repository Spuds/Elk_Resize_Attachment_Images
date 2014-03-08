<?php

/**
 * This file is a simplified database uninstaller. It does what it is supposed to.
 */

// If we have found SSI.php and we are outside of ElkArte, then we are running standalone.
if (file_exists(dirname(__FILE__) . '/SSI.php') && !defined('ELK'))
	require_once(dirname(__FILE__) . '/SSI.php');
elseif (!defined('ELK')) 
	die('<b>Error:</b> Cannot install - please verify you put this file in the same place as ElkArte\'s SSI.php.');

global $modSettings;

// List the addon variables to remove array('one',two',three')
$remove_settings = array(
	'attachment_image_width' => 0,
	'attachment_image_height' => 0,
	'attachment_image_reformat' => 1,
	'attachment_image_enabled' => 0,
);

// Remove settings if applicable
if (count($remove_settings) > 0)
{
	// First remove them from memory
	foreach ($remove_settings as $setting)
	{
		if (isset($modSettings[$setting]))
			unset($modSettings[$setting]);
	}

	// And now from sight
	$smcFunc['db_query']('', '
		DELETE FROM {db_prefix}settings
		WHERE variable IN ({array_string:variables})',
		array(
			'variables' => $remove_settings,
		)
	);

	// And let ElkArte know we have been mucking about so the cache is reset
	updateSettings(array('settings_updated' => time()));
}

if (ELK == 'SSI')
   echo 'Congratulations! You have successfully removed this addon!';