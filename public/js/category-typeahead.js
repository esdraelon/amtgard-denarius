(function () {
    function flowLabel(flow) {
        return flow.charAt(0).toUpperCase() + flow.slice(1);
    }

    function bind(root) {
        var input = root.querySelector('[data-typeahead-input]');
        var slugInput = root.querySelector('[data-typeahead-slug]');
        var list = root.querySelector('[data-typeahead-list]');
        var searchUrl = root.getAttribute('data-search-url');
        var flow = root.getAttribute('data-flow') || '';
        var activeIndex = -1;
        var options = [];

        function hideList() {
            list.classList.add('d-none');
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function showList() {
            list.classList.remove('d-none');
            input.setAttribute('aria-expanded', 'true');
        }

        function renderItems(items) {
            options = items;
            list.innerHTML = '';
            items.forEach(function (item, index) {
                var li = document.createElement('li');
                li.className = 'list-group-item list-group-item-action py-1 small';
                li.setAttribute('role', 'option');
                li.setAttribute('data-index', String(index));
                li.textContent = flowLabel(item.flow) + ' · ' + item.label;
                li.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    select(index);
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
            slugInput.value = item.slug;
            input.value = flowLabel(item.flow) + ' · ' + item.label;
            hideList();
        }

        function fetchResults(query) {
            var url = searchUrl + '?flow=' + encodeURIComponent(flow) + '&q=' + encodeURIComponent(query);
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
            fetchResults(input.value.trim());
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                input.value = slugInput.value;
                hideList();
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
