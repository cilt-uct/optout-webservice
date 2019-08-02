(function($){

    $.fn.email_multiple = function(options) {

        let defaults = {
            reset: false,
            fill: false,
            data: null,
            inputPlaceholder: "Enter Email ..."
        };

        let settings = $.extend(defaults, options);
        let email = "";

        return this.each(function() {
            $(this).after("<div class=\"multi-container\"></div>\n" +
                "<input type=\"text\" name=\"multi-input\" class=\"multi-input\" placeholder=\""+ settings.inputPlaceholder +"\" />");
            let $orig = $(this);
            let $element = $('.multi-input');
            $element.keydown(function (e) {
                $element.css('border', '');
                if (e.keyCode === 13 || e.keyCode === 32) {
                    let inp = $element.val().split(/^(.*)\s|<(.*)>/gm).filter(function(s) { return (s != '') && (s); });
                    $.each(inp, function(i, st) {
                        if (/^[a-z0-9._-]+@[a-z0-9._-]+\.[a-z]{2,6}$/.test(st)){
                            $('.multi-container').append('<span class="multi-item">' + st + '<span class="multi-item-cancel"><i class="fas fa-times"></i></span></span>');
                            $element.val('');
                            email += st + ';'
                        } else {
                            $('.multi-container').append('<span class="multi-item wrong">' + st + '<span class="multi-item-cancel"><i class="fas fa-times"></i></span></span>');
                            $('.multi-container .wrong').delay(3000).fadeOut(500);
                            $element.val('');
                        }
                    });
                }
                $orig.val(email.slice(0, -1)).trigger('change');
            });

            $(document).on('click','.multi-item-cancel',function(){
                $(this).parent().remove();
            });

            if(settings.data){
                $.each(settings.data, function(i, st) {
                    if (/^[a-z0-9._-]+@[a-z0-9._-]+\.[a-z]{2,6}$/.test(st)){
                        $('.multi-container').append('<span class="multi-item">' + st + '<span class="multi-item-cancel"><i class="fas fa-times"></i></span></span>');
                        email += st + ';'
                    }
                })
                $element.val('');
                $orig.val(email.slice(0, -1));
            }

            if(settings.reset){
                $('.multi-item').remove()
            }

            return $orig.hide()
        });
    };

})(jQuery);
