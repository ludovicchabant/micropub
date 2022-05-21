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

function get_url_from_properties($properties) {
    global $config;
    $url_values = array();

    # we expect some properties set, like 'date' and 'slug'
    $datetime = date_parse($properties['date']);
    $url_values['year'] = sprintf("%04d", $datetime['year']);
    $url_values['month'] = sprintf("%02d", $datetime['month']);
    $url_values['day'] = sprintf("%02d", $datetime['day']);

    $url_values['filename'] = $properties['slug'];

    $posttype = $properties['posttype'];
    $url_format = $config['content_urls'][$posttype];
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
