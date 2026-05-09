import { Controller } from '@hotwired/stimulus';

/**
 * Loading form controller.
 *
 * Disables the submit button and shows a spinner while a form is submitting.
 * Prevents double-submission on slow server-side operations (e.g. AO3 scraping).
 *
 * The page reload that follows a successful POST (or a redirect after an error)
 * naturally resets button state, so no cleanup logic is needed.
 *
 * Usage:
 *   <form data-controller="loading-form">
 *     <input data-loading-form-target="field" ...>
 *     <button type="submit" data-loading-form-target="submit">Import</button>
 *   </form>
 *
 * Targets:
 *   submit  The submit button to disable and replace with a spinner.
 *   field   (optional) One or more inputs to disable during submission.
 */
export default class extends Controller {
    static targets = ['submit', 'field'];

    connect() {
        this.element.addEventListener('submit', this.#onSubmit.bind(this));
    }

    #onSubmit(event) {
        if (this.hasSubmitTarget) {
            const btn = this.submitTargets.includes(event.submitter)
                ? event.submitter
                : this.submitTarget;

            // Replace button content with a spinner + loading text.
            // Defer disabling until after the browser has collected form data.
            // Disabling the clicked submit button synchronously can drop its
            // name/value pair, which matters for multi-action forms.
            setTimeout(() => {
                btn.disabled = true;
                btn.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>'
                    + btn.dataset.loadingText;
            }, 0);
        }

        if (this.hasFieldTarget) {
            // Defer disabling until after the browser has collected the form data.
            // Disabling a field synchronously inside the submit event excludes it from
            // the POST payload, because the browser reads field values after the event.
            setTimeout(() => {
                this.fieldTargets.forEach((el) => { el.disabled = true; });
            }, 0);
        }
    }
}
