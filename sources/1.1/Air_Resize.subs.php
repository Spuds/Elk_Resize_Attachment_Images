<?php

/**
 * @package Attachment_Image_Resize Addon for Elkarte
 * @author Spuds
 * @copyright (c) 2011-2021 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.6
 *
 */

use ElkArte\Errors\AttachmentErrorContext;

/**
 * Called before entering the post controller, integrate_action_post_before, call from Dispatcher.class
 * Used to set up the post form to maximize the allowable file size so we can
 * resize it / compress it.
 *
 * @param string $function_name
 */
function ipb_air_prepost($function_name)
{
	global $modSettings;

	// Make the post form accept any file size, or up to ini upload_max_filesize if its available
	if (!empty($modSettings['attachmentSizeLimit']) && !empty($modSettings['attachment_image_enabled']))
	{
		// Creating the post, or submitting the post
		if ($function_name === 'action_index' || $function_name === 'action_post2')
		{
			air_setlimits();
		}
	}
}

/**
 * Called after the post controller runs, integrate_action_post_after, call from Dispatcher.class
 * Checks if the action was post2, a post attempt, and if so were attachment errors
 * present.  If so again sets up the form to allow increase file size
 *
 * @param string $function_name
 */
function ipa_air_afterpost($function_name)
{
	global $modSettings;

	// Make the post form accept any file size, or up to ini upload_max_filesize if its available
	if (!empty($modSettings['attachmentSizeLimit']) && !empty($modSettings['attachment_image_enabled']))
	{
		// Tried to post and produced some attachment errors?
		if ($function_name === 'action_post2' && AttachmentErrorContext::context()->hasErrors())
		{
			air_setlimits();
		}
	}
}

/**
 * This allows the form to send larger files which we will resize / shrink.
 * The size limit in the ACP is still honored, this just updates the form checks so its not rejected
 * by the browser before we can even work on it
 *
 * Maximum file size is based on upload_max_filesize and total payload on post_max_size
 * With D&D post_max_size is less important since its done one at a time vs all at once,
 * still we set it.
 */
function air_setlimits()
{
	global $modSettings;

	// Max file size in a post based on upload_max_filesize
	$upload_max_filesize = ini_get('upload_max_filesize');
	$upload_max_filesize = !empty($upload_max_filesize) ? memoryReturnBytes($upload_max_filesize) / 1024 : 0;

	// The overall post size limits, governed by post_max_size in ini if set.
	$post_max_size = ini_get('post_max_size');
	$post_max_size = !empty($post_max_size) ? memoryReturnBytes($post_max_size) / 1024 : 0;

	// Set it
	$modSettings['attachmentPostLimit'] = $post_max_size;
	$modSettings['attachmentSizeLimit'] = $upload_max_filesize;
}

/**
 * integrate_modify_attachment_settings Called from ManageAttachments.controller.php
 *
 * @param array $config_vars
 */
function imas_air_settings(&$config_vars)
{
	global $txt, $modSettings;

	loadLanguage('air_elk');

	$help = $txt['helpattachment_image_enabled'] . (!empty($modSettings['attachmentSizeLimit']) ? ' ' . sprintf($txt['helpattachment_image_enabled_size'], $modSettings['attachmentSizeLimit']) : '');

	$config_vars = array_merge($config_vars, array(
		array('title', 'attachment_image_resize'),
		array('check', 'attachment_image_enabled', 'helptext' => $help),
		array('check', 'attachment_image_reformat', 'helptext' => $txt['help_attachment_image_reformat']),
		array('text', 'attachment_image_width', 'helptext' => $txt['help_attachment_image_width'], 'subtext' => $txt['attachment_image_sub'], 6, 'postinput' => $txt['attachment_image_post']),
		array('text', 'attachment_image_height', 'helptext' => $txt['help_attachment_image_height'], 'subtext' => $txt['attachment_image_sub'], 6, 'postinput' => $txt['attachment_image_post']),
	));
}

/**
 *  iau_resize_images()
 *
 * What it does:
 *
 * - integrate_attachment_upload, Called from attachments.subs.php
 * - Used to provide alternative upload processing
 *
 * @uses Attachment_Image_Resize
 */
function iau_air_resize_images()
{
	global $modSettings;

	// If its off, just return
	if (empty($modSettings['attachment_image_enabled']))
	{
		return;
	}

	// If its an api call (ulattach for an icon image for D&D dialog etc)
	if (isset($_GET['api']))
	{
		return;
	}

	// Load in the attachment errors, if there are any
	$attach_errors = AttachmentErrorContext::context();

	// Get the errors
	if ($attach_errors->hasErrors())
	{
		$errors = $attach_errors->prepareErrors();
	}

	// If there were generic errors during the upload, we bail out
	if (isset($errors['attach_generic']))
	{
		return;
	}

	// Good to process
	$air = new Attachment_Image_Resize();
	$air->init();
}

/**
 * Class to resize an image file to fit within WxH values set in the ACP
 */
class Attachment_Image_Resize
{
	/**
	 * Holds the current image size / type values
	 *
	 * @var array
	 */
	protected $_size_current;

	/**
	 * Holds the WxH bounds an image must be within
	 *
	 * @var int[]
	 */
	protected $_size_bounds;

	/**
	 * The id of the $_SESSION attachment we are working on
	 *
	 * @var int
	 */
	protected $_attachID;

	/**
	 * Holds the errors we will try to work around
	 *
	 * @var string[]
	 */
	protected $_resize_errors = array('file_too_big', 'attach_max_total_file_size');

	/**
	 * Looks at each attachment in $_SESSION and determines which can be worked on
	 *
	 * What it does:
	 *
	 * - If generic errors were found, exits
	 * - If attachment specific errors are found other than size related, the attachment is skipped
	 * - If size related issues are found, will forward to the resize, new format function.
	 * - If no errors are found, will forward to the resize function, same format, to see if it
	 * needs to be reduced.
	 */
	public function init()
	{
		global $modSettings;

		// Loop through all of the attachments
		foreach ($_SESSION['temp_attachments'] as $this->_attachID => $attachment)
		{
			if ($this->_attachID === 'post')
			{
				continue;
			}

			// No errors for this file, lets just resize (if needed) while keeping its format
			if (empty($attachment['errors']))
			{
				$this->resize();
			}
			// Errors associated with the file, if they are size related, lets see if we can help
			else
			{
				$other = false;

				// Look at each error and see if they are only due to file size limits
				foreach ($attachment['errors'] as $errors)
				{
					if (!is_array($errors) || !in_array($errors[0], $this->_resize_errors))
					{
						$other = true;
						break;
					}
				}

				// OK lets see if we can fix this file
				if ($other === false)
				{
					// Rotation is bypassed on errors during normal Elk processing
					if (!empty($modSettings['attachment_autorotate']) && substr($_SESSION['temp_attachments'][$this->_attachID]['type'], 0, 5) === 'image')
					{
						// exif data may be stripped by resize operations, so do this first.
						if (function_exists('autoRotateImage'))
						{
							autoRotateImage($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);
						}
					}

					$this->resize(false);
				}
			}
		}
	}

	/**
	 * Sets the WxH bounds for an image and prepares for the resize call
	 *
	 * What it does:
	 *
	 * - Loads the current images information, most importantly its size and format
	 * - Set the WxH bounds based on settings
	 * - Forwards to the proper resizer (same or new format)
	 *
	 * @param boolean $resize_only
	 */
	public function resize($resize_only = true)
	{
		global $modSettings;

		// Fetch the details for this attachment
		$this->_size_current = @getimagesize($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);

		if ($this->_size_current !== false)
		{
			// Going to need help
			require_once (SUBSDIR . '/Graphics.subs.php');

			// Bounds to use for constraining this image
			$this->_size_bounds[0] = empty($modSettings['attachment_image_width']) ? $this->_size_current[0] : min($this->_size_current[0], $modSettings['attachment_image_width']);
			$this->_size_bounds[1] = empty($modSettings['attachment_image_height']) ? $this->_size_current[1] : min($this->_size_current[1], $modSettings['attachment_image_height']);

			// Is a PNG -AND- we allow format changes -AND- it has no alpha channel DNA
			$force_reformat = $this->_size_current[2] === 3
				&& !empty($modSettings['attachment_image_reformat'])
				&& !$this->_check_transparency();

			// Attempt to resize (if needed), maintaining the format
			if ($resize_only)
			{
				$this->air_resize(!$force_reformat);
			}
			// Attempt to resize, change the format only if needed or forced
			else
			{
				$this->air_resize_new_format($force_reformat);
			}
		}
	}

	/**
	 * Called when we are over the allowed file size limit.  Will attempt to reduce
	 * the file size, maintaining the current format if possible.
	 *
	 * What it does:
	 *
	 * - Under the WxH limits and already a jpeg, simply returns
	 * - Over the WxH limits and a jpeg, resizes with same format
	 * - Under the WxH limits and not a jpeg, resize changing to a jpeg
	 * - Over the WxH limits and not a jpeg, first resize keeping format
	 *    - if it achieves file size limits, done
	 *    - if not attempt to resize and change format to jpeg
	 *
	 * @param boolean $force_reformat if set will always convert PNG to JPG
	 */
	public function air_resize_new_format($force_reformat = false)
	{
		global $modSettings;

		// Not over the WxH size limit and already a jpeg, nothing we can try,
		// its broke (sure jack around with quality)
		if (!$this->_air_validate_resize() && $this->_size_current[2] === 2)
		{
			return;
		}

		// Over the WxH size limit and already a jpeg, try resize
		if ($this->_air_validate_resize() && $this->_size_current[2] === 2)
		{
			$this->air_resize();
		}
		// Not over the WxH size limit, not a jpeg, and allowed to change formats (eg png->jpg)
		elseif (!empty($modSettings['attachment_image_reformat']) && !$this->_air_validate_resize())
		{
			$this->air_resize(false);
		}
		// Over the WxH size limit and allowed to reformat, two steps to try resize same
		// format first then change format
		elseif (!empty($modSettings['attachment_image_reformat']) && $this->_air_validate_resize())
		{
			if ($force_reformat)
			{
				$this->air_resize(false);

				return;
			}

			if (!$this->air_resize())
			{
				$this->air_resize(false);
			}
		}
	}

	/**
	 * Executes the actual call to resizeImageFile to change an images WxH and format
	 *
	 * @param boolean $same_format if true will maintain the current image format
	 * @return boolean
	 */
	public function air_resize($same_format = true)
	{
		// Let try to resize this image
		$check = resizeImageFile($_SESSION['temp_attachments'][$this->_attachID]['tmp_name'], $_SESSION['temp_attachments'][$this->_attachID]['tmp_name'] . 'airtemp', $this->_size_bounds[0], $this->_size_bounds[1], ($same_format ? $this->_size_current[2] : 2), true);

		// If successful, replace the uploaded image with the newly created one
		if ($check)
		{
			@unlink($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);
			@unlink($_SESSION['temp_attachments'][$this->_attachID]['tmp_name'] . '_thumb');
			@rename($_SESSION['temp_attachments'][$this->_attachID]['tmp_name'] . 'airtemp', $_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);

			// Reload the file size
			$check = $this->_air_update_size();

			// Change the file name and mime type if it's not a .jpg
			if ($same_format === false && strrchr($_SESSION['temp_attachments'][$this->_attachID]['name'], '.') !== '.jpg')
			{
				$_SESSION['temp_attachments'][$this->_attachID]['name'] = substr($_SESSION['temp_attachments'][$this->_attachID]['name'], 0, -(strlen(strrchr($_SESSION['temp_attachments'][$this->_attachID]['name'], '.')))) . '.jpg';
				$_SESSION['temp_attachments'][$this->_attachID]['type'] = 'image/jpeg';
			}
		}

		return $check;
	}

	/**
	 * Updates the $_SESSION attachment file array with its new size
	 *
	 * What it does:
	 *
	 * - Updates the total size of the posting
	 * - Calls air_reset_error to remove any errors that were fixed, if any
	 */
	private function _air_update_size()
	{
		// Get the new and old size details
		clearstatcache($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);
		$new = filesize($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);

		// Make the tracking updates
		$_SESSION['temp_attachments'][$this->_attachID]['size'] = $new;

		return $this->_air_reset_error();
	}

	/**
	 * Returns if the image's W or H dimension is over the set thresholds
	 *
	 * @return boolean
	 */
	private function _air_validate_resize()
	{
		global $modSettings;

		$width = !empty($modSettings['attachment_image_width']) && $this->_size_current[0] > $modSettings['attachment_image_width'];
		$height = !empty($modSettings['attachment_image_height']) && $this->_size_current[1] > $modSettings['attachment_image_height'];

		return ($width || $height);
	}

	/**
	 * Checks if the new size "fits"
	 *
	 * What it does:
	 *
	 * - Validates its under the file size limits
	 * - Validates its under the total post size limits
	 */
	private function _air_reset_error()
	{
		global $modSettings, $context;

		// Is the file now within file size limits?
		if (!empty($modSettings['attachmentSizeLimit']) && $_SESSION['temp_attachments'][$this->_attachID]['size'] > $modSettings['attachmentSizeLimit'] * 1024)
		{
			return false;
		}

		// Is the file now withing post size limits?
		if (!empty($modSettings['attachmentPostLimit']) && $context['attachments']['total_size'] + $_SESSION['temp_attachments'][$this->_attachID]['size'] > $modSettings['attachmentPostLimit'] * 1024)
		{
			return false;
		}

		// It fits so remove any existing error(s) against this file, and rerun the full attachment scan
		// To get here there can only be errors of type $this->_resize_errors
		$attach_errors = AttachmentErrorContext::context();
		$attach_errors->activate($this->_attachID);
		if ($attach_errors->hasErrors($this->_attachID))
		{
			foreach ($this->_resize_errors as $error)
			{
				$attach_errors->removeError($error);
			}
		}

		// Now recheck this file
		require_once (SUBSDIR . '/Attachments.subs.php');
		unset($_SESSION['temp_attachments'][$this->_attachID]['errors']);
		$context['attachments']['quantity']--;
		attachmentChecks($this->_attachID);

		// Reload errors in case there are still some
		$this->_air_reload_error();

		return true;
	}

	/**
	 * If errors were found on the recheck, load them back up for display
	 */
	private function _air_reload_error()
	{
		// Sort out the errors for display and delete any associated files.
		if (!empty($_SESSION['temp_attachments'][$this->_attachID]['errors']))
		{
			$attach_errors = AttachmentErrorContext::context();
			$attach_errors->addAttach($this->_attachID, $_SESSION['temp_attachments'][$this->_attachID]['name']);

			foreach ($_SESSION['temp_attachments'][$this->_attachID]['errors'] as $error)
			{
				if (!is_array($error))
				{
					$attach_errors->addError($error);
				}
				else
				{
					$attach_errors->addError(array($error[0], $error[1]));
				}
			}
		}
	}

	/**
	 * Checks for transparency in a PNG image
	 *
	 *  - Checks file header to see if its been saved with Alpha space
	 *  - 8 Bit (256 color) PNG's are not handled.
	 *  - If the alpha flag is set, will go pixel by pixel to validate if any alpha
	 * pixels exist before returning true.
	 *
	 * @return bool
	 */
	private function _check_transparency()
	{
		// It claims to be, but we need to do pixel inspection :'(
		if (ord(file_get_contents($_SESSION['temp_attachments'][$this->_attachID]['tmp_name'], false, null, 25, 1)) & 4)
		{
			if (checkImagick())
			{
				return $this->_check_transparency_IM();
			}

			if (checkGD())
			{
				return $this->_check_transparency_GD();
			}
		}

		return false;
	}

	/**
	 * Validate PNG transparency the Imagick way
	 *
	 * @return bool
	 */
	private function _check_transparency_IM()
	{
		$trans = false;
		try
		{
			$image = new Imagick($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);
			$pixel_iterator = $image->getPixelIterator();

			// Look at each one, or until we find just one
			foreach ($pixel_iterator as $y => $pixels)
			{
				foreach ($pixels as $x => $pixel)
				{
					$color = $pixel->getColor();
					if ($color['a'] < 1)
					{
						$trans = true;
						break 2;
					}
				}
			}
		}
		catch (Exception $e)
		{
			// We don't know what it is, so don't mess with it
			$trans = true;
		}

		unset($image);

		return $trans;
	}

	/**
	 * Validate PNG transparency the GD way
	 *
	 * @return bool
	 */
	private function _check_transparency_GD()
	{
		$trans = false;
		$image = imagecreatefrompng($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);
		if (!is_resource($image))
		{
			return true;
		}

		// Go through the image pixel by pixel and as soon as we find a transparent pixel
		for ($i = 0; $i < $this->_size_current[0]; $i++)
		{
			for ($j = 0; $j < $this->_size_current[1]; $j++)
			{
				if (imagecolorat($image, $i, $j) & 0x7F000000)
				{
					$trans = true;
					break 2;
				}
			}
		}

		unset($image);

		return $trans;
	}
}
