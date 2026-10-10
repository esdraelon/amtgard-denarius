(function () {
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById('review-pattern-modal');
        var form = document.getElementById('review-pattern-form');
        if (!modalEl || !form || typeof bootstrap === 'undefined') {
            return;
        }
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        var tokenInput = document.getElementById('review-pattern-token');
        var typeaheadRoot = modalEl.querySelector('[data-category-typeahead]');
        var categoryIdInput = typeaheadRoot ? typeaheadRoot.querySelector('[data-typeahead-id]') : null;
        var categoryDisplayInput = typeaheadRoot ? typeaheadRoot.querySelector('[data-typeahead-input]') : null;
        var counterpartyInput = document.getElementById('review-pattern-source-counterparty');
        var descriptionInput = document.getElementById('review-pattern-source-description');
        var previewStatus = document.getElementById('review-pattern-preview-status');
        var matchesPanel = document.getElementById('review-pattern-matches-panel');
        var matchesScroll = document.getElementById('review-pattern-matches-scroll');
        var matchesBody = document.getElementById('review-pattern-matches-body');
        var selectAll = document.getElementById('review-pattern-select-all');
        var submitBtn = document.getElementById('review-pattern-submit');
        var errorEl = document.getElementById('review-pattern-error');
        var previewUrl = form.action.replace(/\/patterns$/, '/patterns/preview');
        var ruleFlowInput = document.getElementById('review-pattern-rule-flow');
        var anchorCentsInput = document.getElementById('review-pattern-anchor-cents');
        var anchorTxnInput = document.getElementById('review-pattern-anchor-txn');
        var hasMatches = false;
        var previewDebounceMs = 500;
        var previewDebounceTimer = null;
        var previewRequestSeq = 0;
        var latchedPreviewKey = '';

        function initPopovers() {
            modalEl.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (trigger) {
                bootstrap.Popover.getOrCreateInstance(trigger);
            });
        }

        function syncRuleFlow() {
            if (anchorCentsInput && anchorCentsInput.value !== '') {
                var cents = parseInt(anchorCentsInput.value, 10);
                if (!Number.isNaN(cents) && ruleFlowInput) {
                    ruleFlowInput.value = cents > 0 ? 'income' : 'expense';
                }

                return;
            }
            if (!ruleFlowInput || !typeaheadRoot) {
                return;
            }
            ruleFlowInput.value = typeaheadRoot.getAttribute('data-flow') || 'expense';
        }

        function setCategory(categoryId, display, flow) {
            if (!categoryDisplayInput || !typeaheadRoot) {
                return;
            }
            var resolvedFlow = flow || 'expense';
            typeaheadRoot.setAttribute('data-flow', resolvedFlow);
            if (categoryIdInput) {
                categoryIdInput.value = categoryId || '';
            }
            categoryDisplayInput.value = display || '';
            syncRuleFlow();
        }

        function clearMatches() {
            hasMatches = false;
            matchesBody.innerHTML = '';
            matchesPanel.classList.add('d-none');
            submitBtn.disabled = true;
            previewStatus.textContent = '';
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
        }

        function previewStateKey() {
            return [
                tokenInput.value.trim(),
                categoryIdInput ? categoryIdInput.value.trim() : '',
                categoryDisplayInput ? categoryDisplayInput.value.trim() : '',
                ruleFlowInput ? ruleFlowInput.value.trim() : '',
                anchorCentsInput ? anchorCentsInput.value.trim() : '',
            ].join('|');
        }

        function schedulePreview() {
            window.clearTimeout(previewDebounceTimer);
            previewDebounceTimer = window.setTimeout(function () {
                var key = previewStateKey();
                if (key === latchedPreviewKey) {
                    return;
                }
                runPreview();
            }, previewDebounceMs);
        }

        function renderMatches(matches) {
            matchesBody.innerHTML = '';
            matches.forEach(function (row) {
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td class="text-center">' +
                    '<input class="form-check-input review-pattern-match" type="checkbox" name="apply_transaction_ids[]" value="' +
                    escapeHtml(row.tellerTransactionId) + '" checked aria-label="Apply to this row">' +
                    '</td>' +
                    '<td>' + escapeHtml(row.postedOn) + '</td>' +
                    '<td>' + escapeHtml(row.description) + '</td>' +
                    '<td>' + escapeHtml(row.counterparty) + '</td>' +
                    '<td>' + escapeHtml(row.amount) + '</td>';
                matchesBody.appendChild(tr);
            });
            selectAll.checked = matches.length > 0;
            matchesPanel.classList.remove('d-none');
            if (matchesScroll) {
                matchesScroll.scrollTop = 0;
            }
            hasMatches = matches.length > 0;
            submitBtn.disabled = !hasMatches;
            previewStatus.textContent = matches.length === 0
                ? 'No matching rows with the same sign (expense/income) as this transaction on published accounts.'
                : matches.length + ' matching row' + (matches.length === 1 ? '' : 's') + '.';
        }

        function runPreview() {
            var requestSeq = ++previewRequestSeq;
            clearMatches();
            previewStatus.textContent = 'Finding matches…';
            syncRuleFlow();
            fetch(previewUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: new FormData(form),
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        return { ok: response.ok, payload: payload };
                    });
                })
                .then(function (result) {
                    if (requestSeq !== previewRequestSeq) {
                        return;
                    }
                    if (!result.ok) {
                        errorEl.textContent = result.payload.error || 'Could not preview matches.';
                        errorEl.classList.remove('d-none');
                        previewStatus.textContent = '';
                        return;
                    }
                    renderMatches(result.payload.matches || []);
                    latchedPreviewKey = previewStateKey();
                })
                .catch(function () {
                    if (requestSeq !== previewRequestSeq) {
                        return;
                    }
                    errorEl.textContent = 'Could not preview matches.';
                    errorEl.classList.remove('d-none');
                    previewStatus.textContent = '';
                });
        }

        initPopovers();
        modalEl.addEventListener('shown.bs.modal', initPopovers);

        document.querySelectorAll('.review-pattern-open').forEach(function (button) {
            button.addEventListener('click', function () {
                tokenInput.value = button.getAttribute('data-pattern-token') || '';
                setCategory(
                    button.getAttribute('data-pattern-category-id') || '',
                    button.getAttribute('data-pattern-category-display') || '',
                    button.getAttribute('data-row-flow') || 'expense',
                );
                if (anchorCentsInput) {
                    anchorCentsInput.value = button.getAttribute('data-row-amount-cents') || '';
                }
                if (anchorTxnInput) {
                    anchorTxnInput.value = button.getAttribute('data-row-transaction-id') || '';
                }
                counterpartyInput.value = button.getAttribute('data-row-counterparty') || '';
                descriptionInput.value = button.getAttribute('data-row-description') || '';
                syncRuleFlow();
                latchedPreviewKey = '';
                clearMatches();
                modal.show();
                runPreview();
            });
        });

        if (tokenInput) {
            tokenInput.addEventListener('input', function () {
                latchedPreviewKey = '';
                schedulePreview();
            });
            tokenInput.addEventListener('blur', function () {
                schedulePreview();
            });
        }

        if (typeaheadRoot && categoryDisplayInput) {
            typeaheadRoot.addEventListener('category-typeahead:commit', function () {
                latchedPreviewKey = '';
                syncRuleFlow();
                schedulePreview();
            });
            categoryDisplayInput.addEventListener('input', function () {
                latchedPreviewKey = '';
                schedulePreview();
            });
            categoryDisplayInput.addEventListener('blur', function () {
                schedulePreview();
            });
        }

        selectAll.addEventListener('change', function () {
            matchesBody.querySelectorAll('.review-pattern-match').forEach(function (box) {
                box.checked = selectAll.checked;
            });
        });

        form.addEventListener('submit', function (event) {
            syncRuleFlow();
            if (!hasMatches) {
                event.preventDefault();
                errorEl.textContent = 'Wait for matching rows to load before saving.';
                errorEl.classList.remove('d-none');
                return;
            }
            var selectedApply = matchesBody.querySelectorAll('.review-pattern-match:checked');
            if (selectedApply.length === 0) {
                event.preventDefault();
                errorEl.textContent = 'Select at least one matching transaction to categorize.';
                errorEl.classList.remove('d-none');
                return;
            }
            if ((categoryDisplayInput && categoryDisplayInput.value.trim() !== '')
                || (categoryIdInput && categoryIdInput.value.trim() !== '')) {
                return;
            }
            event.preventDefault();
            errorEl.textContent = 'Enter a category name for this pattern.';
            errorEl.classList.remove('d-none');
        });
    });
})();
