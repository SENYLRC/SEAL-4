<?php
/**
 * SEAL_Page_email_harvest — /email-harvest, "Staff Email Export" (capability seal_email_harvest).
 *
 * Lists every user's email address, name, institution and roles, and downloads the same list as CSV (e.g. for a
 * mailing list). Cells are made formula-safe with SEAL_Functions::csv_safe_row().
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Page_email_harvest extends SEAL_Page_Base {
    /**
     * Entry point, called by SEAL_Router when the page is opened. Prints the page into the theme's content
     * area. With ?download=csv it streams the file instead.
     */
    public function render(): void {
        if ( $this->get_str('download') === 'csv' ) {
            $this->stream_csv();
            return;
        }
        $self = esc_url( home_url('/email-harvest') );
        $users = get_users(['orderby'=>'user_email','order'=>'ASC']);
        ?>
        <div class="seal-page-wrap">
        <h3>Staff Email Addresses</h3>
        <p><a href="<?php echo esc_url(add_query_arg('download','csv',$self)); ?>" class="seal-btn-primary">⬇️ Download CSV</a></p>
        <div class="seal-table-wrap"><table class="seal-table">
        <thead><tr><th scope="col">Email</th><th scope="col">Name</th><th scope="col">Institution</th><th scope="col">Role(s)</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u):
            $inst = (string)get_user_meta($u->ID,'institution',true);
            $fn   = (string)get_user_meta($u->ID,'first_name', true);
            $ln   = (string)get_user_meta($u->ID,'last_name',  true);
        ?>
        <tr>
            <td><a href="mailto:<?php echo esc_attr($u->user_email); ?>"><?php echo esc_html($u->user_email); ?></a></td>
            <td><?php echo esc_html(trim("$fn $ln")); ?></td>
            <td><?php echo esc_html($inst); ?></td>
            <td><?php echo esc_html(implode(', ',$u->roles)); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        </div>
        <?php
    }

    /** Sends the CSV download (clears any buffered page output first) and exits. */
    private function stream_csv(): void {
        $sys = strtolower(seal_config('system_name'));
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$sys.'_staff_emails_'.date('Y-m-d').'.csv"');
        header('Pragma: no-cache'); header('Expires: 0');
        $fp = fopen('php://output','w');
        fputcsv($fp,['Email','First Name','Last Name','Institution','Role(s)','LOC Code']);
        foreach (get_users() as $u) {
            fputcsv($fp,SEAL_Functions::csv_safe_row([$u->user_email,get_user_meta($u->ID,'first_name',true),get_user_meta($u->ID,'last_name',true),get_user_meta($u->ID,'institution',true),implode(', ',$u->roles),get_user_meta($u->ID,'address_loc_code',true)]));
        }
        fclose($fp); exit;
    }
}
