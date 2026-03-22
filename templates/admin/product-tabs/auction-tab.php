<?php
/*
 * This file belongs to the YIT Framework.
 *
 * This source file is subject to the GNU GENERAL PUBLIC LICENSE (GPL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.txt
 */
if ( !defined( 'YITH_WCACT_VERSION' ) ) {
    exit( 'Direct access forbidden.' );
}

$post_id = $post->ID;
$auction_product = wc_get_product($post_id);

do_action('yith_before_auction_tab',$post_id);

$from_auction = ( $datetime = yit_get_prop($auction_product,'_yith_auction_for',true) ) ? absint( $datetime ) : '';
$from_auction = $from_auction ? get_date_from_gmt( date( 'Y-m-d H:i:s', $from_auction ) ) : '';
$to_auction   = ( $datetime = yit_get_prop($auction_product,'_yith_auction_to',true) ) ? absint( $datetime ) : '';
$to_auction   = $to_auction ? get_date_from_gmt( date( 'Y-m-d H:i:s', $to_auction ) ) : '';

// Get stored values for new fields
$start_price   = yit_get_prop( $auction_product, '_yith_auction_start_price', true );
$start_price   = ( '' !== $start_price ) ? $start_price : '';
$reserve_price = yit_get_prop( $auction_product, '_yith_auction_reserve_price', true );
$reserve_price = ( '' !== $reserve_price ) ? $reserve_price : '';

$bid_increment_mgr = YITH_WCACT_Bid_Increment::get_instance();
$use_global        = $bid_increment_mgr->product_uses_global( $post_id );
$product_ranges    = $bid_increment_mgr->get_ranges( $post_id );

echo '<p class="form-field wc_auction_dates">
                        <label for="wc_auction_dates_from">' . esc_html__( 'Auction Dates', 'yith-auctions-for-woocommerce' ) . '</label>
                        <input type="text" name="_yith_auction_for" class="wc_auction_datepicker" id="_yith_auction_for" value="' . esc_attr( $from_auction ) . '" placeholder="' . esc_attr__( 'From', 'yith-auctions-for-woocommerce' ) . '"
                        pattern="[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01]) (0[0-9]|1[0-9]|2[0-3]):(0[0-9]|1[0-9]|2[0-9]|3[0-9]|4[0-9]|5[0-9]):(0[0-9]|1[0-9]|2[0-9]|3[0-9]|4[0-9]|5[0-9])"
						title="YYYY-MM-DD hh:mm:ss" data-related-to="#_yith_auction_to">
                        <input type="text" name="_yith_auction_to" class="wc_auction_datepicker" id="_yith_auction_to" value="' . esc_attr( $to_auction ) . '" placeholder="' . esc_attr__( 'To', 'yith-auctions-for-woocommerce' ) . '"
                        pattern="[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01]) (0[0-9]|1[0-9]|2[0-3]):(0[0-9]|1[0-9]|2[0-9]|3[0-9]|4[0-9]|5[0-9]):(0[0-9]|1[0-9]|2[0-9]|3[0-9]|4[0-9]|5[0-9])"
						title="YYYY-MM-DD hh:mm:ss">
		</p>';

// Starting Bid (minimum bid)
echo '<p class="form-field _yith_auction_start_price_field">
    <label for="_yith_auction_start_price">' . esc_html__( 'Starting Bid', 'yith-auctions-for-woocommerce' ) . '</label>
    <input type="number" step="0.01" min="0" name="_yith_auction_start_price" id="_yith_auction_start_price" value="' . esc_attr( $start_price ) . '" placeholder="' . esc_attr__( 'Minimum opening bid', 'yith-auctions-for-woocommerce' ) . '" />
    <span class="description">' . esc_html__( 'The minimum amount for the first bid on this auction.', 'yith-auctions-for-woocommerce' ) . '</span>
</p>';

// Reserve Price
echo '<p class="form-field _yith_auction_reserve_price_field">
    <label for="_yith_auction_reserve_price">' . esc_html__( 'Reserve Price', 'yith-auctions-for-woocommerce' ) . '</label>
    <input type="number" step="0.01" min="0" name="_yith_auction_reserve_price" id="_yith_auction_reserve_price" value="' . esc_attr( $reserve_price ) . '" placeholder="' . esc_attr__( 'Reserve price (optional)', 'yith-auctions-for-woocommerce' ) . '" />
    <span class="description">' . esc_html__( 'If set, the auction will only complete if the highest bid meets or exceeds this price.', 'yith-auctions-for-woocommerce' ) . '</span>
</p>';

// Bid Increment Ranges section
echo '<div class="form-field yith-wcact-bid-increment-section">';
echo '<h4>' . esc_html__( 'Bid Increment Ranges', 'yith-auctions-for-woocommerce' ) . '</h4>';

// Use Global checkbox
$checked = $use_global ? 'checked="checked"' : '';
echo '<p class="form-field _yith_auction_bid_increment_use_global_field">
    <label for="_yith_auction_bid_increment_use_global">' . esc_html__( 'Use Global Increments', 'yith-auctions-for-woocommerce' ) . '</label>
    <input type="checkbox" name="_yith_auction_bid_increment_use_global" id="_yith_auction_bid_increment_use_global" value="yes" ' . $checked . ' />
    <span class="description">' . esc_html__( 'Use the global bid increment ranges. Uncheck to set product-specific ranges.', 'yith-auctions-for-woocommerce' ) . '</span>
</p>';

// Product-specific ranges table (hidden if using global)
$display_style = $use_global ? 'display:none;' : '';
echo '<div id="yith-wcact-product-bid-increments" style="' . esc_attr( $display_style ) . '">';
echo '<table class="widefat yith-wcact-bid-increment-table">';
echo '<thead><tr>
    <th>' . esc_html__( 'From Price', 'yith-auctions-for-woocommerce' ) . '</th>
    <th>' . esc_html__( 'Increment', 'yith-auctions-for-woocommerce' ) . '</th>
    <th>&nbsp;</th>
</tr></thead>';
echo '<tbody>';

if ( ! empty( $product_ranges ) ) {
    foreach ( $product_ranges as $range ) {
        echo '<tr>
            <td><input type="number" step="0.01" min="0" name="_yith_bid_increment_from_price[]" value="' . esc_attr( $range->from_price ) . '" /></td>
            <td><input type="number" step="0.01" min="0.01" name="_yith_bid_increment_amount[]" value="' . esc_attr( $range->increment ) . '" /></td>
            <td><button type="button" class="button yith-wcact-remove-row">' . esc_html__( 'Remove', 'yith-auctions-for-woocommerce' ) . '</button></td>
        </tr>';
    }
} else {
    echo '<tr>
        <td><input type="number" step="0.01" min="0" name="_yith_bid_increment_from_price[]" value="0.00" /></td>
        <td><input type="number" step="0.01" min="0.01" name="_yith_bid_increment_amount[]" value="1.00" /></td>
        <td><button type="button" class="button yith-wcact-remove-row">' . esc_html__( 'Remove', 'yith-auctions-for-woocommerce' ) . '</button></td>
    </tr>';
}

echo '</tbody>';
echo '<tfoot><tr><td colspan="3">
    <button type="button" class="button button-primary yith-wcact-add-row">' . esc_html__( 'Add Range', 'yith-auctions-for-woocommerce' ) . '</button>
    <button type="button" class="button yith-wcact-copy-global" data-product-id="' . esc_attr( $post_id ) . '">' . esc_html__( 'Copy from Global', 'yith-auctions-for-woocommerce' ) . '</button>
</td></tr></tfoot>';
echo '</table>';
echo '<p class="description">' . esc_html__( 'From Price: minimum current bid for this range. Increment: minimum required bid increase.', 'yith-auctions-for-woocommerce' ) . '</p>';
echo '</div>'; // #yith-wcact-product-bid-increments
echo '</div>'; // .yith-wcact-bid-increment-section

do_action('yith_after_auction_tab',$post_id);