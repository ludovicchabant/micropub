<?php

use Symfony\Component\Yaml\Yaml;

function normalize_frontmatter($mf2props) {
    global $config;
    $fmt = $config['frontmatter_format'] ?? 'default';
    $funcname = "normalize_${fmt}_frontmatter";
    if (is_callable($funcname)) {
        return call_user_func($funcname, $mf2props);
    } else {
        return normalize_default_frontmatter($mf2props);
    }
}

function normalize_default_frontmatter(array $mf2props) {
    return normalize_properties($mf2props);
}

function make_image_frontmatter(array &$properties, array &$photos, $replace=false) {
    global $config;
    $fmt = $config['frontmatter_format'] ?? 'default';
    $funcname = "make_${fmt}_image_frontmatter";
    if (is_callable($funcname)) {
        call_user_func_array($funcname, array(&$properties, &$photos, $replace));
    } else {
        make_default_image_frontmatter($properties, $photos, $replace);
    }
}

function make_default_image_frontmatter(array &$properties, array &$photos, $replace) {
    $photo_urls = array_map(function ($val) { return $val['photo']; }, $photos);
    if ($replace || !isset($properties['photo'])) {
        $properties['photo'] = $photo_urls;
    } else {
        $properties['photo'] = array_merge($properties['photo'], $photo_urls);
    }

    # add thumbnails to the front matter.
    $thumb_urls = array_filter(
        array_map(function ($val) { return $val['thumbnail']; }, $photos),
            function ($val) { return $val; });
    if ($replace || !isset($properties['thumbnail'])) {
        $properties['thumbnail'] = $thumb_urls;
    } else {
        $properties['thumbnail'] = array_merge($properties['thumbnail'], $thumb_urls);
    }
}

function finalize_frontmatter(array &$properties) {
    global $config;
    $fmt = $config['frontmatter_format'] ?? 'default';
    $funcname = "finalize_${fmt}_frontmatter";
    if (is_callable($funcname)) {
        call_user_func_array($funcname, array(&$properties));
    } else {
        finalize_default_frontmatter($properties);
    }
}

function finalize_default_frontmatter(array &$properties) {
}

function parse_file($original) {
    $properties = [];
    # all of the front matter will be in $parts[1]
    # and the contents will be in $parts[2]
    $parts = preg_split('/[\n]*[-]{3}[\n]/', file_get_contents($original), 3);
    $front_matter = Yaml::parse($parts[1]);
    // All values in mf2 json are arrays
    foreach (Yaml::parse($parts[1]) as $k => $v) {
        if(!is_array($v)) {
            $v = [$v];
        }
        $properties[$k] = $v;
    }
    $properties['content'] = [ trim($parts[2]) ];
    return $properties;
}

# finds the posttype of the given URL, defaults to `article`.
function get_content_type_from_url($url) {
    global $config;
    foreach ($config['content_urls'] as $pt => $perm) {
        $perm_regex = permalink_format_to_regex($perm);
        if (preg_match($perm_regex, $url) === 1) {
            return $pt;
        }
    }
    return 'article';
}

# this function fetches the source of a post and returns a JSON
# encoded object of it.
function show_content_source($url, $properties = []) {
    $posttype = get_content_type_from_url($url);
    $source = parse_file( get_source_from_url($posttype, $url) );
    $props = [];

    # the request may define specific properties to return, so
    # check for them.
    if ( ! empty($properties)) {
        foreach ($properties as $p) {
            if (array_key_exists($p, $source)) {
                $props[$p] = $source[$p];
            }
        }
    } else {
        $props = $source;
    }
    header( "Content-Type: application/json");
    print json_encode( [ 'properties' => $props ] );
    die();
}

# this takes a string and returns a slug.
# I generally don't use non-ASCII items in titles, so this doesn't
# worry about any of that.
function slugify($string) {
    return strtolower( preg_replace("/[^-\w+]/", "", str_replace(' ', '-', $string) ) );
}

# this takes an MF2 array of arrays and converts single-element arrays
# into non-arrays.
function normalize_properties($properties) {
    $props = [];
    foreach ($properties as $k => $v) {
        # we want the "photo" property to be an array, even if it's a
        # single element.
        if ($k == 'photo') {
            $props[$k] = $v;
        } elseif (is_array($v) && count($v) === 1) {
            $props[$k] = $v[0];
        } else {
            $props[$k] = $v;
        }
    }
    # MF2 defines "name" instead of title, but Hugo wants "title".
    # Only assign a title if the post has a name.
    if (isset($props['name'])) {
        $props['title'] = $props['name'];
    }
    return $props;
}

# this function is a router to other functions that can operate on the source
# URLs of reposts, replies, bookmarks, etc.
# $type = the indieweb type (https://indieweb.org/post-type-discovery)
# $properties = array of front-matter properties for this post
# $content = the content of this post (which may be an empty string)
#
function posttype_source_function($posttype, $properties, $content) {
    # replace all hyphens with underscores, for later use
    $type = str_replace('-', '_', $posttype);
    # get the domain of the site to which we are replying, and convert
    # all dots to underscores.
    $target = str_replace('.', '_', parse_url($properties[$posttype], PHP_URL_HOST));
    # if a function exists for this type + target combo, call it
    if (function_exists("${type}_${target}")) {
        list($properties, $content) = call_user_func("${type}_${target}", $properties, $content);
    }
    return [$properties, $content];
}

# this function accepts the properties of a post and
# tries to perform post type discovery according to
# https://indieweb.org/post-type-discovery
# returns the MF2 post type
function post_type_discovery($properties) {
    $vocab = array('rsvp',
                 'in-reply-to',
                 'repost-of',
                 'like-of',
                 'bookmark-of',
                 'photo');
    foreach ($vocab as $type) {
        if (isset($properties[$type])) {
            return $type;
        }
    }
    # articles have titles, which Micropub defines as "name"
    if (isset($properties['name'])) {
        return 'article';
    }
    # no other match?  Must be a note.
    return 'note';
}

# given an array of front matter and body content, return a full post
# Articles are full Markdown files; everything else is just YAML blobs
# to be appended to a data file.
function build_post( $front_matter, $content) {
    global $config;
    $posttype = $front_matter['posttype'];
    $storage_type = $config['content_storage_type'][$posttype] ?? 'data';
    ksort($front_matter);
    if ($storage_type == 'page') {
      return "---\n" . Yaml::dump($front_matter) . "---\n" . $content . "\n";
    } else {
      $front_matter['content'] = $content;
      return Yaml::dump(array($front_matter), 2, 2);
    }
}

function write_file($file, $content, $overwrite = false) {
    # make sure the directory exists, in the event that the filename includes
    # a new sub-directory
    if ( ! file_exists(dirname($file))) {
        check_target_dir(dirname($file));
    }
    if (file_exists($file) && ($overwrite == false) ) {
        quit(400, 'file_conflict', 'The specified file exists');
    }
    if ( FALSE === file_put_contents( $file, $content ) ) {
        quit(400, 'file_error', 'Unable to open Markdown file');
    }
}

function delete($request) {
    global $config;

    $posttype = get_content_type_from_url($request->url);
    $filename = get_source_from_url($posttype, $request->url);
    if (false === unlink($filename)) {
        quit(400, 'unlink_failed', 'Unable to delete the source file.');
    }
    # to delete a post, simply set the "published" property to "false"
    # and unlink the relevant .html file
    $json = json_encode( array('url' => $request->url,
        'action' => 'update',
        'replace' => [ 'published' => [ false ] ]) );
    $new_request = \p3k\Micropub\Request::create($json);
    update($new_request);
}

function undelete($request) {
    # to undelete a post, simply set the "published" property to "true"
    $json = json_encode( array('url' => $request->url,
        'action' => 'update',
        'replace' => [ 'published' => [ true ] ]) );
    $new_request = \p3k\Micropub\Request::create($json);
    update($new_request);
}

function update($request) {
    $posttype = get_content_type_from_url($request->url);
    $filename = get_source_from_url($posttype, $request->url);
    $original = parse_file($filename);
    foreach($request->update['replace'] as $key=>$value) {
        $original[$key] = $value;
    }
    foreach($request->update['add'] as $key=>$value) {
        if (!array_key_exists($key, $original)) {
            # adding a value to a new key.
            $original[$key] = $value;
        } else {
            # adding a value to an existing key
            $original[$key] = array_merge($original[$key], $value);
        }
    }
    foreach($request->update['delete'] as $key=>$value) {
        if (!is_array($value)) {
            # deleting a whole property
            if (isset($original[$value])) {
                unset($original[$value]);
            }
        } else {
            # deleting one or more elements from a property
            $original[$key] = array_diff($original[$key], $value);
        }
    }
    $content = $original['content'][0];
    unset($original['content']);
    $original = normalize_properties($original);
    write_file($filename, build_post($original, $content), true);
    build_site();
}

function create($request, $photos = []) {
    global $config;

    $mf2 = $request->toMf2();
    # grab the list of photos from the MF2 data, we might need it later.
    $all_photos = $photos;
    if ($config['bundle_photos'] ?? FALSE) {
        $thumbnail_width = $config['thumbnail_width'];
        if (isset($mf2['properties']['photo'])) {
            foreach ($mf2['properties']['photo'] as $photo_url) {
                $all_photos[] = array(
                    'photo' => $photo_url,
                    'thumbnail' => find_thumbnail($photo_url, $thumbnail_width)
                );
            }
        }
    }
    # make a more normal PHP array from the MF2 JSON array.
    $properties = normalize_frontmatter($mf2['properties']);

    # pull out just the content, so that $properties can be front matter
    # NOTE: content may be in ['content'] or ['content']['html'].
    # NOTE 2: there may be NO content!
    if (isset($properties['content'])) {
        if (is_array($properties['content']) && isset($properties['content']['html'])) {
            $content = $properties['content']['html'];
        } else {
            $content = $properties['content'];
        }
    } else {
        $content = '';
    }
    # ensure that the properties array doesn't contain 'content'
    unset($properties['content']);

    if (!empty($photos)) {
        # add uploaded photos to the front matter.
        make_image_frontmatter($properties, $photos);
    }

    # figure out what kind of post this is.
    $posttype = post_type_discovery($properties);
    $properties['posttype'] = $posttype;

    # invoke any source-specific functions for this post type.
    # articles, notes, and photos don't really have "sources", other than
    # their own content.
    # replies, reposts, likes, bookmarks, etc, should reference source URLs
    # and may interact with those sources here.
    if (! in_array($posttype, ['article', 'note', 'photo'])) {
        list($properties, $content) = posttype_source_function($posttype, $properties, $content);
    }

    # all items need a date
    if (!isset($properties['date'])) {
        $properties['date'] = date('Y-m-d H:i:s');
    }

    if (isset($properties['post-status'])) {
        if ($properties['post-status'] == 'draft') {
            $properties['published'] = false;
        } else {
            $properties['published'] = true;
        }
        unset($properties['post-status']);
    } else {
        # explicitly mark this item as published
        $properties['published'] = true;
    }

    # we need either a title, or a slug.
    # NOTE: MF2 defines "name" as the title value.
    if ((!isset($properties['name']) || !$properties['name']) &&
	    (!isset($properties['slug']) || !$properties['slug'])) {
        # We will assign this a slug.
	$properties['slug'] = isset($config['slug_fallback']) ?
	    $config['slug_fallback'] : dechex(date('U'));
    }

    # if we have a title but not a slug, generate a slug
    if (isset($properties['name']) && !isset($properties['slug'])) {
        $properties['slug'] = $properties['name'];
    }
    # make sure the slugs are safe.
    if (isset($properties['slug'])) {
        $properties['slug'] = slugify($properties['slug']);
    }

    # figure out the URL and filename for this post.
    $url = get_url_from_properties($properties);
    $filename = get_source_from_properties($properties);

    # optionally bundle photos with the post
    $storage_type = $config['content_storage_type'][$posttype] ?? 'data';
    if ($storage_type == 'page') {
        if ($config['bundle_photos'] ?? FALSE) {
            $filename = bundle_photos_with_post($filename, $url, $all_photos, $properties);
        }
    }

    # last minute massaging of the front-matter.
    finalize_frontmatter($properties);

    # build the entire source file, with front matter and content for articles
    # or YAML blobs for notes, etc
    $file_contents = build_post($properties, $content);

    if ($storage_type == 'page') {

        # write_file will default to NOT overwriting existing files,
        # so we don't need to check that here.
        write_file($filename, $file_contents);

    } elseif ($storage_type == 'data') {

        # this content will be appended to a data file, and both a post
        # file and (optionally) a section file will be created.
        $yaml_path = $filename[0];
        $md_path = $filename[1];
        $section_path = $filename[2];
        $url = $url . '/#' . $properties['slug'];
        check_target_dir(dirname($yaml_path));
        check_target_dir(dirname($md_path));
        if (! file_exists($yaml_path)) {
            # prep the YAML for our note which will follow
            file_put_contents($yaml_path, "---\nentries:\n");
        }
        file_put_contents($yaml_path, $file_contents, FILE_APPEND);
        # now we need to create a Markdown file, so that Hugo will
        # build the file for public consumption.
        # NOTE: we may want to override the post type here, so that we
        #       can use a singular Hugo theme for multiple post types.
        if (array_key_exists($properties['posttype'], $config['content_overrides'])) {
            $content_type = $config['content_overrides'][$properties['posttype']];
        } else {
            $content_type = $properties['posttype'];
        }
        if (! file_exists($md_path)) {
            file_put_contents($md_path, "---\ntype: $content_type\n---\n");
        }
        # we may need to create a _index.md file so that a section template
        # can be generated. If the content_path has any slashes in it, that
        # means that sub-directories are defined, and thus a section index
        # is required.
        if (FALSE !== $section_path) {
            file_put_contents($section_path, "---\ntype: $content_type\n---\n");
        }

    } else {

        quit(400, 'storage_error', "Unsupported storage type: {$storage_type}");

    }

    # build the site.
    build_site();

    # set the header for the new post's URL.
    global $is_cli;
    if (!$is_cli) {
        # allow the client to move on, while we syndicate this post
        header('HTTP/1.1 201 Created');
        header('Location: ' . $url);
    }

    # syndicate this post.
    syndicate_post($request, $properties, $content, $url);

    # send a 201 response, with the URL of this item.
    quit(201, null, null, $url);
}

function syndicate_post($request, $properties, $content, $url) {
    global $config;

    $syndication_targets = array();
    # some post kinds may enforce syndication, even if the Micropub client
    # did not send an mp-syndicate-to parameter. This code finds those post
    # kinds and sets the mp-syndicate-to.
    if (isset($config['always_syndicate'])) {
        if (array_key_exists($properties['posttype'], $config['always_syndicate'])) {
            foreach ($config['always_syndicate'][$properties['posttype']] as $target) {
                $syndication_targets[] = $target;
            }
        }
    }
    if (isset($request->commands['mp-syndicate-to'])) {
        $syndication_targets = array_unique(array_merge($syndication_targets, $request->commands['mp-syndicate-to']));
    }
    if (! empty($syndication_targets)) {
        # ensure we don't have duplicate syndication targets
        foreach ($syndication_targets as $target) {
            if (function_exists("syndicate_$target")) {
                $syndicated_url = call_user_func("syndicate_$target", $config['syndication'][$target], $properties, $content, $url);
                if (false !== $syndicated_url) {
                    $syndicated_urls["$target-url"] = $syndicated_url;
                }
            }
        }
        if (!empty($syndicated_urls)) {
            # convert the array of syndicated URLs into scalar key/value pairs
            # if this is an article let's just re-write it,
            # with the new properties in the front matter.
            # NOTE: we are NOT rebuilding the site at this time.
            #       I am unsure whether I even want to display these
            #       links.  But it's easy enough to collect them, for now.
            if ($properties['posttype'] == 'article') {
                foreach ($syndicated_urls as $k => $v) {
                    $properties[$k] = $v;
                }
                $file_contents = build_post($properties, $content);
                write_file($filename, $file_contents, true);
            } else {
                # this is not an article, so we should be able to simply
                # append the syndicated URL to the YAML data file
                foreach ($syndicated_urls as $k => $v) {
                  file_put_contents($yaml_path, "  $k: $v\n", FILE_APPEND);
                }
            }
        }
    }
}

function bundle_photos_with_post($filename, $url, array $photos, array &$properties) {
    global $config;

    $age_threshold = $config['bundle_photos_age_threshold'] ?? 30;
    $now_minus_threshold = time() - $age_threshold;

    $files_to_move = array();
    $files_to_unlink = array();
    $url = rtrim($url, '/ ').'/';

    # Find the file(s) for each photo and thumbnail. If they are more recent
    # than our threshold, bundle them with the page.
    foreach ($photos as &$photo) {
        $photo_url = $photo['photo'];
        $photo_paths = find_media_path($photo_url, true);

        $thumb_url = $photo['thumbnail'];
        $thumb_paths = $thumb_url ? find_media_path($thumb_url, true) : false;

        if ($photo_paths !== false) {
            $photo_mtime = filemtime($photo_paths[0]);
            if ($photo_mtime >= $now_minus_threshold) {
                $files_to_move[] = $photo_paths[0];
                if (isset($photo_paths[1])) $files_to_unlink[] = $photo_paths[1];

                $fname = basename($photo_paths[0]);
                $photo['photo'] = $url.$fname;
            }
        }
        if ($thumb_paths !== false) {
            $thumb_mtime = filemtime($thumb_paths[0]);
            if ($thumb_mtime >= $now_minus_threshold) {
                $files_to_move[] = $thumb_paths[0];
                if (isset($thumb_paths[1])) $files_to_unlink[] = $thumb_paths[1];

                $fname = basename($thumb_paths[0]);
                $photo['thumbnail'] = $url.$fname;
            }
        }
    }

    if (count($files_to_move) == 0) {
        return $filename;
    }

    # We have found files to bundle. Create the bundle directory and move
    # the files there. Return the path for the bundle's index file.
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if ($ext) $ext = ".".$ext;  # pathinfo doesn't include the dot
    $bundle_dir = substr($filename, 0, strlen($filename) - strlen($ext))."/";
    $bundle_index = $bundle_dir."index".$ext;

    check_target_dir($bundle_dir);
    foreach ($files_to_move as $file_to_move) {
        $bundle_file = $bundle_dir.basename($file_to_move);
        if (false === rename($file_to_move, $bundle_file)) {
            quit(400, "cannot_move", "Can't move ".$file_to_move." to ".$bundle_file);
        }
    }

    # Delete files that were uploaded directly to the public upload directory.
    foreach ($files_to_unlink as $file_to_unlink) {
        unlink($file_to_unlink);
    }

    # Rewrite the frontmatter for the different photo URLs.
    # We pass `true` to indicate the frontmatter formatter to replace any
    # existing photos.
    make_image_frontmatter($properties, $photos, true);

    return $bundle_index;
}

?>
