##Attachment Image Resizing

###License

This Adddons Source Code is subject to the terms of the Mozilla Public License version 1.1 (the "License"). You can obtain a copy of the License at [http://mozilla.org/MPL/1.1/](http://mozilla.org/MPL/1.1.)

###Introduction

- Adds capability to automatically resize attachment images (.jpg, .png, .gif, or .bmp) to fit within the bounds specified.  Without this addon, over-sized WxH images would be rejected.
- The image format will be maintained unless it is unable to fit the resized image within the max allowed file size specified.  In this case, if the optional change format is enabled, the system will convert the image to JPEG for better compression.
- The addon also allows the upload size to be the maximum allowed by the server, but still respects the limits set in the admin panel.  This again allows over-sized images to be compressed, the compressed and resized images are then validated against the limits set in the control panel.
- The ability to set a max width and height for attached images, images are resized proportionately
- The ability to allow converting image formats only if its required to maintain the file within the specified max file size

There are admin settings available with this mod, go to forum -> attachments and avatars -> attachment settings -> find resize attachment section on the page
Here you can set various features of the addon, as well as disable it.