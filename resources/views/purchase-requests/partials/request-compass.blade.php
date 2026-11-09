<aside class="request-compass" aria-labelledby="request-compass-heading">
    <header class="request-compass-header">
        <span class="request-compass-emblem"><x-ui.icon name="send" size="25" /></span>
        <div>
            <h2 id="request-compass-heading">Request Compass</h2>
            <p>Your guided path from request to approval.</p>
        </div>
    </header>
    <div class="request-compass-body">
        <nav aria-label="Submission progress">
            <div class="request-compass-section-heading">
                <h3>Submission Progress</h3>
                <span data-compass-progress>Step 1 of 3</span>
            </div>
            <ol class="request-compass-steps">
                <li><a href="#request-details-heading" data-compass-step="1" aria-current="step"><strong>Provide Request Details</strong><small>Complete the core information for your purchase request.</small></a></li>
                <li><a href="#requested-items-heading" data-compass-step="2"><strong>Review &amp; Confirm</strong><small>Add items, quantities, and supporting documents.</small></a></li>
                <li><a href="#request-submission-signature-heading" data-compass-step="3"><strong>Submit for Approval</strong><small>Sign your request and submit it for screening.</small></a></li>
            </ol>
        </nav>
        <section class="request-compass-path" aria-labelledby="compass-path-heading">
            <h3 id="compass-path-heading"><x-ui.icon name="flow" size="22" />Approval Path <span>(Preview)</span></h3>
            <ol>
                <li><x-ui.icon name="user" size="21" /><span>Requestor<br>(You)</span></li>
                <li><x-ui.icon name="users" size="21" /><span>Department<br>Screening</span></li>
                <li><x-ui.icon name="clipboard-check" size="21" /><span>Procurement<br>Review</span></li>
            </ol>
            <p>The full workflow is shown below your requested items.</p>
        </section>
        <section class="request-compass-tips" aria-labelledby="compass-tips-heading">
            <h3 id="compass-tips-heading"><x-ui.icon name="info" size="23" />Smart Tips</h3>
            <ul>
                <li>Choose the team and category that match your request.</li>
                <li>Ensure the required date is realistic.</li>
                <li>Provide a concise purpose and complete item details.</li>
            </ul>
        </section>
        <details class="request-compass-next">
            <summary><x-ui.icon name="search" size="23" />What Happens Next?<x-ui.icon name="chevron-right" size="18" /></summary>
            <p>After submission, your request goes to IT or Premises screening. Purchasing then handles canvassing and recommendations before President approval.</p>
        </details>
        <p class="request-compass-draft-note"><x-ui.icon name="save" size="16" />Need more time? Save a draft and return later.</p>
    </div>
</aside>

@push('scripts')
<script>
    (() => {
        const form = document.getElementById('purchase-request-form');
        const links = [...document.querySelectorAll('[data-compass-step]')];
        const progress = document.querySelector('[data-compass-progress]');
        const sections = ['.request-details-card', '.items-builder-card', '.builder-signature-card'];
        function setStep(step) {
            links.forEach((link) => {
                if (Number(link.dataset.compassStep) === step) link.setAttribute('aria-current', 'step');
                else link.removeAttribute('aria-current');
            });
            progress.textContent = `Step ${step} of 3`;
        }
        links.forEach((link) => link.addEventListener('click', () => {
            setStep(Number(link.dataset.compassStep));
            document.querySelector(link.getAttribute('href'))?.focus({ preventScroll: true });
        }));
        form.addEventListener('focusin', (event) => {
            const index = sections.findIndex((selector) => event.target.closest(selector));
            if (index !== -1) setStep(index + 1);
        });
    })();
</script>
@endpush
