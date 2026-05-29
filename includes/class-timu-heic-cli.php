<?php
/**
 * WP-CLI commands for HEIC Support by thisismyurl.com.
 *
 * Registered via `WP_CLI::add_command( 'heic', 'TIMU_HEIC_Support_CLI' )`
 * inside TIMU_HEIC_Support::init() when WP-CLI is loaded.
 *
 * @package TIMU_HEIC_Support
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
    return;
}

/**
 * Manage HEIC/HEIF to WebP conversion and restoration of Media Library
 * attachments.
 */
class TIMU_HEIC_Support_CLI extends WP_CLI_Command {

    /**
     * Convert one or more HEIC/HEIF attachments to WebP.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : One or more attachment IDs to convert. Mutually exclusive with --all.
     *
     * [--all]
     * : Convert every pending HEIC/HEIF attachment in the Media Library.
     *
     * [--dry-run]
     * : Print what would be converted without doing the work.
     *
     * ## EXAMPLES
     *
     *     wp heic convert 42 43 44
     *     wp heic convert --all
     *     wp heic convert --all --dry-run
     *
     * @param array $args       Positional arguments (attachment IDs).
     * @param array $assoc_args Associative arguments.
     *
     * @return void
     */
    public function convert( $args, $assoc_args ) {
        $all     = ! empty( $assoc_args['all'] );
        $dry_run = ! empty( $assoc_args['dry-run'] );

        if ( $all && ! empty( $args ) ) {
            WP_CLI::error( '--all and explicit IDs are mutually exclusive.' );
        }

        if ( ! $dry_run && ! TIMU_HEIC_Support::imagick_supports_heic() ) {
            WP_CLI::error( 'Imagick with HEIC support is not available on this server.' );
        }

        if ( $all ) {
            $lists = TIMU_HEIC_Support::get_media_lists();
            $ids   = array_map(
                static function ( $post ) {
                    return (int) $post->ID;
                },
                $lists['pending']
            );
        } else {
            $ids = array_filter( array_map( 'absint', $args ) );
        }

        if ( empty( $ids ) ) {
            WP_CLI::warning( 'No attachments to convert.' );
            return;
        }

        WP_CLI::log( sprintf( 'Converting %d attachment(s)%s.', count( $ids ), $dry_run ? ' [dry-run]' : '' ) );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Converting', count( $ids ) );
        $ok       = 0;
        $failed   = 0;

        foreach ( $ids as $id ) {
            if ( $dry_run ) {
                WP_CLI::log( sprintf( '  would convert #%d (%s)', $id, basename( (string) get_attached_file( $id ) ) ) );
                ++$ok;
                $progress->tick();
                continue;
            }

            $result = TIMU_HEIC_Support::convert_image( $id );
            if ( true === $result ) {
                ++$ok;
            } else {
                ++$failed;
                $message = is_wp_error( $result ) ? $result->get_error_message() : 'unknown error';
                WP_CLI::warning( sprintf( '#%d: %s', $id, $message ) );
            }
            $progress->tick();
        }

        $progress->finish();

        if ( $failed > 0 ) {
            WP_CLI::error( sprintf( 'Converted %d, failed %d.', $ok, $failed ), false );
            WP_CLI::halt( 1 );
        }

        WP_CLI::success( sprintf( 'Converted %d attachment(s).', $ok ) );
    }

    /**
     * Restore one or more converted attachments from backup.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : One or more attachment IDs to restore. Mutually exclusive with --all.
     *
     * [--all]
     * : Restore every managed attachment that has a backup on disk.
     *
     * [--dry-run]
     * : Print what would be restored without doing the work.
     *
     * ## EXAMPLES
     *
     *     wp heic restore 42
     *     wp heic restore --all
     *     wp heic restore --all --dry-run
     *
     * @param array $args       Positional arguments (attachment IDs).
     * @param array $assoc_args Associative arguments.
     *
     * @return void
     */
    public function restore( $args, $assoc_args ) {
        $all     = ! empty( $assoc_args['all'] );
        $dry_run = ! empty( $assoc_args['dry-run'] );

        if ( $all && ! empty( $args ) ) {
            WP_CLI::error( '--all and explicit IDs are mutually exclusive.' );
        }

        if ( $all ) {
            $lists = TIMU_HEIC_Support::get_media_lists();
            $ids   = array();
            foreach ( $lists['media'] as $post ) {
                $orig = get_post_meta( $post->ID, TIMU_HEIC_Support::BACKUP_META_KEY, true );
                if ( $orig ) {
                    $ids[] = (int) $post->ID;
                }
            }
        } else {
            $ids = array_filter( array_map( 'absint', $args ) );
        }

        if ( empty( $ids ) ) {
            WP_CLI::warning( 'No attachments to restore.' );
            return;
        }

        WP_CLI::log( sprintf( 'Restoring %d attachment(s)%s.', count( $ids ), $dry_run ? ' [dry-run]' : '' ) );

        $progress = \WP_CLI\Utils\make_progress_bar( 'Restoring', count( $ids ) );
        $ok       = 0;
        $failed   = 0;

        foreach ( $ids as $id ) {
            if ( $dry_run ) {
                WP_CLI::log( sprintf( '  would restore #%d', $id ) );
                ++$ok;
                $progress->tick();
                continue;
            }

            if ( TIMU_HEIC_Support::restore_image( $id ) ) {
                ++$ok;
            } else {
                ++$failed;
                WP_CLI::warning( sprintf( '#%d: restore failed (no backup on disk?).', $id ) );
            }
            $progress->tick();
        }

        $progress->finish();

        if ( $failed > 0 ) {
            WP_CLI::error( sprintf( 'Restored %d, failed %d.', $ok, $failed ), false );
            WP_CLI::halt( 1 );
        }

        WP_CLI::success( sprintf( 'Restored %d attachment(s).', $ok ) );
    }

    /**
     * Report pending, managed, and restorable attachment counts.
     *
     * ## EXAMPLES
     *
     *     wp heic status
     *
     * @return void
     */
    public function status() {
        $lists      = TIMU_HEIC_Support::get_media_lists();
        $pending    = count( $lists['pending'] );
        $managed    = count( $lists['media'] );
        $restorable = 0;
        $missing    = 0;

        foreach ( $lists['media'] as $post ) {
            if ( isset( $post->timu_heic_status ) && 'missing' === $post->timu_heic_status ) {
                ++$missing;
                continue;
            }
            $orig = get_post_meta( $post->ID, TIMU_HEIC_Support::BACKUP_META_KEY, true );
            if ( $orig ) {
                ++$restorable;
            }
        }

        WP_CLI::log( sprintf( 'Imagick HEIC: %s', TIMU_HEIC_Support::imagick_supports_heic() ? 'available' : 'unavailable' ) );
        WP_CLI::log( sprintf( 'Pending:      %d', $pending ) );
        WP_CLI::log( sprintf( 'Managed:      %d', $managed ) );
        WP_CLI::log( sprintf( 'Restorable:   %d', $restorable ) );
        WP_CLI::log( sprintf( 'Missing:      %d', $missing ) );
    }
}
