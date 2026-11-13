// This is a responsive workspace preference; server policies govern every action.
export function initializeMobileCampaignWorkspace(root = document, viewport = window.matchMedia('(max-width: 767.98px)'), navigate = url => window.location.replace(url)) {
    const config = root.querySelector('meta[name="mobile-campaign-workspace"]');
    if (!config) return;
    const update = () => {
        root.documentElement.classList.toggle('mobile-campaign-workspace', viewport.matches);
        if (viewport.matches && config.dataset.allowed !== 'true') navigate(config.dataset.url);
    };
    update();
    viewport.addEventListener('change', update);
}

initializeMobileCampaignWorkspace();
