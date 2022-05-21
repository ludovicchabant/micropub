<?php

# we can't do anything without a config file
if ( ! file_exists('config.php') ) {
    die;
}

$config = include_once './config.php';

# TODO: default config values

# invoke the composer autoloader for our dependencies
require_once __DIR__.'/vendor/autoload.php';

# load our common libraries
include_once './inc/common.php';
include_once './inc/urls.php';
include_once './inc/content.php';
include_once './inc/media.php';
include_once './inc/twitter.php';

$is_cli = (php_sapi_name() == "cli");

if (!$is_cli) {

    // Take headers and other incoming data
    $headers = getallheaders();
    if ($headers === false ) {
        quit(400, 'invalid_headers', 'The request lacks valid headers');
    }
    $headers = array_change_key_case($headers, CASE_LOWER);
    if (!empty($_POST['access_token'])) {
        $token = "Bearer ".$_POST['access_token'];
        $headers["authorization"] = $token;
    } elseif (!empty($_GET['access_token'])) {
        $token = "Bearer ".$_GET['access_token'];
        $headers["authorization"] = $token;
    }
    if (! isset($headers['authorization']) ) {
        quit(401, 'no_auth', 'No authorization token supplied.');
    }
    // check the token for this connection.
    indieAuth($config['token_endpoint'], $headers['authorization'], $config['base_url']);

} else {

    // read cli options
    $options = getopt("m:f:p:d:h");

    if (isset($options['h'])) {
        echo "Micropub test cli\n";
        echo "\n";
        echo "Usage:\n";
        echo "    -m         http method (default: GET)\n";
        echo "    -f [path]  upload file\n";
        echo "    -p [k=val] pass query parameters\n";
        echo "    -d [data]  pass post data (sets http method to POST)\n";
        echo "\n";
        die();
    }

    $_SERVER['REQUEST_METHOD'] = $options['m'] ?? 'GET';
    $_SERVER['CONTENT_TYPE'] = 'text';
    $_SERVER['HTTP_CONTENT_TYPE'] = 'text';

    if (isset($options['f'])) {
        $filename = $options['f'];
        $_FILES['file'] = array(
            'size' => filesize($filename),
            'tmp_name' => $filename,
            'name' => $filename
        );
    }
    if (isset($options['p'])) {
        $params = $options['p'];
        if (!is_array($params)) {
            $params = array($params);
        }
        foreach($params as $param) {
            $pair = explode('=', $param, 2);
            if (count($pair) == 2) {
                $_GET[$pair[0]] = $pair[1];
            } else {
                echo "ERROR: ignoring invalid key=value parameter: {$param}";
            }
        }
    }
    if (isset($options['d'])) {
        parse_str($options['d'], $_POST);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded; charset=utf-8';
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/x-www-form-urlencoded; charset=utf-8';
    }

}

# is this a GET request?
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['q'])) {
        switch ($_GET['q']):
            case 'config':
                show_config();
                break;
            case 'source':
                show_content_source($_GET['url'], $_GET['properties']);
                break;
            case 'syndicate-to':
                show_config('syndicate-to');
                break;
            default:
                show_info();
                break;
        endswitch;
    } else {
        show_info();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (array_key_exists('file', $_FILES)) {
        # this is a media endpoint file upload.  Process it and end.
        $url = create_media($_FILES['file']);
        quit(201, '', '', $url);
    }
    # one or more photos may be uploaded along with the content.
    $photo_urls = array();
    if (!empty($_FILES['photo'])) {
        # ensure we have a normal array of files on which to iterate
        $photos = normalize_files_array($_FILES['photo']);
        $photo_urls = create_photo($photos);
    }
    # Parse the JSON or POST body into an object
    $request = parse_request();
    switch($request->action):
        case 'delete':
            delete($request);
            break;
        case 'undelete':
            undelete($request);
            break;
        case 'update':
            update($request);
            break;
        default:
            create($request, $photo_urls);
            break;
    endswitch;
} else {
    # something other than GET or POST?  Unsupported.
    quit(400, 'invalid_request', 'HTTP method unsupported');
}

?>
