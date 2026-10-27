<?php
// Shared by the database-check tests: parse mysqldump --no-data output into what SEAL_DB_Schema::gather() returns.
/** Tables from CREATE TABLE statements, in the shape gather() returns. */
function parse_schema( string $sql ): array {
    $out = [];
    preg_match_all( '/CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)` \((.*?)\n\) ENGINE=(\w+)([^;]*);/s', $sql, $m, PREG_SET_ORDER );
    foreach ( $m as [ , $name, $body, $engine, $tail ] ) {
        $cols = []; $idx = [];
        foreach ( preg_split( '/,\n/', $body ) as $line ) {
            $line = trim( $line );
            if ( preg_match( '/^`([^`]+)` (\w+(?:\([^)]*\))?(?: unsigned)?)(.*)$/i', $line, $c ) ) {
                $rest = $c[3];
                $def  = null;
                if ( preg_match( "/DEFAULT '([^']*)'/i", $rest, $d ) ) { $def = $d[1]; }
                elseif ( preg_match( '/DEFAULT (?!NULL)(\S+)/i', $rest, $d ) ) { $def = $d[1]; }
                $cols[ strtolower( $c[1] ) ] = [ 'name' => $c[1], 'null' => ! preg_match( '/NOT NULL/i', $rest ), 'default' => $def,
                    'type' => strtolower( $c[2] ), 'extra' => stripos( $rest, 'AUTO_INCREMENT' ) !== false ? 'auto_increment' : '' ];
            } elseif ( preg_match( '/^(PRIMARY KEY|(?:UNIQUE )?KEY `[^`]+`) \(`([^`]+)`/', $line, $k ) ) {
                $idx[ strtolower( $k[2] ) ] = $k[1];
            }
        }
        preg_match( '/COLLATE=(\w+)/', $tail, $co );
        preg_match( '/CHARSET=(\w+)/', $tail, $cs );
        $out[ $name ] = [ 'name' => $name, 'exists' => true, 'engine' => $engine, 'collation' => $co[1] ?? ( ( $cs[1] ?? 'latin1' ) . '_general_ci' ), 'rows' => 1000, 'columns' => $cols, 'indexes' => $idx ];
    }
    return $out;
}
