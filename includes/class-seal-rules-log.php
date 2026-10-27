<?php
/**
 * SEAL_Rules_Log — audit trail for ILL → Org Rules.
 *
 * Every change to any rule list (and the crontab settings) is recorded with who made it, when,
 * from which IP address, and exactly which rows were added, removed or reordered. Entries are
 * kept in a non-autoloaded option (newest first, capped) and shown under "Change history" on
 * the Org Rules page.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Rules_Log {

    public const OPTION    = 'seal_rules_log';
    public const MAX_ITEMS = 300;
    public const MAX_LINES = 200;

    private static bool $paused = false;

    /** Suppress logging while the plugin itself writes options (one-time seeding). */
    public static function pause( bool $on ): void { self::$paused = $on; }

    /** Record a plain note (no row diff). */
    public static function note( string $summary ): void {
        self::add( [ 'type' => 'setting', 'key' => 'crons', 'summary' => $summary, 'added' => [], 'removed' => [], 'label' => 'Org Rules' ] );
    }

    /**
     * Hooks the change log onto every Org Rules list option and the Cron Jobs settings, so each save is
     * recorded.
     */
    public static function init(): void {
        foreach ( [ 'code' => 'Short code', 'dir' => 'Scripts folder', 'php' => 'PHP program', 'output' => 'Job output', 'log' => 'Log file' ] as $ck => $clabel ) {
            add_action( 'update_option_seal_cron_' . $ck, static function ( $old, $new ) use ( $clabel ) { self::on_setting( $clabel, (string) $old, (string) $new ); }, 10, 2 );
            add_action( 'add_option_seal_cron_' . $ck,    static function ( $option, $new ) use ( $clabel ) { self::on_setting( $clabel, '', (string) $new ); }, 10, 2 );
        }
        foreach ( SEAL_Org_Rules::KEYS as $key ) {
            add_action( 'update_option_seal_rules_' . $key, static function ( $old, $new ) use ( $key ) { self::on_list( $key, (string) $old, (string) $new ); }, 10, 2 );
            add_action( 'add_option_seal_rules_' . $key,    static function ( $option, $new ) use ( $key ) { self::on_list( $key, '', (string) $new ); }, 10, 2 );
        }
    }

    // ── Recording ─────────────────────────────────────────────────────────────

    /** The rows of a stored list: trimmed, blank lines and # comments removed. */
    public static function lines( string $text ): array {
        $out = [];
        foreach ( preg_split( '/\R/', $text ) as $line ) {
            $line = trim( $line );
            if ( $line !== '' && $line[0] !== '#' ) { $out[] = $line; }
        }
        return $out;
    }

    /**
     * Records the change to one Org Rules list: which rows were added and removed, or that the order changed.
     * Skipped while paused (during import, which logs its own entry).
     */
    public static function on_list( string $key, string $old_saved, string $new_saved ): void {
        if ( self::$paused ) { return; }
        $old = self::lines( $old_saved );
        $new = self::lines( $new_saved );
        if ( $old === $new ) { return; }

        $added   = array_values( array_diff( $new, $old ) );
        $removed = array_values( array_diff( $old, $new ) );

        if ( ! $added && ! $removed ) {
            $summary = 'Reordered';
        } else {
            $parts = [];
            if ( $added )   { $parts[] = count( $added ) . ' added'; }
            if ( $removed ) { $parts[] = count( $removed ) . ' removed'; }
            $summary = implode( ', ', $parts );
        }

        self::add( [
            'type'    => 'list',
            'key'     => $key,
            'summary' => $summary,
            'added'   => array_slice( $added, 0, self::MAX_LINES ),
            'removed' => array_slice( $removed, 0, self::MAX_LINES ),
            'more'    => max( 0, count( $added ) - self::MAX_LINES ) + max( 0, count( $removed ) - self::MAX_LINES ),
            'count'   => count( $new ),
        ] );
    }

    /** Records a change to one Cron Jobs setting (old and new value). */
    public static function on_setting( string $label, string $old, string $new ): void {
        if ( self::$paused || $old === $new ) { return; }
        self::add( [
            'type'    => 'setting',
            'key'     => 'crons',
            'summary' => 'Scheduled jobs setting "' . $label . '": ' . ( $old === '' ? '(default)' : $old ) . ' → ' . ( $new === '' ? '(default)' : $new ),
            'added'   => [],
            'removed' => [],
        ] );
    }

    /** Stores one log entry with who made it, when and from which IP address, keeping the newest MAX_ITEMS. */
    private static function add( array $entry ): void {
        $user = wp_get_current_user();
        $ip   = class_exists( 'SEAL_Module_LoginProtection' ) ? SEAL_Module_LoginProtection::client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        $entry += [
            'time'  => time(),
            'uid'   => (int) $user->ID,
            'user'  => $user->ID ? (string) $user->user_login : 'system',
            'ip'    => sanitize_text_field( $ip ),
        ];
        $log = self::entries();
        array_unshift( $log, $entry );
        update_option( self::OPTION, array_slice( $log, 0, self::MAX_ITEMS ), false );
    }

    /** Every stored log entry, oldest first. */
    public static function entries(): array {
        $log = get_option( self::OPTION, [] );
        return is_array( $log ) ? $log : [];
    }

    // ── Display ───────────────────────────────────────────────────────────────

    /**
     * The Change history table at the bottom of Org Rules: newest first, with who, when, from where and the
     * rows that changed.
     */
    public static function render(): void {
        $log = self::entries();
        echo '<h2 id="rules-history">Change history</h2>';
        echo '<p class="description">Every change to a list or the crontab settings: who, when, and exactly what changed. The newest ' . (int) self::MAX_ITEMS . ' changes are kept.</p>';
        if ( ! $log ) {
            echo '<p><em>No changes recorded yet.</em></p>';
            return;
        }
        echo '<table class="widefat striped seal-rules-history" style="max-width:1120px"><thead><tr>'
           . '<th scope="col" style="width:170px">When</th><th scope="col" style="width:130px">Who</th>'
           . '<th scope="col" style="width:220px">List</th><th scope="col">What changed</th></tr></thead><tbody>';
        foreach ( $log as $e ) {
            $title = ! empty( $e['label'] ) ? (string) $e['label'] : ( $e['type'] === 'preset' ? 'Preset (removed feature)' : ( SEAL_Org_Rules::schema( (string) $e['key'] )['title'] ?? (string) $e['key'] ) );
            $when  = wp_date( 'Y-m-d H:i', (int) $e['time'] );
            echo '<tr><td>' . esc_html( $when ) . '</td>'
               . '<td>' . esc_html( (string) $e['user'] ) . '<br><small>' . esc_html( (string) ( $e['ip'] ?? '' ) ) . '</small></td>'
               . '<td>' . esc_html( $title ) . '</td><td>';
            echo '<details><summary>' . esc_html( (string) $e['summary'] ) . '</summary>';
            if ( ! empty( $e['note'] ) ) { echo '<p>' . esc_html( (string) $e['note'] ) . '</p>'; }
            foreach ( [ 'removed' => 'Removed', 'added' => 'Added' ] as $k => $label ) {
                if ( empty( $e[ $k ] ) ) { continue; }
                echo '<p style="margin:8px 0 2px"><strong>' . esc_html( $label ) . '</strong></p><ul style="margin:0 0 6px 18px;list-style:' . ( $k === 'added' ? 'disc' : 'circle' ) . '">';
                foreach ( $e[ $k ] as $line ) { echo '<li><code>' . esc_html( (string) $line ) . '</code></li>'; }
                echo '</ul>';
            }
            if ( ! empty( $e['more'] ) ) { echo '<p><em>…and ' . (int) $e['more'] . ' more.</em></p>'; }
            echo '</details></td></tr>';
        }
        echo '</tbody></table>';
    }
}
