<?php
/*
 * This file belongs to the YIT Framework.
 *
 * This source file is subject to the GNU GENERAL PUBLIC LICENSE (GPL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.txt
 */

$regenerate_price_url = add_query_arg(array('yith-wcact-action' => 'regenerate_auction_prices'));

return array(

    'settings' => apply_filters( 'yith_wcact_settings_options', array(

            //////////////////////////////////////////////////////

            'settings_tab_auction_options_start'    => array(
                'type' => 'sectionstart',
                'id'   => 'yith_wcact_settings_tab_auction_start'
            ),

            'settings_tab_auction_options_title'    => array(
                'title' => _x( 'Product settings', 'Panel: page title', 'yith-auctions-for-woocommerce' ),
                'type'  => 'title',
                'desc'  => '',
                'id'    => 'yith_wcact_settings_tab_auction_title'
            ),
            'settings_tab_auction_show_name' => array(
                'title'   => _x( 'Show full Username in bid tab', 'Admin option: Show auctions on shop page', 'yith-auctions-for-woocommerce' ),
                'type'    => 'checkbox',
                'desc'    => _x( 'Check this option to show full Username in bid tab', 'Admin option description: Check this option to show full Username in bid tab', 'yith-auctions-for-woocommerce' ),
                'id'      => 'yith_wcact_settings_tab_auction_show_name',
                'default' => 'no'
            ),
            'settings_tab_auction_show_button_plus_minus' => array(
                'title'   => _x( 'Show buttons in product page', 'Admin option: Show buttons in product page to increase or decrease the bid', 'yith-auctions-for-woocommerce' ),
                'type'    => 'checkbox',
                'desc'    => _x( 'Check this option to show buttons in product page to increase or decrease the bid', 'Admin option description: Check this option to show buttons in product page to increase or decrease the bid', 'yith-auctions-for-woocommerce' ),
                'id'      => 'yith_wcact_settings_tab_auction_show_button_plus_minus',
                'default' => 'no'
            ),

            'settings_tab_auction_show_button_pay_now' => array(
                'title'   => _x( 'Show pay now button in product page', 'Admin option: Show button pay now in product page', 'yith-auctions-for-woocommerce' ),
                'type'    => 'checkbox',
                'desc'    => _x( 'Check this option to show pay now button in product when the auction ends ', 'Admin option description: Check this option to show pay now button in product when the auction ends', 'yith-auctions-for-woocommerce' ),
                'id'      => 'yith_wcact_settings_tab_auction_show_button_pay_now',
                'default' => 'yes'
            ),

            'settings_tab_auction_options_end'      => array(
                'type' => 'sectionend',
                'id'   => 'yith_wcact_settings_tab_auction_end'
            ),

            ////////////////////////////////////////////////////////////////////////////////////////////////////////////

            'settings_bid_increment_options_start'    => array(
                'type' => 'sectionstart',
                'id'   => 'yith_wcact_settings_bid_increment_start'
            ),

            'settings_bid_increment_options_title'    => array(
                'title' => _x( 'Global Bid Increment Ranges', 'Panel: page title', 'yith-auctions-for-woocommerce' ),
                'type'  => 'title',
                'desc'  => _x( 'Define bid increment amounts by price range. When the current bid falls within a range, the corresponding increment is the minimum amount a bidder must increase their bid. Products use these global ranges unless overridden at the product level.', 'Admin option description', 'yith-auctions-for-woocommerce' ),
                'id'    => 'yith_wcact_settings_bid_increment_title'
            ),

            'settings_bid_increment_ranges' => array(
                'title' => _x( 'Bid Increment Ranges', 'Admin option: Bid increment ranges', 'yith-auctions-for-woocommerce' ),
                'type'  => 'yith_wcact_bid_increment_ranges',
                'id'    => 'yith_wcact_global_bid_increment_ranges',
            ),

            'settings_bid_increment_options_end'      => array(
                'type' => 'sectionend',
                'id'   => 'yith_wcact_settings_bid_increment_end'
            ),

            ////////////////////////////////////////////////////////////////////////////////////////////////////////////
        )
    )
);
