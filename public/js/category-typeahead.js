(function () {
    function flowLabel(flow) {
        return flow.charAt(0).toUpperCase() + flow.slice(1);
    }

    function formatDisplay(flow, label) {
        return flowLabel(flow) + ': ' + label;
    }

    function stripDisplayPrefix(query) {
        return query.replace(/^\s*(expense|income|transfer)\s*:\s*/i, '').trim();
    }

    function bind(root) {
        var input = root.querySelector('[data-typeahead-input]');
        var idInput = root.querySelector('[data-typeahead-id]');
        var list = root.querySelector('[data-typeahead-list]');
        var searchUrl = root.getAttribute('data-search-url');

        function currentFlow() {
            return root.getAttribute('data-flow') || '';
        }
        var form = root.closest('form.review-category-form');
        var indicatorEl = root.querySelector('.category-typeahead-save-indicator');
        var activeIndex = -1;
        var options = [];
        var pickingFromList = false;
        var saving = false;
        var initialDisplay = input.value;
        var initialCategoryId = idInput.value;
        var fadeTimer = null;

        function hideList() {
            list.classList.add('d-none');
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function showList() {
            list.classList.remove('d-none');
            input.setAttribute('aria-expanded', 'true');
        }

        function setSaveIndicator(state) {
            if (!indicatorEl) {
                return;
            }
            window.clearTimeout(fadeTimer);
            indicatorEl.classList.remove('is-visible', 'is-saving', 'is-success', 'is-error');
            indicatorEl.innerHTML = '';
            if (!state) {
                return;
            }
            indicatorEl.classList.add('is-visible');
            if (state === 'saving') {
                indicatorEl.classList.add('is-saving');
                indicatorEl.innerHTML = '<i class="fa-solid fa-ellipsis"></i>';
                return;
            }
            if (state === 'success') {
                indicatorEl.classList.add('is-success');
                indicatorEl.innerHTML = '<i class="fa-solid fa-check"></i>';
                fadeTimer = window.setTimeout(function () {
                    setSaveIndicator('');
                }, 1500);
                return;
            }
            if (state === 'error') {
                indicatorEl.classList.add('is-error');
                indicatorEl.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                fadeTimer = window.setTimeout(function () {
                    setSaveIndicator('');
                }, 2200);
            }
        }

        function commitSaved(categoryId, display) {
            if (form) {
                form.setAttribute('data-saved-category-id', categoryId);
            }
            initialCategoryId = categoryId;
            initialDisplay = display;
        }

        function markReviewRowPublishable() {
            if (!form) {
                return;
            }
            var row = form.closest('tr');
            if (!row) {
                return;
            }
            var badge = row.querySelector('.review-row-status .badge');
            if (badge) {
                badge.className = 'badge text-bg-warning';
                badge.textContent = 'Ready to publish';
            }
            row.querySelectorAll('[data-review-publish], [data-review-redact]').forEach(function (input) {
                if (input.hasAttribute('data-review-publish') && input.classList.contains('review-publish-via-redact')) {
                    return;
                }
                input.disabled = false;
                input.removeAttribute('title');
            });
        }

        function renderItems(items) {
            options = items;
            list.innerHTML = '';
            items.forEach(function (item, index) {
                var li = document.createElement('li');
                li.className = 'list-group-item list-group-item-action py-1 small';
                li.setAttribute('role', 'option');
                li.setAttribute('data-index', String(index));
                li.textContent = formatDisplay(item.flow, item.label);
                li.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    pickingFromList = true;
                    select(index);
                    window.setTimeout(maybeAutoSave, 0);
                });
                list.appendChild(li);
            });
            if (items.length === 0) {
                hideList();
            } else {
                showList();
            }
        }

        function select(index) {
            var item = options[index];
            if (!item) {
                return;
            }
            idInput.value = String(item.id);
            input.value = formatDisplay(item.flow, item.label);
            root.setAttribute('data-flow', item.flow);
            hideList();
            root.dispatchEvent(new CustomEvent('category-typeahead:commit', {
                bubbles: true,
                detail: {
                    categoryId: item.id,
                    lineageKey: item.lineageKey,
                    flow: item.flow,
                    display: input.value,
                },
            }));
        }

        function savedCategoryId() {
            if (!form) {
                return initialCategoryId;
            }

            return form.getAttribute('data-saved-category-id') || initialCategoryId;
        }

        function displayChanged() {
            return input.value.trim() !== initialDisplay.trim();
        }

        function maybeAutoSave() {
            if (!form || pickingFromList) {
                pickingFromList = false;
                return;
            }
            if (saving) {
                return;
            }
            if (!displayChanged() && idInput.value === savedCategoryId()) {
                return;
            }
            if (idInput.value === '' && !displayChanged()) {
                input.value = initialDisplay;
                idInput.value = initialCategoryId;
                return;
            }
            if (input.value.trim() === '') {
                input.value = initialDisplay;
                idInput.value = initialCategoryId;
                return;
            }
            saving = true;
            setSaveIndicator('saving');
            var body = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: body,
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        return { ok: response.ok, payload: payload };
                    });
                })
                .then(function (result) {
                    saving = false;
                    if (!result.ok) {
                        input.value = initialDisplay;
                        idInput.value = initialCategoryId;
                        setSaveIndicator('error');
                        return;
                    }
                    if (result.payload.categoryId) {
                        idInput.value = String(result.payload.categoryId);
                    }
                    commitSaved(idInput.value, input.value);
                    markReviewRowPublishable();
                    setSaveIndicator('success');
                })
                .catch(function () {
                    saving = false;
                    input.value = initialDisplay;
                    idInput.value = initialCategoryId;
                    setSaveIndicator('error');
                });
        }

        function fetchResults(query) {
            var flow = currentFlow();
            var url = searchUrl + '?flow=' + encodeURIComponent(flow) + '&q=' + encodeURIComponent(stripDisplayPrefix(query));
            fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    renderItems(payload.results || []);
                })
                .catch(function () {
                    hideList();
                });
        }

        input.addEventListener('input', function () {
            idInput.value = '';
            setSaveIndicator('');
            fetchResults(input.value.trim());
        });

        input.addEventListener('blur', function () {
            window.setTimeout(maybeAutoSave, 150);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                input.value = initialDisplay;
                idInput.value = initialCategoryId;
                hideList();
                setSaveIndicator('');
                event.preventDefault();
                return;
            }
            if (event.key === 'ArrowDown') {
                activeIndex = Math.min(activeIndex + 1, options.length - 1);
                event.preventDefault();
                return;
            }
            if (event.key === 'ArrowUp') {
                activeIndex = Math.max(activeIndex - 1, 0);
                event.preventDefault();
                return;
            }
            if (event.key === 'Enter' && activeIndex >= 0) {
                select(activeIndex);
                event.preventDefault();
                window.setTimeout(maybeAutoSave, 0);
            }
        });

        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                hideList();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-category-typeahead]').forEach(bind);
    });
})();
