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
            serverSide:true,
            serverMethod:"POST",
            ajax:{
                url:crmWalletManager.ajaxUrl,
                data:function(d){
                    d.action = "crm_wallet_manager_table";
                    d._ajax_nonce = crmWalletManager.ajaxNonce;
                }
            },
            order:[[3,"desc"]],
            dom:"Bfrtip",
            deferRender:true,
            columns:[
                {data:"row_number",orderable:false,searchable:false},
                {data:"first_name",orderable:false},
                {data:"last_name",orderable:false},
                {data:"balance",orderable:true},
                {data:"card_number",orderable:false},
                {data:"iban",orderable:false},
                {data:"amount",orderable:false,searchable:false},
                {data:"memo",orderable:false,searchable:false},
                {data:"actions",orderable:false,searchable:false}
            ],
            buttons:[
                {extend:"excelHtml5",text:"خروجی اکسل",exportOptions:{columns:[0,1,2,3,4,5],rows:function(idx,data){return parseFloat(data.balance_raw) !== 0;}}},
                {extend:"print",text:"چاپ",exportOptions:{columns:[0,1,2,3,4,5],rows:function(idx,data){return parseFloat(data.balance_raw) !== 0;}},customize:function(win){$(win.document.body).css("direction","rtl");$(win.document.body).find("table").addClass("rtl-table");}}
            ],
            columnDefs:[{targets:[6,7,8],className:"dt-nowrap"}],
            drawCallback:function(){ $(".crm-wallet-amount").attr({min:0,step:0.01}); }
        });
    });
})(jQuery);
