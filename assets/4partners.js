(function( $ ){
    $(function(){

        isChildChecked($('.forpartners-categories'));
        function isChildChecked(elem) {
            var result = false;

            elem.find('> li').each(function(){
                if($(this).find('> label > input[type=checkbox]:checked').length > 0) {
                    result = true;
                    return false;
                }

                result = isChildChecked($(this).find('ul'));
                if(result) {
                    $(this).addClass('visible-category');
                    return false;
                }
            });

            return result;
        }


        $('.forpartners-sync_table input[name=start_sync_all]').click(function () {
            $('.forpartners-sync_table tbody input[type=checkbox]').prop('checked', $(this).is(':checked'));
        });

        $('.forpartners-categories input[type=checkbox]').click(function () {
            $(this).closest('li').find('ul input[type=checkbox]').prop('checked', $(this).is(':checked'));

            recalculate_categories();
            /*
            if ($(this).is(':checked')) {
                $(this).parents('li').find('> label input[type=checkbox]').prop('checked', true);
            }
            */
        });

        function recalculate_categories() {
            var all = 0, withOutChildren = 0;
            $('.forpartners-categories input[type=checkbox]:checked').each(function(){
                all++;
                if(!$(this).closest('li').hasClass('forpartners-categories__has-children')) {
                    withOutChildren++;
                }

            });
            $('[data-checked-categories-count]').text(all+' ('+withOutChildren+')');
        }
        recalculate_categories();

        $('.forpartners-spoiler-icon').click(function(){
            $(this).closest('li').toggleClass('visible-category');
        });

        $('.forpartners-form .form-table').each(function(index){
            $(this).addClass('forpartners-section forpartners-section_'+index);
        });

        $('.forpartners-tabs-panel > div').click(function(){
            var section = $(this).data('section');
            $('.forpartners-section').hide();
            $('.'+section).show();
            $('.forpartners-tabs-panel > div').removeClass('active');
            $(this).addClass('active');

            $('.forpartners-form > .submit').toggle(section == 'forpartners-section_2');
        });

        $('.forpartners-tabs-panel > div').eq(0).click();




    });
})(jQuery);