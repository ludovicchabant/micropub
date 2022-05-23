<?php

function resize_image($file, $width) {
    # https://stackoverflow.com/a/25181208/3297734
    $rotate = 0;
    $ext = pathinfo(basename($file), PATHINFO_EXTENSION);
    # if this is a JPEG, read the Exif data in order to
    # rotate the image, if needed
    if ($ext == 'jpeg') {
        $exif = @exif_read_data($file);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3:
                    $rotate = 180;
                    break;
                case 6:
                     $rotate = -90;
                     break;
                case 8:
                     $rotate = 90;
                     break;
            }
        }
    }
    $new = @imagecreatefromstring(@file_get_contents($file));
    if ($rotate !== 0) {
        $new = imagerotate($new, $rotate, 0);
    }
    // resize to our max width
    $new = imagescale( $new, $width );
    if ( $new === false ) {
        quit(415, 'invalid_file', 'Unable to process image.');
    }
    $width = imagesx($new);
    $height = imagesy($new);
    // create a new image from our source, for safety purposes
    $target = imagecreatetruecolor($width, $height);
    imagecopy($target, $new, 0, 0, 0, 0, $width, $height);
    imagedestroy($new);
    if ( $ext == 'gif' ) {
        // Convert to palette-based with no dithering and 255 colors
        imagetruecolortopalette($target, false, 255);
    }
    // write the file, using the GD function of the file type
    $result = call_user_func("image$ext", $target, $file);
    if ( $result === false ) {
        quit(400, 'error', 'unable to write image');
    }
    imagedestroy($target);
    chmod($file, 0644);
}

function media_upload($file, $target_dir, $max_width, $thumb_width) {
    # first make sure the file isn't too large
    if ( $file['size'] > 6000000 )  {
        quit(413, 'too_large', 'The file is too large.');
    }
    # now make sure it's an image. We only deal with JPG, GIF, PNG right now
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if (false === $ext = array_search($finfo->file($file['tmp_name']),
      array(
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
      ), true) ) {
        quit(415, 'invalid_file', 'Invalid file type was uploaded.');
    }

    $ext = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
    if ( $ext == 'jpg' ) {
        # normalize JPEG extension, so we can invoke GD functions easier
        $ext = 'jpeg';
    }
    # define our own name for this file.
    # and replace spaces with dashes, for sanity and safety
    $orig = str_replace(' ', '-', explode('.', $file['name'])[0]);
    $width_suffix = ($max_width > 0 ? '-$max_width' : '') . ".$ext";
    $filename = $orig . $width_suffix;
    # if the file already exists, add the date as a suffix
    $date_suffix = '';
    if (file_exists("$target_dir$filename")) {
        $date = new DateTime();
        $date_suffix = '-' . $date->format('u');
        $filename = $orig . $date_suffix . $width_suffix;
    }
    if (file_exists("$target_dir$filename")) {
        quit(409, 'file_exists', 'A filename conflict has occurred on the server.');
    }

    # we got here, so let's copy the file into place.
    if (! move_uploaded_file($file["tmp_name"], "$target_dir$filename")) {
        quit(403, 'file_error', 'Unable to save the uploaded file');
    }

    // check the image and resize if necessary
    $details = getimagesize("$target_dir$filename");
    if ( $details === false ) {
        quit(415, 'invalid_file', 'Invalid file type was uploaded.');
    }
    if ( $max_width > 0 && $details[0] > $max_width ) {
        resize_image("$target_dir$filename", $max_width );
    }

    if ($thumb_width > 0) {
        # let's make a thumbnail, too.
        $thumbnail = $orig . $date_suffix . "-$thumb_width.$ext";
        copy("$target_dir$filename", "$target_dir$thumbnail");
        resize_image("$target_dir$thumbnail", $thumb_width );
    } else {
        $thumbnail = false;
    }

    return [$filename, $thumbnail];
}

function create_media($file) {
    global $config;

    # upload the given file, and optionally copy it to the site source.
    $upload_path = $config['base_path'] . $config['upload_path'];
    check_target_dir($upload_path);

    [$filename, $thumbname] = media_upload($file, $upload_path,
        $config['max_image_width'], $config['thumbnail_width']);
    # do we need to copy this file to the source /static/ directory?
    if ($config['copy_uploads_to_source'] === TRUE ) {
        # we need to ensure '/source/static/uploads/YYYY/mm/' exists
        $copy_path = $config['source_path'] . 'static/' . $config['upload_path'];
        check_target_dir($copy_path);

        if ( copy ( $upload_path . $filename, $copy_path . $filename ) === FALSE ) {
            quit(400, 'copy_error', 'Unable to copy upload to source directory');
        }
        # be sure to copy the thumbnail file, too
        if ($thumbname) {
            if ( copy ( $upload_path . $thumbname, $copy_path . $thumbname ) === FALSE ) {
                quit(400, 'copy_error', 'Unable to copy thumbnail to source directory');
            }
        }
    }
    $url = $config['base_url'] . $config['upload_path'] . $filename;
    return $url;
}

function create_photo(array $photos) {
    global $config;

    # we upload to the source path here, because Hugo will copy the contents
    # of our static site into the rendered site for us.
    $copy_path = $config['source_path'] . 'static/' . $config['upload_path'];
    check_target_dir($copy_path);

    $photo_urls = array();
    foreach($photos as $photo) {
        [$filename, $thumbname] = media_upload($photo, $copy_path,
            $config['max_image_width'], $config['thumbnail_width']);
        $base_photo_url = $config['base_url'] . $config['upload_path'];
        $photo_urls[] = array(
            'photo' => $base_photo_url . $filename,
            'thumbnail' => ($thumbname ? $base_photo_url . $thumbname : false));
    }
    return $photo_urls;
}

?>
