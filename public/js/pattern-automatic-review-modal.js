(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById('pattern-automatic-review-modal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }
        var openBtn = document.getElementById('pattern-automatic-review-open');
        var selectAll = document.getElementById('pattern-automatic-select-all');
        var form = document.getElementById('pattern-automatic-review-form');
        if (!openBtn || !selectAll || !form) {
            return;
        }
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        openBtn.addEventListener('click', function () {
            modal.show();
        });
        selectAll.addEventListener('change', function () {
            modalEl.querySelectorAll('.pattern-automatic-apply').forEach(function (box) {
                box.checked = selectAll.checked;
            });
        });
        form.addEventListener('submit', function (event) {
            var checked = modalEl.querySelectorAll('.pattern-automatic-apply:checked');
            if (checked.length === 0) {
                event.preventDefault();
            }
        });
    });
})();
