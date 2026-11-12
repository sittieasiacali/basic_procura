<script>
    // Only PROCURA workflow frames hide app navigation; phone previews keep it.
    try {
        if (window.self !== window.top && (
            window.frameElement?.matches('[data-workflow-action-frame], [data-workflow-request-frame]')
            || ['procura-workflow-action', 'procura-workflow-request'].includes(window.name)
        )) document.documentElement.classList.add('is-embedded');
    } catch (_) { /* An unrelated preview frame keeps normal navigation. */ }
</script>
