(function($){
    function triggerCartRefresh(){
        var $cartForm = $('.woocommerce-cart-form');
        if(!$cartForm.length){
            return;
        }
        var $updateBtn = $cartForm.find('input[name="update_cart"]');
        if($updateBtn.length){
            $updateBtn.prop('disabled', false);
            $updateBtn.trigger('click');
        }
    }

    function triggerCheckoutRefresh(){
        $(document.body).trigger('update_checkout');
    }

    $(document).on('change', 'input[name="crm_use_wallet"]', function(){
        if($(document.body).hasClass('woocommerce-checkout')){
            triggerCheckoutRefresh();
            return;
        }
        triggerCartRefresh();
    });
})(jQuery);
