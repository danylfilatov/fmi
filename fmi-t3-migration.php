<?php
/*
 * Plugin Name:       fMi Migration
 * Description:       Custom Typo3 to WP migration plugin for fMi website
 * Version:           1.0.0
 * Plugin URI:        https://github.com/danylfilatov
 * Author:            Danyl Filatov
 * Author URI:        https://github.com/danylfilatov
 */

// DEBUGGING

function dbg( ...$els ) {
	$bool_args = array_fill_keys( [ 'p', 'r', 'h', 'd', 'w', 'l', 'x' ], false );
    $args_array = array_filter( $els, function ( $el ) { return is_string( $el ) && strpos( $el, 'dbg:' ) === 0; } ) ?: '';
    if ( ! empty( $args_array ) ) { unset( $els[ array_keys( $args_array )[ 0 ] ] ); $args_array = array_values( $args_array )[ 0 ]; }
    if ( empty( $els ) ) { return; }
    $args_array = substr( $args_array, 4 );
	if ( is_string( $args_array ) ) foreach ( str_split( $args_array ) as $arg ) { if ( isset( $bool_args[ $arg ] ) ) $bool_args[ $arg ] = ! $bool_args[ $arg ]; }
	extract( $bool_args );
	$n = $args_array[ 'n' ] ?? '';
	if ( $l ) { array_map( function( $el ) use ( $x ) { error_log( 'DBG: ' . ( $x ? $el : var_export( $el, true ) ) ); }, $els ); return; }
	if ( $r ) ob_start();
	echo '<pre' . ( $w ? ' style="white-space: pre-wrap; overflow-wrap: break-word;"' : '' ) . '>';
	echo 'DBG:' . ( $n ? ' ' . $n : '' ) . '<br>';
    foreach( $els as $el ) {
		if ( $h ) ob_start();
		if ( $p ) { if ( is_array( $el ) || $el instanceof Countable ) echo '(Count: ' . count( $el ) . ') '; print_r( $el ); }
        else var_dump( $el );
		if ( $h ) echo htmlspecialchars( ob_get_clean() );
        echo '<br>';
    }
	echo '</pre>';
	if ( $r ) return ob_get_clean();
	if ( $d ) die;
}

// 

class fmi_Migration {

    private static $t3_db_user = 'root';

    private static $t3_db_password = '';

    private static $t3_db_name = 'fitnessmanagement';

    private static $t3_db_host = 'localhost';

    private static $t3_site_path = 'C:/laragon/www/www.fitnessmanagement.de';

    private static $t3_upload_dir = '/fileadmin';

    private static $tpdb;

    private static $t3_upload_base;

    private static $cat_mapping;

    private static $tag_mapping;

    public function __construct() {
        self::$tpdb = new wpdb( self::$t3_db_user, self::$t3_db_password, self::$t3_db_name, self::$t3_db_host );
        self::$t3_upload_base = self::$t3_site_path . self::$t3_upload_dir;
        self::$cat_mapping = get_option( 'fmi_typo3_wp_cat_mapping', [] );
        self::$tag_mapping = get_option( 'fmi_typo3_wp_tag_mapping', [] );

        add_filter( 'wp_insert_post_data', [ 'fmi_Migration', 'wpcli_insert_filter' ], 10, 2 );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'fmi_migrate_news', [ 'fmi_Migration', 'fmi_wpcli_news' ] );
            WP_CLI::add_command( 'fmi_migrate_categories', [ 'fmi_Migration', 'fmi_wpcli_categories' ] );
            WP_CLI::add_command( 'fmi_migrate_tags', [ 'fmi_Migration', 'fmi_wpcli_tags' ] );
        }
    }

    // 

    private static function wp_upload_t3_attachments( $uid, $foreign_table_name = 'tt_content', $field_name = false, $use_own_uid = false ) {
        // Handle typo3 stuff first

        $prepare_params = [
            $use_own_uid ? 'uid' : 'uid_foreign',
            $uid,
            $foreign_table_name,
        ];

        $prepare_query = "SELECT * FROM sys_file_reference WHERE %i = %d AND tablenames = %s";

        if ( $field_name ) {
            $prepare_query .= " AND fieldname = %s";
            $prepare_params[] = $field_name;
        }

        $prepare_query .= " AND hidden = 0 AND deleted = 0 ORDER BY sorting_foreign ASC";

        $img_data_arr = self::$tpdb->get_results(
            self::$tpdb->prepare(
                $prepare_query,
                $prepare_params
            ),
            ARRAY_A
        );

        $attachment_ids = [];

        foreach ( $img_data_arr as $img_data ) {
            $img_file_data = self::$tpdb->get_results(
                self::$tpdb->prepare(
                    "SELECT * FROM %i WHERE uid = %d",
                    $img_data['table_local'],
                    (int) $img_data['uid_local']
                ),
                ARRAY_A
            )[0];

            $image_title = $img_data['title'];
            $image_alt = $img_data['alternative'];

            $image_path = self::$t3_upload_base . $img_file_data['identifier'];

            $image_mime_type = $img_file_data['mime_type'];

            // Starting wp logic
            if ( !file_exists($image_path) ) continue;

            // Youtube / vimeo videos
            if ( 'video/youtube' === $image_mime_type ) {
                $attachment_ids[] = [
                    'type' => 'youtube',
                    'url' => 'https://www.youtube.com/watch?v=' . file_get_contents( $image_path )
                ];

                continue;
            } else if ( 'video/vimeo' === $image_mime_type ) {
                $attachment_ids[] = [
                    'type' => 'vimeo',
                    'url' => 'https://vimeo.com/' . file_get_contents( $image_path )
                ];

                continue;
            } else if ( 'application/pdf' === $image_mime_type ) {
                // skip pdfs

                continue;
            }

            // Include file and attachment utilities that are used below
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/image.php' );

            // mynotes.txt -> mynotes, txt
            $filename = basename($image_path);
            $extension = strtolower(pathinfo($image_path, PATHINFO_EXTENSION));

            // Pretend to upload a file using the traditional $_FILES global.
            // We use a temporary file because uploading this way deletes the existing file in order to put it in the uploads folder.
            // We do not want the original to be deleted, so the temporary file we use gets deleted instead
            $tmp = tmpfile();
            $tmp_path = stream_get_meta_data($tmp)['uri'];
            $tmp_filename = basename($tmp_path);
            fwrite($tmp, file_get_contents( $image_path ));
            fseek($tmp, 0); // If we don't do this, WordPress thinks the file is empty

            // Get mime type from file
            $mime = mime_content_type( $tmp_path );
            $mime = is_string($mime) ? sanitize_mime_type( $mime ) : false;

            // Mime type must be present or else it would fail the "upload"
            if ( !$mime ) {
                fclose($tmp);
                @unlink($tmp_path);
                continue;
            }

            // Array structure designed to mimic $_FILES, like if you submitted a form with an <input type="file">
            $_FILES[$tmp_filename] = array(
                'name'      => $filename,
                'type'      => $mime,
                'tmp_name'  => $tmp_path,
                'error'     => UPLOAD_ERR_OK,
                'size'      => filesize($image_path),
            );

            // Do the upload, this moves the file to the uploads folder
            $upload = wp_handle_upload( $_FILES[$tmp_filename], array( 'test_form' => false, 'action' => 'local' ) );

            // Clean up after upload
            fclose($tmp);
            @unlink($tmp_path);
            unset($_FILES[basename($tmp_path)]);

            // Abort if error occurred
            if ( !empty($upload['error']) ) continue;

            // Generate a title if needed
            if ( empty($image_title) ) $image_title = pathinfo($image_path, PATHINFO_FILENAME);

            // Create the "attachment" post, as seen on the media page
            $args = array(
                'post_title' => $image_title,
                'post_content' => '',
                'post_status' => 'publish',
                'post_mime_type' => $upload['type'],
            );

            $attachment_id = wp_insert_attachment( $args, $upload['file'] );

            // Abort if we could not insert the attachment
            // Also when aborted, delete the unattached file since it would not show up in the media gallery
            if ( is_wp_error( $attachment_id ) ) {
                @unlink($upload['file']);
                continue;
            }

            // Upload was successful, generate and save the image metadata
            $data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
            wp_update_attachment_metadata( $attachment_id, $data );

            // Set alt text
            if ( ! empty($image_alt) ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', $image_alt );
            }

            // All successful, return the attachment ID
            $attachment_ids[] = $attachment_id;
        }

        return $attachment_ids;
    }

    // 

    public static function wpcli_insert_filter( $data, $postarr ) {
        $data['post_modified'] = $postarr['post_modified'] ?? $data['post_modified'];
        $data['post_modified_gmt'] = $postarr['post_modified_gmt'] ?? $data['post_modified_gmt'];

        return $data;
    }

    // 

    private static function test_ids_flat( $tree ) {
        $ids_flat = [];

        array_walk_recursive(
            $tree,
            function( $value, $key ) use ( &$ids_flat ) {
                $ids_flat[] = $value;
            }
        );

        return $ids_flat;
    }

    // 

    public static function fmi_wpcli_news( $args ) {
        // 

        $news_q_batch_size = 100;
        $news_count = true;

        $news_q_offset = get_option( 'fmi_typo3_news_migration_done_count', 0 );

        $news_migrated_ids = get_option( 'fmi_typo3_news_migration_done_ids', [] );
        $news_migration_failed_ids = get_option( 'fmi_typo3_news_migration_failed_ids', [] );

        echo "Starting news migration at offset $news_q_offset...\n";

        while ( $news_count ) {
            $news_array = self::$tpdb->get_results(
                self::$tpdb->prepare(
                    "SELECT * FROM tx_news_domain_model_news WHERE hidden = 0 AND deleted = 0 AND endtime = 0 LIMIT %d, %d",
                    $news_q_offset,
                    $news_q_batch_size
                ),
                ARRAY_A
            );

            // DEBUGGING

            // $news_array = self::$tpdb->get_results(
            //     self::$tpdb->prepare(
            //         "SELECT * FROM tx_news_domain_model_news WHERE hidden = 0 AND deleted = 0 AND endtime = 0 AND uid = %d",
            //         5092
            //     ),
            //     ARRAY_A
            // );

            $news_count = count( $news_array );

            foreach( $news_array as $news_data ) {
                // 

                $news_uid = (int) $news_data['uid'];
                $news_pid = (int) $news_data['pid'];
                $news_author = $news_data['author'];
                $news_title = $news_data['title'];
                $news_title_detail = $news_data['title_detail'];
                $news_excerpt = $news_data['teaser'];
                $news_excerpt_detail = $news_data['teaser_detail'];
                $news_alternative_title = $news_data['alternative_title'];
                $news_description = $news_data['description'];
                $news_keywords = $news_data['keywords'];

                // DEBUGGING

                if ( isset( $news_migrated_ids[ $news_uid ] ) ) {
                    // 

                    $news_q_offset++;

                    update_option( 'fmi_typo3_news_migration_done_count', $news_q_offset );
                    
                    continue;
                }

                $news_categories = self::$tpdb->get_results(
                    self::$tpdb->prepare(
                        "SELECT uid_local FROM sys_category_record_mm WHERE uid_foreign = %d AND tablenames = %s",
                        $news_uid,
                        'tx_news_domain_model_news'
                    ),
                    ARRAY_A
                );

                $content_items = self::$tpdb->get_results(
                    self::$tpdb->prepare(
                        "SELECT * FROM tt_content WHERE tx_news_related_news = %d AND hidden = 0 AND deleted = 0 AND endtime = 0 ORDER BY sorting ASC",
                        $news_uid
                    ),
                    ARRAY_A
                );

                // 

                $post_data = [
                    'post_title' => $news_title,
                    'post_name' => $news_data['path_segment'],
                    'post_excerpt' => $news_excerpt,
                    'post_date' => date( "Y-m-d H:i:s", $news_data['datetime'] ),
                    'post_date_gmt' => gmdate( "Y-m-d H:i:s", $news_data['datetime'] ),
                    'post_modified' => date( "Y-m-d H:i:s", $news_data['tstamp'] ),
                    'post_modified_gmt' => gmdate( "Y-m-d H:i:s", $news_data['tstamp'] ),
                    'post_status' => 'publish',
                    'post_author' => 1,
                ];

                // 

                $wp_categories = array_values(
                    array_intersect_key(
                        self::$cat_mapping,
                        array_flip(
                            array_column(
                                $news_categories,
                                'uid_local'
                            )
                        )
                    )
                );

                $post_data['post_category'] = $wp_categories;

                // 

                if ( $news_pid === 21 ) {

                } else {
                    $post_data['post_type'] = 'article';
                }

                // 

                $featured_image = self::wp_upload_t3_attachments( $news_uid, 'tx_news_domain_model_news', 'fal_media' );

                if ( $featured_image && ! is_array( $featured_image[0] ) ) {
                    $post_data['_thumbnail_id'] = $featured_image[0];
                }

                // 

                $post_content = '';

                foreach( $content_items as $content_item ) {
                    $uid = (int) $content_item['uid'];
                    $item_type = $content_item['CType'];

                    // Body wysiwyg html

                    $body_text = $content_item['bodytext'];

                    $body_text = $body_text ? "\n\n<!-- wp:freeform -->$body_text<!-- /wp:freeform -->" : '';

                    // Image

                    $image = (int) $content_item['image'];
                    $image_orient = (int) $content_item['imageorient'];
                    $image_cols = (int) $content_item['imagecols'];

                    // 

                    $plugin_options_xml = $content_item['pi_flexform'];
                    $plugin_options = $plugin_options_xml ? simplexml_load_string( $plugin_options_xml ) : false;

                    $append_content = true;

                    if (
                        $image ||
                        'textmedia' === $item_type
                    ) {
                        // 

                        $wp_attachments = self::wp_upload_t3_attachments( $uid );

                        $image_html = '';

                        if ( $wp_attachments ) {
                            // Successfully uploaded image(s) to wp

                            if ( count( $wp_attachments ) > 1 ) {
                                // Multiple images - gallery block (+ html)

                                $image_html = "\n\n<!-- wp:gallery {\"columns\":$image_cols,\"imageCrop\":false,\"linkTo\":\"none\"} -->\n<figure class=\"wp-block-gallery has-nested-images columns-$image_cols\">";

                                foreach ( $wp_attachments as $wp_attachment ) {
                                    if ( is_array( $wp_attachment ) ) {
                                        // Skip videos

                                        continue;
                                    }

                                    $image_html .= "\n\n<!-- wp:image {\"id\":$wp_attachment,\"sizeSlug\":\"full\"} -->\n<figure class=\"wp-block-image size-full\"><img src=\"" . wp_get_attachment_image_url( $wp_attachment, "full" ) . "\" alt=\"" . get_post_meta( $wp_attachment, "_wp_attachment_image_alt", true ) . "\" class=\"wp-image-$wp_attachment\"/></figure>\n<!-- /wp:image -->";
                                }

                                $image_html .= "\n\n</figure>\n<!-- /wp:gallery -->";
                            } else {
                                // Single image

                                $wp_attachment = $wp_attachments[0];

                                if ( ! is_array( $wp_attachment ) ) {
                                    // Skip videos

                                    if (
                                        $image_orient == 26 ||
                                        $image_orient == 25
                                    ) {
                                        // Image beside text - media & text block, with html inside

                                        $align_right = $image_orient == 25;

                                        $content_html = "<div class=\"wp-block-media-text__content\">$body_text\n\n</div>";

                                        $image_html = "<figure class=\"wp-block-media-text__media\"><img src=\"" . wp_get_attachment_image_url( $wp_attachment, "full" ) . "\" alt=\"" . get_post_meta( $wp_attachment, "_wp_attachment_image_alt", true ) . "\" class=\"wp-image-$wp_attachment size-full\"/></figure>";

                                        $block_html = "\n\n<!-- wp:media-text {\"mediaId\":$wp_attachment," . ( $align_right ? "\"mediaPosition\":\"right\"," : "" ) . "\"mediaLink\":\"" . get_attachment_link( $wp_attachment ) . "\",\"mediaType\":\"image\",\"verticalAlignment\":\"top\"} -->\n<div class=\"wp-block-media-text" . ( $align_right ? " has-media-on-the-right" : "" ) . " is-stacked-on-mobile is-vertically-aligned-top\">";

                                        if ( $align_right ) {
                                            $block_html .= $content_html . $image_html;
                                        } else {
                                            $block_html .= $image_html . $content_html;
                                        }

                                        $block_html .= "</div>\n<!-- /wp:media-text -->";

                                        $post_content .= $block_html;

                                        $append_content = false;
                                    } else if (
                                        $image_orient == 0 ||
                                        $image_orient == 2 ||
                                        $image_orient == 1 ||
                                        $image_orient == 18 ||
                                        $image_orient == 17 ||
                                        $image_orient == 8 ||
                                        $image_orient == 10 ||
                                        $image_orient == 9
                                    ) {
                                        // Image above, below or in text - image block + html

                                        // Using left/right align only for in text position (sets float)
                                        $align = $image_orient == 18 ? 'left' : ( $image_orient == 17 ? 'right' : '' );

                                        $image_html = "\n\n<!-- wp:image {\"id\":$wp_attachment,\"sizeSlug\":\"full\"" . ( $align ? ( ",\"align\":\"$align\"" ) : "" ) . "} -->\n<figure class=\"wp-block-image" . ( $align ? ( " align$align" ) : "" ) . " size-full\"><img src=\"" . wp_get_attachment_image_url( $wp_attachment, "full" ) . "\" alt=\"" . get_post_meta( $wp_attachment, "_wp_attachment_image_alt", true ) . "\" class=\"wp-image-$wp_attachment\"/></figure>\n<!-- /wp:image -->";
                                    }
                                }
                            }

                            // Videos (youtube / vimeo)

                            foreach ( $wp_attachments as $wp_attachment ) {
                                if ( is_array( $wp_attachment ) ) {

                                    $image_html .= "\n\n<!-- wp:embed {\"url\":\"$wp_attachment[url]\",\"type\":\"video\",\"providerNameSlug\":\"$wp_attachment[type]\",\"responsive\":true,\"className\":\"wp-embed-aspect-16-9 wp-has-aspect-ratio\"} -->\n<figure class=\"wp-block-embed is-type-video is-provider-$wp_attachment[type] wp-block-embed-$wp_attachment[type] wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\n$wp_attachment[url]\n</div></figure>\n<!-- /wp:embed -->";

                                }
                            }
                        }

                        if ( $append_content ) {
                            // Add html if present

                            if (
                                $image_orient == 8 ||
                                $image_orient == 10 ||
                                $image_orient == 9
                            ) {
                                // Image below text

                                $post_content .= $body_text . $image_html;
                            } else {
                                // Image above text

                                $post_content .= $image_html . $body_text;
                            }
                        }
                    } else {
                        // Not image - wysiwyg (text/html items) but not only!

                        // Always migrating body text
                        $post_content .= $body_text;

                        if ( 'list' === $item_type ) {
                            // 

                            if ( 'tmfmifunctions_banner' === $content_item['list_type'] ) {
                                // placeholder string for now

                                $post_content .= "\n\nFMI_AD_PLACEHOLDER";
                                // $post_content .= "\n\n<!-- wp:carbon-fields/fmi-ad /-->";
                            }
                        } else if ( 'gridelements_pi1' === $item_type ) {
                            // 

                            if ( 'video' === $content_item['tx_gridelements_backend_layout'] ) {
                                // 

                                if ( ! empty( $plugin_options->data->sheet->language->field ) ) {
                                    foreach ( $plugin_options->data->sheet->language->field as $field ) {
                                        if( 'videourl' === (string) $field['index'] ) {
                                            // 

                                            $video_url = (string) $field->value;

                                            if ( $video_url ) {
                                                $embed_block_html = "\n\n<!-- wp:embed {\"url\":\"$video_url\",\"type\":\"rich\",\"providerNameSlug\":\"embed-handler\",\"responsive\":true,\"className\":\"wp-embed-aspect-16-9 wp-has-aspect-ratio\"} -->\n<figure class=\"wp-block-embed is-type-rich is-provider-embed-handler wp-block-embed-embed-handler wp-embed-aspect-16-9 wp-has-aspect-ratio\">\n<div class=\"wp-block-embed__wrapper\">\n$video_url\n</div>\n</figure>\n<!-- /wp:embed -->";

                                                $post_content .= $embed_block_html;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }


                }

                $post_content = trim( $post_content );

                $post_data['post_content'] = $post_content;

                // 

                $post_id = wp_insert_post( $post_data, true );

                if ( ! is_wp_error( $post_id ) ) {
                    // 

                    if ( isset( self::$tag_mapping[ $news_pid ] ) ) {
                        wp_set_post_terms(
                            $post_id,
                            array_values( self::$tag_mapping[ $news_pid ] ),
                            'post_tag'
                        );
                    }

                    // 

                    $news_authors = preg_split( '/(&|,|und|\|)/', $news_author );

                    if ( is_array( $news_authors ) ) {
                        $author_terms = [];

                        foreach( $news_authors as $author ) {
                            $author_term = wp_create_term( trim( $author ), 'author' );

                            if ( ! is_wp_error( $author_term ) ) {
                                $author_terms[] = (int) $author_term['term_id'];
                            }
                        }

                        wp_set_post_terms(
                            $post_id,
                            $author_terms,
                            'author'
                        );
                    }

                    // 

                    update_post_meta( $post_id, 'title_detail', $news_title_detail );
                    update_post_meta( $post_id, 'excerpt_detail', $news_excerpt_detail );

                    // 

                    update_post_meta( $post_id, '_yoast_wpseo_title', $news_alternative_title ?: $news_title_detail ?: $news_title );
                    update_post_meta( $post_id, '_yoast_wpseo_metadesc', $news_excerpt );

                    // ===== Avg field char lengths / percentage of news that have this field not empty:

                    // title - 26 / 100%
                    // alternative_title - 60 / 90%
                    // title_detail - 71 / 79%
                    // og_title - 40 / 79%

                    // ===== seo rec title length: 50-60

                    // teaser - 161 / 100%
                    // description - 136 / 91%
                    // teaser_detail - 363 / 80%

                    // ===== seo rec desc length: 150-160

                    // ===== Other fields:

                    // og_title -> only used for opengraph
                    // og_media -> only set for 27 news
                    // keywords -> used in <meta name="keywords"> tag - not used for seo anymore (98% coverage though)
                    // alternative_title -> used in browser tab (<title> tag)

                    // 

                    update_post_meta( $post_id, 't3_uid', $news_uid );

                    // 

                    // DEBUGGING

                    // die;

                    $news_migrated_ids[ $news_uid ] = $post_id;

                    update_option(
                        'fmi_typo3_news_migration_done_ids',
                        $news_migrated_ids
                    );

                    $news_q_offset++;

                    update_option( 'fmi_typo3_news_migration_done_count', $news_q_offset );

                    echo "$news_uid\n";
                } else {
                    // 

                    $news_migration_failed_ids[ $news_uid ] = $post_id->get_error_message();

                    update_option(
                        'fmi_typo3_news_migration_failed_ids',
                        $news_migration_failed_ids
                    );

                    echo "$news_uid - FAILED\n";
                }
            }
        }
    }

    // 

    public static function fmi_wpcli_categories( $args ) {
        // 

        $t3_categories = self::$tpdb->get_results(
            self::$tpdb->prepare(
                "SELECT * FROM sys_category WHERE hidden = 0 AND deleted = 0 AND parent = %d",
                7 // Only using child categories of this parent ("fitnessmanagement.de" category)
            ),
            ARRAY_A
        );

        self::$cat_mapping = get_option( 'fmi_typo3_wp_cat_mapping', [] );

        foreach ( $t3_categories as $t3_category ) {
            $t3_category_id = (int) $t3_category['uid'];

            $wp_category_id = wp_create_category( $t3_category['title'] );

            self::$cat_mapping[ $t3_category_id ] = $wp_category_id;
        }

        update_option(
            'fmi_typo3_wp_cat_mapping',
            self::$cat_mapping
        );
    }

    // 

    public static function fmi_wpcli_tags( $args ) {
        // 

        $t3_pages = self::$tpdb->get_results(
            "SELECT * FROM pages WHERE hidden = 0 AND deleted = 0 AND endtime = 0",
            ARRAY_A
        );

        $page_names = array_column( $t3_pages, 'title', 'uid' );

        $tree = [];

        foreach ( $t3_pages as $page ) {
            $id = (int) $page['uid'];
            $pid = (int) $page['pid'];

            isset( $tree[ $pid ] ) ?: $tree[ $pid ] = [];
            isset( $tree[ $id ] ) ?: $tree[ $id ] = [ $id ];
            $tree[ $pid ][ $id ] = &$tree[ $id ];
        }

        $medical = self::test_ids_flat( $tree[49] );
        $ausgaben = self::test_ids_flat( $tree[20] );
        $online_articles = self::test_ids_flat( $tree[102] );

        // articles = medical + ausgaben
        $articles = array_merge( $medical, $ausgaben );

        // Exclude parent "medical" and "ausgaben" pages
        $tags_exclude_ids = [
            49,
            20,
        ];

        self::$tag_mapping = get_option( 'fmi_typo3_wp_tag_mapping', [] );

        $taxonomy_name = 'post_tag';

        foreach ( $articles as $id ) {
            $terms = [];

            if ( in_array( $id, $online_articles ) ) {
                // Online article tag

                $terms[] = wp_create_term( 'Online', $taxonomy_name );
            } else if ( ! in_array( $id, $tags_exclude_ids ) ) {
                // Create tag(s) from title

                $names = explode( ' ', $page_names[ $id ] );

                foreach( $names as $name ) {
                    $terms[] = wp_create_term( $name, $taxonomy_name );
                }
            }

            foreach( $terms as $term ) {
                if ( ! is_wp_error( $term ) ) {
                    $term_id = (int) $term['term_id'];

                    self::$tag_mapping[ $id ][ $term_id ] = $term_id;
                }
            }
        }

        update_option(
            'fmi_typo3_wp_tag_mapping',
            self::$tag_mapping
        );
    }

}

new fmi_Migration();
