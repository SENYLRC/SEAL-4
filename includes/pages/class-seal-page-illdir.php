<?php
/**
 * SEAL_Page_illdir — public "Directory of Participating Libraries".
 *
 * Reachable without logging in (see SEAL_Router::PUBLIC_ROUTES). Phone numbers
 * and ILL email addresses are only shown to logged-in users.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_illdir extends SEAL_Page_Base {

    /**
     * Entry point: SEAL_Router calls this to print the page into the theme's content area. The public directory
     * of participating libraries, filterable by library system; contact details only for logged-in users.
     */
    public function render(): void {
        $db      = SEAL_DB::get();
        $lib_tbl = SEAL_DB::$sealLIB;

        $libname   = sanitize_text_field( $this->get_str( 'libname' ) );
        $system    = sanitize_text_field( $this->get_str( 'system' ) );
        $rec_limit = 50;
        $pagenum   = max( 0, $this->req_int( 'pagenum', 0 ) );
        $offset    = $pagenum * $rec_limit;
        $logged_in = is_user_logged_in();

        $base = "SELECT * FROM `{$lib_tbl}` WHERE `participant`='1'";
        if ( $libname !== '' ) { $base .= " AND `Name` LIKE '%" . SEAL_DB::esc( $libname ) . "%'"; }
        if ( $system  !== '' ) { $base .= " AND `system`='"     . SEAL_DB::esc( $system  ) . "'"; }

        $count_r = $db->query( $base );
        $total   = $count_r ? $count_r->num_rows : 0;
        $rows    = $db->query( $base . " ORDER BY `Name` ASC LIMIT {$offset}, {$rec_limit}" );

        $systems = SEAL_Functions::system_options();
        $self    = esc_url( home_url( '/illdir' ) );
        ?>
        <div class="seal-page-wrap">
        <h3 id="illdir-title">Search the directory</h3>

        <form action="<?php echo $self; ?>" method="get" aria-labelledby="illdir-title">
            <label for="libname"><strong>Library Name:</strong></label>
            <input id="libname" type="text" size="60" maxlength="255" name="libname"
                   value="<?php echo esc_attr( $libname ); ?>"><br>
            <label for="system"><strong>Library System:</strong></label>
            <select id="system" name="system">
                <option value=""></option>
                <?php foreach ( $systems as $code => $name ): ?>
                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $system, $code ); ?>><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select><br>
            <div class="illDirActions">
                <input type="submit" value="Submit">
                <a href="<?php echo $self; ?>" class="clear-btn">Clear</a>
            </div>
        </form>

        <?php if ( ! $logged_in ): ?>
        <p><em>Phone numbers and ILL email addresses are shown to
            <a href="<?php echo esc_url( wp_login_url( home_url( '/illdir' ) ) ); ?>">logged-in library staff</a> only.</em></p>
        <?php endif; ?>

        <p id="illdir-results" role="status" aria-live="polite" aria-atomic="true">
            <strong><?php echo (int) $total; ?></strong> results
        </p>

        <?php if ( $rows && $rows->num_rows > 0 ): ?>
        <div class="illDirGrid">
            <?php $i = 0; while ( $r = $rows->fetch_assoc() ):
                $title_id = 'illdir-card-title-' . $i;
                $btn_id   = 'loanbtn-' . $i;
                $loan_id  = 'loaning-' . $i;
                $accepting = ( ( $r['suspend'] ?? '' ) == '0' ) ? 'Yes' : 'No';
                $email_raw = (string) ( $r['ill_email'] ?? '' );
                $mailto    = preg_replace( '/\s+/', '', str_replace( ';', ',', $email_raw ) );
            ?>
            <div class="illDirCard">
                <h4 id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $r['Name'] ?? '' ); ?></h4>
                <p><strong>Address:</strong><br><?php echo esc_html( $r['address2'] ?? '' ); ?><br><?php echo esc_html( $r['address3'] ?? '' ); ?></p>
                <?php if ( $logged_in ): ?>
                <p><strong>Phone:</strong> <?php echo esc_html( $r['phone'] ?? '' ); ?></p>
                <?php endif; ?>
                <p><strong>Library System:</strong> <?php echo esc_html( SEAL_Functions::system_name( $r['system'] ?? '' ) ); ?></p>
                <?php if ( $logged_in && $email_raw !== '' ): ?>
                <p><strong>ILL Email(s):</strong> <a href="mailto:<?php echo esc_attr( $mailto ); ?>"><?php echo esc_html( $email_raw ); ?></a></p>
                <?php endif; ?>
                <p><strong>OCLC Symbol:</strong> <?php echo esc_html( $r['oclc'] ?? '' ); ?><br>
                   <strong>LOC Code:</strong> <?php echo esc_html( $r['loc'] ?? '' ); ?><br>
                   <strong>Accepting Requests:</strong> <?php echo esc_html( $accepting ); ?></p>

                <button id="<?php echo esc_attr( $btn_id ); ?>" class="loan-btn" type="button"
                        data-target="<?php echo esc_attr( $loan_id ); ?>"
                        aria-controls="<?php echo esc_attr( $loan_id ); ?>" aria-expanded="false">Show loaning options</button>
                <div id="<?php echo esc_attr( $loan_id ); ?>" class="loaning-options" role="region"
                     aria-labelledby="<?php echo esc_attr( $btn_id ); ?>" hidden>
                    Loaning Print Book: <strong><?php echo esc_html( $r['book_loan'] ?? '' ); ?></strong><br>
                    Loaning Print Journal or Article: <strong><?php echo esc_html( $r['periodical_loan'] ?? '' ); ?></strong><br>
                    Loaning Audio Video Materials: <strong><?php echo esc_html( $r['av_loan'] ?? '' ); ?></strong><br>
                    Loaning Reference/Microfilm: <strong><?php echo esc_html( $r['theses_loan'] ?? '' ); ?></strong><br>
                    Loaning Electronic Book: <strong><?php echo esc_html( $r['ebook_request'] ?? '' ); ?></strong><br>
                    Loaning Electronic Journal: <strong><?php echo esc_html( $r['ejournal_request'] ?? '' ); ?></strong><br>
                </div>
            </div>
            <?php $i++; endwhile; ?>
        </div>
        <?php else: ?>
        <p role="status" aria-live="polite">No results found.</p>
        <?php endif; ?>

        <?php if ( $total > $rec_limit ):
            $qs = array_filter( [ 'libname' => $libname, 'system' => $system ] );
        ?>
        <nav class="pagination" aria-label="Directory pages">
            <?php if ( $pagenum > 0 ): ?>
            <a href="<?php echo esc_url( add_query_arg( $qs + [ 'pagenum' => $pagenum - 1 ], $self ) ); ?>" rel="prev" aria-label="Previous page">&laquo; Previous</a>
            <?php endif; ?>
            <?php if ( ( $offset + $rec_limit ) < $total ): ?>
            <a href="<?php echo esc_url( add_query_arg( $qs + [ 'pagenum' => $pagenum + 1 ], $self ) ); ?>" rel="next" aria-label="Next page">Next &raquo;</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.loan-btn').forEach(function (button) {
                button.addEventListener('click', function () {
                    var target = document.getElementById(button.getAttribute('data-target'));
                    if (!target) { return; }
                    var open = target.classList.toggle('open');
                    target.hidden = !open;
                    button.textContent = open ? 'Hide loaning options' : 'Show loaning options';
                    button.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            });
        });
        </script>
        </div>
        <?php
    }
}
