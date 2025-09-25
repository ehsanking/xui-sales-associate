(function($){
  function doPing(btn, outSpan){
    var $btn = $(btn);
    var $out = $(outSpan);
    $btn.prop('disabled', true);
    $out.text('در حال تست...');

    $.post(XUI_SA.ajaxUrl, {
      action: 'xui_sa_ping',
      nonce: XUI_SA.nonce
    })
    .done(function(res){
      if(res && res.ok){
        Swal.fire({
          icon: 'success',
          title: 'ارتباط برقرار است',
          text: 'HTTP ' + (res.status||'') + (res.body ? (' — '+ String(res.body).substring(0,120)) : ''),
          timer: 2500,
          showConfirmButton: false
        });
        $out.text('OK (' + res.status + ')');
      }else{
        Swal.fire({
          icon: 'warning',
          title: 'پاسخ نامعتبر',
          text: (res && res.body) ? String(res.body).substring(0,200) : 'نامشخص'
        });
        $out.text('Invalid');
      }
    })
    .fail(function(xhr){
      var msg = 'خطا';
      try{ msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : xhr.statusText; }catch(e){}
      Swal.fire({ icon:'error', title:'عدم دسترسی', text: msg || '...' });
      $out.text('Failed');
    })
    .always(function(){ $btn.prop('disabled', false); });
  }

  $(document).on('click', '#xui-sa-ping', function(){ doPing(this, '#xui-sa-ping-result'); });
  $(document).on('click', '#xui-sa-ping-2', function(){ doPing(this, '#xui-sa-ping-result-2'); });

  // Copy wallet
  $(document).on('click', '#xui-sa-copy-wallet', function(){
    var text = $('#xui-sa-wallet').text().trim();
    if(!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function(){
        Swal.fire({icon:'success', title:'Copied!', text:'Wallet address copied to clipboard.', timer:1800, showConfirmButton:false});
      }, function(){ fallbackCopy(text); });
    } else { fallbackCopy(text); }
  });
  function fallbackCopy(text){
    var $tmp = $('<textarea>').val(text).appendTo('body').select();
    try { document.execCommand('copy'); } catch(e){}
    $tmp.remove();
    Swal.fire({icon:'success', title:'Copied!', text:'Wallet address copied to clipboard.', timer:1800, showConfirmButton:false});
  }
})(jQuery);

// Enable Bootstrap tooltips globally
jQuery(function($){ try{ new bootstrap.Tooltip(document.body,{selector:'[data-bs-toggle="tooltip"]'}); }catch(e){} });
