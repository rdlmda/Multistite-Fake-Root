<?php

/**
 * Plugin Name: Multistite Fake Root
 * Description: A pseudo-root directory for serving files on URLs of multisite instances
 * Plugin URI:  https://github.com/rdlmda/wp-ms-fake-root
 * Version:     1.0
 * Author:      Rudá Almeida
 * Author URI:  https://rdlmda.me
 * Text Domain: rdlmda-ms-fake-root
 */

add_action( 'parse_request', 'msfr_handle_request', 0 );
function msfr_handle_request( $wp ) {
    // Configuration
    $SUBDIR_NAME    = 'fake-root'; // folder name, place on 
    $USE_X_SENDFILE = false;       // set true if server supports X-Sendfile
    $CACHE_MAX_AGE  = 0;           // time in seconds. 
                                   // Disabled because of conflicts with the Surge cache plugin.

    // Only proceed on front-end requests
    if ( is_admin() ) {
        return;
    }

    // Get requested path (no query), decode and normalize
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? strtok( $_SERVER['REQUEST_URI'], '?' ) : '/';
    $path = rawurldecode( $request_uri );
    $path = preg_replace('#/+#', '/', $path);
    $path = ltrim( $path, '/' ); // empty string means root request

    // Map WP blog/site to uploads path
    if ( ! function_exists( 'wp_get_upload_dir' ) ) {
        return;
    }
    $uploads = wp_get_upload_dir(); // returns current site's uploads
    if ( empty( $uploads['basedir'] ) ) {
        return;
    }

    $site_serve_base = trailingslashit( $uploads['basedir'] ) . $SUBDIR_NAME;
    $site_serve_base_real = realpath( $site_serve_base );
    if ( $site_serve_base_real === false ) {
        return;
    }

    // Build filesystem path for request
    $fs_path = $site_serve_base_real . ( $path !== '' ? '/' . $path : '' );

    // Normalize and protect against traversal
    $fs_real = realpath( $fs_path );
    if ( $fs_real === false ) {
        return;
    }
    if ( strpos( $fs_real, $site_serve_base_real ) !== 0 ) {
        status_header(400);
        echo 'Bad request';
        exit;
    }

    // If it's a directory
    if ( is_dir( $fs_real ) ) {
        // Add trailing slash if missing
        if ( substr( $request_uri, -1 ) !== '/' ) {
            wp_redirect( trailingslashit( $request_uri ), 301 );
            exit;
        }

        // Try to serve index.html
        $index = $fs_real . '/index.html';
        if ( is_file( $index ) && is_readable( $index ) ) {
            $fs_real = $index;
        } else {
            //status_header(403);
            //echo 'Forbidden';
            //exit;
            return;
        }
    }

    if ( ! is_file( $fs_real ) || ! is_readable( $fs_real ) ) {
        return; // let WP continue (404 if nothing)
    }

    // Determine MIME type
    $filetype = wp_check_filetype( $fs_real );
    $mime = $filetype['type'];
    if ( ! $mime ) {
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            $mime = finfo_file( $finfo, $fs_real );
            finfo_close( $finfo );
        } else {
            $mime = 'application/octet-stream';
        }
    }

    // Prevent executing PHP-like files
    $ext = strtolower( pathinfo( $fs_real, PATHINFO_EXTENSION ) );
    $dangerous = array( 'php', 'phtml', 'phar' );
    $as_attachment = in_array( $ext, $dangerous, true );

    // Send headers
    status_header(200);
    header_remove();
    header( 'Content-Type: ' . $mime );
    header( 'Content-Length: ' . filesize( $fs_real ) );
    header( 'Cache-Control: public, max-age=' . intval( $CACHE_MAX_AGE ) );
    if ( $as_attachment ) {
        header( 'Content-Disposition: attachment; filename="' . basename( $fs_real ) . '"' );
    }

    // Use X-Sendfile when available
    if ( $USE_X_SENDFILE ) {
        header( 'X-Sendfile: ' . $fs_real );
        exit;
    }

    // Stream file efficiently
    $fp = fopen( $fs_real, 'rb' );
    if ( $fp === false ) {
        status_header(500);
        echo 'Server error';
        exit;
    }
    while ( ob_get_level() ) {
        ob_end_flush();
    }
    fpassthru( $fp );
    fclose( $fp );
    exit;
}

// Helper: ensure trailingslashit exists if plugin loaded too early (should be present)
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $string ) {
        return rtrim( $string, '/' ) . '/';
    }
}
