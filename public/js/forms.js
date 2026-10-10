document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
        return;
    }
    const button = event.submitter;
    if (button) {
        button.disabled = true;
        button.dataset.originalLabel = button.textContent;
        button.textContent = 'Please wait…';
    }
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('button[data-original-label]').forEach(button => {
        button.disabled = false;
        button.textContent = button.dataset.originalLabel;
    });
});
