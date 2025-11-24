document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('special-rows');
    const addBtn = document.getElementById('add-special');

    function attachRemoveHandlers(scope) {
    (scope || document).querySelectorAll('.remove-row').forEach(function (btn) {
        btn.onclick = function () {
        const row = btn.closest('.special-row');
        if (!row) return;
        if (container.querySelectorAll('.special-row').length === 1) {
            row.querySelectorAll('input, select').forEach(input => input.value = '');
            return;
        }
        row.remove();
        };
    });
    }

    addBtn.addEventListener('click', function () {
    const rows = container.querySelectorAll('.special-row');
    const last = rows[rows.length - 1];
    const clone = last.cloneNode(true);
    clone.querySelectorAll('input, select').forEach(function (input) {
        input.value = '';
    });
    container.appendChild(clone);
    attachRemoveHandlers(clone);
    });

    attachRemoveHandlers(document);
});