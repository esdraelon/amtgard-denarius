(function () {
    function syncRow(redactInput) {
        var rowId = redactInput.getAttribute('data-review-redact');
        if (!rowId) {
            return;
        }
        var publishInput = document.querySelector('[data-review-publish="' + rowId + '"]');
        if (!publishInput) {
            return;
        }
        if (redactInput.checked) {
            publishInput.checked = true;
            publishInput.disabled = true;
            publishInput.classList.add('review-publish-via-redact');
            return;
        }
        publishInput.disabled = false;
        publishInput.classList.remove('review-publish-via-redact');
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-review-redact]').forEach(function (redactInput) {
            syncRow(redactInput);
            redactInput.addEventListener('change', function () {
                syncRow(redactInput);
            });
        });
    });
})();
