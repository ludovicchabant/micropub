<?php

function autorotate_image(Imagick $image) {
    switch ($image->getImageOrientation()) {
    case Imagick::ORIENTATION_TOPLEFT:
        break;
    case Imagick::ORIENTATION_TOPRIGHT:
        $image->flopImage();
        break;
    case Imagick::ORIENTATION_BOTTOMRIGHT:
        $image->rotateImage("#000", 180);
        break;
    case Imagick::ORIENTATION_BOTTOMLEFT:
        $image->flopImage();
        $image->rotateImage("#000", 180);
        break;
    case Imagick::ORIENTATION_LEFTTOP:
        $image->flopImage();
        $image->rotateImage("#000", -90);
        break;
    case Imagick::ORIENTATION_RIGHTTOP:
        $image->rotateImage("#000", 90);
        break;
    case Imagick::ORIENTATION_RIGHTBOTTOM:
        $image->flopImage();
        $image->rotateImage("#000", 90);
        break;
    case Imagick::ORIENTATION_LEFTBOTTOM:
        $image->rotateImage("#000", -90);
        break;
    default: // Invalid orientation
        break;
    }
    $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
}

function resize_image(Imagick $image, $width, $outpath="") {
    # handle orientation
    autorotate_image($image);

    # figure out height based on width
    $old_width = $image->getImageWidth();
    $old_height = $image->getImageHeight();
    if ($old_width <= 0 || $old_height <= 0) {
        quit(415, 'invalid_file', 'Unable to process image.');
    }

    # do the resize (passing 0 as the height does proportional resizing)
    $image->resizeImage($width, 0, Imagick::FILTER_TRIANGLE, 1);

    # save file
    if (!$outpath) {
        $outpath = $image->getImageFilename();
    }
    $result = $image->writeImage($outpath);
    if ($result !== true) {
        quit(400, 'error', 'Unable to write image: ' . $outpath);
    }
    chmod($outpath, 0644);
}

function check_upload($file) {
    global $config;

    # check any php errors
    if (isset($file['error'])) {
        switch ($file['error']) {
        case UPLOAD_ERR_INI_SIZE:
            quit(413, 'too_large', 'The file exceeds server limits');
        case UPLOAD_ERR_FORM_SIZE:
            quit(413, 'too_large', 'The file exceeds the form limits');
        case UPLOAD_ERR_PARTIAL:
            quit(400, 'incomplete', 'The file was incompletely uploaded');
        case UPLOAD_ERR_NO_FILE:
            quit(400, 'missing', 'No file was uploaded');
        case UPLOAD_ERR_NO_TMP_DIR:
            quit(500, 'no_tmp_dir', 'No temp directory exists on the server');
        case UPLOAD_ERR_CANT_WRITE:
            quit(500, 'no_write', 'The server has no write access to the temp directory');
        case UPLOAD_ERR_EXTENSION:
            quit(500, 'stopped', 'A server module stopped the file upload');
        }
    }

    # make sure the file isn't too small
    if ($file['size'] == 0) {
        quit(400, 'too_small', 'The file is empty');
    }

    # make sure the file isn't too large
    $max_size = isset($config['max_upload_size']) ? $config['max_upload_size'] : 6000000;
    if ($file['size'] > $max_size)  {
        quit(413, 'too_large', 'The file is too large.');
    }
}

function media_upload($file, $target_dir, $max_width, $thumb_width) {
    # make sure there's something valid.
    check_upload($file);
    # now make sure it's an image.
    # we only deal with JPG, GIF, PNG right now.
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

    $name = pathinfo($file['name'], PATHINFO_FILENAME);
    # replace spaces with dashes, for sanity and safety
    $name = str_replace(' ', '-', $name);
    $ext = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
    $width_suffix = ($max_width > 0 ? '-$max_width' : '');
    $filename = "$name$width_suffix.$ext";

    # if the file already exists or is a default name, add the date as a suffix
    $date_suffix = '';
    $is_nondescript = in_array($name, array('image', 'photo', 'file'));
    if ($is_nondescript || file_exists("$target_dir$filename")) {
        $date = new DateTime();
        $date_suffix = '-' . $date->format('u');
        $filename = "$name$date_suffix$width_suffix.$ext";
    }
    if (file_exists("$target_dir$filename")) {
        quit(409, 'file_exists', 'A filename conflict has occurred on the server.');
    }

    # we got here, so let's copy the file into place.
    if (! move_uploaded_file($file["tmp_name"], "$target_dir$filename")) {
        quit(403, 'file_error', 'Unable to save the uploaded file');
    }

    // check the image and resize if necessary.
    try {
        $image = new Imagick("$target_dir$filename");
    } catch (Exception $ex) {
        quit(415, 'invalid_file', 'Invalid file was uploaded.');
    }
    $image_width = $image->getImageWidth();
    if ($max_width > 0 && $image_width > $max_width ) {
        resize_image($image, $max_width);
    }

    # let's make a thumbnail, too.
    if ($thumb_width > 0 && $image_width > $thumb_width) {
        $thumbnail = "$name$date_suffix-$thumb_width.$ext";
        resize_image($image, $thumb_width, "$target_dir$thumbnail");
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

function create_photos(array $photos) {
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

function find_thumbnail($photo_url, $thumbnail_width) {
    global $config;

    $base_url = $config['base_url'];
    if (stripos($photo_url, $base_url) !== 0) {
        # Not an image that lives on our server.
        return false;
    }
    $rel_photo_url = substr($photo_url, strlen($base_url));
    $info = pathinfo($rel_photo_url);
    $rel_path = $info['dirname'];
    $name = $info['filename'];
    $ext = $info['extension'];

    $rel_thumb = "${rel_path}/${name}-${thumbnail_width}.${ext}";
    if (file_exists($config['source_path']."static/".$rel_thumb)) {
        return $base_url.$rel_thumb;
    }
    if (file_exists($config['base_path'].$rel_thumb)) {
        return $base_url.$rel_thumb;
    }
    return false;
}

function find_media_path($media_url, $all=false) {
    global $config;

    $base_url = $config['base_url'];
    if (stripos($media_url, $base_url) !== 0) {
        # Not a file that lives on our server.
        return false;
    }

    $out_paths = array();

    $rel_media_url = substr($media_url, strlen($base_url));
    $source_path = $config['source_path']."static/".$rel_media_url;
    if (file_exists($source_path)) {
        $out_paths[] = $source_path;
    }
    $public_path = $config['base_path'].$rel_media_url;
    if (file_exists($public_path)) {
        $out_paths[] = $public_path;
    }

    if (count($out_paths) == 0) {
        return false;
    }
    return $all ? $out_paths : $out_paths[0];
}

?>
