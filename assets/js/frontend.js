
jQuery(document).ready(function($) {
    var timer;



    var result = parseInt($('#time').data('remaining-time'));

    //Datetimeformat in product auction
    var utcSeconds =  parseInt($('#time').data('finish'));
    var d = new Date(0); // The 0 there is the key, which sets the date to the epoch
    d.setUTCSeconds(utcSeconds);

    $("#dateend").text(d.toLocaleString());



    //Timeleft
    timer = setInterval(function() {
        timeBetweenDates(result);
        result--
    }, 1000);

    function timeBetweenDates(result) {
        if (result <= 0) {

            // Timer done

            clearInterval(timer);
            window.location.reload(true);

        } else {

            var seconds = Math.floor(result);
            var minutes = Math.floor(seconds / 60);
            var hours = Math.floor(minutes / 60);
            var days = Math.floor(hours / 24);

            hours %= 24;
            minutes %= 60;
            seconds %= 60;

            $("#days").text(days);
            $("#hours").text(hours);
            $("#minutes").text(minutes);
            $("#seconds").text(seconds);
        }
    }

    //Button up or down bid
    var current = parseFloat($('#time').data('current'));
    var bidIncrement = parseFloat($('#time').data('bid-increment')) || 1;
    var minimumBid = parseFloat($('#time').data('minimum-bid')) || current;
    $(".bid").click(function(e){
        e.preventDefault();
        var actual_bid = parseFloat($('#_actual_bid').val());
        if($(this).hasClass("button_bid_add")){
            if(!actual_bid || isNaN(actual_bid)){
                actual_bid = minimumBid;
            } else {
                actual_bid = actual_bid + bidIncrement;
            }
            $('#_actual_bid').val(actual_bid.toFixed(2));
        } else {
            if(actual_bid && !isNaN(actual_bid)){
                actual_bid = actual_bid - bidIncrement;
                if (actual_bid >= minimumBid){
                    $('#_actual_bid').val(actual_bid.toFixed(2));
                }else{
                    $('#_actual_bid').val(minimumBid.toFixed(2));
                }
            }
        }
    });

//Button bid
//
    $( document ).on( 'click', '.auction_bid', function( e ) {
        //var target = $( e.target ); // this code get the target of the click -->  $('.bid')
        var post_data = {
            'bid': $('#_actual_bid').val(),
            'product' : $('#time').data('product'),
            //security: object.search_post_nonce,
            action: 'yith_wcact_add_bid'
        };

        $.ajax({
            type    : "POST",
            data    : post_data,
            url     : object.ajaxurl,
            success : function ( response ) {
                if ( response.bid_accepted === false ) {
                    // Remove any previous error message
                    $( '#yith-wcact-bid-error' ).remove();

                    var msg = response.message || 'Your bid was too low.';
                    $( '#yith-wcact-form-bid' ).after(
                        '<div id="yith-wcact-bid-error" class="yith-wcact-bid-error">' + $('<span>').text(msg).html() + '</div>'
                    );

                    // Update the input to the minimum allowed bid
                    if ( response.minimum_bid ) {
                        $( '#_actual_bid' ).val( parseFloat( response.minimum_bid ).toFixed(2) );
                        minimumBid = parseFloat( response.minimum_bid );
                    }
                    if ( response.increment ) {
                        bidIncrement = parseFloat( response.increment );
                    }
                } else {
                    window.location = response.url;
                }
            },
            complete: function () {
            }
        });
    } );

    //Disable enter in input
    $("#_actual_bid").keydown(function( event ) {
        if ( event.which == 13 ) {
            event.preventDefault();
        }
    });

    $( '.yith_auction_datetime' ).each( function ( index ) {
        var datetime     = $( this ).text();
        datetime = datetime+'Z';
        /*  //datetime = datetime.replace(/-/g,'/');
         //console.log(datetime);
         //var current_date = new Date(Date.parse(datetime));*/
        var current_date = new Date( datetime );
        $( this ).text( current_date.toLocaleString() );
    } );

});

