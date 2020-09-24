<?php

/**
 * This file is a simplified database installer. It does what it is supposed to.
 */

// If we have found SSI.php and we are outside of ELK, then we are running standalone.
if (file_exists(dirname(__FILE__) . '/SSI.php') && !defined('ELK'))
	require_once(dirname(__FILE__) . '/SSI.php');
elseif (!defined('ELK')) 
	die('<b>Error:</b> Cannot install - please verify you put this file in the same place as ElkArte\'s SSI.php.');

global $modSettings;

// List settings here in the format: setting_key => default_value.  Escape any "s. (" => \")
$add_settings = array(
	'attachment_image_width' => 0,
	'attachment_image_height' => 0,
	'attachment_image_reformat' => 1,
	'attachment_image_enabled' => 0,
);

// Update mod settings if applicable
foreach ($add_settings as $new_setting => $new_value)
{
	if (!isset($modSettings[$new_setting]))
		updateSettings(array($new_setting => $new_value));
}

if (ELK === 'SSI')
   echo 'Congratulations! You have successfully installed this addon!';