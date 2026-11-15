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
            searchDelay:500,
            lengthMenu:[25,50,100],
            serverMethod:"POST",
            ajax:{
                url:crmWalletManager.ajaxUrl,
                type:'POST',
                data:function(d){
                    d.action = "crm_wallet_manager_table";
                    d._ajax_nonce = crmWalletManager.ajaxNonce;
                    $('.imao-directory-filters').serializeArray().forEach(function(item){ d[item.name] = item.value; });
                }
            },
            order:[[7,"desc"]],
            dom:"frtip",
            deferRender:true,
            columns:[
                {data:"row_number",orderable:false,searchable:false},
                {data:"first_name",orderable:false},
                {data:"last_name",orderable:false},
                {data:"province",orderable:false},
                {data:"city",orderable:false},
                {data:"status",orderable:false},
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
        bindDirectoryControls(table, $table);
    });
  function bindDirectoryControls(api, $table) {
    $('.imao-directory-filters').on('submit', function (event) {
      event.preventDefault();
      api.ajax.reload();
    }).on('reset', function () {
      window.setTimeout(function () { api.search('').ajax.reload(); }, 0);
    });
    $('.imao-directory-export').on('click', function () {
      const form = $('<form>', {method: 'POST', action: $table.data('exportUrl'), target: '_blank'});
      const order = api.order()[0] || [0, 'desc'];
      const fields = $('.imao-directory-filters').serializeArray().concat([
        {name: 'action', value: $table.data('exportAction')},
        {name: 'nonce', value: $table.data('nonce')},
        {name: 'format', value: $(this).data('format')},
        {name: 'search_text', value: api.search()},
        {name: 'sort_column', value: order[0]}, {name: 'sort_dir', value: order[1]}
      ]);
      fields.forEach(function (field) { $('<input>', {type: 'hidden', name: field.name, value: field.value}).appendTo(form); });
      form.appendTo(document.body).trigger('submit').remove();
    });
  }
})(jQuery);
