<?php
/**
 * SEAL_Page_export — /export, "Export Data" (capability seal_export).
 *
 * Downloads the library directory as a CSV file (name, alias, ILL email, phone, address, system, suspension).
 * Cells go through SEAL_Functions::csv_safe_row() so a value starting with = + - @ cannot run as a spreadsheet
 * formula.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_export extends SEAL_Page_Base {
    /**
     * Entry point, called by SEAL_Router when the page is opened. Prints the page into the theme's content
     * area. With ?download=csv it streams the file instead.
     */
    public function render(): void {
        // If download requested, stream CSV and exit
        if ( $this->get_str('download') === 'csv' ) {
            $this->stream_csv();
            return;
        }
        $self  = esc_url( home_url('/export') );
        $sys   = esc_html( seal_config('system_name') );
        ?>
        <div class="seal-page-wrap">
        <h3><?php echo $sys; ?> — Library Directory Export</h3>
        <p>Download the participating library directory as a CSV file.</p>
        <a href="<?php echo esc_url(add_query_arg('download','csv',$self)); ?>" class="seal-btn-primary">⬇️ Download CSV</a>
        </div>
        <?php
    }

    /** Sends the CSV download (clears any buffered page output first) and exits. */
    private function stream_csv(): void {
        $db      = SEAL_DB::get();
        $lib_tbl = SEAL_DB::$sealLIB;
        $sys     = strtolower(seal_config('system_name'));

        while ( ob_get_level() ) { ob_end_clean(); }

        $result = $db->query("SELECT recnum,Name,alias,ill_email,phone,address1,address2,address3,`system`,suspend,SuspendDateEnd FROM `{$lib_tbl}` ORDER BY `Name` ASC");
        if ( ! $result ) { wp_die('Export query failed.'); }

        $filename = $sys . '_libraries_' . date('Y-m-d_H-i-s') . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $fp = fopen( 'php://output', 'w' );
        fputcsv($fp, ['Library Name','Alias','ILL Email','Phone','Address 1','Address 2','Address 3','System','Suspended','Suspend Until']);
        while ($r = $result->fetch_assoc()) {
            fputcsv($fp, SEAL_Functions::csv_safe_row([$r['Name']??'',$r['alias']??'',$r['ill_email']??'',$r['phone']??'',$r['address1']??'',$r['address2']??'',$r['address3']??'',$r['system']??'',$r['suspend']??'',$r['SuspendDateEnd']??'']));
        }
        fclose($fp);
        exit;
    }
}
