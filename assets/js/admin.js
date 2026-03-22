jQuery(document).ready(function($) {
    $('select#product-type').on('change',function(){
        var value = $(this).val();
        if (value == 'auction'){
            $('#_regular_price').val('');
            $('#_sale_price').val('');
        }
    });

    // Toggle product bid increment ranges when "Use Global" checkbox changes
    $(document).on('change', '#_yith_auction_bid_increment_use_global', function() {
        if ($(this).is(':checked')) {
            $('#yith-wcact-product-bid-increments').hide();
        } else {
            $('#yith-wcact-product-bid-increments').show();
        }
    });

    // Add a new row to any bid increment ranges table
    $(document).on('click', '.yith-wcact-add-row', function(e) {
        e.preventDefault();
        var $table = $(this).closest('table');
        var $tbody = $table.find('tbody');
        var isGlobal = $table.attr('id') === 'yith-wcact-global-bid-increments';
        var fromName = isGlobal ? '_yith_global_bid_increment_from_price[]' : '_yith_bid_increment_from_price[]';
        var incName  = isGlobal ? '_yith_global_bid_increment_amount[]' : '_yith_bid_increment_amount[]';

        var newRow = '<tr>' +
            '<td><input type="number" step="0.01" min="0" name="' + fromName + '" value="0.00" /></td>' +
            '<td><input type="number" step="0.01" min="0.01" name="' + incName + '" value="1.00" /></td>' +
            '<td><button type="button" class="button yith-wcact-remove-row">Remove</button></td>' +
            '</tr>';
        $tbody.append(newRow);
    });

    // Remove a row from bid increment ranges table
    $(document).on('click', '.yith-wcact-remove-row', function(e) {
        e.preventDefault();
        var $tbody = $(this).closest('tbody');
        if ($tbody.find('tr').length > 1) {
            $(this).closest('tr').remove();
        }
    });

    // Copy global bid increment ranges to this product
    $(document).on('click', '.yith-wcact-copy-global', function(e) {
        e.preventDefault();
        var productId = $(this).data('product-id');
        var $table = $(this).closest('table');
        var $tbody = $table.find('tbody');

        $.ajax({
            type: 'POST',
            url: object.ajaxurl,
            data: {
                action: 'yith_wcact_copy_global_increments',
                product_id: productId
            },
            success: function(response) {
                if (response.success && response.data) {
                    $tbody.empty();
                    $.each(response.data, function(i, range) {
                        var row = '<tr>' +
                            '<td><input type="number" step="0.01" min="0" name="_yith_bid_increment_from_price[]" value="' + range.from_price + '" /></td>' +
                            '<td><input type="number" step="0.01" min="0.01" name="_yith_bid_increment_amount[]" value="' + range.increment + '" /></td>' +
                            '<td><button type="button" class="button yith-wcact-remove-row">Remove</button></td>' +
                            '</tr>';
                        $tbody.append(row);
                    });
                }
            }
        });
    });
});
