<?php

function get_source_from_url($posttype, $url) {
    global $config;

    # strip base URL and do general cleanup
    $url = str_replace($config['base_url'], '', $url);
    $url = rtrim($url, "/ ");

    # capture important values from the URL
    $url_format = $config['content_urls'][$posttype];
    $post_regex = permalink_format_to_regex($url_format);
    $url_values = array();
    if (preg_match($post_regex, $url, $url_values) !== 1) {
        quit(400, 'permalink_error', "Can't match permalink format to: {$url}");
    }

    # use those values to come up with the source path
    $path_format = $config['content_paths'][$posttype];
    $source_post_path = format_permalink($path_format, $url_values);
    return $config['source_path'].'content/'.$source_post_path;
}

function get_permalink_tokens_from_properties($properties) {
    $tokens = array();

    # we expect some properties set, like 'date' and 'slug'
    $datetime = date_parse($properties['date']);
    $tokens['year'] = sprintf("%04d", $datetime['year']);
    $tokens['month'] = sprintf("%02d", $datetime['month']);
    $tokens['day'] = sprintf("%02d", $datetime['day']);

    $tokens['filename'] = $properties['slug'];

    return $tokens;
}

function get_source_from_properties($properties) {
    global $config;

    # figure out the relative path(s) for this post.
    $posttype = $properties['posttype'];

    $content_path = $config['content_paths'][$posttype];
    $storage = $config['content_storage_type'][$posttype] ?? 'data';

    if ($storage == 'page') {
        # page items only have a post file.
        $content_path = array($content_path);
    } else if ($storage == 'data') {
        # data items have both a data file and a post file.
        if (!is_array($content_path)) {
            $content_path = array(
                $content_path.'.yaml',
                $content_path.'.md'
            );
        }
        # if the post file goes into a subdirectory, we need to add a
        # section file so that Hugo will generate a section template.
        if (strpos($content_path[1], '/') !== false) {
            $section_path = dirname($content_path[1]).'/_index.md';
            $content_path[] = $section_path;
        } else {
            $content_path[] = false;
        }
    } else {
        quit(400, 'config_error', "Unsupported storage type: ${storage}");
    }

    # optionally render the paths if they're using formatting tokens.
    $out_paths = array();
    foreach ($content_path as $path) {
        if (strchr($path, ':') !== false) {
            # we may have to format this path
            $path_values = get_permalink_tokens_from_properties($properties);
            $path = format_permalink($path, $path_values);
        }
        $out_paths[] = $path;
    }

    # make the paths absolute.
    if (count($out_paths) == 1) {
        return $config['source_path'].'content/'.$out_paths[0];
    } else {
        $data_path = $config['source_path'].'data/'.$out_paths[0];
        $post_path = $config['source_path'].'content/'.$out_paths[1];
        $section_path = $out_paths[2] ? $config['source_path'].'content/'.$out_paths[2] : false;
        return array($data_path, $post_path, $section_path);
    }
}

function get_url_from_properties($properties) {
    global $config;

    # if we have a custom permalink format, use it.
    # otherwise, no custom URL is defined, it matches the on-disk path.
    $posttype = $properties['posttype'];
    $url_format = $config['content_urls'][$posttype] ?? false;
    if ($url_format === false) {
        $url = $config['content_paths'][$posttype];
        return $config['base_url'].$url;
    }

    $url_values = get_permalink_tokens_from_properties($properties);
    $url = format_permalink($url_format, $url_values);
    return $config['base_url'].$url;
}

function permalink_format_to_regex($permalink) {
    $pattern = str_replace(
        array(
            ':year', ':month', ':day',
            ':filename'),
        array(
            '(?P<year>[0-9]{4})', '(?P<month>[0-9]{2})', '(?P<day>[0-9]{2})',
            '(?P<filename>[^/\?]+)'),
        $permalink);
    return '~'.$pattern.'~';
}

function format_permalink($permalink_format, $values) {
    # remove any trailing slash or `index.html` from the filename
    $stripped_filename = rtrim($values["filename"], ' /');
    $stripped_filename = preg_replace('~(/index\.html?)$~', '', $stripped_filename);
    $permalink = str_replace(
        array(
            ':year', ':month', ':day',
            ':filename'),
        array(
            $values["year"], $values["month"], $values["day"],
            $stripped_filename),
        $permalink_format);
    return $permalink;
}

?>
