<?php
/**
 * SEAL_Module_SiteFooter
 *
 * Renders a configurable organization footer (logo, about/address block,
 * link list, copyright line) at the end of every front-end page. Every value
 * comes from ILL → Branding → Site Footer; nothing is hardcoded.
 *
 * Markup avoids a second <footer> element so the theme's own contentinfo
 * landmark stays unique (WCAG 1.3.1 / landmark best practice).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SEAL_Module_SiteFooter {

    /** Prints the organization footer at the bottom of every front-end page. */
    public static function init(): void {
        add_action( 'wp_footer', [ __CLASS__, 'render' ], 5 );
    }

    /**
     * The footer: logo (with alt text and optional link), about line, address, tap-to-call phone, website,
     * links and copyright, all from ILL System → Branding.
     */
    public static function render(): void {
        if ( is_admin() ) { return; }

        $logo_url  = seal_config( 'footer_logo_url' );
        $logo_alt  = seal_config( 'footer_logo_alt' ) ?: seal_config( 'org_name' ) . ' logo';
        $logo_link = seal_config( 'footer_logo_link' );
        $about     = trim( seal_config( 'footer_about' ) );
        $address   = trim( seal_config( 'footer_address' ) );
        $phone     = trim( seal_config( 'footer_phone' ) );
        $website   = trim( seal_config( 'footer_website' ) );
        $links     = self::parse_links( seal_config( 'footer_links' ) );
        $copy      = trim( seal_config( 'footer_copyright' ) );
        if ( $copy === '' ) {
            $copy = '© {year} {system}, All rights reserved.';
        }
        $copy = str_replace(
            [ '{current year}', '{year}', '{System Short Name}', '{system}' ],
            [ wp_date( 'Y' ),   wp_date( 'Y' ), seal_config( 'system_name' ), seal_config( 'system_name' ) ],
            $copy
        );

        $tel = preg_replace( '/[^0-9+]/', '', $phone );
        ?>
        <div class="seal-site-footer" role="region" aria-label="<?php echo esc_attr( seal_config( 'org_name' ) . ' information' ); ?>">
            <div class="seal-site-footer-inner">

                <?php if ( $logo_url ) : ?>
                <div class="seal-sf-logo">
                    <?php if ( $logo_link ) : ?><a href="<?php echo esc_url( $logo_link ); ?>"><?php endif; ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $logo_alt ); ?>" loading="lazy" decoding="async">
                    <?php if ( $logo_link ) : ?></a><?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ( $about || $address || $phone || $website ) : ?>
                <address class="seal-sf-about">
                    <?php if ( $about ) : ?><p><?php echo esc_html( $about ); ?></p><?php endif; ?>
                    <?php if ( $address || $phone || $website ) : ?>
                    <p>
                        <?php if ( $address ) : ?><?php echo nl2br( esc_html( $address ) ); ?><br><?php endif; ?>
                        <?php if ( $phone ) : ?>
                            <?php if ( $tel ) : ?><a href="<?php echo esc_url( 'tel:' . $tel ); ?>"><?php echo esc_html( $phone ); ?></a>
                            <?php else : ?><?php echo esc_html( $phone ); ?><?php endif; ?><br>
                        <?php endif; ?>
                        <?php if ( $website ) : ?>
                            <a href="<?php echo esc_url( $website ); ?>"><?php echo esc_html( preg_replace( '#^https?://#i', '', rtrim( $website, '/' ) ) ); ?></a>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                </address>
                <?php endif; ?>

                <?php if ( $links ) : ?>
                <nav class="seal-sf-links" aria-label="<?php echo esc_attr( seal_config( 'system_name' ) . ' footer' ); ?>">
                    <ul>
                        <?php foreach ( $links as $l ) : ?>
                        <li><a href="<?php echo esc_url( $l['url'] ); ?>"><?php echo esc_html( $l['label'] ); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
                <?php endif; ?>

                <p class="seal-sf-copy"><?php echo esc_html( $copy ); ?></p>
            </div>
        </div>
        <?php
        self::inline_css();
    }

    /**
     * Footer Links text (one Label|URL per line) as [label, url] pairs; {login} becomes the login page address.
     */
    private static function parse_links( string $raw ): array {
        $out = [];
        foreach ( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) as $line ) {
            $parts = explode( '|', $line, 2 );
            if ( count( $parts ) < 2 ) { continue; }
            $label = trim( $parts[0] );
            $url   = trim( $parts[1] );
            if ( $url === '{login}' ) { $url = wp_login_url(); }
            if ( $label !== '' && esc_url( $url ) !== '' ) {
                $out[] = [ 'label' => $label, 'url' => $url ];
            }
        }
        return $out;
    }

    /** The footer's styles. */
    private static function inline_css(): void {
        ?>
        <style>
        .seal-site-footer{background:#fff;color:#333;padding:24px 16px;font-size:.9rem;line-height:1.5;}
        .seal-site-footer-inner{display:grid;grid-template-columns:auto 1fr 1fr;gap:1.25rem 2rem;align-items:start;max-width:1150px;margin:0 auto;}
        .seal-site-footer address{font-style:normal;}
        .seal-site-footer p{margin:0 0 .5em;}
        .seal-site-footer ul{list-style:none;margin:0;padding:0;}
        .seal-site-footer li{margin:0 0 .4em;}
        .seal-site-footer a{color:#3f3585;text-decoration:underline;}
        .seal-site-footer a:hover{color:#2a2260;}
        .seal-site-footer a:focus-visible{outline:3px solid var(--seal-brand,#4a4180);outline-offset:2px;}
        .seal-sf-logo img{max-width:100%;height:auto;max-height:70px;width:auto;}
        .seal-sf-copy{grid-column:1 / -1;border-top:1px solid #ccc;padding-top:1rem;margin:0;}
        @media(max-width:700px){.seal-site-footer-inner{grid-template-columns:1fr;}}
        </style>
        <?php
    }
}
