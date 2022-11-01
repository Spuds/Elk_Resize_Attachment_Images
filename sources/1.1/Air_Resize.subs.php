<?php

/**
 * @package Attachment_Image_Resize Addon for Elkarte
 * @author Spuds
 * @copyright (c) 2011-2022 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.7
 *
 */

use ElkArte\Errors\AttachmentErrorContext;

/**
 * Called before entering the post controller, integrate_action_post_before, called from Dispatcher.class
 * Used to set up the post form to maximize the allowable server file size, so we can
 * resize it / compress it.
 *
 * @param string $function_name
 */
function ipb_air_prepost($function_name)
{
	global $modSettings;

	// Make the post form accept any file size, or up to ini upload_max_filesize if it's available
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

	// Make the post form accept any file size, or up to ini upload_max_filesize if available
	// Tried to post and produced some attachment errors?
	if (!empty($modSettings['attachmentSizeLimit'])
		&& !empty($modSettings['attachment_image_enabled'])
		&& $function_name === 'action_post2'
		&& AttachmentErrorContext::context()->hasErrors())
	{
		air_setlimits();
	}
}

/**
 * This allows the form to send larger files which we will resize / shrink.
 * The size limit in the ACP is still honored, this just updates the form checks, so it's not rejected
 * by the browser before we can even work on it
 *
 * Maximum file size is based on upload_max_filesize and total payload on post_max_size
 * With D&D post_max_size is less important since it's done one at a time vs all at once,
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

	// Let 1.1.9 know there is a resizing agent
	$modSettings['attachment_image_resize_enabled'] = 1;
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

	// If off, just return
	if (empty($modSettings['attachment_image_enabled']))
	{
		return;
	}

	// If an api call (ulattach for an icon image for D&D dialog etc.)
	if (!isset($_GET['sa']) || $_GET['sa'] !== 'ulattach')
	{
		return;
	}

	// Load in the attachment errors, if there are any
	$attach_errors = AttachmentErrorContext::context();
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
	/** @var array Holds the current image size / type values */
	protected $_size_current;

	/** @var int[] Holds the WxH bounds an image must be within */
	protected $_size_bounds;

	/** @var string The id of the $_SESSION attachment we are working on */
	protected $_attachID;

	/** @var string[] Holds the errors we will try to work around */
	protected $_resize_errors = array('file_too_big', 'attach_max_total_file_size');

	/** @var int image type value for final image format */
	protected $_new_format;

	/** @var string[] image constants to file extensions */
	protected $_valid_extensions = array(2 => '.jpg', 3 => '.png', 18 => '.webp');

	/** @var string[] image constants to mime types */
	protected $_valid_mime = array(2 => 'image/jpeg', 3 => 'image/png', 18 => 'image/webp');

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

		// Loop through all the attachments
		foreach ($_SESSION['temp_attachments'] as $this->_attachID => $attachment)
		{
			if ($this->_attachID === 'post')
			{
				continue;
			}

			// No errors for this file, lets resize (if needed) while choosing the best format
			if (empty($attachment['errors']))
			{
				$this->resize(false);
				continue;
			}

			// Errors associated with the file, if they are only size related, lets see if we can help
			$other = false;
			foreach ($attachment['errors'] as $errors)
			{
				if (!is_array($errors) || !in_array($errors[0], $this->_resize_errors, true))
				{
					$other = true;
					break;
				}
			}

			// OK lets see if we can fix this file
			if ($other === false)
			{
				// Rotation is bypassed on errors during normal Elk processing
				// exif data may be stripped by resize operations, so do this first.
				if (!empty($modSettings['attachment_autorotate'])
					&& function_exists('autoRotateImage')
					&& strpos($_SESSION['temp_attachments'][$this->_attachID]['type'], 'image') === 0)
				{
					autoRotateImage($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);
				}

				$this->resize(true);
			}
		}
	}

	/**
	 * Sets the WxH bounds for an image and prepares for the resize call
	 *
	 * What it does:
	 *
	 * - Loads the current images' information, most importantly its size and format
	 * - Set the WxH bounds based on settings
	 * - Forwards to the proper resizer (same or new format)
	 *
	 * @param bool $had_errors if the upload had size related errors or not
	 */
	public function resize($had_errors)
	{
		global $modSettings;

		// Fetch the details for this attachment
		$this->_size_current = @getimagesize($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);

		// Valid Images only
		if ($this->_size_current !== false)
		{
			// Going to need help
			require_once (SUBSDIR . '/Graphics.subs.php');

			// Bounds to use for constraining this image
			$this->_size_bounds[0] = empty($modSettings['attachment_image_width']) ? $this->_size_current[0] : min($this->_size_current[0], $modSettings['attachment_image_width']);
			$this->_size_bounds[1] = empty($modSettings['attachment_image_height']) ? $this->_size_current[1] : min($this->_size_current[1], $modSettings['attachment_image_height']);

			$this->_new_format = $this->chooseImageFormat();
			$change_format = $this->_new_format !== $this->_size_current[2];

			// If the file was error free, only allow for the possible compression of png / jpg image
			// leaving others alone, like bmp, tiff, gif, etc
			if (!$had_errors)
			{
				// e.g. jpg -> webp or opaque png -> jpg or webp or transparent png -> webp
				$change_format = in_array($this->_size_current[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG));
			}

			// Attempt to resize (if needed), possibly changing the format
			$this->air_resize_new_format($change_format);
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
	 * @param boolean $change_format if we can change from the current format
	 */
	public function air_resize_new_format($change_format = false)
	{
		// Not over the WxH size limit, and we can not change the format
		if (!$change_format && !$this->_air_validate_resize())
		{
			return;
		}

		// Over the WxH size limit and can not change format, try resize
		if (!$change_format && $this->_air_validate_resize())
		{
			$this->air_resize();
			return;
		}

		// Not over the WxH size limit, and allowed to change formats (eg png->jpg)
		if ($change_format && !$this->_air_validate_resize())
		{
			$this->air_resize(false);
			return;
		}

		// Over the WxH size limit and allowed to change formats
		if ($change_format && $this->_air_validate_resize())
		{
			$this->air_resize(false);
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
		$check = resizeImageFile(
			$_SESSION['temp_attachments'][$this->_attachID]['tmp_name'],
			$_SESSION['temp_attachments'][$this->_attachID]['tmp_name'] . 'airtemp',
			$this->_size_bounds[0],
			$this->_size_bounds[1],
			$same_format ? $this->_size_current[2] : $this->_new_format,
			true);

		// If successful, replace the uploaded image with the newly created one
		if ($check)
		{
			@unlink($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);
			@unlink($_SESSION['temp_attachments'][$this->_attachID]['tmp_name'] . '_thumb');
			@rename($_SESSION['temp_attachments'][$this->_attachID]['tmp_name'] . 'airtemp', $_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);

			// Reload the file size
			$check = $this->_air_update_size();

			// Change the file name and mime type as needed
			$_SESSION['temp_attachments'][$this->_attachID]['name'] = substr($_SESSION['temp_attachments'][$this->_attachID]['name'], 0, -(strlen(strrchr($_SESSION['temp_attachments'][$this->_attachID]['name'], '.')))) . $this->_valid_extensions[$this->_new_format];
			$_SESSION['temp_attachments'][$this->_attachID]['type'] = $this->_valid_mime[$this->_new_format];
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
		clearstatcache(true, $_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);
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
	 *  - Checks file header to see if it has been saved with Alpha space
	 *  - 8 Bit (256 color) PNG's are not handled.
	 *  - If the files alpha flag is set, will do detailed validation to see if any alpha
	 * pixels actually do exist before returning true.
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
	 * Validate PNG transparency the Imagick way.
	 *
	 * - First try with channel statistics which is the fastest way
	 * - Failing that, do pixel by pixel interrogation looking an alpha value < 1
	 * - Do not fully depend on the file alpha flag, images are often saved with this
	 * on but w/o any alpha pixels
	 *
	 * @return bool
	 */
	private function _check_transparency_IM()
	{
		try
		{
			$image = new Imagick($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);
			if ($this->_size_current[0] > 1024 || $this->_size_current[1] > 1024)
			{
				// Large images get scaled down to reduce processing time, this is only used to look for
				// alpha, it is not intended to be a quality image.
				list($thumb_w, $thumb_h) = $this->getImageScaleFactor();
				$image->scaleImage($thumb_w, $thumb_h, true);
			}
		}
		catch (\ImagickException $e)
		{
			return true;
		}

		// First attempt by looking at the channel statistics which is faster
		$transparent = $this->_checkIMOpacityChannel($image);
		if (!isset($transparent))
		{
			// Failing channel stats, do pixel inspection
			$transparent = $this->_checkIMOpacityPixelInspection($image);
		}

		unset($image);

		return $transparent;
	}

	/**
	 * Attempts to use imagick getImageChannelMean to determine alpha/opacity channel statistics
	 *
	 * - An opaque image will have 0 standard deviation and a mean of 1 (65535)
	 * - If failure returns null, otherwise bool
	 *
	 * @param \Imagick $image
	 * @return bool|null
	 */
	private function _checkIMOpacityChannel($image)
	{
		try
		{
			$transparent = true;
			$stats = $image->getImageChannelMean(imagick::CHANNEL_OPACITY);

			// If mean = 65535 and std = 0, then its perfectly opaque.
			if ((int) $stats['mean'] === 65535 && (int) $stats['standardDeviation'] === 0)
			{
				$transparent = false;
			}
		}
		catch (\ImagickException $e)
		{
			$transparent = null;
		}

		return $transparent;
	}

	/**
	 * Does pixel by pixel inspection to determine if any have an alpha value < 1
	 *
	 * - Any pixel alpha < 1 is not perfectly opaque.
	 * - Resizes images > 1024x1024 to reduce pixel count
	 *
	 * @param \Imagick $image
	 * @return bool
	 */
	private function _checkIMOpacityPixelInspection($image)
	{
		try
		{
			$transparent = false;
			$pixel_iterator = $image->getPixelIterator();

			// Look at each one, or until we find just one, or we have to give up
			foreach ($pixel_iterator as $pixels)
			{
				foreach ($pixels as $pixel)
				{
					$color = $pixel->getColor();
					if ($color['a'] < 1.0)
					{
						$transparent = true;
						break 2;
					}
				}
			}
		}
		catch (\ImagickPixelIteratorException $e)
		{
			// We don't know for sure what it is, so don't mess with it
			$transparent = true;
		}

		return $transparent;
	}

	/**
	 * Validate PNG transparency the GD way
	 *
	 * @return bool
	 */
	private function _check_transparency_GD()
	{
		$transparent = false;
		$image = imagecreatefrompng($_SESSION['temp_attachments'][$this->_attachID]['tmp_name']);
		if (!$image)
		{
			return true;
		}

		// Large images get scaled down to reduce processing time
		if ($this->_size_current[0] > 1024 || $this->_size_current[1] > 1024)
		{
			// Single pass scale down, not looking for quality here
			list($thumb_w, $thumb_h) = $this->getImageScaleFactor();
			$image = imagescale($image, $thumb_w, $thumb_h, IMG_NEAREST_NEIGHBOUR);
			if (!$image)
			{
				return true;
			}
		}

		// Go through the image pixel by pixel until we find any transparent pixel
		$x = imagesx($image);
		$y = imagesy($image);
		for ($i = 0; $i < $x; $i++)
		{
			for ($j = 0; $j < $y; $j++)
			{
				if (imagecolorat($image, $i, $j) & 0x7F000000)
				{
					$transparent = true;
					break 2;
				}
			}
		}

		unset($image);

		return $transparent;
	}

	/**
	 * Determine the WxH values to scale an image while maintaining aspect ratio
	 *
	 * @param int $limit the wxh clamping limit
	 * @return array
	 */
	private function getImageScaleFactor($limit = 800)
	{
		$thumb_w = $limit;
		$thumb_h = $limit;

		if ($this->_size_current[0] > $this->_size_current[1])
		{
			$thumb_h = max (1, $this->_size_current[1] * ($limit / $this->_size_current[0]));
		}
		elseif ($this->_size_current[0] < $this->_size_current[1])
		{
			$thumb_w = max(1, $this->_size_current[0] * ($limit / $this->_size_current[1]));
		}

		return array((int) $thumb_w, (int) $thumb_h);
	}

	/**
	 * Based on ACP settings and Server settings, returns the best image type for saving
	 *
	 * - Prefers webp if enabled and available
	 * - w/o webp, non transparent png -> jpg
	 * - transparent png stays as png
	 *
	 * @return int|mixed
	 */
	private function chooseImageFormat()
	{
		global $modSettings;

		if (empty($modSettings['attachment_image_reformat']))
		{
			return $this->_size_current[2];
		}

		$hasWebp = function_exists('hasWebpSupport') && hasWebpSupport();

		// Webp is the best choice if server supports
		if ($hasWebp
			&&!empty($modSettings['attachment_webp_enable'])
			&& (empty($modSettings['attachmentCheckExtensions']) || stripos($modSettings['attachmentExtensions'], ',webp') !== false))
		{
			return IMAGETYPE_WEBP;
		}

		// If you have alpha channels, best keep with PNG
		if ($this->_size_current[2] === IMAGETYPE_PNG
			&& $this->_check_transparency())
		{
			return IMAGETYPE_PNG;
		}

		// The default, JPG
		return IMAGETYPE_JPEG;
	}
}