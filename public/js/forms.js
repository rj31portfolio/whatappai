document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.dataset.contactAction) {
        const selection = form.querySelector('[data-contact-selection]');
        if (selection?.value) form.action = `${form.dataset.contactAction}/${selection.value}/consent`;
    }
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
document.addEventListener('change', event => {
    if (event.target.dataset.templateChoice) {
        document.getElementById(event.target.dataset.templateChoice).value = event.target.selectedOptions[0]?.dataset.body || '';
    }
});
const workflowForm = document.querySelector('[data-workflow-form]');
if (workflowForm) {
    let nextStep = 0;
    const addStep = () => {
        const container = workflowForm.querySelector('[data-workflow-steps]');
        if (container.children.length >= 12) return;
        const index = nextStep++;
        const node = document.createElement('div');
        node.className = 'workflow-editor-step';
        node.innerHTML = `<label>Action<select name="steps[${index}][type]"><option value="task">Create follow-up task</option><option value="score">Set lead score</option><option value="delay">Delay (minutes)</option><option value="email">Send email</option><option value="whatsapp">Send WhatsApp reply</option></select></label><label>Value<input name="steps[${index}][value]" maxlength="2000" required></label><label>Condition<select name="steps[${index}][if_score]"><option value="">Always</option><option>hot</option><option>warm</option><option>cold</option></select></label><button type="button" class="btn-small">Remove</button>`;
        node.querySelector('button').addEventListener('click', () => node.remove());
        container.append(node);
    };
    workflowForm.querySelector('[data-add-step]').addEventListener('click', addStep);
    workflowForm.addEventListener('submit', () => {
        [...workflowForm.querySelectorAll('.workflow-editor-step')].forEach((step, index) => {
            step.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/steps\[\d+\]/, `steps[${index}]`); });
        });
    });
    addStep();
}
window.addEventListener('pageshow', () => {
    document.querySelectorAll('button[data-original-label]').forEach(button => {
        button.disabled = false;
        button.textContent = button.dataset.originalLabel;
    });
});
