(function($){
    $(function(){
        if (typeof crmWalletManager === 'undefined') {
            return;
        }

        var $table = $("#crm-wallet-table");
        if ($table.length === 0) {
            return;
        }

        var table = $table.DataTable({
            language:{url:"https://cdn.datatables.net/plug-ins/1.13.8/i18n/fa.json"},
            pageLength:50,
            processing:true,
            serverSide:false,
            serverMethod:"POST",
            ajax:{
                url:crmWalletManager.ajaxUrl,
                data:function(d){
                    d.action = "crm_wallet_manager_table";
                    d._ajax_nonce = crmWalletManager.ajaxNonce;
                    d.start = 0;
                    d.length = -1;
                }
            },
            order:[[7,"desc"]],
            dom:"frtip",
            deferRender:true,
            columns:[
                {data:"row_number",orderable:false,searchable:false},
                {data:"first_name",orderable:false},
                {data:"last_name",orderable:false},
                {data:"province",orderable:true},
                {data:"city",orderable:true},
                {data:"status",orderable:true},
                {data:"registered",orderable:true},
                {data:"balance",orderable:true},
                {data:"card_number",orderable:false},
                {data:"iban",orderable:false},
                {data:"amount",orderable:false,searchable:false},
                {data:"memo",orderable:false,searchable:false},
                {data:"actions",orderable:false,searchable:false}
            ],
            columnDefs:[{targets:[10,11,12],className:"dt-nowrap"}],
            drawCallback:function(){ $(".crm-wallet-amount").attr({min:0,step:0.01}); }
        });
    });
})(jQuery);
