// js/facility-form-modules/admin-data-page.js
(function() {
    if (window.KOP_AdminDataPage) return;

    window.KOP_AdminDataPage = {
        init: function() {
            console.log('KOP_AdminDataPage initialized');
            if (window.KOP_DataPage && window.KOP_DataPage.init) {
                window.KOP_DataPage.init();
            }
        }
    };
    
    document.addEventListener('DOMContentLoaded', () => {
        window.KOP_AdminDataPage.init();
    });
})();