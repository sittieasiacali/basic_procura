document.addEventListener('DOMContentLoaded', () => {
    const history = document.querySelector('[data-dashboard-history]');
    if (!history) return;

    // Keep the selected view when its filters or pagination reload the page.
    history.querySelectorAll('[data-history-panel]').forEach((panel) => {
        const view = panel.dataset.historyPanel;
        panel.querySelectorAll('form[method="GET" i]').forEach((form) => {
            let input = form.querySelector('input[name="history_view"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'history_view';
                form.append(input);
            }
            input.value = view;
        });
        panel.querySelectorAll('.pagination a, .table-toolbar-actions a').forEach((link) => {
            const url = new URL(link.href);
            url.searchParams.set('history_view', view);
            link.href = url.href;
        });
    });

    const panels = [...history.querySelectorAll('[data-history-panel]')];
    const arrows = [...history.querySelectorAll('[data-history-direction]')];
    const labels = { processed: 'Processed by me', voided: 'Voided requests' };
    let busy = false;

    const showView = (view, updateUrl = true) => {
        panels.forEach((panel) => {
            const active = panel.dataset.historyPanel === view;
            panel.hidden = !active;
            panel.classList.toggle('active', active);
            panel.classList.toggle('show', active);
        });
        history.dataset.currentView = view;
        history.querySelector('[data-history-target]').textContent = view === 'processed' ? 'Voided' : 'Processed';
        arrows.forEach((arrow) => {
            arrow.setAttribute('aria-label', `Show ${labels[view === 'processed' ? 'voided' : 'processed']}`);
            arrow.dataset.historyDirection = view === 'processed' ? 'next' : 'previous';
        });
        if (!updateUrl) return;
        const url = new URL(window.location.href);
        url.searchParams.set('history_view', view);
        url.hash = view === 'voided' ? 'voided-monitoring-heading' : 'processed-requests-heading';
        window.history.replaceState(window.history.state, '', url);
    };

    arrows.forEach((arrow) => arrow.addEventListener('click', async () => {
        if (busy) return;
        const view = history.dataset.currentView === 'processed' ? 'voided' : 'processed';
        const current = panels.find((panel) => !panel.hidden);
        const incoming = panels.find((panel) => panel.dataset.historyPanel === view);
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !current.animate) {
            showView(view);
            return;
        }

        busy = true;
        arrows.forEach((button) => button.setAttribute('aria-disabled', 'true'));
        const content = history.querySelector('.tab-content');
        content.style.minHeight = `${current.getBoundingClientRect().height}px`;
        const forward = arrow.dataset.historyDirection === 'next';
        const origin = forward ? 'left center' : 'right center';
        const angle = forward ? -85 : 85;
        const timing = { duration: 220, easing: 'ease-in-out' };
        try {
            await current.animate([
                { transform: 'rotateY(0deg)', opacity: 1, transformOrigin: origin },
                { transform: `rotateY(${angle}deg)`, opacity: 0, transformOrigin: origin },
            ], timing).finished;
            showView(view);
            await incoming.animate([
                { transform: `rotateY(${-angle}deg)`, opacity: 0, transformOrigin: origin },
                { transform: 'rotateY(0deg)', opacity: 1, transformOrigin: origin },
            ], timing).finished;
        } catch {
            showView(view);
        } finally {
            content.style.minHeight = '';
            arrows.forEach((button) => button.removeAttribute('aria-disabled'));
            busy = false;
        }
    }));

    history.querySelector('.dashboard-history-switcher').addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        event.preventDefault();
        arrows[0].click();
    });

    // Existing links to a history heading should reveal the matching table.
    const revealLinkedPanel = () => {
        const view = window.location.hash === '#voided-monitoring-heading' ? 'voided'
            : window.location.hash === '#processed-requests-heading' ? 'processed' : null;
        if (!view || busy) return;
        showView(view, false);
    };
    revealLinkedPanel();
    window.addEventListener('hashchange', revealLinkedPanel);
});
